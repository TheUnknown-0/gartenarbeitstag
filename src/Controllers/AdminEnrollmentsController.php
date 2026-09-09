<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\DayQueries;
use App\Services\EnrollmentService;
use App\Services\LimitCheck;
use App\Services\LimitViolation;

/**
 * Einschreibungen aus Sicht der Orga: Übersicht, Anlegen, Umbuchen, Löschen,
 * Nachrücken. Alle Aktionen beziehen sich auf den aktiven Aktionstag.
 *
 * Übersteuern weicher Limits: nur mit EINSCHREIBUNGEN_UEBERSTEUERN, nach
 * expliziter Bestätigung (override=1) und mit Begründung (override_note).
 * Harte Verstöße (Ausschlusskriterium, Zeitkonflikt, …) bleiben immer gesperrt.
 */
final class AdminEnrollmentsController extends Controller
{
    private const STATUS_LABELS = ['assigned' => 'fest', 'waitlist' => 'Warteliste', 'wish' => 'Wunsch'];

    // =====================================================================
    // Übersicht
    // =====================================================================

    /** GET /admin/einschreibungen */
    public function index(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $db = $this->ctx->db;
        $dayId = (int) $day['id'];

        $filter = [
            'stand' => (int) ($_GET['stand'] ?? 0),
            'block' => (int) ($_GET['block'] ?? 0),
            'klasse' => trim((string) ($_GET['klasse'] ?? '')),
            'stufe' => (int) ($_GET['stufe'] ?? 0),
            'status' => (string) ($_GET['status'] ?? 'assigned'),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        if (!in_array($filter['status'], ['assigned', 'waitlist', 'wish', 'alle'], true)) {
            $filter['status'] = 'assigned';
        }

        $rows = DayQueries::filteredEnrollments($db, $dayId, $filter);

        $stats = $db->fetchOne(
            "SELECT SUM(status = 'assigned') AS assigned, SUM(status = 'waitlist') AS waitlist, SUM(status = 'wish') AS wish
             FROM enrollments WHERE garden_day_id = ?",
            [$dayId],
        ) ?? [];
        $stats['under'] = count(DayQueries::underEnrolled($db, $day));
        $stats['students'] = (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1");

        return $this->render('pages/enrollments/index', [
            'title' => 'Einschreibungen',
            'day' => $day,
            'rows' => $rows,
            'stats' => $stats,
            'filter' => $filter,
            'stations' => DayQueries::stationsOf($db, $dayId),
            'blocks' => DayQueries::blocksOf($db, $dayId),
            'classes' => DayQueries::classesOf($db),
            'grades' => DayQueries::gradesOf($db),
            'statusLabels' => self::STATUS_LABELS,
            'canEdit' => $this->ctx->auth->can(P::EINSCHREIBUNGEN_BEARBEITEN),
        ]);
    }

    /** GET /admin/einschreibungen/offen */
    public function open(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $db = $this->ctx->db;
        $class = trim((string) ($_GET['klasse'] ?? ''));
        $grade = (int) ($_GET['stufe'] ?? 0);

        $rows = DayQueries::underEnrolled($db, $day, $class !== '' ? $class : null, $grade > 0 ? $grade : null);
        $blocks = DayQueries::blocksOf($db, (int) $day['id']);

        return $this->render('pages/enrollments/open', [
            'title' => 'Offene Einschreibungen',
            'day' => $day,
            'rows' => $rows,
            'class' => $class,
            'grade' => $grade,
            'classes' => DayQueries::classesOf($db),
            'grades' => DayQueries::gradesOf($db),
            'minBlocks' => min((int) $day['min_blocks_per_student'], count($blocks)),
            'canEdit' => $this->ctx->auth->can(P::EINSCHREIBUNGEN_BEARBEITEN),
        ]);
    }

    // =====================================================================
    // Anlegen
    // =====================================================================

    /** GET /admin/einschreibungen/neu */
    public function create(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $day = $this->ctx->requireActiveDay();

        return $this->renderCreateForm($day, [
            'user_id' => (int) ($_GET['user'] ?? 0),
            'station_id' => (int) ($_GET['stand'] ?? 0),
            'time_block_id' => (int) ($_GET['block'] ?? 0),
            'status' => 'assigned',
        ]);
    }

    /** POST /admin/einschreibungen/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();

        $input = [
            'user_id' => (int) ($_POST['user_id'] ?? 0),
            'station_id' => (int) ($_POST['station_id'] ?? 0),
            'time_block_id' => (int) ($_POST['time_block_id'] ?? 0),
            'status' => in_array($_POST['status'] ?? '', ['assigned', 'waitlist'], true) ? (string) $_POST['status'] : 'assigned',
            'override' => (int) ($_POST['override'] ?? 0) === 1,
            'override_note' => trim((string) ($_POST['override_note'] ?? '')),
        ];

        if ($input['user_id'] <= 0 || $input['station_id'] <= 0 || $input['time_block_id'] <= 0) {
            $this->flash('error', 'Bitte Schüler:in, Stand und Zeitblock auswählen.');

            return $this->renderCreateForm($day, $input);
        }

        [$override, $note, $error] = $this->overrideInput($input);
        if ($error !== null) {
            $this->flash('error', $error);

            return $this->renderCreateForm($day, $input);
        }

        try {
            $result = $this->enrollments()->create($day, $input['user_id'], $input['station_id'], $input['time_block_id'], [
                'status' => $input['status'],
                'source' => 'orga',
                'created_by' => $this->ctx->auth->id(),
                'override' => $override,
                'override_note' => $note,
            ]);
        } catch (LimitViolation $e) {
            return $this->renderCreateForm($day, $input, $e);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->renderCreateForm($day, $input);
        }

        $this->flash(
            $result['violations'] !== [] ? 'warning' : 'success',
            'Einschreibung angelegt (' . self::STATUS_LABELS[$result['status']] . ').'
            . ($result['violations'] !== [] ? ' Limits wurden übersteuert: ' . LimitCheck::messages($result['violations']) : ''),
        );
        $this->redirect($this->ctx->url('/admin/einschreibungen') . '?stand=' . $input['station_id'] . '&block=' . $input['time_block_id'] . '&status=' . $result['status']);
    }

    /** GET /api/einschreibungen/pruefen?user=&stand=&block= — Live-Vorschau der Verstöße */
    public function check(array $params): array
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->jsonError('Kein aktiver Aktionstag.', 404);
        }

        $userId = (int) ($_GET['user'] ?? 0);
        $stationId = (int) ($_GET['stand'] ?? 0);
        $blockId = (int) ($_GET['block'] ?? 0);
        $ignore = (int) ($_GET['ignore'] ?? 0);
        if ($userId <= 0 || $stationId <= 0 || $blockId <= 0) {
            return ['success' => true, 'violations' => [], 'incomplete' => true];
        }

        $db = $this->ctx->db;
        $student = $db->fetchOne("SELECT * FROM users WHERE id = ? AND role = 'student'", [$userId]);
        $station = $db->fetchOne('SELECT * FROM stations WHERE id = ?', [$stationId]);
        if ($student === null || $station === null) {
            return $this->jsonError('Schüler:in oder Stand nicht gefunden.', 404);
        }

        $violations = (new LimitCheck($db))->check($student, $station, $blockId, $day, 'assigned', ['ignore_enrollment_id' => $ignore]);
        $counts = (new LimitCheck($db))->counts($stationId, $blockId, $ignore);
        $capacity = $db->fetchValue('SELECT capacity FROM station_blocks WHERE station_id = ? AND time_block_id = ?', [$stationId, $blockId]);

        return [
            'success' => true,
            'violations' => $violations,
            'hard' => LimitCheck::hasHard($violations),
            'occupancy' => $capacity !== null ? $counts['total'] . '/' . (int) $capacity : null,
        ];
    }

