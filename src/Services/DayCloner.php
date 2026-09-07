<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Dupliziert einen Aktionstag als Vorlage für den nächsten.
 *
 * Kopiert wird nur die Struktur: Zeitblöcke, Stände samt Block-Kapazitäten,
 * Limits, Ausschlusskriterien-Zuordnung und (optional) Standleitungen.
 * NIE kopiert werden Einschreibungen, Wünsche oder Anwesenheiten.
 *
 * Der neue Tag startet immer als Entwurf (`status = 'draft'`).
 */
final class DayCloner
{
    /** Kopierbare Bereiche (Key => Anzeigename). */
    public const PARTS = [
        'time_blocks' => 'Zeitblöcke',
        'stations' => 'Stände inkl. Kapazitäten, Limits & Ausschlusskriterien',
        'leaders' => 'Standleitungen',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Legt den neuen Aktionstag an und kopiert die gewählten Bereiche.
     *
     * @param  list<string> $parts Auswahl aus PARTS
     * @return array{id: int, stats: array<string, int>}
     */
    public function clone(int $sourceId, string $name, string $eventDate, array $parts): array
    {
        return $this->db->transaction(function () use ($sourceId, $name, $eventDate, $parts): array {
            $source = $this->db->fetchOne('SELECT * FROM garden_days WHERE id = ?', [$sourceId]);
            if ($source === null) {
                throw new \RuntimeException('Quell-Aktionstag nicht gefunden.');
            }

            $this->db->run(
                "INSERT INTO garden_days (name, event_date, status, mode, min_blocks_per_student, max_blocks_per_student, wishes_per_block, waitlist_enabled, notes)
                 VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?)",
                [
                    $name,
                    $eventDate,
                    $source['mode'],
                    (int) $source['min_blocks_per_student'],
                    $source['max_blocks_per_student'],
                    (int) $source['wishes_per_block'],
                    (int) $source['waitlist_enabled'],
                    $source['notes'],
                ],
            );
            $targetId = $this->db->lastInsertId();
            $stats = [];

            // Stände brauchen die neuen Block-IDs → Zeitblöcke immer zuerst
            $blockMap = [];
            if (in_array('time_blocks', $parts, true) || in_array('stations', $parts, true)) {
                $blockMap = $this->copyTimeBlocks($sourceId, $targetId);
                $stats['time_blocks'] = count($blockMap);
            }

            if (in_array('stations', $parts, true)) {
                $stationMap = $this->copyStations($sourceId, $targetId, $blockMap);
                $stats['stations'] = count($stationMap);
                if (in_array('leaders', $parts, true)) {
                    $stats['leaders'] = $this->copyLeaders($stationMap);
                }
            }

            return ['id' => $targetId, 'stats' => $stats];
        });
    }

    /** @return array<int, int> alte Block-ID => neue Block-ID */
    private function copyTimeBlocks(int $sourceId, int $targetId): array
    {
        $map = [];
        foreach ($this->db->fetchAll('SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order, id', [$sourceId]) as $block) {
            $this->db->run(
                'INSERT INTO time_blocks (garden_day_id, name, start_time, end_time, sort_order) VALUES (?, ?, ?, ?, ?)',
                [$targetId, $block['name'], $block['start_time'], $block['end_time'], (int) $block['sort_order']],
            );
            $map[(int) $block['id']] = $this->db->lastInsertId();
        }

        return $map;
    }

    /**
     * @param  array<int, int> $blockMap
     * @return array<int, int> alte Stand-ID => neue Stand-ID
     */
    private function copyStations(int $sourceId, int $targetId, array $blockMap): array
    {
        $map = [];
        foreach ($this->db->fetchAll('SELECT * FROM stations WHERE garden_day_id = ? ORDER BY sort_order, id', [$sourceId]) as $station) {
            $this->db->run(
                'INSERT INTO stations (garden_day_id, name, description, location, materials, max_per_class, max_per_grade, allowed_grades, allowed_classes, min_students, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $targetId,
                    $station['name'],
                    $station['description'],
                    $station['location'],
                    $station['materials'],
                    $station['max_per_class'],
                    $station['max_per_grade'],
                    $station['allowed_grades'],
                    $station['allowed_classes'],
                    (int) $station['min_students'],
                    (int) $station['is_active'],
                    (int) $station['sort_order'],
                ],
            );
            $newId = $this->db->lastInsertId();
            $map[(int) $station['id']] = $newId;

            foreach ($this->db->fetchAll('SELECT * FROM station_blocks WHERE station_id = ?', [(int) $station['id']]) as $sb) {
                $newBlockId = $blockMap[(int) $sb['time_block_id']] ?? null;
                if ($newBlockId === null) {
                    continue;
                }
                $this->db->run(
                    'INSERT INTO station_blocks (station_id, time_block_id, capacity) VALUES (?, ?, ?)',
                    [$newId, $newBlockId, (int) $sb['capacity']],
                );
            }

            $this->db->run(
                'INSERT INTO station_exclusions (station_id, criterion_id) SELECT ?, criterion_id FROM station_exclusions WHERE station_id = ?',
                [$newId, (int) $station['id']],
            );
        }

        return $map;
    }

    /** @param array<int, int> $stationMap */
    private function copyLeaders(array $stationMap): int
    {
        $count = 0;
        foreach ($stationMap as $oldId => $newId) {
            $count += $this->db->run(
                'INSERT INTO station_leaders (station_id, user_id)
                 SELECT ?, sl.user_id FROM station_leaders sl JOIN users u ON u.id = sl.user_id AND u.is_active = 1
                 WHERE sl.station_id = ?',
                [$newId, $oldId],
            )->rowCount();
        }

        return $count;
    }
}
