<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * EINZIGE Quelle für die Frage „Darf diese Person an diesem Stand in diesem
 * Zeitblock eingeschrieben werden?“ — von Schüler-Selbstbuchung, Orga-Formular,
 * Warteliste und automatischer Zuteilung gleichermaßen genutzt.
 *
 * Ergebnis ist eine Liste von Verstößen. Jeder Verstoß ist entweder
 *  - hart:  nie übersteuerbar (Ausschlusskriterium, Zeitkonflikt, Duplikat,
 *           inaktiver Stand) oder
 *  - weich: mit dem Recht EINSCHREIBUNGEN_UEBERSTEUERN + Bestätigung übersteuerbar
 *           (Kapazität, Klassen-/Stufenlimit, Klassen-/Stufen-Whitelist,
 *           Höchstzahl Blöcke, Anmeldefenster).
 */
final class LimitCheck
{
    public const EXCLUSION = 'exclusion';
    public const STATION_INACTIVE = 'station_inactive';
    public const NO_BLOCK = 'no_block';
    public const DUPLICATE = 'duplicate';
    public const TIME_CONFLICT = 'time_conflict';
    public const GRADE_NOT_ALLOWED = 'grade_not_allowed';
    public const CLASS_NOT_ALLOWED = 'class_not_allowed';
    public const CAPACITY = 'capacity';
    public const CLASS_LIMIT = 'class_limit';
    public const GRADE_LIMIT = 'grade_limit';
    public const MAX_BLOCKS = 'max_blocks';
    public const WINDOW_CLOSED = 'window_closed';
    public const DAY_NOT_ACTIVE = 'day_not_active';