    // =====================================================================
    // Umbuchen
    // =====================================================================

    /** GET /admin/einschreibungen/{id}/umbuchen */
    public function rebookForm(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $day = $this->ctx->requireActiveDay();
        $enrollment = $this->findOrFail((int) ($params['id'] ?? 0), $day);

        return $this->renderRebookForm($day, $enrollment, [
            'station_id' => (int) $enrollment['station_id'],
            'time_block_id' => (int) $enrollment['time_block_id'],
        ]);
    }

    /** POST /admin/einschreibungen/{id}/umbuchen */
    public function rebook(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        $enrollment = $this->findOrFail((int) ($params['id'] ?? 0), $day);

        $input = [
            'station_id' => (int) ($_POST['station_id'] ?? 0),
            'time_block_id' => (int) ($_POST['time_block_id'] ?? 0),
            'override' => (int) ($_POST['override'] ?? 0) === 1,
            'override_note' => trim((string) ($_POST['override_note'] ?? '')),
        ];
        if ($input['station_id'] <= 0 || $input['time_block_id'] <= 0) {
            $this->flash('error', 'Bitte Stand und Zeitblock auswählen.');

            return $this->renderRebookForm($day, $enrollment, $input);
        }
        if ($input['station_id'] === (int) $enrollment['station_id'] && $input['time_block_id'] === (int) $enrollment['time_block_id'] && $enrollment['status'] === 'assigned') {
            $this->flash('info', 'Stand und Zeitblock sind unverändert.');

            return $this->renderRebookForm($day, $enrollment, $input);
        }

        [$override, $note, $error] = $this->overrideInput($input);
        if ($error !== null) {
            $this->flash('error', $error);

            return $this->renderRebookForm($day, $enrollment, $input);
        }

        try {
            $result = $this->enrollments()->rebook((int) $enrollment['id'], $day, $input['station_id'], $input['time_block_id'], [
                'override' => $override,
                'override_note' => $note,
                'created_by' => $this->ctx->auth->id(),
            ]);
        } catch (LimitViolation $e) {
            return $this->renderRebookForm($day, $enrollment, $input, $e);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());

            return $this->renderRebookForm($day, $enrollment, $input);
        }

