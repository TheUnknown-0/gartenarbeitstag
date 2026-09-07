<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Anwesenheit: Abhak-Liste je Stand und Zeitblock.
 *
 * Wird von der Verwaltung (/admin/anwesenheit) und von Standleitungen
 * (/meine-staende/{id}) gleichermaßen genutzt — die Berechtigungsprüfung
 * liegt beim Aufrufer.
 */
final class AttendanceService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Feste Einschreibungen eines Stands in einem Block inkl. Anwesenheitsstatus.
     *
     * @return list<array<string, mixed>> enrollment_id, user_id, username, firstname, lastname, class, grade,
     *                                    present (int|null), note, marked_at, marked_by_name
     */
    public function roster(int $stationId, int $timeBlockId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id AS enrollment_id, e.user_id, u.username, u.firstname, u.lastname, u.class, u.grade,
                    a.present, a.note, a.marked_at,
                    TRIM(CONCAT(COALESCE(m.firstname, ''), ' ', COALESCE(m.lastname, ''))) AS marked_by_name
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             LEFT JOIN attendance a ON a.enrollment_id = e.id
             LEFT JOIN users m ON m.id = a.marked_by
             WHERE e.station_id = ? AND e.time_block_id = ? AND e.status = 'assigned'
             ORDER BY u.class, u.lastname, u.firstname, u.id",
            [$stationId, $timeBlockId],
        );
    }

    /**
     * Feste Einschreibungen einer Klasse in einem Block (über alle Stände), inkl. Stand und Anwesenheit.
     *
     * @return list<array<string, mixed>> wie roster() plus station_id, station, location
     */
    public function rosterByClass(int $dayId, int $timeBlockId, string $class): array
    {
        return $this->db->fetchAll(
            "SELECT e.id AS enrollment_id, e.user_id, u.username, u.firstname, u.lastname, u.class, u.grade,
                    s.id AS station_id, s.name AS station, s.location,
                    a.present, a.note, a.marked_at,
                    TRIM(CONCAT(COALESCE(m.firstname, ''), ' ', COALESCE(m.lastname, ''))) AS marked_by_name
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN stations s ON s.id = e.station_id
             LEFT JOIN attendance a ON a.enrollment_id = e.id
             LEFT JOIN users m ON m.id = a.marked_by
             WHERE e.garden_day_id = ? AND e.time_block_id = ? AND u.class = ? AND e.status = 'assigned'
             ORDER BY u.lastname, u.firstname, u.id",
            [$dayId, $timeBlockId, $class],
        );
    }

    /**
     * Setzt (oder aktualisiert) den Anwesenheitsstatus einer festen Einschreibung.
     *
     * @return array{enrollment_id: int, present: bool, note: string, marked_at: string}
     */
    public function mark(int $enrollmentId, bool $present, ?string $note, int $markedBy): array
    {
        $enrollment = $this->db->fetchOne(
            "SELECT id, station_id, time_block_id FROM enrollments WHERE id = ? AND status = 'assigned'",
            [$enrollmentId],
        );
        if ($enrollment === null) {
            throw new RuntimeException('Einschreibung nicht gefunden oder nicht fest.');
        }

        $note = $note === null ? null : trim(mb_substr($note, 0, 255));

        $this->db->run(
            'INSERT INTO attendance (enrollment_id, present, note, marked_by, marked_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE present = VALUES(present), note = COALESCE(VALUES(note), note), marked_by = VALUES(marked_by), marked_at = NOW()',
            [$enrollmentId, $present ? 1 : 0, $note, $markedBy],
        );

        $row = $this->db->fetchOne('SELECT present, note, marked_at FROM attendance WHERE enrollment_id = ?', [$enrollmentId]);

        return [
            'enrollment_id' => $enrollmentId,
            'present' => (int) ($row['present'] ?? 0) === 1,
            'note' => (string) ($row['note'] ?? ''),
            'marked_at' => (string) ($row['marked_at'] ?? ''),
        ];
    }

    /**
     * Setzt für einen Stand+Block alle festen Einschreibungen auf einmal
     * (Formular ohne JavaScript): Alle IDs in $presentIds werden anwesend,
     * alle anderen abwesend.
     *
     * @param list<int> $presentIds
     */
    public function markAll(int $stationId, int $timeBlockId, array $presentIds, int $markedBy): int
    {
        $presentIds = array_map('intval', $presentIds);
        $count = 0;
        foreach ($this->roster($stationId, $timeBlockId) as $row) {
            $id = (int) $row['enrollment_id'];
            $this->mark($id, in_array($id, $presentIds, true), null, $markedBy);
            $count++;
        }

        return $count;
    }

    /**
     * Wie markAll(), aber für eine beliebige Liste fester Einschreibungen
     * (z. B. Klassenansicht): $allIds werden gesetzt, $presentIds davon anwesend.
     *
     * @param list<int> $allIds
     * @param list<int> $presentIds
     */
    public function markIds(array $allIds, array $presentIds, int $markedBy): int
    {
        $presentIds = array_map('intval', $presentIds);
        $count = 0;
        foreach (array_unique(array_map('intval', $allIds)) as $id) {
            try {
                $this->mark($id, in_array($id, $presentIds, true), null, $markedBy);
                $count++;
            } catch (RuntimeException) {
                // nicht (mehr) fest eingeschrieben → überspringen
            }
        }

        return $count;
    }

    /**
     * Übersicht je Stand × Block: gesamt / anwesend / abwesend / offen.
     *
     * @return list<array<string, mixed>> station_id, station, location, block_id, block, capacity, total, present, absent
     */
    public function summary(int $dayId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id AS station_id, s.name AS station, s.location, s.min_students,
                    tb.id AS block_id, tb.name AS block, tb.start_time, tb.end_time, sb.capacity,
                    COUNT(e.id) AS total,
                    COALESCE(SUM(a.present = 1), 0) AS present,
                    COALESCE(SUM(a.present = 0), 0) AS absent
             FROM stations s
             JOIN station_blocks sb ON sb.station_id = s.id
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             LEFT JOIN enrollments e ON e.station_id = s.id AND e.time_block_id = tb.id AND e.status = 'assigned'
             LEFT JOIN attendance a ON a.enrollment_id = e.id
             WHERE s.garden_day_id = ? AND s.is_active = 1
             GROUP BY s.id, s.name, s.location, s.min_students, tb.id, tb.name, tb.start_time, tb.end_time, sb.capacity
             ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name",
            [$dayId],
        );
    }
}
