<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\DayCloner;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/** Aktionstag als Vorlage duplizieren: Struktur ja, Einschreibungen nie. */
final class DayClonerTest extends DatabaseTestCase
{
    use Fixtures;

    /** @var array<string, mixed> */
    private array $source;
    private int $block1;
    private int $block2;
    private int $stationA;
    private int $stationB;
    private int $teacher;
    private int $inactiveTeacher;
    private int $criterion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->createDay([
            'name' => 'Frühjahr 2026',
            'mode' => 'wishlist',
            'min_blocks_per_student' => 2,
            'max_blocks_per_student' => 3,
            'wishes_per_block' => 4,
            'waitlist_enabled' => 0,
        ]);
        $this->db->run("UPDATE garden_days SET notes = 'Bitte Handschuhe mitbringen', assignment_done_at = NOW() WHERE id = ?", [(int) $this->source['id']]);
        $this->source = $this->reloadDay((int) $this->source['id']);
        $sourceId = (int) $this->source['id'];

        $this->block1 = $this->createBlock($sourceId, 'Vormittag', '08:00:00', '10:30:00', 1);
        $this->block2 = $this->createBlock($sourceId, 'Nachmittag', '13:00:00', '15:00:00', 2);

        $a = $this->createStation($sourceId, 'Hochbeet', [$this->block1 => 4, $this->block2 => 6], [
            'max_per_class' => 2,
            'max_per_grade' => 5,
            'allowed_grades' => '5,6',
            'allowed_classes' => '5a, 6b',
            'min_students' => 3,
            'is_active' => 0,
            'sort_order' => 7,
        ]);
        $this->stationA = (int) $a['id'];
        $this->db->run("UPDATE stations SET description = 'Beete bauen', location = 'Schulhof Nord', materials = 'Spaten, Erde' WHERE id = ?", [$this->stationA]);
        $b = $this->createStation($sourceId, 'Kompost', [$this->block1 => 5]);
        $this->stationB = (int) $b['id'];

        $this->criterion = $this->createCriterion('Allergie');
        $this->excludeStation($this->stationA, $this->criterion);

        $this->teacher = $this->createTeacher('lehrkraft');
        $this->inactiveTeacher = $this->createTeacher('ehemalig');
        $this->db->run('UPDATE users SET is_active = 0 WHERE id = ?', [$this->inactiveTeacher]);
        $this->db->run('INSERT INTO station_leaders (station_id, user_id) VALUES (?, ?), (?, ?), (?, ?)', [
            $this->stationA, $this->teacher,
            $this->stationA, $this->inactiveTeacher,
            $this->stationB, $this->teacher,
        ]);