    /** Verstöße, die niemals übersteuert werden können. */
    public const HARD = [
        self::EXCLUSION,
        self::STATION_INACTIVE,
        self::NO_BLOCK,
        self::DUPLICATE,
        self::TIME_CONFLICT,
        self::DAY_NOT_ACTIVE,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Prüft eine geplante Einschreibung.
     *
     * @param array<string, mixed> $student   Zeile aus users (id, class, grade)
     * @param array<string, mixed> $station   Zeile aus stations
     * @param array<string, mixed> $day       Zeile aus garden_days
     * @param string $status                  assigned | waitlist | wish
     * @param array{ignore_enrollment_id?: int, self_service?: bool, now?: string} $options
     *        ignore_enrollment_id: beim Umbuchen die bestehende Einschreibung nicht mitzählen
     *        self_service: Schüler bucht selbst → Anmeldefenster prüfen
     * @return list<array{code: string, message: string, hard: bool}>
     */
    public function check(array $student, array $station, int $timeBlockId, array $day, string $status = 'assigned', array $options = []): array
    {
        $violations = [];
        $add = static function (string $code, string $message) use (&$violations): void {
            $violations[] = ['code' => $code, 'message' => $message, 'hard' => in_array($code, self::HARD, true)];
        };

        $studentId = (int) $student['id'];
        $stationId = (int) $station['id'];
        $ignoreId = isset($options['ignore_enrollment_id']) ? (int) $options['ignore_enrollment_id'] : 0;
        $selfService = (bool) ($options['self_service'] ?? false);

        // --- Rahmen: Aktionstag, Stand, Block ---------------------------------
        if (($day['status'] ?? '') !== 'active') {
            $add(self::DAY_NOT_ACTIVE, 'Der Aktionstag ist nicht aktiv.');
        }
        if ((int) ($station['is_active'] ?? 1) !== 1) {
            $add(self::STATION_INACTIVE, 'Dieser Stand ist deaktiviert.');
        }
        if ((int) $station['garden_day_id'] !== (int) $day['id']) {
            $add(self::NO_BLOCK, 'Der Stand gehört nicht zu diesem Aktionstag.');
        }

        $stationBlock = $this->db->fetchOne(
            'SELECT sb.*, tb.name AS block_name FROM station_blocks sb
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             WHERE sb.station_id = ? AND sb.time_block_id = ?',
            [$stationId, $timeBlockId],
        );
        if ($stationBlock === null) {
            $add(self::NO_BLOCK, 'Dieser Stand wird in diesem Zeitblock nicht angeboten.');

            return $violations;
        }

        if ($selfService) {
            $now = $options['now'] ?? date('Y-m-d H:i:s');
            if (!empty($day['registration_start']) && $now < $day['registration_start']) {
                $add(self::WINDOW_CLOSED, 'Die Einschreibung hat noch nicht begonnen.');
            }
            if (!empty($day['registration_end']) && $now > $day['registration_end']) {
                $add(self::WINDOW_CLOSED, 'Die Einschreibung ist bereits beendet.');
            }
        }

        // --- Ausschlusskriterien (hart) -----------------------------------------
        $matches = $this->db->fetchAll(
            'SELECT ec.name FROM station_exclusions se
             JOIN user_exclusions ue ON ue.criterion_id = se.criterion_id AND ue.user_id = ?
             JOIN exclusion_criteria ec ON ec.id = se.criterion_id
             WHERE se.station_id = ? AND ec.is_active = 1',
            [$studentId, $stationId],
        );
        if ($matches !== []) {
            $add(self::EXCLUSION, 'Ausschlusskriterium: ' . implode(', ', array_column($matches, 'name')));
        }

        // --- Duplikat / Zeitkonflikt (hart) --------------------------------------
        $duplicate = $this->db->fetchValue(
            'SELECT 1 FROM enrollments WHERE user_id = ? AND station_id = ? AND time_block_id = ? AND id <> ? LIMIT 1',
            [$studentId, $stationId, $timeBlockId, $ignoreId],
        );
        if ($duplicate !== null) {
            $add(self::DUPLICATE, 'Für diesen Stand und Zeitblock existiert bereits ein Eintrag.');
        }

        if ($status === 'assigned') {
            $conflict = $this->db->fetchOne(
                "SELECT s.name FROM enrollments e JOIN stations s ON s.id = e.station_id
                 WHERE e.user_id = ? AND e.time_block_id = ? AND e.status = 'assigned' AND e.id <> ? LIMIT 1",
                [$studentId, $timeBlockId, $ignoreId],
            );
            if ($conflict !== null) {
                $add(self::TIME_CONFLICT, 'Im Zeitblock „' . $stationBlock['block_name'] . '“ besteht bereits eine feste Einschreibung (' . $conflict['name'] . ').');
            }
        }

        // --- Whitelist Stufen/Klassen (weich) -----------------------------------
        $allowedGrades = self::parseList((string) ($station['allowed_grades'] ?? ''));
        $allowedClasses = self::parseList((string) ($station['allowed_classes'] ?? ''));
        $grade = $student['grade'] !== null ? (string) (int) $student['grade'] : '';
        $class = trim((string) ($student['class'] ?? ''));

        if ($allowedGrades !== [] && !in_array($grade, $allowedGrades, true)) {
            $add(self::GRADE_NOT_ALLOWED, 'Dieser Stand ist nur für die Jahrgangsstufen ' . implode(', ', $allowedGrades) . ' geöffnet.');
        }
        if ($allowedClasses !== [] && !in_array(mb_strtolower($class), array_map('mb_strtolower', $allowedClasses), true)) {
            $add(self::CLASS_NOT_ALLOWED, 'Dieser Stand ist nur für die Klassen ' . implode(', ', $allowedClasses) . ' geöffnet.');
        }

        // Wünsche zählen nicht gegen Kapazität und Limits
        if ($status !== 'assigned') {
            return $violations;
        }

        // --- Kapazität & Klassen-/Stufenlimits (weich) --------------------------
        $counts = $this->counts($stationId, $timeBlockId, $ignoreId);
        $capacity = (int) $stationBlock['capacity'];
        if ($counts['total'] >= $capacity) {
            $add(self::CAPACITY, 'Der Stand ist in diesem Zeitblock voll (' . $counts['total'] . '/' . $capacity . ').');
        }

        $maxPerClass = $station['max_per_class'] !== null ? (int) $station['max_per_class'] : null;
        if ($maxPerClass !== null && $class !== '') {
            $inClass = $counts['classes'][mb_strtolower($class)] ?? 0;
            if ($inClass >= $maxPerClass) {
                $add(self::CLASS_LIMIT, 'Aus der Klasse ' . $class . ' sind bereits ' . $inClass . ' von max. ' . $maxPerClass . ' eingeschrieben.');
            }
        }
        $maxPerGrade = $station['max_per_grade'] !== null ? (int) $station['max_per_grade'] : null;
        if ($maxPerGrade !== null && $grade !== '') {
            $inGrade = $counts['grades'][$grade] ?? 0;
            if ($inGrade >= $maxPerGrade) {
                $add(self::GRADE_LIMIT, 'Aus der Jahrgangsstufe ' . $grade . ' sind bereits ' . $inGrade . ' von max. ' . $maxPerGrade . ' eingeschrieben.');
            }
        }

        // --- Höchstzahl Blöcke je Schüler:in (weich) -----------------------------
        $maxBlocks = $day['max_blocks_per_student'] !== null ? (int) $day['max_blocks_per_student'] : null;
        if ($maxBlocks !== null) {
            $assignedBlocks = (int) $this->db->fetchValue(
                "SELECT COUNT(DISTINCT time_block_id) FROM enrollments
                 WHERE user_id = ? AND garden_day_id = ? AND status = 'assigned' AND id <> ? AND time_block_id <> ?",
                [$studentId, (int) $day['id'], $ignoreId, $timeBlockId],
            );
            if ($assignedBlocks >= $maxBlocks) {
                $add(self::MAX_BLOCKS, 'Es sind höchstens ' . $maxBlocks . ' Zeitblöcke je Schüler:in erlaubt.');
            }
        }

        return $violations;
    }

    /**
     * Belegung eines Stand-Blocks: gesamt sowie je Klasse und Stufe
     * (nur feste Einschreibungen).
     *
     * @return array{total: int, classes: array<string, int>, grades: array<string, int>}
     */
    public function counts(int $stationId, int $timeBlockId, int $ignoreEnrollmentId = 0): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.class, u.grade FROM enrollments e JOIN users u ON u.id = e.user_id
             WHERE e.station_id = ? AND e.time_block_id = ? AND e.status = 'assigned' AND e.id <> ?",
            [$stationId, $timeBlockId, $ignoreEnrollmentId],
        );

        $classes = [];
        $grades = [];
        foreach ($rows as $row) {
            $class = mb_strtolower(trim((string) ($row['class'] ?? '')));
            if ($class !== '') {
                $classes[$class] = ($classes[$class] ?? 0) + 1;
            }
            if ($row['grade'] !== null) {
                $grade = (string) (int) $row['grade'];
                $grades[$grade] = ($grades[$grade] ?? 0) + 1;
            }
        }

        return ['total' => count($rows), 'classes' => $classes, 'grades' => $grades];
    }

    /** @param list<array{code: string, message: string, hard: bool}> $violations */
    public static function hasHard(array $violations): bool
    {
        foreach ($violations as $violation) {
            if ($violation['hard']) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{code: string, message: string, hard: bool}> $violations */
    public static function messages(array $violations): string
    {
        return implode(' ', array_column($violations, 'message'));
    }

    /**
     * Kommagetrennte Liste (z. B. "5, 6,7" oder "5a,5b") in eine bereinigte
     * Liste umwandeln.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $items = [];
        foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
