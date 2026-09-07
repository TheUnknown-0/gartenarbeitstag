<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/**
 * Automatische Zuteilung im Wunschmodus: Kapazitäten, Zeitkonflikte,
 * Ausschlüsse, Reproduzierbarkeit, Probelauf und Zurücksetzen.
 *
 * Aufbau: 2 Blöcke, 3 Stände mit kleinen Kapazitäten, 8 Schüler:innen mit
 * Wünschen — mehr Nachfrage als Plätze, damit Auffüllen und Fairness greifen.
 */
final class AutoAssignTest extends DatabaseTestCase
{
    use Fixtures;

    private const SEED = 4711;

    /** @var array<string, mixed> */
    private array $day;
    private int $block1;
    private int $block2;
    /** @var array<string, array<string, mixed>> Name => Zeile */
    private array $stations = [];
    /** @var array<string, array<string, mixed>> Username => Zeile */
    private array $students = [];
    private int $criterion;

    /** Wurde der Fixture-Stand committet (siehe outsideTransaction)? */
    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = $this->createDay(['mode' => 'wishlist', 'min_blocks_per_student' => 1]);
        $dayId = (int) $this->day['id'];
        $this->block1 = $this->createBlock($dayId, 'Block 1', '08:00:00', '10:00:00', 1);
        $this->block2 = $this->createBlock($dayId, 'Block 2', '10:00:00', '12:00:00', 2);

        // Gesamtkapazität je Block: 2 + 3 + 2 = 7 < 8 Schüler:innen
        $this->stations['Hochbeet'] = $this->createStation($dayId, 'Hochbeet', [$this->block1 => 2, $this->block2 => 2]);
        $this->stations['Kompost'] = $this->createStation($dayId, 'Kompost', [$this->block1 => 3, $this->block2 => 3]);
        $this->stations['Teich'] = $this->createStation($dayId, 'Teich', [$this->block1 => 2, $this->block2 => 2]);

        // Ausschluss: Anna darf nicht an den Teich
        $this->criterion = $this->createCriterion('Nichtschwimmer');
        $this->excludeStation((int) $this->stations['Teich']['id'], $this->criterion);

        foreach (['anna', 'ben', 'cem', 'dana', 'emil', 'fara', 'gus', 'hedi'] as $i => $name) {
            $this->students[$name] = $this->createStudent($name, $i % 2 === 0 ? '5a' : '5b', 5);
        }
        $this->excludeUser((int) $this->students['anna']['id'], $this->criterion);

        // Wünsche: alle wollen zuerst ans Hochbeet (Kapazität 2), dann verschieden
        $prefs = [
            'anna' => ['Hochbeet', 'Teich', 'Kompost'],   // Teich ist für Anna gesperrt
            'ben' => ['Hochbeet', 'Kompost', 'Teich'],
            'cem' => ['Hochbeet', 'Teich', 'Kompost'],
            'dana' => ['Hochbeet', 'Kompost', 'Teich'],
            'emil' => ['Hochbeet', 'Teich', 'Kompost'],
            'fara' => ['Hochbeet', 'Kompost', 'Teich'],
            'gus' => ['Hochbeet', 'Teich', 'Kompost'],
            'hedi' => ['Hochbeet', 'Kompost', 'Teich'],
        ];
        foreach ($prefs as $name => $order) {
            foreach ([$this->block1, $this->block2] as $blockId) {
                foreach ($order as $priority => $stationName) {
                    $this->insertEnrollment($dayId, (int) $this->students[$name]['id'], (int) $this->stations[$stationName]['id'], $blockId, 'wish', $priority + 1, 'self');
                }
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->committed) {
            // Fixtures wurden committet → von Hand aufräumen (FKs kaskadieren)
            self::$pdo->exec('DELETE FROM garden_days');
            self::$pdo->exec('DELETE FROM users');
            self::$pdo->exec('DELETE FROM exclusion_criteria');
            self::$pdo->exec('DELETE FROM audit_logs');
            $this->committed = false;
        }

        parent::tearDown();
    }

