<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\AttendanceService;
use App\Services\DayQueries;
use RuntimeException;

/**
 * Anwesenheit (Orga-Sicht): Übersicht, Abhak-Liste je Stand/Block oder Klasse/Block, JSON-API.
 */
final class AttendanceController extends Controller
{
    /** GET /admin/anwesenheit?block=&stand=|klasse= */
    public function index(array $params): string
    {
        $this->requirePermission(P::ANWESENHEIT_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $db = $this->ctx->db;
        $dayId = (int) $day['id'];
        $service = new AttendanceService($db);

        $blockId = (int) ($_GET['block'] ?? 0);
        $stationId = (int) ($_GET['stand'] ?? 0);
        $class = trim((string) ($_GET['klasse'] ?? ''));

        $blocks = DayQueries::blocksOf($db, $dayId);
        $stations = DayQueries::stationsOf($db, $dayId, true);
        $classes = DayQueries::classesOf($db);
        $grades = DayQueries::gradesOf($db);
        $grade = (int) ($_GET['stufe'] ?? 0);

        $block = null;
        foreach ($blocks as $b) {
            if ((int) $b['id'] === $blockId) {
                $block = $b;
            }
        }
        $station = null;
        foreach ($stations as $s) {
            if ((int) $s['id'] === $stationId) {
                $station = $s;
            }
        }
        if ($class !== '' && !in_array($class, $classes, true)) {
            $class = '';
        }

        $roster = null;
        $mode = null;
        if ($block !== null && $station !== null) {
            $mode = 'station';
            $roster = $service->roster((int) $station['id'], (int) $block['id']);
        } elseif ($block !== null && $class !== '') {
            $mode = 'class';
            $roster = $service->rosterByClass($dayId, (int) $block['id'], $class);
        }

        $summary = $roster === null ? $service->summary($dayId) : [];

        return $this->render('pages/attendance/index', [
            'title' => 'Anwesenheit',
            'day' => $day,
            'blocks' => $blocks,
            'stations' => $stations,
            'classes' => $classes,
            'grades' => $grades,
            'grade' => $grade,
            'block' => $block,
            'station' => $station,
            'class' => $class,
            'mode' => $mode,
            'roster' => $roster,
            'summary' => $summary,
            'canEdit' => $this->ctx->auth->can(P::ANWESENHEIT_BEARBEITEN),
            'pageScripts' => $roster !== null ? ['attendance.js'] : [],
        ]);
    }

    /**
     * POST /api/anwesenheit  JSON {enrollment_id, present, note?}
     *
     * @return array{success: bool, present?: bool, note?: string, marked_at?: string, error?: string}
     */
    public function mark(array $params): array
    {
        // Standleitungen dürfen die Liste ihres eigenen Standes immer abhaken,
        // auch ohne das allgemeine Recht ANWESENHEIT_BEARBEITEN (siehe unten).
        $this->requireLogin();
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        $input = $this->jsonInput();

        $enrollmentId = (int) ($input['enrollment_id'] ?? 0);
        if ($enrollmentId <= 0) {
            return $this->jsonError('enrollment_id fehlt.');
        }
        $present = filter_var($input['present'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $note = array_key_exists('note', $input) && $input['note'] !== null ? (string) $input['note'] : null;

        $enrollment = $this->ctx->db->fetchOne('SELECT id, garden_day_id, station_id FROM enrollments WHERE id = ?', [$enrollmentId]);
        if ($enrollment === null || (int) $enrollment['garden_day_id'] !== (int) $day['id']) {
            return $this->jsonError('Einschreibung nicht gefunden.', 404);
        }
        if (!$this->ctx->auth->can(P::ANWESENHEIT_BEARBEITEN) && !$this->ctx->auth->leadsStation((int) $enrollment['station_id'])) {
            return $this->jsonError('Keine Berechtigung für diesen Stand.', 403);
        }

        try {
            $result = (new AttendanceService($this->ctx->db))->mark($enrollmentId, $present, $note, $this->ctx->auth->id());
        } catch (RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 409);
        }

        return [
            'success' => true,
            'enrollment_id' => $result['enrollment_id'],
            'present' => $result['present'],
            'note' => $result['note'],
            'marked_at' => $result['marked_at'],
            'marked_at_label' => $result['marked_at'] !== '' ? format_datetime($result['marked_at']) : '',
        ];
    }

    /** POST /admin/anwesenheit/speichern (Fallback ohne JavaScript) */
    public function save(array $params): string
    {
        $this->requirePermission(P::ANWESENHEIT_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        $service = new AttendanceService($this->ctx->db);

        $blockId = (int) ($_POST['block'] ?? 0);
        $stationId = (int) ($_POST['stand'] ?? 0);
        $class = trim((string) ($_POST['klasse'] ?? ''));
        $presentIds = array_map('intval', (array) ($_POST['present'] ?? []));
        $allIds = array_map('intval', (array) ($_POST['ids'] ?? []));

        $blockOk = $this->ctx->db->fetchValue('SELECT COUNT(*) FROM time_blocks WHERE id = ? AND garden_day_id = ?', [$blockId, (int) $day['id']]);
        if ($blockId <= 0 || (int) $blockOk === 0) {
            throw new HttpException(400, 'Ungültiger Zeitblock.');
        }

        if ($stationId > 0) {
            $stationOk = $this->ctx->db->fetchValue('SELECT COUNT(*) FROM stations WHERE id = ? AND garden_day_id = ?', [$stationId, (int) $day['id']]);
            if ((int) $stationOk === 0) {
                throw new HttpException(400, 'Ungültiger Stand.');
            }
            $count = $service->markAll($stationId, $blockId, $presentIds, $this->ctx->auth->id());
            $query = '?block=' . $blockId . '&stand=' . $stationId;
        } elseif ($class !== '') {
            // Nur IDs übernehmen, die wirklich zu dieser Klasse/diesem Block gehören
            $valid = array_map(static fn (array $r): int => (int) $r['enrollment_id'], $service->rosterByClass((int) $day['id'], $blockId, $class));
            $count = $service->markIds(array_intersect($allIds, $valid), $presentIds, $this->ctx->auth->id());
            $query = '?block=' . $blockId . '&klasse=' . rawurlencode($class);
        } else {
            throw new HttpException(400, 'Stand oder Klasse fehlt.');
        }

        $this->ctx->audit->log('attendance.save', 'info', sprintf('Anwesenheit gespeichert (%d Einträge, Block %d, %s)', $count, $blockId, $stationId > 0 ? 'Stand ' . $stationId : 'Klasse ' . $class));
        $this->flash('success', sprintf('Anwesenheit für %d Schüler:innen gespeichert.', $count));
        $this->redirect($this->ctx->url('/admin/anwesenheit' . $query));
    }
}
