<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\LimitCheck;
use App\Services\LimitViolation;
use PDOException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/**
 * Schreibende Einschreibungs-Operationen: Anlegen (mit/ohne Übersteuern),
 * Warteliste, Löschen mit Nachrücken, Umbuchen, Wünsche.
 */
final class EnrollmentServiceTest extends DatabaseTestCase
{
    use Fixtures;

    /** @var array<string, mixed> */
    private array $day;
    private int $block1;
    private int $block2;
    /** @var array<string, mixed> Kapazität 1 je Block */
    private array $small;
    /** @var array<string, mixed> Kapazität 5 je Block */
    private array $big;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = $this->createDay();
        $this->block1 = $this->createBlock((int) $this->day['id'], 'Block 1', '08:00:00', '10:00:00', 1);
        $this->block2 = $this->createBlock((int) $this->day['id'], 'Block 2', '10:00:00', '12:00:00', 2);
        $this->small = $this->createStation((int) $this->day['id'], 'Klein', [$this->block1 => 1, $this->block2 => 1]);
        $this->big = $this->createStation((int) $this->day['id'], 'Groß', [$this->block1 => 5, $this->block2 => 5]);
    }

    private function dayId(): int
    {
        return (int) $this->day['id'];
    }

    // ---------- create ----------

    public function testCreateLegtFesteEinschreibungAn(): void
    {
        $anna = $this->createStudent('anna');

        $result = $this->enrollments()->create($this->day, (int) $anna['id'], (int) $this->big['id'], $this->block1, [
            'source' => 'self',
            'self_service' => true,
        ]);

        self::assertSame('assigned', $result['status']);
        self::assertSame([], $result['violations']);

        $row = $this->enrollment($result['id']);
        self::assertNotNull($row);
        self::assertSame('assigned', $row['status']);
        self::assertSame('self', $row['source']);
        self::assertNull($row['override_note']);
        self::assertSame($this->block1, (int) $row['assigned_block_key']);

        $audit = $this->db->fetchOne("SELECT * FROM audit_logs WHERE action = 'enrollment.create' ORDER BY id DESC LIMIT 1");
        self::assertNotNull($audit);
        self::assertSame('info', $audit['severity']);
    }

    public function testCreateMitHartemVerstossWirftNichtUebersteuerbar(): void
    {
        $anna = $this->createStudent('anna');
        $criterion = $this->createCriterion('Höhenangst');
        $this->excludeUser((int) $anna['id'], $criterion);
        $this->excludeStation((int) $this->big['id'], $criterion);

        try {
            $this->enrollments()->create($this->day, (int) $anna['id'], (int) $this->big['id'], $this->block1, ['override' => true, 'override_note' => 'egal']);
            self::fail('LimitViolation erwartet');
        } catch (LimitViolation $e) {
            self::assertFalse($e->overridable());
            self::assertSame([LimitCheck::EXCLUSION], $e->codes());
        }

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments'));
    }

    public function testCreateMitWeichemVerstossOhneOverrideWirftUebersteuerbar(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        try {
            $this->enrollments()->create($this->day, (int) $ben['id'], (int) $this->small['id'], $this->block1);
            self::fail('LimitViolation erwartet');
        } catch (LimitViolation $e) {
            self::assertTrue($e->overridable());
            self::assertSame([LimitCheck::CAPACITY], $e->codes());
            self::assertStringContainsString('voll', $e->getMessage());
        }

        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments'));
    }

    public function testCreateMitOverrideLegtAnUndProtokolliert(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        $result = $this->enrollments()->create($this->day, (int) $ben['id'], (int) $this->small['id'], $this->block1, [
            'override' => true,
            'override_note' => 'Geschwisterkind, Absprache mit Klassenleitung',
            'source' => 'orga',
        ]);

        self::assertSame('assigned', $result['status']);
        self::assertSame([LimitCheck::CAPACITY], self::codes($result['violations']));

        $row = $this->enrollment($result['id']);
        self::assertSame('Geschwisterkind, Absprache mit Klassenleitung', $row['override_note']);
        self::assertSame('orga', $row['source']);

        $audit = $this->db->fetchOne("SELECT * FROM audit_logs WHERE action = 'enrollment.create' ORDER BY id DESC LIMIT 1");
        self::assertSame('warning', $audit['severity']);
        self::assertStringContainsString('ÜBERSTEUERT', $audit['details']);
        self::assertStringContainsString('Geschwisterkind', $audit['details']);
    }

    public function testOverrideOhneVerstossHinterlaesstKeineNotiz(): void
    {
        $anna = $this->createStudent('anna');

        $result = $this->enrollments()->create($this->day, (int) $anna['id'], (int) $this->big['id'], $this->block1, [
            'override' => true,
            'override_note' => 'unnötig',
        ]);

        self::assertSame([], $result['violations']);
        self::assertNull($this->enrollment($result['id'])['override_note']);
    }

    public function testAutoWaitlistBeiVollerKapazitaet(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        $result = $this->enrollments()->create($this->day, (int) $ben['id'], (int) $this->small['id'], $this->block1, [
            'self_service' => true,
            'auto_waitlist' => true,
        ]);

        self::assertSame('waitlist', $result['status']);
        self::assertSame('waitlist', $this->enrollment($result['id'])['status']);
        self::assertNull($this->enrollment($result['id'])['assigned_block_key']);
    }

    public function testAutoWaitlistNurWennWartelisteAktiv(): void
    {
        $day = $this->createDay(['name' => 'Ohne Warteliste', 'waitlist_enabled' => 0]);
        $b1 = $this->createBlock((int) $day['id']);
        $station = $this->createStation((int) $day['id'], 'S', [$b1 => 1]);
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment((int) $day['id'], (int) $anna['id'], (int) $station['id'], $b1);

        $this->expectException(LimitViolation::class);
        $this->enrollments()->create($day, (int) $ben['id'], (int) $station['id'], $b1, ['self_service' => true, 'auto_waitlist' => true]);
    }

    public function testAutoWaitlistGreiftNichtBeiAnderenWeichenVerstoessen(): void
    {
        $station = $this->createStation($this->dayId(), 'Nur 6', [$this->block1 => 1], ['allowed_grades' => '6']);
        $anna = $this->createStudent('anna', '6a', 6);
        $ben = $this->createStudent('ben', '5a', 5);
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $station['id'], $this->block1);

        try {
            $this->enrollments()->create($this->day, (int) $ben['id'], (int) $station['id'], $this->block1, ['self_service' => true, 'auto_waitlist' => true]);
            self::fail('LimitViolation erwartet');
        } catch (LimitViolation $e) {
            self::assertContains(LimitCheck::GRADE_NOT_ALLOWED, $e->codes());
        }
    }

    public function testCreateLehntUngueltigenStatusAb(): void
    {
        $anna = $this->createStudent('anna');

        $this->expectException(\InvalidArgumentException::class);
        $this->enrollments()->create($this->day, (int) $anna['id'], (int) $this->big['id'], $this->block1, ['status' => 'foo']);
    }

    public function testCreateLehntNichtSchuelerAb(): void
    {
        $teacher = $this->createTeacher('lehrer');

        $this->expectException(\RuntimeException::class);
        $this->enrollments()->create($this->day, $teacher, (int) $this->big['id'], $this->block1);
    }

    // ---------- delete / Warteliste ----------

    public function testDeleteLaesstWartelisteNachCreatedAtNachruecken(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $assigned = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);
        // Cem hat sich früher eingetragen als Ben, obwohl seine Zeile jünger ist
        $benWait = $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->small['id'], $this->block1, 'waitlist', null, 'self');
        $cemWait = $this->insertEnrollment($this->dayId(), (int) $cem['id'], (int) $this->small['id'], $this->block1, 'waitlist', null, 'self');
        $this->db->run('UPDATE enrollments SET created_at = ? WHERE id = ?', ['2026-01-01 10:00:00', $benWait]);
        $this->db->run('UPDATE enrollments SET created_at = ? WHERE id = ?', ['2026-01-01 09:00:00', $cemWait]);

        $promoted = $this->enrollments()->delete($assigned, $this->day, 'Test');

        self::assertSame($cemWait, $promoted);
        self::assertNull($this->enrollment($assigned));
        self::assertSame('assigned', $this->enrollment($cemWait)['status']);
        // Quelle bleibt 'self' — 'auto' ist der automatischen Zuteilung vorbehalten (Reset!)
        self::assertSame('self', $this->enrollment($cemWait)['source']);
        self::assertSame('waitlist', $this->enrollment($benWait)['status']);

        $audit = $this->db->fetchOne("SELECT * FROM audit_logs WHERE action = 'enrollment.promote'");
        self::assertNotNull($audit);
    }

    public function testNachrueckenUeberspringtKandidatenMitZeitkonflikt(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $assigned = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);
        $benWait = $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->small['id'], $this->block1, 'waitlist');
        $cemWait = $this->insertEnrollment($this->dayId(), (int) $cem['id'], (int) $this->small['id'], $this->block1, 'waitlist');
        $this->db->run('UPDATE enrollments SET created_at = ? WHERE id = ?', ['2026-01-01 09:00:00', $benWait]);
        $this->db->run('UPDATE enrollments SET created_at = ? WHERE id = ?', ['2026-01-01 10:00:00', $cemWait]);
        // Ben ist inzwischen woanders im selben Block fest eingeschrieben
        $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->big['id'], $this->block1);

        $promoted = $this->enrollments()->delete($assigned, $this->day);

        self::assertSame($cemWait, $promoted);
        self::assertSame('waitlist', $this->enrollment($benWait)['status']);
        self::assertSame('assigned', $this->enrollment($cemWait)['status']);
    }

    public function testDeleteOhneWartelisteGibtNullZurueck(): void
    {
        $anna = $this->createStudent('anna');
        $assigned = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        self::assertNull($this->enrollments()->delete($assigned, $this->day));
        self::assertNull($this->enrollment($assigned));
    }

    public function testDeleteEinesWartelistenEintragsRuecktNiemandenNach(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);
        $benWait = $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->small['id'], $this->block1, 'waitlist');
        $cemWait = $this->insertEnrollment($this->dayId(), (int) $cem['id'], (int) $this->small['id'], $this->block1, 'waitlist');

        self::assertNull($this->enrollments()->delete($benWait, $this->day));
        self::assertSame('waitlist', $this->enrollment($cemWait)['status']);
    }

    public function testDeleteRuecktNichtNachWennWartelisteDeaktiviert(): void
    {
        $day = $this->createDay(['name' => 'Ohne Warteliste', 'waitlist_enabled' => 0]);
        $b1 = $this->createBlock((int) $day['id']);
        $station = $this->createStation((int) $day['id'], 'S', [$b1 => 1]);
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $assigned = $this->insertEnrollment((int) $day['id'], (int) $anna['id'], (int) $station['id'], $b1);
        $wait = $this->insertEnrollment((int) $day['id'], (int) $ben['id'], (int) $station['id'], $b1, 'waitlist');

        self::assertNull($this->enrollments()->delete($assigned, $day));
        self::assertSame('waitlist', $this->enrollment($wait)['status']);
    }

    public function testDeleteUnbekannterIdWirft(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->enrollments()->delete(999999, $this->day);
    }

    // ---------- rebook ----------

    public function testRebookInnerhalbDesselbenBlocksIstKeinZeitkonflikt(): void
    {
        $anna = $this->createStudent('anna');
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        $result = $this->enrollments()->rebook($id, $this->day, (int) $this->big['id'], $this->block1);

        self::assertSame($id, $result['id']);
        self::assertSame([], $result['violations']);
        $row = $this->enrollment($id);
        self::assertSame((int) $this->big['id'], (int) $row['station_id']);
        self::assertSame($this->block1, (int) $row['time_block_id']);
        self::assertSame('assigned', $row['status']);
        self::assertSame('orga', $row['source']);

        $audit = $this->db->fetchOne("SELECT * FROM audit_logs WHERE action = 'enrollment.rebook'");
        self::assertNotNull($audit);
        self::assertStringContainsString('Klein', $audit['details']);
        self::assertStringContainsString('Groß', $audit['details']);
    }

    public function testRebookAufVollenStandWirft(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->small['id'], $this->block1);
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->big['id'], $this->block1);

        try {
            $this->enrollments()->rebook($id, $this->day, (int) $this->small['id'], $this->block1);
            self::fail('LimitViolation erwartet');
        } catch (LimitViolation $e) {
            self::assertSame([LimitCheck::CAPACITY], $e->codes());
            self::assertTrue($e->overridable());
        }

        // Unverändert
        self::assertSame((int) $this->big['id'], (int) $this->enrollment($id)['station_id']);
    }

    public function testRebookMitOverrideUndNachruecken(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $this->insertEnrollment($this->dayId(), (int) $ben['id'], (int) $this->small['id'], $this->block1);
        // Anna sitzt in Block 2 am kleinen Stand, Cem wartet dort
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block2);
        $cemWait = $this->insertEnrollment($this->dayId(), (int) $cem['id'], (int) $this->small['id'], $this->block2, 'waitlist');

        $result = $this->enrollments()->rebook($id, $this->day, (int) $this->small['id'], $this->block1, [
            'override' => true,
            'override_note' => 'Ausnahme',
        ]);

        self::assertSame([LimitCheck::CAPACITY], self::codes($result['violations']));
        self::assertSame('Ausnahme', $this->enrollment($id)['override_note']);
        self::assertSame($this->block1, (int) $this->enrollment($id)['time_block_id']);
        // Cem ist am alten Platz nachgerückt
        self::assertSame('assigned', $this->enrollment($cemWait)['status']);
    }

    public function testRebookInAnderenBlockMitZeitkonfliktWirftHart(): void
    {
        $anna = $this->createStudent('anna');
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->big['id'], $this->block1);
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block2);

        try {
            $this->enrollments()->rebook($id, $this->day, (int) $this->big['id'], $this->block2, ['override' => true, 'override_note' => 'x']);
            self::fail('LimitViolation erwartet');
        } catch (LimitViolation $e) {
            self::assertFalse($e->overridable());
            self::assertSame([LimitCheck::TIME_CONFLICT], $e->codes());
        }
    }

    public function testRebookLoeschtAnwesenheitDerAltenBuchung(): void
    {
        $anna = $this->createStudent('anna');
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);
        $this->db->run('INSERT INTO attendance (enrollment_id, present) VALUES (?, 1)', [$id]);

        $this->enrollments()->rebook($id, $this->day, (int) $this->big['id'], $this->block1);

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM attendance WHERE enrollment_id = ?', [$id]));
    }

    // ---------- Wünsche ----------

    public function testReplaceWishesLegtPrioritaetenAnUndErsetztAlte(): void
    {
        $day = $this->createDay(['name' => 'Wunsch', 'mode' => 'wishlist']);
        $b1 = $this->createBlock((int) $day['id']);
        $s1 = $this->createStation((int) $day['id'], 'S1', [$b1 => 3]);
        $s2 = $this->createStation((int) $day['id'], 'S2', [$b1 => 3]);
        $s3 = $this->createStation((int) $day['id'], 'S3', [$b1 => 3]);
        $anna = $this->createStudent('anna');

        $errors = $this->enrollments()->replaceWishes($day, (int) $anna['id'], $b1, [(int) $s1['id'], (int) $s2['id']]);
        self::assertSame([], $errors);

        $wishes = $this->db->fetchAll(
            "SELECT station_id, priority, source FROM enrollments WHERE user_id = ? AND status = 'wish' ORDER BY priority",
            [(int) $anna['id']],
        );
        self::assertCount(2, $wishes);
        self::assertSame([(int) $s1['id'], 1], [(int) $wishes[0]['station_id'], (int) $wishes[0]['priority']]);
        self::assertSame([(int) $s2['id'], 2], [(int) $wishes[1]['station_id'], (int) $wishes[1]['priority']]);
        self::assertSame('self', $wishes[0]['source']);

        // Neue Reihenfolge inkl. Duplikat ersetzt alles
        $errors = $this->enrollments()->replaceWishes($day, (int) $anna['id'], $b1, [(int) $s3['id'], (int) $s1['id'], (int) $s3['id']]);
        self::assertSame([], $errors);

        $wishes = $this->db->fetchAll(
            "SELECT station_id, priority FROM enrollments WHERE user_id = ? AND status = 'wish' ORDER BY priority",
            [(int) $anna['id']],
        );
        self::assertCount(2, $wishes);
        self::assertSame((int) $s3['id'], (int) $wishes[0]['station_id']);
        self::assertSame((int) $s1['id'], (int) $wishes[1]['station_id']);
    }

    public function testReplaceWishesLehntGesperrtenStandAbUndSchreibtNichts(): void
    {
        $day = $this->createDay(['name' => 'Wunsch', 'mode' => 'wishlist']);
        $b1 = $this->createBlock((int) $day['id']);
        $s1 = $this->createStation((int) $day['id'], 'S1', [$b1 => 3]);
        $s2 = $this->createStation((int) $day['id'], 'Gesperrt', [$b1 => 3]);
        $anna = $this->createStudent('anna');
        $criterion = $this->createCriterion('Allergie');
        $this->excludeUser((int) $anna['id'], $criterion);
        $this->excludeStation((int) $s2['id'], $criterion);

        $errors = $this->enrollments()->replaceWishes($day, (int) $anna['id'], $b1, [(int) $s1['id'], (int) $s2['id']]);

        self::assertCount(1, $errors);
        self::assertSame(LimitCheck::EXCLUSION, $errors[0]['code']);
        self::assertStringStartsWith('Gesperrt: ', $errors[0]['message']);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments'));
    }

    public function testReplaceWishesIgnoriertDuplikatVerstossBeimErsetzen(): void
    {
        $day = $this->createDay(['name' => 'Wunsch', 'mode' => 'wishlist']);
        $b1 = $this->createBlock((int) $day['id']);
        $s1 = $this->createStation((int) $day['id'], 'S1', [$b1 => 3]);
        $anna = $this->createStudent('anna');

        self::assertSame([], $this->enrollments()->replaceWishes($day, (int) $anna['id'], $b1, [(int) $s1['id']]));
        // Derselbe Wunsch erneut: die vorhandene Zeile ist kein Duplikat-Fehler
        self::assertSame([], $this->enrollments()->replaceWishes($day, (int) $anna['id'], $b1, [(int) $s1['id']]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments'));
    }

    // ---------- Datenbank-Constraint ----------

    public function testDatenbankVerhindertZweiteFesteEinschreibungImSelbenBlock(): void
    {
        $anna = $this->createStudent('anna');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        $this->expectException(PDOException::class);
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->big['id'], $this->block1);
    }

    public function testDatenbankErlaubtWunschUndWartelisteNebenFesterBuchung(): void
    {
        $anna = $this->createStudent('anna');
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->big['id'], $this->block1, 'waitlist');
        $other = $this->createStation($this->dayId(), 'Dritter', [$this->block1 => 3]);
        $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $other['id'], $this->block1, 'wish', 1);

        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE user_id = ?', [(int) $anna['id']]));
    }

    public function testFindLiefertAngereicherteZeile(): void
    {
        $anna = $this->createStudent('anna', '5a', 5);
        $id = $this->insertEnrollment($this->dayId(), (int) $anna['id'], (int) $this->small['id'], $this->block1);

        $row = $this->enrollments()->find($id);

        self::assertSame('anna', $row['username']);
        self::assertSame('Klein', $row['name']);
        self::assertSame('Block 1', $row['block_name']);
        self::assertNull($this->enrollments()->find(999999));
    }
}
