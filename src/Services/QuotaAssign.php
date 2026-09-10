<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Dritter Zuteilungsmodus "Quote" — angelehnt an
 * github.com/schlaumischlumpf/Gartenarbeitstag-Tool: die Orga legt je Stand,
 * Zeitblock und Klassenstufe eine Zielzahl fest (station_grade_demand),
 * optional eine gewichtete Klassen-Präferenz je Stand
 * (station_class_preference) oder eine Prioritätsklasse je Stufe+Block
 * (garden_day_slot_priority). Der Algorithmus entscheidet dann nur noch
 * zufällig, WELCHE Schüler:innen der passenden Klassen die Quote auffüllen.
 *
 * Anders als im Referenz-Tool (ein Platz pro Schüler:in für den ganzen Tag)
 * läuft die Verteilung hier je Zeitblock unabhängig, weil dieses Projekt
 * Rotation über mehrere Zeitblöcke kennt — genau wie Direkt-/Wunschmodus.
 * Zwei Grenzen halten den Kandidatenpool je Block klein: `max_blocks_per_student`
 * begrenzt, in wie vielen Blöcken eine Person insgesamt landen darf (bestehende
 * manuelle Plätze eingerechnet), und wer in einem zeitlich überlappenden Block
 * schon fest eingeteilt ist, fällt für diesen Block ebenfalls raus.
 *
 * manual_only-Stände und Ausschlusskriterien gelten unverändert: erstere
 * werden nie automatisch befüllt, letztere schließen einzelne Schüler:innen
 * aus dem Kandidatenpool eines Standes aus.
 *
 * `simulate()` läuft in einer Transaktion und rollt am Ende zurück (siehe
 * RollbackSimulation) — der Bericht zeigt, was passieren würde.
 */
