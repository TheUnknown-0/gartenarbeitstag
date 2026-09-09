<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Automatische Zuteilung im Wunschmodus.
 *
 * Ablauf je Zeitblock:
 *  1. Alle Schüler:innen mit Wünschen in diesem Block einsammeln.
 *  2. Runden nach Priorität (1, dann 2, …): In jeder Runde werden die noch
 *     unversorgten Schüler:innen in fairer Reihenfolge durchgegangen — wer in
 *     früheren Blöcken schlechter bedient wurde, kommt zuerst dran, Gleichstand
 *     entscheidet ein seed-basierter Zufall (reproduzierbar).
 *  3. Optional: Wer leer ausgeht, wird auf einen beliebigen freien Stand
 *     verteilt („Auffüllen“). Ebenso optional werden Schüler:innen ohne Wünsche
 *     bis zur Mindestzahl Blöcke des Aktionstags aufgefüllt.
 *
 * Jede einzelne Platzierung läuft durch LimitCheck — Ausschlusskriterien,
 * Zeitkonflikte, Kapazität, Klassen-/Stufenlimits gelten hier ohne Ausnahme.
 * Bestehende feste Einschreibungen (z. B. durch die Orga) bleiben erhalten und
 * zählen mit.
 *
 * `simulate()` führt exakt dieselbe Logik in einer Transaktion aus und rollt
 * sie am Ende zurück — der Bericht zeigt, was passieren würde.
 */
final class AutoAssign
{
    public function __construct(
        private readonly Database $db,
        private readonly LimitCheck $limits,
        private readonly Audit $audit,
    ) {
    }

    /**
     * @param array{seed?: int, fill_unlucky?: bool, fill_no_wishes?: bool} $options
     * @return array<string, mixed> Bericht (siehe execute())
     */
    public function simulate(array $day, array $options = []): array
    {
        $report = null;
        try {
            $this->db->transaction(function () use ($day, $options, &$report): void {
                $report = $this->execute($day, $options);
                throw new RollbackSimulation();
            });
        } catch (RollbackSimulation) {
            // gewollt: alle Änderungen verworfen
        }

        if ($report === null) {
            throw new RuntimeException('Probelauf fehlgeschlagen.');
        }
        $report['simulated'] = true;

        return $report;
    }

    /**
     * @param array{seed?: int, fill_unlucky?: bool, fill_no_wishes?: bool} $options
     * @return array<string, mixed>
     */
    public function run(array $day, array $options = []): array
    {
        return $this->db->transaction(function () use ($day, $options): array {
            $report = $this->execute($day, $options);
            $this->db->run('UPDATE garden_days SET assignment_done_at = NOW() WHERE id = ?', [(int) $day['id']]);
            $this->audit->log(
                'assignment.run',
                'warning',
                sprintf('Zuteilung „%s“: %d Plätze vergeben, %d offene Wünsche, Seed %d', $day['name'], $report['assigned_total'], $report['unassigned_total'], $report['seed']),
            );
            $report['simulated'] = false;

            return $report;
        });
    }

