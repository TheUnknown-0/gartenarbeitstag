<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Alle schreibenden Operationen auf `enrollments` laufen hier durch:
 * Anlegen (fest / Warteliste / Wunsch), Löschen, Umbuchen und das Nachrücken
 * von der Warteliste. Jede Änderung wird geprüft (LimitCheck) und protokolliert.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly Database $db,
        private readonly LimitCheck $limits,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Legt eine Einschreibung an.
     *
     * @param array{
     *   status?: string,            assigned | waitlist | wish (Standard: assigned)
     *   priority?: int|null,        nur für Wünsche (1..n)
     *   source?: string,            self | auto | orga
     *   self_service?: bool,        Schüler:in bucht selbst (Anmeldefenster prüfen)
     *   override?: bool,            weiche Verstöße bewusst übersteuern (Orga)
     *   override_note?: string|null,
     *   created_by?: int|null,
     *   auto_waitlist?: bool        bei voller Kapazität automatisch auf die Warteliste
     * } $options
     * @return array{id: int, status: string, violations: list<array{code: string, message: string, hard: bool}>}
     * @throws LimitViolation wenn Verstöße vorliegen, die nicht übersteuert wurden
     */
    public function create(array $day, int $userId, int $stationId, int $timeBlockId, array $options = []): array
    {
        $status = $options['status'] ?? 'assigned';
        if (!in_array($status, ['assigned', 'waitlist', 'wish'], true)) {
            throw new InvalidArgumentException('Ungültiger Status: ' . $status);
        }

        $student = $this->student($userId);
        $station = $this->station($stationId);
        $override = (bool) ($options['override'] ?? false);
        $selfService = (bool) ($options['self_service'] ?? false);

        return $this->db->transaction(function () use ($day, $student, $station, $timeBlockId, $status, $options, $override, $selfService): array {
            $violations = $this->limits->check($student, $station, $timeBlockId, $day, $status, [
                'self_service' => $selfService,
            ]);

            if (LimitCheck::hasHard($violations)) {
                throw new LimitViolation($violations);
            }

            // Volle Kapazität → automatisch Warteliste (nur wenn erlaubt und nichts anderes im Weg)
            $soft = array_column($violations, 'code');
            if ($status === 'assigned' && $soft !== [] && !$override) {
                $onlyCapacity = array_diff($soft, [LimitCheck::CAPACITY, LimitCheck::CLASS_LIMIT, LimitCheck::GRADE_LIMIT]) === [];
                if ($onlyCapacity && ($options['auto_waitlist'] ?? false) && (int) ($day['waitlist_enabled'] ?? 0) === 1) {
                    $status = 'waitlist';
                    $violations = $this->limits->check($student, $station, $timeBlockId, $day, 'waitlist', ['self_service' => $selfService]);
                    if ($violations !== []) {
                        throw new LimitViolation($violations);
                    }
                } else {
                    throw new LimitViolation($violations);
                }
            } elseif ($soft !== [] && !$override) {
                throw new LimitViolation($violations);
            }

            $this->db->run(
                'INSERT INTO enrollments (garden_day_id, user_id, station_id, time_block_id, status, priority, source, override_note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $day['id'],
                    (int) $student['id'],
                    (int) $station['id'],
                    $timeBlockId,
                    $status,
                    $status === 'wish' ? (int) ($options['priority'] ?? 1) : null,
                    $options['source'] ?? ($selfService ? 'self' : 'orga'),
                    $override && $violations !== [] ? (string) ($options['override_note'] ?? '') : null,
                    $options['created_by'] ?? null,
                ],
            );
            $id = $this->db->lastInsertId();

            $overridden = $override && $violations !== [];
            $this->audit->log(
                'enrollment.create',
                $overridden ? 'warning' : 'info',
                $this->describe($student, $station, $timeBlockId, $status)
                . ($overridden ? ' | ÜBERSTEUERT: ' . LimitCheck::messages($violations) . ' | Begründung: ' . (string) ($options['override_note'] ?? '') : ''),
            );

            return ['id' => $id, 'status' => $status, 'violations' => $overridden ? $violations : []];
        });
    }

    /**
     * Löscht eine Einschreibung. War sie fest, rückt ggf. die Warteliste nach.
     *
     * @return int|null ID der nachgerückten Einschreibung
     */
    public function delete(int $enrollmentId, array $day, string $reason = ''): ?int
    {
        return $this->db->transaction(function () use ($enrollmentId, $day, $reason): ?int {
            $enrollment = $this->find($enrollmentId);
            if ($enrollment === null) {
                throw new RuntimeException('Einschreibung nicht gefunden.');
            }

            $this->db->run('DELETE FROM enrollments WHERE id = ?', [$enrollmentId]);
            $this->audit->log(
                'enrollment.delete',
                'info',
                $this->describe($enrollment, $enrollment, (int) $enrollment['time_block_id'], $enrollment['status']) . ($reason !== '' ? ' | ' . $reason : ''),
            );

            if ($enrollment['status'] === 'assigned') {
                return $this->promoteWaitlist($day, (int) $enrollment['station_id'], (int) $enrollment['time_block_id']);
            }

            return null;
        });
    }

    /**
     * Bucht eine feste Einschreibung auf einen anderen Stand/Block um.
     * Läuft als Löschen + Anlegen in einer Transaktion; die alte Einschreibung
     * wird bei der Prüfung ignoriert, damit ein Wechsel innerhalb desselben
     * Blocks kein Zeitkonflikt ist.
     *
     * @param array{override?: bool, override_note?: string|null, created_by?: int|null, self_service?: bool} $options
     * @return array{id: int, status: string, violations: list<array{code: string, message: string, hard: bool}>}
     */
    public function rebook(int $enrollmentId, array $day, int $newStationId, int $newTimeBlockId, array $options = []): array
    {
        return $this->db->transaction(function () use ($enrollmentId, $day, $newStationId, $newTimeBlockId, $options): array {
            $enrollment = $this->find($enrollmentId);
            if ($enrollment === null) {
                throw new RuntimeException('Einschreibung nicht gefunden.');
            }

            $student = $this->student((int) $enrollment['user_id']);
            $station = $this->station($newStationId);
            $override = (bool) ($options['override'] ?? false);

            $violations = $this->limits->check($student, $station, $newTimeBlockId, $day, 'assigned', [
                'ignore_enrollment_id' => $enrollmentId,
                'self_service' => (bool) ($options['self_service'] ?? false),
            ]);
            if (LimitCheck::hasHard($violations) || ($violations !== [] && !$override)) {
                throw new LimitViolation($violations);
            }

            $oldStationId = (int) $enrollment['station_id'];
            $oldBlockId = (int) $enrollment['time_block_id'];
            $overridden = $override && $violations !== [];

            $this->db->run(
                "UPDATE enrollments SET station_id = ?, time_block_id = ?, status = 'assigned', priority = NULL, source = 'orga', override_note = ?, created_by = ? WHERE id = ?",
                [$newStationId, $newTimeBlockId, $overridden ? (string) ($options['override_note'] ?? '') : null, $options['created_by'] ?? null, $enrollmentId],
            );
            // Anwesenheit gehört zur alten Buchung
            $this->db->run('DELETE FROM attendance WHERE enrollment_id = ?', [$enrollmentId]);

            $this->audit->log(
                'enrollment.rebook',
                $overridden ? 'warning' : 'info',
                $this->describe($student, $enrollment, $oldBlockId, 'assigned') . ' → ' . $this->describe($student, $station, $newTimeBlockId, 'assigned')
                . ($overridden ? ' | ÜBERSTEUERT: ' . LimitCheck::messages($violations) . ' | Begründung: ' . (string) ($options['override_note'] ?? '') : ''),
            );

            if ($enrollment['status'] === 'assigned' && ($oldStationId !== $newStationId || $oldBlockId !== $newTimeBlockId)) {
                $this->promoteWaitlist($day, $oldStationId, $oldBlockId);
            }

            return ['id' => $enrollmentId, 'status' => 'assigned', 'violations' => $overridden ? $violations : []];
        });
    }

    /**
     * Rückt die nächste passende Person von der Warteliste nach, sofern in
     * diesem Stand-Block Platz ist. Reihenfolge: Eintragszeitpunkt.
     * Wartelisten-Einträge, die nicht mehr regelkonform sind, werden übersprungen.
     */
    public function promoteWaitlist(array $day, int $stationId, int $timeBlockId): ?int
    {
        if ((int) ($day['waitlist_enabled'] ?? 0) !== 1) {
            return null;
        }

        $station = $this->station($stationId);
        $candidates = $this->db->fetchAll(
            "SELECT e.id, e.user_id FROM enrollments e
             WHERE e.station_id = ? AND e.time_block_id = ? AND e.status = 'waitlist'
             ORDER BY e.created_at ASC, e.id ASC",
            [$stationId, $timeBlockId],
        );

        foreach ($candidates as $candidate) {
            $student = $this->student((int) $candidate['user_id']);
            $violations = $this->limits->check($student, $station, $timeBlockId, $day, 'assigned', [
                'ignore_enrollment_id' => (int) $candidate['id'],
            ]);
            if ($violations !== []) {
                continue;
            }

            // Quelle bleibt erhalten: 'auto' ist der automatischen Zuteilung vorbehalten,
            // sonst würde AutoAssign::reset() nachgerückte Plätze mit entfernen.
            $this->db->run("UPDATE enrollments SET status = 'assigned' WHERE id = ?", [(int) $candidate['id']]);
            $this->audit->log('enrollment.promote', 'info', $this->describe($student, $station, $timeBlockId, 'assigned') . ' (von Warteliste nachgerückt)');

            return (int) $candidate['id'];
        }

        return null;
    }

    /**
     * Entfernt alle Wünsche einer Person für einen Block und legt sie neu an
     * (Wunschmodus, Selbstbedienung).
     *
     * @param list<int> $stationIds Prio 1..n in dieser Reihenfolge
     * @return list<array{code: string, message: string, hard: bool}> Verstöße je Wunsch (leer = alles ok)
     */
    public function replaceWishes(array $day, int $userId, int $timeBlockId, array $stationIds, bool $selfService = true): array
    {
        return $this->db->transaction(function () use ($day, $userId, $timeBlockId, $stationIds, $selfService): array {
            $student = $this->student($userId);
            $stationIds = array_values(array_unique(array_map('intval', $stationIds)));

            // Erst prüfen, dann schreiben — ein fehlerhafter Wunsch bricht alles ab
            $errors = [];
            foreach ($stationIds as $stationId) {
                $station = $this->station($stationId);
                $violations = $this->limits->check($student, $station, $timeBlockId, $day, 'wish', ['self_service' => $selfService]);
                $violations = array_values(array_filter($violations, static fn (array $v): bool => $v['code'] !== LimitCheck::DUPLICATE));
                foreach ($violations as $violation) {
                    $violation['message'] = $station['name'] . ': ' . $violation['message'];
                    $errors[] = $violation;
                }
            }
            if ($errors !== []) {
                return $errors;
            }

            $this->db->run(
                "DELETE FROM enrollments WHERE user_id = ? AND garden_day_id = ? AND time_block_id = ? AND status = 'wish'",
                [$userId, (int) $day['id'], $timeBlockId],
            );
            foreach ($stationIds as $index => $stationId) {
                $this->db->run(
                    "INSERT INTO enrollments (garden_day_id, user_id, station_id, time_block_id, status, priority, source, created_by)
                     VALUES (?, ?, ?, ?, 'wish', ?, ?, ?)",
                    [(int) $day['id'], $userId, $stationId, $timeBlockId, $index + 1, $selfService ? 'self' : 'orga', $selfService ? null : $userId],
                );
            }
            $this->audit->log('enrollment.wishes', 'info', $student['username'] . ' Wünsche Block #' . $timeBlockId . ': ' . implode(', ', $stationIds));

            return [];
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $enrollmentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT e.*, u.username, u.firstname, u.lastname, u.class, u.grade, s.name, s.garden_day_id AS station_day_id, tb.name AS block_name
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN stations s ON s.id = e.station_id
             JOIN time_blocks tb ON tb.id = e.time_block_id
             WHERE e.id = ?',
            [$enrollmentId],
        );
    }

    /** @return array<string, mixed> */
    private function student(int $userId): array
    {
        $student = $this->db->fetchOne("SELECT * FROM users WHERE id = ? AND role = 'student'", [$userId]);
        if ($student === null) {
            throw new RuntimeException('Schüler:in nicht gefunden.');
        }

        return $student;
    }

    /** @return array<string, mixed> */
    private function station(int $stationId): array
    {
        $station = $this->db->fetchOne('SELECT * FROM stations WHERE id = ?', [$stationId]);
        if ($station === null) {
            throw new RuntimeException('Stand nicht gefunden.');
        }

        return $station;
    }

    /** Kurzbeschreibung fürs Audit-Log. */
    private function describe(array $student, array $station, int $timeBlockId, string $status): string
    {
        $blockName = $this->db->fetchValue('SELECT name FROM time_blocks WHERE id = ?', [$timeBlockId]) ?? ('#' . $timeBlockId);
        $labels = ['assigned' => 'fest', 'waitlist' => 'Warteliste', 'wish' => 'Wunsch'];

        return sprintf(
            '%s (%s) → %s [%s] %s',
            trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? '')) ?: ($student['username'] ?? '?'),
            $student['class'] ?? '–',
            $station['name'] ?? '?',
            $blockName,
            $labels[$status] ?? $status,
        );
    }
}