final class QuotaAssign
{
    public function __construct(
        private readonly Database $db,
        private readonly Audit $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function simulate(array $day): array
    {
        $report = null;
        try {
            $this->db->transaction(function () use ($day, &$report): void {
                $report = $this->execute($day);
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

    /** @return array<string, mixed> */
    public function run(array $day): array
    {
        return $this->db->transaction(function () use ($day): array {
            $report = $this->execute($day);
            $this->db->run('UPDATE garden_days SET assignment_done_at = NOW() WHERE id = ?', [(int) $day['id']]);
            $this->audit->log(
                'quota.run',
                'warning',
                sprintf('Quoten-Zuteilung „%s“: %d Plätze vergeben, %d Konflikte', $day['name'], $report['assigned_total'], count($report['conflicts'])),
            );
            $report['simulated'] = false;

            return $report;
        });
    }

    /** Entfernt alle per Quote vergebenen Plätze; manuelle/andere Einschreibungen bleiben erhalten. */
    public function reset(array $day): int
    {
        return $this->db->transaction(function () use ($day): int {
            $dayId = (int) $day['id'];
            $count = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND source = 'quota' AND status = 'assigned'",
                [$dayId],
            );
            $this->db->run(
                "DELETE a FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id
                 WHERE e.garden_day_id = ? AND e.source = 'quota' AND e.status = 'assigned'",
                [$dayId],
            );
            $this->db->run("DELETE FROM enrollments WHERE garden_day_id = ? AND source = 'quota' AND status = 'assigned'", [$dayId]);
            $this->db->run('UPDATE garden_days SET assignment_done_at = NULL WHERE id = ?', [$dayId]);
            $this->audit->log('quota.reset', 'critical', sprintf('Quoten-Zuteilung „%s“ zurückgesetzt: %d Plätze entfernt', $day['name'], $count));

            return $count;
        });
    }

    /** @return array{assigned_total: int, conflicts: list<string>, by_block: list<array{id: int, name: string, assigned: int}>} */
    private function execute(array $day): array
    {
        $dayId = (int) $day['id'];
        $db = $this->db;

        $grades = DayQueries::gradesOf($db);
        $blocks = DayQueries::blocksOf($db, $dayId);

        $stations = $db->fetchAll('SELECT * FROM stations WHERE garden_day_id = ? AND is_active = 1 AND manual_only = 0', [$dayId]);
        $stationsById = [];
        foreach ($stations as $station) {
            $stationsById[(int) $station['id']] = $station;
        }
        $stationIds = array_keys($stationsById);
        if ($stationIds === []) {
            return ['assigned_total' => 0, 'conflicts' => ['Keine automatisch befüllbaren Stände vorhanden.'], 'by_block' => []];
        }

        $demand = $this->demandByStationBlockGrade($dayId);
        $preference = $this->classPreferenceByStation($dayId);
        $priority = $this->slotPriority($dayId);
        $excluded = $this->excludedStudentsByStation($dayId);

        // Höchstzahl Zeitblöcke je Schüler:in am Aktionstag (NULL = unbegrenzt).
        $maxBlocks = $day['max_blocks_per_student'] !== null ? (int) $day['max_blocks_per_student'] : null;

        // blockId => Set der sich zeitlich überschneidenden Block-IDs (inkl. sich
        // selbst). Wer in einem davon fest eingeteilt ist, kann hier nicht mehr
        // hin.
        $overlapSet = [];
        foreach ($blocks as $a) {
            foreach ($blocks as $b) {
                if ((int) $a['id'] === (int) $b['id']
                    || ($a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'])) {
                    $overlapSet[(int) $a['id']][(int) $b['id']] = true;
                }
            }
        }

        // userId => Set bereits fest zugeteilter Block-IDs (manuell, früherer Lauf,
        // …). Wächst mit jeder Platzierung dieses Laufs weiter und speist sowohl
        // die Überschneidungs- als auch die Höchstzahl-Prüfung.
        $assignedBlocksByUser = [];
        foreach ($db->fetchAll(
            "SELECT user_id, time_block_id FROM enrollments WHERE garden_day_id = ? AND status = 'assigned'",
            [$dayId],
        ) as $row) {
            $assignedBlocksByUser[(int) $row['user_id']][(int) $row['time_block_id']] = true;
        }

        $basePools = $this->studentPoolsByGradeClass($grades);

        $assignedTotal = 0;
        $conflicts = [];
        $byBlock = [];

        foreach ($blocks as $block) {
            $blockId = (int) $block['id'];

            $offered = array_map('intval', array_column(
                $db->fetchAll('SELECT station_id FROM station_blocks WHERE time_block_id = ?', [$blockId]),
                'station_id',
            ));
            $blockStationIds = array_values(array_intersect($stationIds, $offered));
            if ($blockStationIds === []) {
                continue;
            }

            $blockOverlap = $overlapSet[$blockId] ?? [$blockId => true];

            // Frischer Pool je Block: wer in diesem oder einem zeitgleichen Block
            // schon fest eingeschrieben ist (manuell, früherer Lauf o.ä.) oder die
            // erlaubte Höchstzahl Zeitblöcke am Tag bereits erreicht hat, zählt
            // nicht mehr mit.
            $pools = [];
            foreach ($basePools as $grade => $byClass) {
                foreach ($byClass as $class => $ids) {
                    $pools[$grade][$class] = array_values(array_filter($ids, static function (int $id) use ($assignedBlocksByUser, $blockOverlap, $maxBlocks): bool {
                        $userBlocks = $assignedBlocksByUser[$id] ?? [];
                        if ($maxBlocks !== null && count($userBlocks) >= $maxBlocks) {
                            return false;
                        }
                        foreach ($userBlocks as $bid => $_true) {
                            if (isset($blockOverlap[$bid])) {
                                return false;
                            }
                        }

                        return true;
                    }));
                }
            }

            $reserve = $this->reservePriorityStudents($pools, $priority[$blockId] ?? [], $demand, $blockStationIds, $blockId);

            $rrPointer = [];
            foreach ($grades as $grade) {
                $rrPointer[$grade] = array_key_exists($grade, $pools) ? random_int(0, max(0, count($pools[$grade]) - 1)) : 0;
            }

            $shuffledStations = $blockStationIds;
            shuffle($shuffledStations);
            $blockAssigned = 0;

            foreach ($shuffledStations as $stationId) {
                $station = $stationsById[$stationId];
                foreach ($grades as $grade) {
                    $need = $demand[$stationId][$blockId][$grade] ?? 0;
                    if ($need <= 0) {
                        continue;
                    }

                    $excludedIds = $excluded[$stationId] ?? [];
                    $stationPreference = $preference[$stationId] ?? [];

                    if ($stationPreference !== []) {
                        $picked = $this->pickWeighted($pools, $grade, $need, $stationPreference, $excludedIds);
                    } else {
                        $priorityClass = $priority[$blockId][$grade] ?? null;
                        $picked = $this->pickWithPriority($pools, $reserve, $rrPointer, $grade, $need, $priorityClass, $excludedIds);
                    }

                    foreach ($picked as $studentId) {
                        $this->place($db, $dayId, $studentId, $stationId, $blockId);
                        // Der Pool dieses Blocks schließt bereits belegte Schüler:innen
                        // aus — jede Platzierung belegt also einen weiteren Zeitblock.
                        $assignedBlocksByUser[$studentId][$blockId] = true;
                    }
                    $assignedTotal += count($picked);
                    $blockAssigned += count($picked);

                    if (count($picked) < $need) {
                        $conflicts[] = sprintf(
                            'Stand „%s“, %s, Stufe %d: Bedarf %d, nur %d verfügbar.',
                            $station['name'],
                            DayQueries::blockLabel($block),
                            $grade,
                            $need,
                            count($picked),
                        );
                    }
                }
            }

            $byBlock[] = ['id' => $blockId, 'name' => DayQueries::blockLabel($block), 'assigned' => $blockAssigned];
        }

        return ['assigned_total' => $assignedTotal, 'conflicts' => $conflicts, 'by_block' => $byBlock];
    }

    /**
     * Trägt eine Person fest ein. Existiert für Person/Stand/Zeitblock schon
     * eine Zeile (z. B. ein alter, nie erfüllter Wunsch — `uq_enrollments_user_station_block`
     * erlaubt ohnehin nur eine Zeile je Kombination), wird sie hochgestuft statt
     * eine zweite anzulegen.
     */
    private function place(Database $db, int $dayId, int $studentId, int $stationId, int $blockId): void
    {
        $existingId = (int) ($db->fetchValue(
            'SELECT id FROM enrollments WHERE user_id = ? AND station_id = ? AND time_block_id = ? LIMIT 1',
            [$studentId, $stationId, $blockId],
        ) ?? 0);

        if ($existingId > 0) {
            $db->run("UPDATE enrollments SET status = 'assigned', source = 'quota', priority = NULL WHERE id = ?", [$existingId]);

            return;
        }

        $db->run(
            "INSERT INTO enrollments (garden_day_id, user_id, station_id, time_block_id, status, source) VALUES (?, ?, ?, ?, 'assigned', 'quota')",
            [$dayId, $studentId, $stationId, $blockId],
        );
    }

    // ---------- Datengrundlage ----------

    /** @return array<int, array<int, array<int, int>>> stationId => blockId => grade => demand */
    private function demandByStationBlockGrade(int $dayId): array
    {
        $result = [];
        foreach ($this->db->fetchAll(
            'SELECT sgd.station_id, sgd.time_block_id, sgd.grade, sgd.demand FROM station_grade_demand sgd
             JOIN stations s ON s.id = sgd.station_id WHERE s.garden_day_id = ?',
            [$dayId],
        ) as $row) {
            $result[(int) $row['station_id']][(int) $row['time_block_id']][(int) $row['grade']] = (int) $row['demand'];
        }

        return $result;
    }

    /** @return array<int, array<string, int>> stationId => class => weight */
    private function classPreferenceByStation(int $dayId): array
    {
        $result = [];
        foreach ($this->db->fetchAll(
            'SELECT scp.station_id, scp.class, scp.weight FROM station_class_preference scp
             JOIN stations s ON s.id = scp.station_id WHERE s.garden_day_id = ?',
            [$dayId],
        ) as $row) {
            $result[(int) $row['station_id']][(string) $row['class']] = (int) $row['weight'];
        }

        return $result;
    }

    /** @return array<int, array<int, string>> blockId => grade => class */
    private function slotPriority(int $dayId): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT * FROM garden_day_slot_priority WHERE garden_day_id = ?', [$dayId]) as $row) {
            $result[(int) $row['time_block_id']][(int) $row['grade']] = (string) $row['class'];
        }

        return $result;
    }

    /** @return array<int, array<int, bool>> stationId => Set<studentId> */
    private function excludedStudentsByStation(int $dayId): array
    {
        $result = [];
        foreach ($this->db->fetchAll(
            "SELECT se.station_id, ue.user_id FROM station_exclusions se
             JOIN user_exclusions ue ON ue.criterion_id = se.criterion_id
             JOIN exclusion_criteria ec ON ec.id = se.criterion_id AND ec.is_active = 1
             JOIN stations s ON s.id = se.station_id WHERE s.garden_day_id = ?",
            [$dayId],
        ) as $row) {
            $result[(int) $row['station_id']][(int) $row['user_id']] = true;
        }

        return $result;
    }

    /**
     * @param list<int> $grades
     * @return array<int, array<string, list<int>>> grade => class => [studentId, ...] (gemischt)
     */
    private function studentPoolsByGradeClass(array $grades): array
    {
        $pools = [];
        foreach ($this->db->fetchAll(
            "SELECT id, class, grade FROM users
             WHERE role = 'student' AND is_active = 1 AND grade IS NOT NULL AND class IS NOT NULL AND class <> ''",
        ) as $row) {
            $pools[(int) $row['grade']][(string) $row['class']][] = (int) $row['id'];
        }
        foreach ($grades as $grade) {
            foreach (array_keys($pools[$grade] ?? []) as $class) {
                shuffle($pools[$grade][$class]);
            }
        }

        return $pools;
    }

    // ---------- Priorität ----------

    /**
     * Reserviert vorab so viele Schüler:innen der konfigurierten Prioritätsklasse,
     * wie insgesamt für Stufe+Block benötigt werden — sie stehen dem Round-Robin
     * anderer Klassen dann nicht mehr zur Verfügung.
     *
     * @param array<int, array<string, list<int>>> $pools grade => class => ids (wird mutiert)
     * @param array<int, string> $priorityForGrade grade => class
     * @param array<int, array<int, array<int, int>>> $demand stationId => blockId => grade => n
     * @param list<int> $blockStationIds
     * @return array<int, array<string, list<int>>> grade => class => reservierte ids
     */
    private function reservePriorityStudents(array &$pools, array $priorityForGrade, array $demand, array $blockStationIds, int $blockId): array
    {
        $reserve = [];
        foreach ($priorityForGrade as $grade => $class) {
            if (!isset($pools[$grade][$class])) {
                continue;
            }
            $need = 0;
            foreach ($blockStationIds as $stationId) {
                $need += $demand[$stationId][$blockId][$grade] ?? 0;
            }
            $take = min($need, count($pools[$grade][$class]));
            if ($take <= 0) {
                continue;
            }
            $reserve[$grade][$class] = array_splice($pools[$grade][$class], 0, $take);
        }

        return $reserve;
    }

    // ---------- Auswahl ----------

    /** @param array<int, bool> $excludedIds @return list<int> */
    private function popEligible(array &$bucket, array $excludedIds, int $count): array
    {
        $picked = [];
        foreach ($bucket as $index => $studentId) {
            if (count($picked) >= $count) {
                break;
            }
            if (isset($excludedIds[$studentId])) {
                continue;
            }
            $picked[] = $studentId;
            unset($bucket[$index]);
        }
        if ($picked !== []) {
            $bucket = array_values($bucket);
        }

        return $picked;
    }

    /**
     * Ohne Klassen-Präferenz: erst aus der Prioritäts-Reserve (falls für diese
     * Stufe+Block konfiguriert), dann Round-Robin über alle Klassen der Stufe.
     *
     * @param array<int, array<string, list<int>>> $pools
     * @param array<int, array<string, list<int>>> $reserve
     * @param array<int, int> $rrPointer
     * @param array<int, bool> $excludedIds
     * @return list<int>
     */
    private function pickWithPriority(array &$pools, array &$reserve, array &$rrPointer, int $grade, int $need, ?string $priorityClass, array $excludedIds): array
    {
        $picked = [];

        if ($priorityClass !== null && isset($reserve[$grade][$priorityClass])) {
            $picked = array_merge($picked, $this->popEligible($reserve[$grade][$priorityClass], $excludedIds, $need));
        }
        if (count($picked) < $need && $priorityClass !== null && isset($pools[$grade][$priorityClass])) {
            $picked = array_merge($picked, $this->popEligible($pools[$grade][$priorityClass], $excludedIds, $need - count($picked)));
        }
        if (count($picked) < $need) {
            $picked = array_merge($picked, $this->pickRoundRobin($pools, $rrPointer, $grade, $need - count($picked), $excludedIds));
        }

        return $picked;
    }

    /**
     * Gleichmäßige Verteilung über alle Klassen der Stufe, fortlaufender Zeiger
     * je Stufe (bleibt über mehrere Stände desselben Blocks erhalten).
     *
     * @param array<int, array<string, list<int>>> $pools
     * @param array<int, int> $rrPointer
     * @param array<int, bool> $excludedIds
     * @return list<int>
     */
    private function pickRoundRobin(array &$pools, array &$rrPointer, int $grade, int $need, array $excludedIds): array
    {
        $classes = array_keys($pools[$grade] ?? []);
        if ($classes === []) {
            return [];
        }

        $picked = [];
        $guard = 0;
        $maxGuard = $need * count($classes) * 3 + 20;
        while (count($picked) < $need && $guard < $maxGuard) {
            $className = $classes[$rrPointer[$grade] % count($classes)];
            $rrPointer[$grade]++;
            $guard++;
            $bucket = &$pools[$grade][$className];
            $found = $this->popEligible($bucket, $excludedIds, 1);
            if ($found !== []) {
                $picked[] = $found[0];
            }
            unset($bucket);

            if ($guard % count($classes) === 0 && $this->poolTotal($pools[$grade] ?? [], $excludedIds) === 0) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Mit Klassen-Präferenz: Bedarf wird nach Gewicht auf die konfigurierten
     * Klassen aufgeteilt (Largest-Remainder-Methode), Rest per Round-Robin aus
     * genau diesen Klassen — nicht aus der ganzen Stufe.
     *
     * @param array<int, array<string, list<int>>> $pools
     * @param array<string, int> $weights class => weight
     * @param array<int, bool> $excludedIds
     * @return list<int>
     */
    private function pickWeighted(array &$pools, int $grade, int $need, array $weights, array $excludedIds): array
    {
        // Nur Klassen berücksichtigen, die tatsächlich zu dieser Stufe gehören (haben
        // einen Pool-Eintrag) — sonst würde Quote an eine fremde Stufe "verschwendet".
        $weights = array_filter(
            $weights,
            static fn (int $w, string $class): bool => $w > 0 && isset($pools[$grade][$class]),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($weights === []) {
            return [];
        }
        $totalWeight = array_sum($weights);

        $quotas = [];
        $assigned = 0;
        $remainders = [];
        foreach ($weights as $class => $weight) {
            $raw = ($weight / $totalWeight) * $need;
            $base = (int) floor($raw);
            $quotas[$class] = $base;
            $assigned += $base;
            $remainders[$class] = $raw - $base;
        }
        arsort($remainders);
        $open = $need - $assigned;
        foreach (array_keys($remainders) as $class) {
            if ($open <= 0) {
                break;
            }
            $quotas[$class]++;
            $open--;
        }

        $picked = [];
        foreach ($quotas as $class => $quota) {
            if ($quota <= 0 || !isset($pools[$grade][$class])) {
                continue;
            }
            $picked = array_merge($picked, $this->popEligible($pools[$grade][$class], $excludedIds, $quota));
        }

        // Reichen die konfigurierten Klassen nicht aus (Pool erschöpft), gewichtet nachziehen.
        $guard = 0;
        while (count($picked) < $need && $guard < 500) {
            $candidates = array_keys(array_filter($weights, fn ($w, $class) => ($pools[$grade][$class] ?? []) !== [], ARRAY_FILTER_USE_BOTH));
            if ($candidates === []) {
                break;
            }
            $class = $this->weightedRandomClass($candidates, $weights);
            $found = $this->popEligible($pools[$grade][$class], $excludedIds, 1);
            if ($found !== []) {
                $picked[] = $found[0];
            }
            $guard++;
        }

        return $picked;
    }

    /** @param list<string> $classes @param array<string, int> $weights */
    private function weightedRandomClass(array $classes, array $weights): string
    {
        $total = 0;
        foreach ($classes as $class) {
            $total += $weights[$class];
        }
        $random = random_int(1, max(1, $total));
        foreach ($classes as $class) {
            $random -= $weights[$class];
            if ($random <= 0) {
                return $class;
            }
        }

        return $classes[array_key_last($classes)];
    }

    /** @param array<string, list<int>> $byClass @param array<int, bool> $excludedIds */
    private function poolTotal(array $byClass, array $excludedIds): int
    {
        $total = 0;
        foreach ($byClass as $ids) {
            foreach ($ids as $id) {
                if (!isset($excludedIds[$id])) {
                    $total++;
                }
            }
        }

        return $total;
    }
}