    /**
     * Database::transaction() legt keinen Savepoint an, wenn bereits eine
     * Transaktion läuft — simulate() kann seinen Rollback also nur außerhalb
     * der Test-Transaktion ausführen. Dafür werden die Fixtures committet und
     * in tearDown() gelöscht.
     */
    private function outsideTransaction(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->commit();
        }
        $this->committed = true;
    }

    private function dayId(): int
    {
        return (int) $this->day['id'];
    }

    /**
     * Entfernt einen bestehenden Wunsch für genau diese Person/Stand/Block.
     *
     * `uq_enrollments_user_station_block` erlaubt pro Person/Stand/Block nur
     * eine Zeile (unabhängig vom Status) — bevor ein Test eine manuelle
     * Orga-Einschreibung für eine Kombination anlegt, die laut setUp() bereits
     * als Wunsch existiert (alle Testpersonen wünschen sich u. a. Hochbeet/
     * Kompost/Teich), muss dieser Wunsch erst weg, sonst verletzt der
     * insertEnrollment()-Aufruf den Unique-Key.
     */
    private function clearWish(int $userId, int $stationId, int $blockId): void
    {
        $this->db->run(
            "DELETE FROM enrollments WHERE user_id = ? AND station_id = ? AND time_block_id = ? AND status = 'wish'",
            [$userId, $stationId, $blockId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function assigned(): array
    {
        return $this->db->fetchAll(
            "SELECT e.*, s.name AS station_name FROM enrollments e JOIN stations s ON s.id = e.station_id
             WHERE e.garden_day_id = ? AND e.status = 'assigned' ORDER BY e.id",
            [$this->dayId()],
        );
    }

    private function assertKeineKapazitaetUeberschritten(): void
    {
        $rows = $this->db->fetchAll(
            "SELECT sb.station_id, sb.time_block_id, sb.capacity,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'assigned') AS used
             FROM station_blocks sb JOIN stations s ON s.id = sb.station_id WHERE s.garden_day_id = ?",
            [$this->dayId()],
        );
        foreach ($rows as $row) {
            self::assertLessThanOrEqual((int) $row['capacity'], (int) $row['used'], sprintf('Stand %d, Block %d überbelegt', $row['station_id'], $row['time_block_id']));
        }
    }

    // ---------- run() ----------

    public function testRunVerteiltUndHaeltAlleRegelnEin(): void
    {
        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED]);

        self::assertFalse($report['simulated']);
        self::assertSame(self::SEED, $report['seed']);
        self::assertCount(2, $report['blocks']);

        $assigned = $this->assigned();
        self::assertCount($report['assigned_total'], $assigned);
        // Gesamtkapazität 7 je Block, 8 Wünschende → beide Blöcke voll, je 1 offen
        self::assertSame(14, $report['assigned_total']);
        self::assertSame(2, $report['unassigned_total']);
        self::assertCount(1, $report['blocks'][0]['unassigned']);
        self::assertCount(1, $report['blocks'][1]['unassigned']);
        self::assertNotSame('', $report['blocks'][0]['unassigned'][0]['reason']);

        $this->assertKeineKapazitaetUeberschritten();

        // Niemand zweimal im selben Block
        $pairs = [];
        foreach ($assigned as $row) {
            $key = $row['user_id'] . '/' . $row['time_block_id'];
            self::assertArrayNotHasKey($key, $pairs, 'Doppelbelegung im selben Block');
            $pairs[$key] = true;
            self::assertSame('auto', $row['source']);
        }

        // Ausschluss: Anna nie am Teich
        foreach ($assigned as $row) {
            if ((int) $row['user_id'] === (int) $this->students['anna']['id']) {
                self::assertNotSame('Teich', $row['station_name']);
            }
        }

        // Hochbeet (Prio 1 für alle) ist in beiden Blöcken genau voll
        self::assertSame(4, $report['by_priority'][1]);

        // Wünsche bleiben erhalten — außer dem einen Wunsch, der durch die
        // Zuteilung gerade erfüllt wurde: uq_enrollments_user_station_block
        // erlaubt je Person/Stand/Block ohnehin nur eine Zeile, die passende
        // Wunsch-Zeile wird beim Erfüllen auf 'assigned' hochgestuft statt
        // eine zweite anzulegen. Nur „Auffüllen“ auf einen nicht gewünschten
        // Stand (report['filled']) legt eine neue Zeile an und lässt die
        // Wunschzahl unverändert.
        self::assertSame(
            48 - ($report['assigned_total'] - $report['filled']),
            (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND status = 'wish'", [$this->dayId()]),
        );

        // Tag markiert, Audit geschrieben
        self::assertNotNull($this->reloadDay($this->dayId())['assignment_done_at']);
        $audit = $this->db->fetchOne("SELECT * FROM audit_logs WHERE action = 'assignment.run'");
        self::assertSame('warning', $audit['severity']);

        // Standbelegung im Bericht stimmt mit der DB überein
        self::assertCount(6, $report['stations']);
        self::assertSame(14, array_sum(array_map(static fn (array $s): int => (int) $s['assigned'], $report['stations'])));
    }

    public function testBestehendeOrgaEinschreibungBleibtUndZaehlt(): void
    {
        // Orga hat Hedi bereits fest ans Hochbeet in Block 1 gesetzt (Kapazität 2)
        $this->clearWish((int) $this->students['hedi']['id'], (int) $this->stations['Hochbeet']['id'], $this->block1);
        $manual = $this->insertEnrollment($this->dayId(), (int) $this->students['hedi']['id'], (int) $this->stations['Hochbeet']['id'], $this->block1, 'assigned', null, 'orga');

        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED]);

        $row = $this->enrollment($manual);
        self::assertSame('assigned', $row['status']);
        self::assertSame('orga', $row['source']);

        $this->assertKeineKapazitaetUeberschritten();
        $hochbeet1 = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM enrollments WHERE station_id = ? AND time_block_id = ? AND status = 'assigned'",
            [(int) $this->stations['Hochbeet']['id'], $this->block1],
        );
        self::assertSame(2, $hochbeet1);
        // Hedi bekommt in Block 1 keinen zweiten Platz; Block 1 hat also 6 Auto-Plätze
        self::assertSame(6, $report['blocks'][0]['assigned']);
        self::assertSame(
            1,
            (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND time_block_id = ? AND status = 'assigned'", [(int) $this->students['hedi']['id'], $this->block1]),
        );
    }

    public function testFillUnluckyVerteiltRestplaetze(): void
    {
        // Nur Kompost-Wünsche mit Prio 1 (Kapazität 3) für 5 Personen; Rest hat gar keine Wünsche
        $this->db->run('DELETE FROM enrollments WHERE garden_day_id = ?', [$this->dayId()]);
        foreach (['anna', 'ben', 'cem', 'dana', 'emil'] as $name) {
            $this->insertEnrollment($this->dayId(), (int) $this->students[$name]['id'], (int) $this->stations['Kompost']['id'], $this->block1, 'wish', 1, 'self');
        }

        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED, 'fill_unlucky' => true]);

        // 3 am Kompost (Wunsch), 2 auf freien Plätzen anderswo — niemand geht leer aus
        self::assertSame(5, $report['assigned_total']);
        self::assertSame(2, $report['filled']);
        self::assertSame(0, $report['unassigned_total']);
        self::assertSame([1 => 3], $report['by_priority']);
        $this->assertKeineKapazitaetUeberschritten();

        $byStation = [];
        foreach ($this->assigned() as $row) {
            $byStation[$row['station_name']] = ($byStation[$row['station_name']] ?? 0) + 1;
        }
        self::assertSame(3, $byStation['Kompost']);
        self::assertSame(2, array_sum($byStation) - $byStation['Kompost']);
    }

    public function testFillUnluckyKannAbgeschaltetWerden(): void
    {
        $this->db->run('DELETE FROM enrollments WHERE garden_day_id = ?', [$this->dayId()]);
        foreach (['anna', 'ben', 'cem', 'dana', 'emil'] as $name) {
            $this->insertEnrollment($this->dayId(), (int) $this->students[$name]['id'], (int) $this->stations['Kompost']['id'], $this->block1, 'wish', 1, 'self');
        }

        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED, 'fill_unlucky' => false]);

        self::assertSame(3, $report['assigned_total']);
        self::assertSame(0, $report['filled']);
        self::assertSame(2, $report['unassigned_total']);
        foreach ($this->assigned() as $row) {
            self::assertSame('Kompost', $row['station_name']);
        }
    }

    public function testFillNoWishesFuelltBisMindestzahl(): void
    {
        $this->db->run('DELETE FROM enrollments WHERE garden_day_id = ?', [$this->dayId()]);
        // Nur Ben wünscht sich etwas; alle anderen haben keine Wünsche
        $this->insertEnrollment($this->dayId(), (int) $this->students['ben']['id'], (int) $this->stations['Kompost']['id'], $this->block1, 'wish', 1, 'self');

        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED, 'fill_no_wishes' => true]);

        // 7 Personen ohne Wünsche bekommen je 1 Block (min_blocks_per_student = 1)
        self::assertSame(7, $report['no_wishes_filled']);
        self::assertSame([], $report['no_wishes_open']);
        self::assertSame(8, $report['assigned_total']);
        $this->assertKeineKapazitaetUeberschritten();

        foreach ($this->students as $student) {
            $n = (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND status = 'assigned'", [(int) $student['id']]);
            self::assertSame(1, $n, $student['username']);
        }
    }

    public function testOhneFillNoWishesBleibenWunschloseUnversorgt(): void
    {
        $this->db->run('DELETE FROM enrollments WHERE garden_day_id = ?', [$this->dayId()]);
        $this->insertEnrollment($this->dayId(), (int) $this->students['ben']['id'], (int) $this->stations['Kompost']['id'], $this->block1, 'wish', 1, 'self');

        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED]);

        self::assertSame(1, $report['assigned_total']);
        self::assertSame(0, $report['no_wishes_filled']);
    }

    // ---------- reset() ----------

    public function testResetEntferntNurAutomatischeZuteilungen(): void
    {
        $this->clearWish((int) $this->students['hedi']['id'], (int) $this->stations['Kompost']['id'], $this->block2);
        $manual = $this->insertEnrollment($this->dayId(), (int) $this->students['hedi']['id'], (int) $this->stations['Kompost']['id'], $this->block2, 'assigned', null, 'orga');
        $this->clearWish((int) $this->students['gus']['id'], (int) $this->stations['Kompost']['id'], $this->block2);
        $waitlist = $this->insertEnrollment($this->dayId(), (int) $this->students['gus']['id'], (int) $this->stations['Kompost']['id'], $this->block2, 'waitlist', null, 'self');
        $report = $this->autoAssign()->run($this->day, ['seed' => self::SEED]);
        $autoId = (int) $this->db->fetchValue("SELECT id FROM enrollments WHERE source = 'auto' AND status = 'assigned' LIMIT 1");
        $this->db->run('INSERT INTO attendance (enrollment_id, present) VALUES (?, 1)', [$autoId]);

        $removed = $this->autoAssign()->reset($this->day);

        self::assertSame($report['assigned_total'], $removed);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE source = 'auto'"));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM attendance'));
        self::assertSame('assigned', $this->enrollment($manual)['status']);
        self::assertSame('waitlist', $this->enrollment($waitlist)['status']);
        // 48 Wünsche minus die beiden oben per clearWish() entfernten: reset() macht aus
        // erfüllten Wünschen wieder Wünsche, sodass die Zuteilung erneut laufen kann.
        self::assertSame(
            48 - 2,
            (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE status = 'wish'"),
        );
        self::assertNull($this->reloadDay($this->dayId())['assignment_done_at']);
        self::assertNotNull($this->db->fetchOne("SELECT id FROM audit_logs WHERE action = 'assignment.reset' AND severity = 'critical'"));
    }

    // ---------- simulate() ----------

    public function testSimulateHinterlaesstKeineEinschreibungen(): void
    {
        $this->outsideTransaction();

        $report = $this->autoAssign()->simulate($this->day, ['seed' => self::SEED]);

        self::assertTrue($report['simulated']);
        self::assertSame(14, $report['assigned_total']);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND status = 'assigned'", [$this->dayId()]));
        self::assertNull($this->reloadDay($this->dayId())['assignment_done_at']);
        self::assertNull($this->db->fetchOne("SELECT id FROM audit_logs WHERE action = 'assignment.run'"));
    }

    public function testSimulateUndRunLiefernDasselbeErgebnisBeiGleichemSeed(): void
    {
        $this->outsideTransaction();

        $sim = $this->autoAssign()->simulate($this->day, ['seed' => self::SEED]);
        $run = $this->autoAssign()->run($this->day, ['seed' => self::SEED]);

        self::assertSame($sim['assigned_total'], $run['assigned_total']);
        self::assertSame($sim['by_priority'], $run['by_priority']);
        self::assertSame($sim['stations'], $run['stations']);
        self::assertSame(self::normalize($sim['blocks']), self::normalize($run['blocks']));
    }

    public function testSimulateIstDeterministischBeiFestemSeed(): void
    {
        $this->outsideTransaction();

        $first = $this->autoAssign()->simulate($this->day, ['seed' => self::SEED]);
        $second = $this->autoAssign()->simulate($this->day, ['seed' => self::SEED]);

        self::assertSame($first, $second);
        // Und der Bericht hängt tatsächlich vom Seed ab: verschiedene Seeds → i. d. R. andere Verlierer
        $seeds = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $seed) {
            $seeds[] = self::normalize($this->autoAssign()->simulate($this->day, ['seed' => $seed])['blocks']);
        }
        self::assertGreaterThan(1, count(array_unique(array_map('serialize', $seeds))), 'Der Seed hat keinen Einfluss auf die Verteilung');
    }

    public function testSimulateOhneSeedWaehltEinen(): void
    {
        $this->outsideTransaction();

        $report = $this->autoAssign()->simulate($this->day);

        self::assertIsInt($report['seed']);
        self::assertGreaterThan(0, $report['seed']);
    }

    /**
     * Blockberichte ohne die Zeitstempel-freien, aber id-abhängigen Teile vergleichbar machen.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function normalize(array $blocks): array
    {
        return array_map(static function (array $b): array {
            $unassigned = array_column($b['unassigned'], 'user_id');
            sort($unassigned);

            return ['id' => $b['id'], 'assigned' => $b['assigned'], 'by_priority' => $b['by_priority'], 'filled' => $b['filled'], 'unassigned' => $unassigned];
        }, $blocks);
    }
}
