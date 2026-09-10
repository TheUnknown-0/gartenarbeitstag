<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\QuotaAssign;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/**
 * Quotenmodus: die Zuteilung hält die am Aktionstag erlaubte Höchstzahl
 * Zeitblöcke je Schüler:in ein (garden_days.max_blocks_per_student) — auch
 * gegenüber bereits bestehenden manuellen Einschreibungen.
 */
final class QuotaAssignTest extends DatabaseTestCase
{
    use Fixtures;

    /** @var array<string, mixed> */
    private array $day;
    private int $block1;
    private int $block2;
    /** @var array<string, mixed> */
    private array $station1;
    /** @var array<string, mixed> */
    private array $station2;
    /** @var list<array<string, mixed>> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = $this->createDay(['mode' => 'quota', 'max_blocks_per_student' => 1]);
        $dayId = (int) $this->day['id'];
        $this->block1 = $this->createBlock($dayId, 'Block 1', '08:00:00', '10:00:00', 1);
        $this->block2 = $this->createBlock($dayId, 'Block 2', '10:00:00', '12:00:00', 2);

        $this->station1 = $this->createStation($dayId, 'Hochbeet', [$this->block1 => 50, $this->block2 => 50]);
        $this->station2 = $this->createStation($dayId, 'Kompost', [$this->block1 => 50, $this->block2 => 50]);

        foreach (['anna', 'ben', 'cem', 'dana'] as $name) {
            $this->students[$name] = $this->createStudent($name, '5a', 5);
        }
    }

    private function dayId(): int
    {
        return (int) $this->day['id'];
    }

    private function setDemand(int $stationId, int $blockId, int $grade, int $demand): void
    {
        $this->db->run(
            'INSERT INTO station_grade_demand (station_id, time_block_id, grade, demand) VALUES (?, ?, ?, ?)',
            [$stationId, $blockId, $grade, $demand],
        );
    }

    private function quota(): QuotaAssign
    {
        return new QuotaAssign($this->db, $this->audit());
    }

    /** userId => Anzahl fest zugeteilter Zeitblöcke */
    private function assignedBlockCounts(?int $dayId = null): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT user_id, COUNT(DISTINCT time_block_id) AS n FROM enrollments
             WHERE garden_day_id = ? AND status = 'assigned' GROUP BY user_id",
            [$dayId ?? $this->dayId()],
        ) as $row) {
            $out[(int) $row['user_id']] = (int) $row['n'];
        }

        return $out;
    }

    public function testGrenzeVerhindertMehrfachzuteilungUeberBloecke(): void
    {
        // Bedarf 3 in Block 1 und 3 in Block 2 — zusammen mehr als die 4 Personen
        // bei max. 1 Block je Person hergeben.
        $this->setDemand((int) $this->station1['id'], $this->block1, 5, 3);
        $this->setDemand((int) $this->station1['id'], $this->block2, 5, 3);

        $report = $this->quota()->run($this->day);

        // 4 Personen, je höchstens 1 Block → 4 Plätze, Block 2 bleibt unterdeckt.
        self::assertSame(4, $report['assigned_total']);
        self::assertNotSame([], $report['conflicts']);
        foreach ($this->assignedBlockCounts() as $userId => $n) {
            self::assertLessThanOrEqual(1, $n, "Person {$userId} in mehr als einem Block");
        }
    }

    public function testBestehendeManuelleZuteilungZaehltGegenGrenze(): void
    {
        // Anna ist bereits manuell in Block 1 eingeteilt.
        $this->insertEnrollment($this->dayId(), (int) $this->students['anna']['id'], (int) $this->station2['id'], $this->block1, 'assigned', null, 'orga');

        // Bedarf nur in Block 2, genug für alle 4 — Anna darf trotzdem nicht rein.
        $this->setDemand((int) $this->station1['id'], $this->block2, 5, 4);

        $report = $this->quota()->run($this->day);

        self::assertSame(3, $report['assigned_total']);
        $annaBlocks = $this->db->fetchAll(
            "SELECT DISTINCT time_block_id FROM enrollments WHERE user_id = ? AND status = 'assigned'",
            [(int) $this->students['anna']['id']],
        );
        self::assertSame([$this->block1], array_map(static fn (array $r): int => (int) $r['time_block_id'], $annaBlocks));
    }

    public function testOhneGrenzeRotiertUeberBloecke(): void
    {
        // Eigene Stufe, damit der Kandidatenpool genau die zwei Personen umfasst.
        $day = $this->createDay(['mode' => 'quota', 'max_blocks_per_student' => null, 'name' => 'Ohne Grenze']);
        $dayId = (int) $day['id'];
        $b1 = $this->createBlock($dayId, 'B1', '08:00:00', '10:00:00', 1);
        $b2 = $this->createBlock($dayId, 'B2', '10:00:00', '12:00:00', 2);
        $s = $this->createStation($dayId, 'Beet', [$b1 => 50, $b2 => 50]);
        foreach (['eve', 'finn'] as $name) {
            $this->createStudent($name, '9x', 9);
        }
        $this->db->run('INSERT INTO station_grade_demand (station_id, time_block_id, grade, demand) VALUES (?, ?, 9, 2), (?, ?, 9, 2)', [(int) $s['id'], $b1, (int) $s['id'], $b2]);

        $report = $this->quota()->run($day);

        // Zwei Personen, Bedarf 2 je Block: ohne Grenze landen beide in beiden Blöcken.
        self::assertSame(4, $report['assigned_total']);
        self::assertSame([], $report['conflicts']);
        foreach ($this->assignedBlockCounts($dayId) as $n) {
            self::assertSame(2, $n);
        }
    }
}
