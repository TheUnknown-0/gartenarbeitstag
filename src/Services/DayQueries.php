<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Kleine, rein lesende Hilfsabfragen rund um einen Aktionstag — für
 * Select-Listen, Filter und Kennzahlen in mehreren Controllern.
 */
final class DayQueries
{
    /** @return list<array<string, mixed>> Stände des Tages (sort_order, name) */
    public static function stationsOf(Database $db, int $dayId, bool $onlyActive = false): array
    {
        return $db->fetchAll(
            'SELECT * FROM stations WHERE garden_day_id = ?' . ($onlyActive ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, name, id',
            [$dayId],
        );
    }

    /** @return list<array<string, mixed>> Zeitblöcke des Tages */
    public static function blocksOf(Database $db, int $dayId): array
    {
        return $db->fetchAll(
            'SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order, start_time, id',
            [$dayId],
        );
    }

    /** @return list<string> Alle Klassen aktiver Schüler:innen */
    public static function classesOf(Database $db): array
    {
        return array_map('strval', array_column($db->fetchAll(
            "SELECT DISTINCT class FROM users WHERE role = 'student' AND is_active = 1 AND class IS NOT NULL AND class <> '' ORDER BY class",
        ), 'class'));
    }

    /** @return list<int> Alle Klassenstufen aktiver Schüler:innen */
    public static function gradesOf(Database $db): array
    {
        return array_map('intval', array_column($db->fetchAll(
            "SELECT DISTINCT grade FROM users WHERE role = 'student' AND is_active = 1 AND grade IS NOT NULL ORDER BY grade",
        ), 'grade'));
    }

    /** @return list<array<string, mixed>> Aktive Schüler:innen (Klasse, Name) */
    public static function studentsOf(Database $db, ?string $class = null): array
    {
        $sql = "SELECT id, username, firstname, lastname, class, grade FROM users WHERE role = 'student' AND is_active = 1";
        $params = [];
        if ($class !== null && $class !== '') {
            $sql .= ' AND class = ?';
            $params[] = $class;
        }

        return $db->fetchAll($sql . ' ORDER BY class, lastname, firstname, id', $params);
    }

    /**
     * Angebotene Blöcke je Stand: station_id => list<time_block_id>.
     *
     * @return array<int, list<int>>
     */
    public static function stationBlockMap(Database $db, int $dayId): array
    {
        $map = [];
        foreach ($db->fetchAll(
            'SELECT sb.station_id, sb.time_block_id FROM station_blocks sb JOIN stations s ON s.id = sb.station_id WHERE s.garden_day_id = ?',
            [$dayId],
        ) as $row) {
            $map[(int) $row['station_id']][] = (int) $row['time_block_id'];
        }

        return $map;
    }

    /**
     * Schüler:innen mit weniger festen Blöcken als die Mindestzahl des Tages.
     *
     * @return list<array<string, mixed>> mit Feldern id, username, firstname, lastname, class, grade, assigned_blocks
     */
    public static function underEnrolled(Database $db, array $day, ?string $class = null, ?int $grade = null): array
    {
        $min = (int) ($day['min_blocks_per_student'] ?? 1);
        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.class, u.grade,
                       (SELECT COUNT(DISTINCT e.time_block_id) FROM enrollments e
                         WHERE e.user_id = u.id AND e.garden_day_id = ? AND e.status = 'assigned') AS assigned_blocks
                FROM users u
                WHERE u.role = 'student' AND u.is_active = 1";
        $params = [(int) $day['id']];
        if ($class !== null && $class !== '') {
            $sql .= ' AND u.class = ?';
            $params[] = $class;
        }
        if ($grade !== null) {
            $sql .= ' AND u.grade = ?';
            $params[] = $grade;
        }
        $sql .= ' HAVING assigned_blocks < ? ORDER BY u.class, u.lastname, u.firstname';
        $params[] = $min;

        return $db->fetchAll($sql, $params);
    }

    /**
     * Einschreibungen eines Tages, gefiltert wie auf der Einschreibungen-Übersicht
     * (Stand, Zeitblock, Klasse, Klassenstufe, Status, Suche) — von der HTML-Liste
     * und dem gleichnamigen PDF-Bericht gemeinsam genutzt, damit beide nie auseinanderlaufen.
     *
     * @param array{stand: int, block: int, klasse: string, stufe: int, status: string, q: string} $filter
     * @return list<array<string, mixed>>
     */
    public static function filteredEnrollments(Database $db, int $dayId, array $filter, ?int $limit = 1000): array
    {
        $where = ['e.garden_day_id = ?'];
        $args = [$dayId];
        if ($filter['stand'] > 0) {
            $where[] = 'e.station_id = ?';
            $args[] = $filter['stand'];
        }
        if ($filter['block'] > 0) {
            $where[] = 'e.time_block_id = ?';
            $args[] = $filter['block'];
        }
        if ($filter['klasse'] !== '') {
            $where[] = 'u.class = ?';
            $args[] = $filter['klasse'];
        }
        if ($filter['stufe'] > 0) {
            $where[] = 'u.grade = ?';
            $args[] = $filter['stufe'];
        }
        if ($filter['status'] !== 'alle') {
            $where[] = 'e.status = ?';
            $args[] = $filter['status'];
        }
        if ($filter['q'] !== '') {
            $where[] = "(u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR CONCAT(u.firstname, ' ', u.lastname) LIKE ?)";
            $like = '%' . $filter['q'] . '%';
            array_push($args, $like, $like, $like, $like);
        }

        $sql = 'SELECT e.*, u.username, u.firstname, u.lastname, u.class, u.grade,
                       s.name AS station_name, s.location, tb.name AS block_name, tb.start_time, tb.end_time, tb.sort_order AS block_sort,
                       c.username AS created_by_name
                FROM enrollments e
                JOIN users u ON u.id = e.user_id
                JOIN stations s ON s.id = e.station_id
                JOIN time_blocks tb ON tb.id = e.time_block_id
                LEFT JOIN users c ON c.id = e.created_by
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name, e.status, e.priority, u.class, u.lastname, u.firstname';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $db->fetchAll($sql, $args);
    }

    /** Anzeigename eines Zeitblocks inkl. Uhrzeit. */
    public static function blockLabel(array $block): string
    {
        $from = substr((string) ($block['start_time'] ?? ''), 0, 5);
        $to = substr((string) ($block['end_time'] ?? ''), 0, 5);

        return $from !== '' ? $block['name'] . ' (' . $from . '–' . $to . ')' : (string) $block['name'];
    }

    /** Anzeigename einer Person. */
    public static function personName(array $row): string
    {
        $name = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));

        return $name !== '' ? $name : (string) ($row['username'] ?? '?');
    }
}