    /**
     * Entfernt alle automatisch vergebenen Plätze des Aktionstags; Wünsche und
     * manuell gesetzte Einschreibungen bleiben erhalten.
     */
    public function reset(array $day): int
    {
        return $this->db->transaction(function () use ($day): int {
            $count = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND source = 'auto' AND status = 'assigned'",
                [(int) $day['id']],
            );
            $this->db->run(
                "DELETE a FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id
                 WHERE e.garden_day_id = ? AND e.source = 'auto' AND e.status = 'assigned'",
                [(int) $day['id']],
            );
            // Aus Wünschen entstandene Plätze werden wieder zu Wünschen,
            // aufgefüllte Plätze (ohne Priorität) verschwinden ganz.
            $this->db->run(
                "UPDATE enrollments SET status = 'wish', source = 'self'
                 WHERE garden_day_id = ? AND source = 'auto' AND status = 'assigned' AND priority IS NOT NULL",
                [(int) $day['id']],
            );
            $this->db->run(
                "DELETE FROM enrollments WHERE garden_day_id = ? AND source = 'auto' AND status = 'assigned'",
                [(int) $day['id']],
            );
            $this->db->run('UPDATE garden_days SET assignment_done_at = NULL WHERE id = ?', [(int) $day['id']]);
            $this->audit->log('assignment.reset', 'critical', sprintf('Zuteilung „%s“ zurückgesetzt: %d Plätze entfernt', $day['name'], $count));

            return $count;
        });
    }

    /**
     * @param array{seed?: int, fill_unlucky?: bool, fill_no_wishes?: bool} $options
     * @return array<string, mixed>
     */
    private function execute(array $day, array $options): array
    {
        $dayId = (int) $day['id'];
        $seed = isset($options['seed']) ? (int) $options['seed'] : random_int(1, 999999);
        mt_srand($seed);
        $fillUnlucky = (bool) ($options['fill_unlucky'] ?? true);
        $fillNoWishes = (bool) ($options['fill_no_wishes'] ?? false);
        $minBlocks = (int) ($day['min_blocks_per_student'] ?? 1);

        $blocks = $this->db->fetchAll('SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order, start_time, id', [$dayId]);
        $stations = [];
        // manual_only-Stände sind der automatischen Zuteilung bewusst nicht zugänglich —
        // dort entscheidet ausschließlich Standleitung/Orga, wer einen festen Platz bekommt.
        foreach ($this->db->fetchAll('SELECT * FROM stations WHERE garden_day_id = ? AND is_active = 1 AND manual_only = 0', [$dayId]) as $station) {
            $stations[(int) $station['id']] = $station;
        }
        $students = [];
        foreach ($this->db->fetchAll("SELECT * FROM users WHERE role = 'student' AND is_active = 1 ORDER BY id") as $student) {
            $students[(int) $student['id']] = $student;
        }

        // Fairness-Score: Summe der erhaltenen Prioritäten (niedriger = besser bedient)
        $score = array_fill_keys(array_keys($students), 0);
        $report = [
            'seed' => $seed,
            'blocks' => [],
            'assigned_total' => 0,
            'unassigned_total' => 0,
            'by_priority' => [],
            'filled' => 0,
            'unassigned' => [],
        ];

        foreach ($blocks as $block) {
            $blockId = (int) $block['id'];
            $blockReport = ['id' => $blockId, 'name' => $block['name'], 'assigned' => 0, 'by_priority' => [], 'filled' => 0, 'unassigned' => []];

            // Wünsche je Schüler:in, nach Priorität
            $wishes = [];
            $rows = $this->db->fetchAll(
                "SELECT user_id, station_id, priority FROM enrollments
                 WHERE garden_day_id = ? AND time_block_id = ? AND status = 'wish' ORDER BY priority, id",
                [$dayId, $blockId],
            );
            foreach ($rows as $row) {
                $userId = (int) $row['user_id'];
                if (!isset($students[$userId])) {
                    continue;
                }
                $wishes[$userId][] = ['station_id' => (int) $row['station_id'], 'priority' => (int) $row['priority']];
            }

            // Wer hat in diesem Block bereits einen festen Platz?
            $settled = [];
            foreach ($this->db->fetchAll(
                "SELECT user_id FROM enrollments WHERE garden_day_id = ? AND time_block_id = ? AND status = 'assigned'",
                [$dayId, $blockId],
            ) as $row) {
                $settled[(int) $row['user_id']] = true;
            }

            $maxPriority = 0;
            foreach ($wishes as $list) {
                foreach ($list as $wish) {
                    $maxPriority = max($maxPriority, $wish['priority']);
                }
            }

            // Runden nach Priorität
            for ($priority = 1; $priority <= $maxPriority; $priority++) {
                $candidates = array_keys(array_filter($wishes, static fn (array $list, int $userId) => !isset($settled[$userId]), ARRAY_FILTER_USE_BOTH));
                $candidates = $this->fairOrder($candidates, $score);

                foreach ($candidates as $userId) {
                    foreach ($wishes[$userId] as $wish) {
                        if ($wish['priority'] !== $priority) {
                            continue;
                        }
                        if ($this->place($day, $students[$userId], $stations[$wish['station_id']] ?? null, $blockId)) {
                            $settled[$userId] = true;
                            $score[$userId] += $priority;
                            $blockReport['assigned']++;
                            $blockReport['by_priority'][$priority] = ($blockReport['by_priority'][$priority] ?? 0) + 1;
                        }
                        break;
                    }
                }
            }

            // Auffüllen: Wünschende ohne Platz
            $unlucky = array_keys(array_filter($wishes, static fn (array $list, int $userId) => !isset($settled[$userId]), ARRAY_FILTER_USE_BOTH));
            if ($fillUnlucky) {
                foreach ($this->fairOrder($unlucky, $score) as $userId) {
                    if ($this->placeAnywhere($day, $students[$userId], $stations, $blockId)) {
                        $settled[$userId] = true;
                        $score[$userId] += $maxPriority + 1;
                        $blockReport['assigned']++;
                        $blockReport['filled']++;
                    }
                }
                $unlucky = array_keys(array_filter($wishes, static fn (array $list, int $userId) => !isset($settled[$userId]), ARRAY_FILTER_USE_BOTH));
            }

            foreach ($unlucky as $userId) {
                $s = $students[$userId];
                $blockReport['unassigned'][] = [
                    'user_id' => $userId,
                    'name' => trim($s['firstname'] . ' ' . $s['lastname']),
                    'class' => $s['class'],
                    'reason' => $this->explain($day, $s, $wishes[$userId], $stations, $blockId),
                ];
            }

            foreach ($blockReport['by_priority'] as $p => $n) {
                $report['by_priority'][$p] = ($report['by_priority'][$p] ?? 0) + $n;
            }
            $report['assigned_total'] += $blockReport['assigned'];
            $report['filled'] += $blockReport['filled'];
            $report['unassigned_total'] += count($blockReport['unassigned']);
            $report['blocks'][] = $blockReport;
        }

        // Schüler:innen ohne Wünsche bis zur Mindestzahl Blöcke auffüllen
        $report['no_wishes_filled'] = 0;
        $report['no_wishes_open'] = [];
        if ($fillNoWishes && $minBlocks > 0) {
            foreach ($this->fairOrder(array_keys($students), $score) as $userId) {
                $assignedBlocks = $this->assignedBlockIds($dayId, $userId);
                if (count($assignedBlocks) >= $minBlocks) {
                    continue;
                }
                foreach ($blocks as $block) {
                    if (count($assignedBlocks) >= $minBlocks) {
                        break;
                    }
                    if (in_array((int) $block['id'], $assignedBlocks, true)) {
                        continue;
                    }
                    if ($this->placeAnywhere($day, $students[$userId], $stations, (int) $block['id'])) {
                        $assignedBlocks[] = (int) $block['id'];
                        $report['no_wishes_filled']++;
                        $report['assigned_total']++;
                    }
                }
                if (count($assignedBlocks) < $minBlocks) {
                    $s = $students[$userId];
                    $report['no_wishes_open'][] = ['user_id' => $userId, 'name' => trim($s['firstname'] . ' ' . $s['lastname']), 'class' => $s['class'], 'blocks' => count($assignedBlocks)];
                }
            }
        }

        ksort($report['by_priority']);
        $report['stations'] = $this->stationFill($dayId);

        return $report;
    }

    /**
     * Versucht, eine Person auf einen konkreten Stand zu setzen.
     *
     * Besteht für genau diese Person/Stand/Block bereits eine Zeile (der
     * Wunsch, der hier erfüllt wird), wird sie auf `assigned` umgestellt statt
     * eine zweite anzulegen — `uq_enrollments_user_station_block` erlaubt pro
     * Person/Stand/Block ohnehin nur eine Zeile, unabhängig vom Status. Beim
     * Prüfen wird dieselbe Zeile deshalb von der Duplikat-Prüfung ausgenommen.
     */
    private function place(array $day, array $student, ?array $station, int $blockId): bool
    {
        if ($station === null) {
            return false;
        }
        $studentId = (int) $student['id'];
        $stationId = (int) $station['id'];

        $existingId = (int) ($this->db->fetchValue(
            'SELECT id FROM enrollments WHERE user_id = ? AND station_id = ? AND time_block_id = ? LIMIT 1',
            [$studentId, $stationId, $blockId],
        ) ?? 0);

        $violations = $this->limits->check($student, $station, $blockId, $day, 'assigned', [
            'ignore_enrollment_id' => $existingId,
        ]);
        if ($violations !== []) {
            return false;
        }

        if ($existingId > 0) {
            // Wunsch erfüllt: Zeile hochstufen, Priorität bleibt erhalten —
            // reset() macht daraus wieder einen Wunsch.
            $this->db->run(
                "UPDATE enrollments SET status = 'assigned', source = 'auto' WHERE id = ?",
                [$existingId],
            );
        } else {
            $this->db->run(
                "INSERT INTO enrollments (garden_day_id, user_id, station_id, time_block_id, status, source) VALUES (?, ?, ?, ?, 'assigned', 'auto')",
                [(int) $day['id'], $studentId, $stationId, $blockId],
            );
        }

        return true;
    }

    /**
     * Setzt eine Person auf den Stand mit den meisten freien Plätzen im Block
     * (bei dem sie alle Regeln erfüllt).
     *
     * @param array<int, array<string, mixed>> $stations
     */
    private function placeAnywhere(array $day, array $student, array $stations, int $blockId): bool
    {
        $free = $this->db->fetchAll(
            "SELECT sb.station_id, sb.capacity - (
                SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'assigned'
             ) AS free
             FROM station_blocks sb
             JOIN stations s ON s.id = sb.station_id
             WHERE sb.time_block_id = ? AND s.garden_day_id = ? AND s.is_active = 1 AND s.manual_only = 0
             HAVING free > 0
             ORDER BY free DESC, RAND(?)",
            [$blockId, (int) $day['id'], mt_rand()],
        );

        foreach ($free as $row) {
            $station = $stations[(int) $row['station_id']] ?? null;
            if ($this->place($day, $student, $station, $blockId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sortiert Kandidaten nach Fairness-Score (aufsteigend), Gleichstand zufällig.
     *
     * @param list<int> $userIds
     * @param array<int, int> $score
     * @return list<int>
     */
    private function fairOrder(array $userIds, array $score): array
    {
        $rand = [];
        foreach ($userIds as $userId) {
            $rand[$userId] = mt_rand();
        }
        usort($userIds, static fn (int $a, int $b): int => [$score[$a] ?? 0, $rand[$a]] <=> [$score[$b] ?? 0, $rand[$b]]);

        return $userIds;
    }

    /** @return list<int> */
    private function assignedBlockIds(int $dayId, int $userId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            "SELECT DISTINCT time_block_id FROM enrollments WHERE garden_day_id = ? AND user_id = ? AND status = 'assigned'",
            [$dayId, $userId],
        ), 'time_block_id'));
    }

    /**
     * Begründung, warum kein Wunsch erfüllt werden konnte (für den Bericht).
     *
     * @param list<array{station_id: int, priority: int}> $wishes
     * @param array<int, array<string, mixed>> $stations
     */
    private function explain(array $day, array $student, array $wishes, array $stations, int $blockId): string
    {
        $parts = [];
        foreach ($wishes as $wish) {
            $station = $stations[$wish['station_id']] ?? null;
            if ($station === null) {
                $parts[] = 'Prio ' . $wish['priority'] . ': Stand inaktiv';
                continue;
            }
            $violations = $this->limits->check($student, $station, $blockId, $day, 'assigned');
            $parts[] = 'Prio ' . $wish['priority'] . ' (' . $station['name'] . '): ' . (LimitCheck::messages($violations) ?: 'ok');
        }

        return implode(' · ', $parts);
    }

    /**
     * Belegung je Stand und Block nach der Zuteilung.
     *
     * @return list<array<string, mixed>>
     */
    private function stationFill(int $dayId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id AS station_id, s.name AS station, s.min_students, tb.id AS block_id, tb.name AS block, sb.capacity,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = s.id AND e.time_block_id = tb.id AND e.status = 'assigned') AS assigned
             FROM stations s
             JOIN station_blocks sb ON sb.station_id = s.id
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             WHERE s.garden_day_id = ? AND s.is_active = 1
             ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name",
            [$dayId],
        );
    }
}