        $this->flash(
            $result['violations'] !== [] ? 'warning' : 'success',
            'Einschreibung umgebucht.' . ($result['violations'] !== [] ? ' Limits wurden übersteuert: ' . LimitCheck::messages($result['violations']) : ''),
        );
        $this->redirect($this->ctx->url('/admin/einschreibungen') . '?stand=' . $input['station_id'] . '&block=' . $input['time_block_id']);
    }

    // =====================================================================
    // Löschen / Nachrücken
    // =====================================================================

    /** POST /admin/einschreibungen/{id}/loeschen */
    public function delete(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        $enrollment = $this->findOrFail((int) ($params['id'] ?? 0), $day);

        $promoted = $this->enrollments()->delete((int) $enrollment['id'], $day, 'Orga: ' . (string) $this->ctx->auth->user()['username']);

        $message = 'Einschreibung von ' . DayQueries::personName($enrollment) . ' gelöscht.';
        if ($promoted !== null) {
            $next = $this->enrollments()->find($promoted);
            $message .= ' Von der Warteliste nachgerückt: ' . ($next !== null ? DayQueries::personName($next) . ' (' . $next['class'] . ')' : '#' . $promoted) . '.';
        }
        $this->flash('success', $message);
        $this->redirect($this->backUrl($enrollment));
    }

    /** POST /admin/einschreibungen/{id}/nachruecken */
    public function promote(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        $enrollment = $this->findOrFail((int) ($params['id'] ?? 0), $day);

        if ($enrollment['status'] !== 'waitlist') {
            $this->flash('error', 'Nur Wartelisten-Einträge können nachrücken.');
            $this->redirect($this->backUrl($enrollment));
        }

        $db = $this->ctx->db;
        $student = $db->fetchOne('SELECT * FROM users WHERE id = ?', [(int) $enrollment['user_id']]);
        $station = $db->fetchOne('SELECT * FROM stations WHERE id = ?', [(int) $enrollment['station_id']]);
        if ($student === null || $station === null) {
            throw new HttpException(404);
        }

        $violations = (new LimitCheck($db))->check($student, $station, (int) $enrollment['time_block_id'], $day, 'assigned', [
            'ignore_enrollment_id' => (int) $enrollment['id'],
        ]);

        $input = [
            'override' => (int) ($_POST['override'] ?? 0) === 1,
            'override_note' => trim((string) ($_POST['override_note'] ?? '')),
        ];
        [$override, $note, $error] = $this->overrideInput($input);
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($this->backUrl($enrollment));
        }

        if ($violations !== []) {
            if (LimitCheck::hasHard($violations) || !$override) {
                // Bestätigungsseite: zeigt Verstöße, bietet Übersteuern an
                return $this->render('pages/enrollments/promote', [
                    'title' => 'Nachrücken bestätigen',
                    'day' => $day,
                    'enrollment' => $enrollment,
                    'violations' => $violations,
                    'overridable' => !LimitCheck::hasHard($violations) && $this->ctx->auth->can(P::EINSCHREIBUNGEN_UEBERSTEUERN),
                    'back' => $this->backUrl($enrollment),
                ]);
            }
        }

        $overridden = $override && $violations !== [];
        $db->run(
            "UPDATE enrollments SET status = 'assigned', source = 'orga', created_by = ?, override_note = ? WHERE id = ?",
            [$this->ctx->auth->id(), $overridden ? $note : null, (int) $enrollment['id']],
        );
        $this->ctx->audit->log(
            'enrollment.promote',
            $overridden ? 'warning' : 'info',
            DayQueries::personName($enrollment) . ' (' . $enrollment['class'] . ') → ' . $enrollment['name'] . ' [' . $enrollment['block_name'] . '] manuell von Warteliste nachgerückt'
            . ($overridden ? ' | ÜBERSTEUERT: ' . LimitCheck::messages($violations) . ' | Begründung: ' . $note : ''),
        );

        $this->flash($overridden ? 'warning' : 'success', DayQueries::personName($enrollment) . ' ist jetzt fest eingeschrieben.');
        $this->redirect($this->backUrl($enrollment));
    }

    // =====================================================================
    // Helfer
    // =====================================================================

    private function enrollments(): EnrollmentService
    {
        $db = $this->ctx->db;

        return new EnrollmentService($db, new LimitCheck($db), $this->ctx->audit);
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $id, array $day): array
    {
        $enrollment = $id > 0 ? $this->enrollments()->find($id) : null;
        if ($enrollment === null || (int) $enrollment['garden_day_id'] !== (int) $day['id']) {
            throw new HttpException(404, 'Einschreibung nicht gefunden.');
        }

        return $enrollment;
    }

    /**
     * Wertet die Override-Felder aus.
     *
     * @return array{0: bool, 1: ?string, 2: ?string} [override, note, fehler]
     */
    private function overrideInput(array $input): array
    {
        if (!$input['override']) {
            return [false, null, null];
        }
        if (!$this->ctx->auth->can(P::EINSCHREIBUNGEN_UEBERSTEUERN)) {
            return [false, null, 'Du darfst Limits nicht übersteuern.'];
        }
        if ($input['override_note'] === '') {
            return [false, null, 'Zum Übersteuern ist eine Begründung erforderlich.'];
        }

        return [true, mb_substr($input['override_note'], 0, 255), null];
    }

    private function renderCreateForm(array $day, array $input, ?LimitViolation $violation = null): string
    {
        $db = $this->ctx->db;
        $dayId = (int) $day['id'];

        return $this->render('pages/enrollments/form', [
            'title' => 'Einschreibung anlegen',
            'pageScripts' => ['enrollments.js'],
            'day' => $day,
            'input' => $input,
            'students' => DayQueries::studentsOf($db),
            'stations' => DayQueries::stationsOf($db, $dayId),
            'blocks' => DayQueries::blocksOf($db, $dayId),
            'stationBlocks' => DayQueries::stationBlockMap($db, $dayId),
            'violations' => $violation?->violations() ?? [],
            'overridable' => $violation !== null && $violation->overridable() && $this->ctx->auth->can(P::EINSCHREIBUNGEN_UEBERSTEUERN),
            'waitlistEnabled' => (int) $day['waitlist_enabled'] === 1,
        ]);
    }

    private function renderRebookForm(array $day, array $enrollment, array $input, ?LimitViolation $violation = null): string
    {
        $db = $this->ctx->db;
        $dayId = (int) $day['id'];

        return $this->render('pages/enrollments/rebook', [
            'title' => 'Einschreibung umbuchen',
            'pageScripts' => ['enrollments.js'],
            'day' => $day,
            'enrollment' => $enrollment,
            'input' => $input,
            'stations' => DayQueries::stationsOf($db, $dayId),
            'blocks' => DayQueries::blocksOf($db, $dayId),
            'stationBlocks' => DayQueries::stationBlockMap($db, $dayId),
            'violations' => $violation?->violations() ?? [],
            'overridable' => $violation !== null && $violation->overridable() && $this->ctx->auth->can(P::EINSCHREIBUNGEN_UEBERSTEUERN),
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    private function backUrl(array $enrollment): string
    {
        $back = (string) ($_POST['back'] ?? '');
        if ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $back;
        }

        return $this->ctx->url('/admin/einschreibungen') . '?stand=' . (int) $enrollment['station_id'] . '&block=' . (int) $enrollment['time_block_id'] . '&status=alle';
    }
}
