<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Datengrundlage für „Eigene Liste" (freier Report-Baukasten in der Druckzentrale):
 * pro Datenquelle die wählbaren Spalten, Gruppierungsfelder und die Zeilen —
 * bereits auf die Spaltenschlüssel formatiert, damit Controller/PDF generisch
 * bleiben (kein Sonderfall je Feld nötig).
 */
final class ReportDefinitions
{
    /** @var array<string, string> Quellenschlüssel => Anzeigename */
    public const SOURCES = [
        'enrollments' => 'Einschreibungen',
        'students' => 'Schüler:innen',
        'stations' => 'Stände',
        'attendance' => 'Anwesenheit',
    ];

    private const STATUS_LABELS = ['assigned' => 'fest', 'waitlist' => 'Warteliste', 'wish' => 'Wunsch'];

    private const SOURCE_LABELS = ['self' => 'Selbst', 'auto' => 'Automatisch', 'orga' => 'Orga'];

    /**
     * Spalten-Metadaten je Quelle, in Standardreihenfolge.
     *
     * @return array<string, array{label: string, weight: float, align: string}>
     */
    public static function fields(string $source): array
    {
        return match ($source) {
            'enrollments' => [
                'name' => ['label' => 'Name', 'weight' => 2.0, 'align' => 'L'],
                'username' => ['label' => 'Benutzername', 'weight' => 1.2, 'align' => 'L'],
                'class' => ['label' => 'Klasse', 'weight' => 0.8, 'align' => 'C'],
                'grade' => ['label' => 'Stufe', 'weight' => 0.6, 'align' => 'C'],
                'station' => ['label' => 'Stand', 'weight' => 1.8, 'align' => 'L'],
                'location' => ['label' => 'Ort', 'weight' => 1.0, 'align' => 'L'],
                'block' => ['label' => 'Zeitblock', 'weight' => 1.6, 'align' => 'L'],
                'status' => ['label' => 'Status', 'weight' => 0.9, 'align' => 'C'],
                'priority' => ['label' => 'Priorität', 'weight' => 0.7, 'align' => 'C'],
                'source' => ['label' => 'Quelle', 'weight' => 0.9, 'align' => 'C'],
                'created_at' => ['label' => 'Angelegt am', 'weight' => 1.2, 'align' => 'C'],
                'created_by_name' => ['label' => 'Angelegt von', 'weight' => 1.2, 'align' => 'L'],
            ],
            'students' => [
                'name' => ['label' => 'Name', 'weight' => 2.0, 'align' => 'L'],
                'username' => ['label' => 'Benutzername', 'weight' => 1.2, 'align' => 'L'],
                'class' => ['label' => 'Klasse', 'weight' => 0.8, 'align' => 'C'],
                'grade' => ['label' => 'Stufe', 'weight' => 0.6, 'align' => 'C'],
                'active' => ['label' => 'Aktiv', 'weight' => 0.7, 'align' => 'C'],
            ],
            'stations' => [
                'name' => ['label' => 'Stand', 'weight' => 2.0, 'align' => 'L'],
                'location' => ['label' => 'Ort', 'weight' => 1.2, 'align' => 'L'],
                'leaders' => ['label' => 'Standleitung', 'weight' => 1.8, 'align' => 'L'],
                'capacity' => ['label' => 'Kapazität gesamt', 'weight' => 0.9, 'align' => 'C'],
                'criteria' => ['label' => 'Ausschlusskriterien', 'weight' => 2.0, 'align' => 'L'],
                'active' => ['label' => 'Aktiv', 'weight' => 0.7, 'align' => 'C'],
                'manual_only' => ['label' => 'Nur manuell', 'weight' => 0.8, 'align' => 'C'],
            ],
            'attendance' => [
                'name' => ['label' => 'Name', 'weight' => 2.0, 'align' => 'L'],
                'class' => ['label' => 'Klasse', 'weight' => 0.8, 'align' => 'C'],
                'grade' => ['label' => 'Stufe', 'weight' => 0.6, 'align' => 'C'],
                'station' => ['label' => 'Stand', 'weight' => 1.8, 'align' => 'L'],
                'block' => ['label' => 'Zeitblock', 'weight' => 1.6, 'align' => 'L'],
                'present' => ['label' => 'Anwesend', 'weight' => 0.9, 'align' => 'C'],
                'note' => ['label' => 'Notiz', 'weight' => 1.8, 'align' => 'L'],
            ],
            default => [],
        };
    }