        // Einschreibungen, Wünsche und Anwesenheit — dürfen nie mitkopiert werden
        $student = $this->createStudent('anna');
        $assigned = $this->insertEnrollment($sourceId, (int) $student['id'], $this->stationB, $this->block1);
        $this->insertEnrollment($sourceId, (int) $student['id'], $this->stationA, $this->block2, 'wish', 1, 'self');
        $this->db->run('INSERT INTO attendance (enrollment_id, present) VALUES (?, 1)', [$assigned]);
    }

    private function cloner(): DayCloner
    {
        return new DayCloner($this->db);
    }

    public function testKopiertVollstaendigeStruktur(): void
    {
        $result = $this->cloner()->clone((int) $this->source['id'], 'Herbst 2026', '2026-10-14', ['time_blocks', 'stations', 'leaders']);

        $newId = $result['id'];
        self::assertNotSame((int) $this->source['id'], $newId);
        self::assertSame(['time_blocks' => 2, 'stations' => 2, 'leaders' => 2], $result['stats']);

        $day = $this->reloadDay($newId);
        self::assertSame('Herbst 2026', $day['name']);
        self::assertSame('2026-10-14', $day['event_date']);
        self::assertSame('draft', $day['status']);
        self::assertSame('wishlist', $day['mode']);
        self::assertSame(2, (int) $day['min_blocks_per_student']);
        self::assertSame(3, (int) $day['max_blocks_per_student']);
        self::assertSame(4, (int) $day['wishes_per_block']);
        self::assertSame(0, (int) $day['waitlist_enabled']);
        self::assertSame('Bitte Handschuhe mitbringen', $day['notes']);
        self::assertNull($day['assignment_done_at']);
        self::assertNull($day['registration_start']);
        self::assertNull($day['registration_end']);

        // Zeitblöcke
        $blocks = $this->db->fetchAll('SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order', [$newId]);
        self::assertCount(2, $blocks);
        self::assertSame(['Vormittag', 'Nachmittag'], array_column($blocks, 'name'));
        self::assertSame('08:00:00', $blocks[0]['start_time']);
        self::assertSame('10:30:00', $blocks[0]['end_time']);
        self::assertSame(2, (int) $blocks[1]['sort_order']);
        self::assertNotContains((string) $this->block1, array_column($blocks, 'id'));

        // Stände inkl. aller Limits
        $stations = $this->db->fetchAll('SELECT * FROM stations WHERE garden_day_id = ? ORDER BY name', [$newId]);
        self::assertCount(2, $stations);
        $hochbeet = $stations[0];
        self::assertSame('Hochbeet', $hochbeet['name']);
        self::assertSame('Beete bauen', $hochbeet['description']);
        self::assertSame('Schulhof Nord', $hochbeet['location']);
        self::assertSame('Spaten, Erde', $hochbeet['materials']);
        self::assertSame(2, (int) $hochbeet['max_per_class']);
        self::assertSame(5, (int) $hochbeet['max_per_grade']);
        self::assertSame('5,6', $hochbeet['allowed_grades']);
        self::assertSame('5a, 6b', $hochbeet['allowed_classes']);
        self::assertSame(3, (int) $hochbeet['min_students']);
        self::assertSame(0, (int) $hochbeet['is_active']);
        self::assertSame(7, (int) $hochbeet['sort_order']);

        // station_blocks auf die NEUEN Blöcke gemappt
        $sb = $this->db->fetchAll(
            'SELECT sb.capacity, tb.name FROM station_blocks sb JOIN time_blocks tb ON tb.id = sb.time_block_id WHERE sb.station_id = ? ORDER BY tb.sort_order',
            [(int) $hochbeet['id']],
        );
        self::assertSame([['capacity' => 4, 'name' => 'Vormittag'], ['capacity' => 6, 'name' => 'Nachmittag']], array_map(static fn (array $r): array => ['capacity' => (int) $r['capacity'], 'name' => $r['name']], $sb));
        $kompost = $stations[1];
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_blocks WHERE station_id = ?', [(int) $kompost['id']]));
        foreach ($this->db->fetchAll('SELECT time_block_id FROM station_blocks WHERE station_id IN (?, ?)', [(int) $hochbeet['id'], (int) $kompost['id']]) as $row) {
            self::assertNotContains((int) $row['time_block_id'], [$this->block1, $this->block2]);
        }

        // Ausschlusskriterien
        self::assertSame([$this->criterion], array_map('intval', array_column($this->db->fetchAll('SELECT criterion_id FROM station_exclusions WHERE station_id = ?', [(int) $hochbeet['id']]), 'criterion_id')));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_exclusions WHERE station_id = ?', [(int) $kompost['id']]));

        // Standleitungen: nur aktive Nutzer
        $leaders = array_map('intval', array_column($this->db->fetchAll('SELECT user_id FROM station_leaders WHERE station_id = ?', [(int) $hochbeet['id']]), 'user_id'));
        self::assertSame([$this->teacher], $leaders);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_leaders WHERE station_id = ?', [(int) $kompost['id']]));

        // Keine Einschreibungen, Wünsche oder Anwesenheiten
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ?', [$newId]));
        self::assertSame(
            0,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM attendance a JOIN enrollments e ON e.id = a.enrollment_id WHERE e.garden_day_id = ?', [$newId]),
        );

        // Quelle unverändert
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ?', [(int) $this->source['id']]));
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_leaders WHERE station_id IN (?, ?)', [$this->stationA, $this->stationB]));
        self::assertSame('active', $this->reloadDay((int) $this->source['id'])['status']);
    }

    public function testNurZeitbloecke(): void
    {
        $result = $this->cloner()->clone((int) $this->source['id'], 'Nur Blöcke', '2026-11-01', ['time_blocks']);

        self::assertSame(['time_blocks' => 2], $result['stats']);
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM time_blocks WHERE garden_day_id = ?', [$result['id']]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM stations WHERE garden_day_id = ?', [$result['id']]));
    }

    public function testStaendeOhneLeitungen(): void
    {
        $result = $this->cloner()->clone((int) $this->source['id'], 'Ohne Leitung', '2026-11-01', ['stations']);

        // Stände brauchen Blöcke → werden implizit mitkopiert
        self::assertSame(['time_blocks' => 2, 'stations' => 2], $result['stats']);
        self::assertSame(
            0,
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_leaders sl JOIN stations s ON s.id = sl.station_id WHERE s.garden_day_id = ?', [$result['id']]),
        );
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM station_blocks sb JOIN stations s ON s.id = sb.station_id WHERE s.garden_day_id = ?', [$result['id']]));
    }

    public function testLeitungenOhneStaendeWerdenIgnoriert(): void
    {
        $result = $this->cloner()->clone((int) $this->source['id'], 'Leer', '2026-11-01', ['leaders']);

        self::assertSame([], $result['stats']);
        self::assertSame('draft', $this->reloadDay($result['id'])['status']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM time_blocks WHERE garden_day_id = ?', [$result['id']]));
    }

    public function testOhneAuswahlEntstehtLeererEntwurf(): void
    {
        $result = $this->cloner()->clone((int) $this->source['id'], 'Leer', '2026-11-01', []);

        self::assertSame([], $result['stats']);
        self::assertSame('Leer', $this->reloadDay($result['id'])['name']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM time_blocks WHERE garden_day_id = ?', [$result['id']]));
    }

    public function testUnbekannteQuelleWirft(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cloner()->clone(999999, 'X', '2026-11-01', ['time_blocks']);
    }
}
