<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\LimitCheck;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Fixtures;

/**
 * LimitCheck ist die einzige Regelinstanz für Einschreibungen — hier wird
 * jeder Verstoßcode einzeln abgesichert, inklusive der Einteilung hart/weich.
 */
final class LimitCheckTest extends DatabaseTestCase
{
    use Fixtures;

    /** @var array<string, mixed> */
    private array $day;
    private int $block1;
    private int $block2;
    /** @var array<string, mixed> */
    private array $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = $this->createDay();
        $this->block1 = $this->createBlock((int) $this->day['id'], 'Block 1', '08:00:00', '10:00:00', 1);
        $this->block2 = $this->createBlock((int) $this->day['id'], 'Block 2', '10:00:00', '12:00:00', 2);
        $this->station = $this->createStation((int) $this->day['id'], 'Hochbeet', [$this->block1 => 2, $this->block2 => 2]);
    }

    public function testOhneVerstoesseIstDieListeLeer(): void
    {
        $anna = $this->createStudent('anna');

        self::assertSame([], $this->limits()->check($anna, $this->station, $this->block1, $this->day));
    }

    public function testAusschlusskriteriumIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $criterion = $this->createCriterion('Pollenallergie');
        $this->excludeUser((int) $anna['id'], $criterion);
        $this->excludeStation((int) $this->station['id'], $criterion);

        $violations = $this->limits()->check($anna, $this->station, $this->block1, $this->day);

        self::assertSame([LimitCheck::EXCLUSION], self::codes($violations));
        self::assertTrue($violations[0]['hard']);
        self::assertTrue(LimitCheck::hasHard($violations));
        self::assertStringContainsString('Pollenallergie', $violations[0]['message']);
    }

    public function testInaktivesKriteriumSperrtNicht(): void
    {
        $anna = $this->createStudent('anna');
        $criterion = $this->createCriterion('Alt', false);
        $this->excludeUser((int) $anna['id'], $criterion);
        $this->excludeStation((int) $this->station['id'], $criterion);

        self::assertSame([], $this->limits()->check($anna, $this->station, $this->block1, $this->day));
    }

    public function testInaktiverStandIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $this->db->run('UPDATE stations SET is_active = 0 WHERE id = ?', [$this->station['id']]);

        $violations = $this->limits()->check($anna, $this->reloadStation((int) $this->station['id']), $this->block1, $this->day);

        self::assertContains(LimitCheck::STATION_INACTIVE, self::codes($violations));
        self::assertTrue(LimitCheck::hasHard($violations));
    }

    public function testNichtAktiverAktionstagIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $this->db->run('UPDATE garden_days SET status = ? WHERE id = ?', ['draft', $this->day['id']]);

        $violations = $this->limits()->check($anna, $this->station, $this->block1, $this->reloadDay((int) $this->day['id']));

        self::assertContains(LimitCheck::DAY_NOT_ACTIVE, self::codes($violations));
        self::assertTrue(LimitCheck::hasHard($violations));
    }

    public function testStandOhneAngebotImBlockIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $block3 = $this->createBlock((int) $this->day['id'], 'Block 3', '12:00:00', '14:00:00', 3);

        $violations = $this->limits()->check($anna, $this->station, $block3, $this->day);

        self::assertSame([LimitCheck::NO_BLOCK], self::codes($violations));
        self::assertTrue(LimitCheck::hasHard($violations));
    }

    public function testDuplikatIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $this->station['id'], $this->block1, 'waitlist');

        $violations = $this->limits()->check($anna, $this->station, $this->block1, $this->day);

        self::assertContains(LimitCheck::DUPLICATE, self::codes($violations));
        self::assertTrue(LimitCheck::hasHard($violations));
    }

    public function testZeitkonfliktMitAnderemStandImSelbenBlockIstHart(): void
    {
        $anna = $this->createStudent('anna');
        $other = $this->createStation((int) $this->day['id'], 'Kompost', [$this->block1 => 5]);
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $other['id'], $this->block1);

        $violations = $this->limits()->check($anna, $this->station, $this->block1, $this->day);

        self::assertSame([LimitCheck::TIME_CONFLICT], self::codes($violations));
        self::assertTrue($violations[0]['hard']);
        self::assertStringContainsString('Kompost', $violations[0]['message']);
    }

    public function testZeitkonfliktGiltNichtFuerWuensche(): void
    {
        $anna = $this->createStudent('anna');
        $other = $this->createStation((int) $this->day['id'], 'Kompost', [$this->block1 => 5]);
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $other['id'], $this->block1);

        self::assertSame([], $this->limits()->check($anna, $this->station, $this->block1, $this->day, 'wish'));
        self::assertSame([], $this->limits()->check($anna, $this->station, $this->block1, $this->day, 'waitlist'));
    }

    public function testWartelisteImSelbenBlockIstKeinZeitkonflikt(): void
    {
        $anna = $this->createStudent('anna');
        $other = $this->createStation((int) $this->day['id'], 'Kompost', [$this->block1 => 5]);
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $other['id'], $this->block1, 'waitlist');

        self::assertSame([], $this->limits()->check($anna, $this->station, $this->block1, $this->day));
    }

    public function testStufenWhitelistIstWeich(): void
    {
        $anna = $this->createStudent('anna', '7b', 7);
        $station = $this->createStation((int) $this->day['id'], 'Nur Unterstufe', [$this->block1 => 5], ['allowed_grades' => '5, 6']);

        $violations = $this->limits()->check($anna, $station, $this->block1, $this->day);

        self::assertSame([LimitCheck::GRADE_NOT_ALLOWED], self::codes($violations));
        self::assertFalse($violations[0]['hard']);
        self::assertFalse(LimitCheck::hasHard($violations));

        $ben = $this->createStudent('ben', '6a', 6);
        self::assertSame([], $this->limits()->check($ben, $station, $this->block1, $this->day));
    }

    public function testKlassenWhitelistIstCaseInsensitiv(): void
    {
        $anna = $this->createStudent('anna', '5A', 5);
        $ben = $this->createStudent('ben', '5c', 5);
        $station = $this->createStation((int) $this->day['id'], 'Nur 5a/5b', [$this->block1 => 5], ['allowed_classes' => '5a,5b']);

        self::assertSame([], $this->limits()->check($anna, $station, $this->block1, $this->day));

        $violations = $this->limits()->check($ben, $station, $this->block1, $this->day);
        self::assertSame([LimitCheck::CLASS_NOT_ALLOWED], self::codes($violations));
        self::assertFalse($violations[0]['hard']);
    }

    public function testVolleKapazitaetIstWeich(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $this->station['id'], $this->block1);
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $this->station['id'], $this->block1);

        $violations = $this->limits()->check($cem, $this->station, $this->block1, $this->day);

        self::assertSame([LimitCheck::CAPACITY], self::codes($violations));
        self::assertFalse($violations[0]['hard']);
        self::assertStringContainsString('2/2', $violations[0]['message']);

        // Anderer Block desselben Stands ist frei
        self::assertSame([], $this->limits()->check($cem, $this->station, $this->block2, $this->day));
    }

    public function testWartelistenEintraegeZaehlenNichtGegenKapazitaet(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $cem = $this->createStudent('cem');
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $this->station['id'], $this->block1);
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $this->station['id'], $this->block1, 'waitlist');

        self::assertSame([], $this->limits()->check($cem, $this->station, $this->block1, $this->day));
    }

    public function testMaxProKlasse(): void
    {
        $station = $this->createStation((int) $this->day['id'], 'Beet', [$this->block1 => 10], ['max_per_class' => 1]);
        $anna = $this->createStudent('anna', '5a', 5);
        $ben = $this->createStudent('ben', '5A', 5);
        $cem = $this->createStudent('cem', '5b', 5);
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $station['id'], $this->block1);

        $violations = $this->limits()->check($ben, $station, $this->block1, $this->day);
        self::assertSame([LimitCheck::CLASS_LIMIT], self::codes($violations));
        self::assertFalse($violations[0]['hard']);

        self::assertSame([], $this->limits()->check($cem, $station, $this->block1, $this->day));
    }

    public function testMaxProStufe(): void
    {
        $station = $this->createStation((int) $this->day['id'], 'Beet', [$this->block1 => 10], ['max_per_grade' => 2]);
        $anna = $this->createStudent('anna', '5a', 5);
        $ben = $this->createStudent('ben', '5b', 5);
        $cem = $this->createStudent('cem', '5c', 5);
        $dora = $this->createStudent('dora', '6a', 6);
        $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $station['id'], $this->block1);
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $station['id'], $this->block1);

        $violations = $this->limits()->check($cem, $station, $this->block1, $this->day);
        self::assertSame([LimitCheck::GRADE_LIMIT], self::codes($violations));
        self::assertFalse($violations[0]['hard']);

        self::assertSame([], $this->limits()->check($dora, $station, $this->block1, $this->day));
    }

    public function testMaxBloeckeProSchueler(): void
    {
        $day = $this->createDay(['name' => 'Begrenzt', 'max_blocks_per_student' => 1]);
        $b1 = $this->createBlock((int) $day['id'], 'B1', '08:00:00', '10:00:00', 1);
        $b2 = $this->createBlock((int) $day['id'], 'B2', '10:00:00', '12:00:00', 2);
        $s1 = $this->createStation((int) $day['id'], 'S1', [$b1 => 5, $b2 => 5]);
        $s2 = $this->createStation((int) $day['id'], 'S2', [$b1 => 5, $b2 => 5]);
        $anna = $this->createStudent('anna');

        // Noch nichts belegt → ok
        self::assertSame([], $this->limits()->check($anna, $s1, $b1, $day));

        $this->insertEnrollment((int) $day['id'], (int) $anna['id'], (int) $s1['id'], $b1);

        // Zweiter Block → Höchstzahl überschritten (weich)
        $violations = $this->limits()->check($anna, $s2, $b2, $day);
        self::assertSame([LimitCheck::MAX_BLOCKS], self::codes($violations));
        self::assertFalse($violations[0]['hard']);

        // Gleicher Block, anderer Stand: der Block zählt nicht gegen max_blocks,
        // aber es ist ein Zeitkonflikt
        $violations = $this->limits()->check($anna, $s2, $b1, $day);
        self::assertSame([LimitCheck::TIME_CONFLICT], self::codes($violations));
    }

    public function testMaxBloeckeIgnoriertWuenscheUndWarteliste(): void
    {
        $day = $this->createDay(['name' => 'Begrenzt', 'max_blocks_per_student' => 1]);
        $b1 = $this->createBlock((int) $day['id'], 'B1', '08:00:00', '10:00:00', 1);
        $b2 = $this->createBlock((int) $day['id'], 'B2', '10:00:00', '12:00:00', 2);
        $s1 = $this->createStation((int) $day['id'], 'S1', [$b1 => 5, $b2 => 5]);
        $anna = $this->createStudent('anna');
        $this->insertEnrollment((int) $day['id'], (int) $anna['id'], (int) $s1['id'], $b1, 'waitlist');

        self::assertSame([], $this->limits()->check($anna, $s1, $b2, $day));
    }

    public function testIgnoreEnrollmentIdBeimUmbuchen(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $this->station['id'], $this->block1);
        $other = $this->createStation((int) $this->day['id'], 'Kompost', [$this->block1 => 5]);
        $existing = $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $other['id'], $this->block1);

        // Ohne Ignorieren: Zeitkonflikt mit der eigenen Buchung
        self::assertSame(
            [LimitCheck::TIME_CONFLICT],
            self::codes($this->limits()->check($anna, $this->station, $this->block1, $this->day)),
        );

        // Mit Ignorieren: Wechsel innerhalb des Blocks ist erlaubt
        self::assertSame(
            [],
            $this->limits()->check($anna, $this->station, $this->block1, $this->day, 'assigned', ['ignore_enrollment_id' => $existing]),
        );

        // Umbuchen auf denselben Stand/Block ist kein Duplikat der eigenen Zeile
        self::assertSame(
            [],
            $this->limits()->check($anna, $other, $this->block1, $this->day, 'assigned', ['ignore_enrollment_id' => $existing]),
        );
    }

    public function testIgnoreEnrollmentIdZaehltNichtGegenKapazitaet(): void
    {
        $anna = $this->createStudent('anna');
        $ben = $this->createStudent('ben');
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $this->station['id'], $this->block1);
        $existing = $this->insertEnrollment((int) $this->day['id'], (int) $anna['id'], (int) $this->station['id'], $this->block1);

        // Stand ist voll (2/2), aber die eigene Zeile wird herausgerechnet
        $counts = $this->limits()->counts((int) $this->station['id'], $this->block1, $existing);
        self::assertSame(1, $counts['total']);
        self::assertSame(['5a' => 1], $counts['classes']);
        self::assertSame(['5' => 1], $counts['grades']);
    }

    public function testAnmeldefensterNurBeiSelbstbedienung(): void
    {
        $day = $this->createDay([
            'name' => 'Mit Fenster',
            'registration_start' => '2026-05-01 08:00:00',
            'registration_end' => '2026-05-10 20:00:00',
        ]);
        $b1 = $this->createBlock((int) $day['id']);
        $station = $this->createStation((int) $day['id'], 'S', [$b1 => 5]);
        $anna = $this->createStudent('anna');

        // Orga: kein Fenster
        self::assertSame([], $this->limits()->check($anna, $station, $b1, $day, 'assigned', ['now' => '2026-04-01 12:00:00']));

        // Selbstbedienung zu früh / zu spät / im Fenster
        $early = $this->limits()->check($anna, $station, $b1, $day, 'assigned', ['self_service' => true, 'now' => '2026-04-01 12:00:00']);
        self::assertSame([LimitCheck::WINDOW_CLOSED], self::codes($early));
        self::assertFalse($early[0]['hard']);

        $late = $this->limits()->check($anna, $station, $b1, $day, 'assigned', ['self_service' => true, 'now' => '2026-06-01 12:00:00']);
        self::assertSame([LimitCheck::WINDOW_CLOSED], self::codes($late));

        self::assertSame([], $this->limits()->check($anna, $station, $b1, $day, 'assigned', ['self_service' => true, 'now' => '2026-05-05 12:00:00']));
    }

    public function testMehrereVerstoesseWerdenGesammelt(): void
    {
        $station = $this->createStation((int) $this->day['id'], 'Streng', [$this->block1 => 1], ['allowed_grades' => '6']);
        $anna = $this->createStudent('anna', '5a', 5);
        $ben = $this->createStudent('ben', '6a', 6);
        $this->insertEnrollment((int) $this->day['id'], (int) $ben['id'], (int) $station['id'], $this->block1);

        $codes = self::codes($this->limits()->check($anna, $station, $this->block1, $this->day));

        self::assertContains(LimitCheck::GRADE_NOT_ALLOWED, $codes);
        self::assertContains(LimitCheck::CAPACITY, $codes);
        self::assertCount(2, $codes);
    }

    public function testParseList(): void
    {
        self::assertSame(['5', '6', '7'], LimitCheck::parseList('5, 6,7'));
        self::assertSame(['5a', '5b'], LimitCheck::parseList('5a;5b'));
        self::assertSame(['5a', '5b'], LimitCheck::parseList(' 5a  5b 5a '));
        self::assertSame([], LimitCheck::parseList(''));
        self::assertSame([], LimitCheck::parseList(' , ; '));
    }

    public function testMessagesUndHasHard(): void
    {
        $violations = [
            ['code' => LimitCheck::CAPACITY, 'message' => 'Voll.', 'hard' => false],
            ['code' => LimitCheck::EXCLUSION, 'message' => 'Gesperrt.', 'hard' => true],
        ];

        self::assertSame('Voll. Gesperrt.', LimitCheck::messages($violations));
        self::assertTrue(LimitCheck::hasHard($violations));
        self::assertFalse(LimitCheck::hasHard([$violations[0]]));
        self::assertFalse(LimitCheck::hasHard([]));
    }
}