    /** @return array<string, string> Gruppierungsschlüssel ('' = keine) => Anzeigename */
    public static function groupFields(string $source): array
    {
        return match ($source) {
            'enrollments' => ['' => 'Keine', 'class' => 'Klasse', 'station' => 'Stand', 'block' => 'Zeitblock', 'status' => 'Status'],
            'students' => ['' => 'Keine', 'class' => 'Klasse'],
            'stations' => ['' => 'Keine'],
            'attendance' => ['' => 'Keine', 'class' => 'Klasse', 'station' => 'Stand', 'block' => 'Zeitblock'],
            default => ['' => 'Keine'],
        };
    }

    /**
     * Zeilen einer Quelle, bereits auf die Spaltenschlüssel aus fields() formatiert.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, string>>
     */
    public static function rows(Database $db, int $dayId, string $source, array $filters): array
    {
        return match ($source) {
            'enrollments' => self::enrollmentRows($db, $dayId, $filters),
            'students' => self::studentRows($db, $filters),
            'stations' => self::stationRows($db, $dayId, $filters),
            'attendance' => self::attendanceRows($db, $dayId, $filters),
            default => [],
        };
    }

    /** @param array{stand: int, block: int, klasse: string, stufe: int, status: string, q: string} $filters */
    private static function enrollmentRows(Database $db, int $dayId, array $filters): array
    {
        $rows = [];
        foreach (DayQueries::filteredEnrollments($db, $dayId, $filters, null) as $row) {
            $rows[] = [
                'name' => trim((string) $row['lastname'] . ', ' . (string) $row['firstname']),
                'username' => (string) $row['username'],
                'class' => (string) ($row['class'] ?? ''),
                'grade' => $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                'station' => (string) $row['station_name'],
                'location' => (string) ($row['location'] ?? ''),
                'block' => DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']]),
                'status' => self::STATUS_LABELS[(string) $row['status']] ?? (string) $row['status'],
                'priority' => $row['priority'] !== null ? (string) (int) $row['priority'] : '',
                'source' => self::SOURCE_LABELS[(string) $row['source']] ?? (string) $row['source'],
                'created_at' => format_datetime($row['created_at']),
                'created_by_name' => (string) ($row['created_by_name'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @param array{klasse: string, stufe: int, aktiv: string} $filters */
    private static function studentRows(Database $db, array $filters): array
    {
        $sql = "SELECT username, firstname, lastname, class, grade, is_active FROM users WHERE role = 'student'";
        $args = [];
        if ($filters['klasse'] !== '') {
            $sql .= ' AND class = ?';
            $args[] = $filters['klasse'];
        }
        if ($filters['stufe'] > 0) {
            $sql .= ' AND grade = ?';
            $args[] = $filters['stufe'];
        }
        if ($filters['aktiv'] === '1') {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY class, lastname, firstname';

        $rows = [];
        foreach ($db->fetchAll($sql, $args) as $row) {
            $rows[] = [
                'name' => trim((string) $row['lastname'] . ', ' . (string) $row['firstname']),
                'username' => (string) $row['username'],
                'class' => (string) ($row['class'] ?? ''),
                'grade' => $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                'active' => (int) $row['is_active'] === 1 ? 'ja' : 'nein',
            ];
        }

        return $rows;
    }

    /** @param array{q: string, aktiv: string} $filters */
    private static function stationRows(Database $db, int $dayId, array $filters): array
    {
        $sql = 'SELECT id, name, location, is_active FROM stations WHERE garden_day_id = ?';
        $args = [$dayId];
        if ($filters['q'] !== '') {
            $sql .= ' AND (name LIKE ? OR location LIKE ? OR description LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($args, $like, $like, $like);
        }
        if ($filters['aktiv'] === '1') {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name';
        $stations = $db->fetchAll($sql, $args);

        $leaders = [];
        foreach ($db->fetchAll(
            'SELECT sl.station_id, u.firstname, u.lastname, u.username FROM station_leaders sl
             JOIN users u ON u.id = sl.user_id
             JOIN stations s ON s.id = sl.station_id
             WHERE s.garden_day_id = ? ORDER BY u.lastname, u.firstname',
            [$dayId],
        ) as $row) {
            $name = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
            $leaders[(int) $row['station_id']][] = $name !== '' ? $name : (string) $row['username'];
        }

        $criteria = [];
        foreach ($db->fetchAll(
            'SELECT se.station_id, ec.name FROM station_exclusions se
             JOIN exclusion_criteria ec ON ec.id = se.criterion_id
             JOIN stations s ON s.id = se.station_id
             WHERE s.garden_day_id = ? ORDER BY ec.sort_order, ec.name',
            [$dayId],
        ) as $row) {
            $criteria[(int) $row['station_id']][] = (string) $row['name'];
        }

        $capacity = [];
        foreach ($db->fetchAll(
            'SELECT sb.station_id, SUM(sb.capacity) AS total FROM station_blocks sb
             JOIN stations s ON s.id = sb.station_id
             WHERE s.garden_day_id = ? GROUP BY sb.station_id',
            [$dayId],
        ) as $row) {
            $capacity[(int) $row['station_id']] = (int) $row['total'];
        }

        $rows = [];
        foreach ($stations as $station) {
            $sid = (int) $station['id'];
            $rows[] = [
                'name' => (string) $station['name'],
                'location' => (string) ($station['location'] ?? ''),
                'leaders' => implode(', ', $leaders[$sid] ?? []),
                'capacity' => isset($capacity[$sid]) ? (string) $capacity[$sid] : '',
                'criteria' => implode(', ', $criteria[$sid] ?? []),
                'active' => (int) $station['is_active'] === 1 ? 'ja' : 'nein',
                'manual_only' => (int) $station['manual_only'] === 1 ? 'ja' : 'nein',
            ];
        }

        return $rows;
    }

    /** @param array{block: int, stand: int, klasse: string, stufe: int} $filters */
    private static function attendanceRows(Database $db, int $dayId, array $filters): array
    {
        $sql = "SELECT u.lastname, u.firstname, u.class, u.grade,
                       s.name AS station, tb.sort_order AS block_sort,
                       tb.name AS block_name, tb.start_time, tb.end_time,
                       a.present, a.note
                FROM enrollments e
                JOIN users u ON u.id = e.user_id
                JOIN stations s ON s.id = e.station_id
                JOIN time_blocks tb ON tb.id = e.time_block_id
                LEFT JOIN attendance a ON a.enrollment_id = e.id
                WHERE e.garden_day_id = ? AND e.status = 'assigned'";
        $args = [$dayId];
        if ($filters['block'] > 0) {
            $sql .= ' AND e.time_block_id = ?';
            $args[] = $filters['block'];
        }
        if ($filters['stand'] > 0) {
            $sql .= ' AND e.station_id = ?';
            $args[] = $filters['stand'];
        }
        if ($filters['klasse'] !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $filters['klasse'];
        }
        if ($filters['stufe'] > 0) {
            $sql .= ' AND u.grade = ?';
            $args[] = $filters['stufe'];
        }
        $sql .= ' ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name, u.class, u.lastname, u.firstname';

        $rows = [];
        foreach ($db->fetchAll($sql, $args) as $row) {
            $present = $row['present'] === null ? null : (int) $row['present'] === 1;
            $rows[] = [
                'name' => trim((string) $row['lastname'] . ', ' . (string) $row['firstname']),
                'class' => (string) ($row['class'] ?? ''),
                'grade' => $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                'station' => (string) $row['station'],
                'block' => DayQueries::blockLabel($row),
                'present' => $present === null ? 'offen' : ($present ? 'ja' : 'nein'),
                'note' => (string) ($row['note'] ?? ''),
            ];
        }

        return $rows;
    }
}
