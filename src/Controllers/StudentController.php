<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\EnrollmentService;
use App\Services\LimitCheck;
use App\Services\LimitViolation;
use App\Services\Pdf;

/**
 * Schüler:innen-Bereich: Übersicht, Stände, Einschreibung (Direkt- und
 * Wunschmodus), Tagesplan. Übersicht/Einschreibung/Plan nur für die Rolle
 * „student“; die Standübersicht ist für alle Angemeldeten lesbar.
 *
 * Sensibel: Ausschlusskriterien werden hier NIE benannt — gesperrte Stände
 * erscheinen nur als „für dich nicht verfügbar“.
 */
final class StudentController extends Controller
{
    // ---------- Übersicht ----------

    /** GET /uebersicht */
    public function overview(array $params): string
    {
        $student = $this->requireStudent();
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->noDay();
        }

        $blocks = $this->blocks((int) $day['id']);
        $mine = $this->myEnrollments((int) $day['id'], (int) $student['id']);

        return $this->render('pages/student/overview', [
            'title' => 'Übersicht',
            'student' => $student,
            'day' => $day,
            'blocks' => $blocks,
            'mine' => $mine,
            'assignedBlocks' => $this->assignedBlockCount($mine),
            'window' => $this->windowState($day),
        ]);
    }

    // ---------- Stände ----------

    /**
     * GET /staende — Standübersicht. Auch für Lehrkräfte/Orga lesbar
     * (Sidebar „Alle Stände“); Verfügbarkeit und Buttons nur für Schüler:innen.
     */
    public function stations(array $params): string
    {
        $user = $this->requireLogin();
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->noDay();
        }

        $blocks = $this->blocks((int) $day['id']);
        $stations = $this->stationsOfDay((int) $day['id']);
        $availability = $this->availability($user, $day, $stations, $blocks);

        return $this->render('pages/student/stations', [
            'title' => 'Stände',
            'day' => $day,
            'blocks' => $blocks,
            'stations' => $stations,
            'availability' => $availability,
            'isStudent' => $user['role'] === 'student',
        ]);
    }

    /** GET /staende/{id} */
    public function station(array $params): string
    {
        $user = $this->requireLogin();
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->noDay();
        }

        $stationId = (int) ($params['id'] ?? 0);
        $stations = $this->stationsOfDay((int) $day['id'], $stationId);
        if ($stations === []) {
            throw new HttpException(404, 'Diesen Stand gibt es nicht.');
        }
        $station = $stations[$stationId];
        $blocks = $this->blocks((int) $day['id']);
        $availability = $this->availability($user, $day, $stations, $blocks)[$stationId];
        $leaders = $this->ctx->db->fetchAll(
            'SELECT u.firstname, u.lastname FROM station_leaders sl JOIN users u ON u.id = sl.user_id WHERE sl.station_id = ? ORDER BY u.lastname, u.firstname',
            [$stationId],
        );
        $isStudent = $user['role'] === 'student';
        $mine = $isStudent ? $this->myEnrollments((int) $day['id'], (int) $user['id']) : [];

        return $this->render('pages/student/station', [
            'title' => $station['name'],
            'day' => $day,
            'blocks' => $blocks,
            'station' => $station,
            'availability' => $availability,
            'leaders' => $leaders,
            'mine' => $mine,
            'isStudent' => $isStudent,
        ]);
    }

    // ---------- Einschreibung ----------

    /** GET /einschreibung */
    public function enrollment(array $params): string
    {
        $student = $this->requireStudent();
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->noDay();
        }

        $blocks = $this->blocks((int) $day['id']);
        $stations = $this->stationsOfDay((int) $day['id']);
        $availability = $this->availability($student, $day, $stations, $blocks);
        $mine = $this->myEnrollments((int) $day['id'], (int) $student['id']);

        return $this->render('pages/student/enrollment', [
            'title' => 'Einschreibung',
            'pageScripts' => ['student.js'],
            'day' => $day,
            'blocks' => $blocks,
            'stations' => $stations,
            'availability' => $availability,
            'mine' => $mine,
            'assignedBlocks' => $this->assignedBlockCount($mine),
            'selfService' => $this->ctx->settings->getBool('student_self_service', true),
            'window' => $this->windowState($day),
            'assignmentDone' => !empty($day['assignment_done_at']),
        ]);
    }

    /** POST /einschreibung — Direktmodus: fest einschreiben oder Warteliste */
    public function enroll(array $params): string
    {
        $this->requireCsrf();
        $student = $this->requireStudent();
        $day = $this->ctx->requireActiveDay();
        $this->requireSelfService($day, 'direct');

        $stationId = (int) ($_POST['station_id'] ?? 0);
        $blockId = (int) ($_POST['time_block_id'] ?? 0);
        $stations = $this->stationsOfDay((int) $day['id'], $stationId);
        if ($stations === [] || $stationId <= 0 || $blockId <= 0) {
            $this->flash('error', 'Dieser Stand ist nicht verfügbar.');
            $this->redirect($this->ctx->url('/einschreibung'));
        }

        try {
            $result = $this->enrollments()->create($day, (int) $student['id'], $stationId, $blockId, [
                'self_service' => true,
                'source' => 'self',
                'auto_waitlist' => true,
            ]);
            $this->flash(
                $result['status'] === 'waitlist' ? 'warning' : 'success',
                $result['status'] === 'waitlist'
                    ? 'Der Stand ist voll — du stehst jetzt auf der Warteliste. Wird ein Platz frei, rückst du automatisch nach.'
                    : 'Du bist eingeschrieben bei „' . $stations[$stationId]['name'] . '“.',
            );
        } catch (LimitViolation $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirect($this->ctx->url('/einschreibung') . '#block-' . $blockId);
    }

    /** POST /einschreibung/{id}/austragen */
    public function withdraw(array $params): string
    {
        $this->requireCsrf();
        $student = $this->requireStudent();
        $day = $this->ctx->requireActiveDay();
        $this->requireSelfService($day, 'direct');

        $enrollmentId = (int) ($params['id'] ?? 0);
        $enrollment = $this->ctx->db->fetchOne(
            "SELECT * FROM enrollments WHERE id = ? AND user_id = ? AND garden_day_id = ? AND status IN ('assigned', 'waitlist')",
            [$enrollmentId, (int) $student['id'], (int) $day['id']],
        );
        if ($enrollment === null) {
            throw new HttpException(404, 'Diese Einschreibung gibt es nicht.');
        }
        if ($this->windowState($day)['state'] !== 'open') {
            $this->flash('error', 'Außerhalb des Anmeldezeitraums kannst du dich nicht selbst austragen — wende dich an deine Lehrkraft.');
            $this->redirect($this->ctx->url('/einschreibung'));
        }

        $this->enrollments()->delete($enrollmentId, $day, 'Schüler:in selbst');
        $this->flash('success', 'Du hast dich ausgetragen.');

        $this->redirect($this->ctx->url('/einschreibung') . '#block-' . (int) $enrollment['time_block_id']);
    }

    /** POST /einschreibung/wuensche — Wunschmodus: Wünsche eines Blocks ersetzen */
    public function wishes(array $params): string
    {
        $this->requireCsrf();
        $student = $this->requireStudent();
        $day = $this->ctx->requireActiveDay();
        $this->requireSelfService($day, 'wishlist');

        if (!empty($day['assignment_done_at'])) {
            $this->flash('error', 'Die Zuteilung ist bereits erfolgt — Wünsche können nicht mehr geändert werden.');
            $this->redirect($this->ctx->url('/einschreibung'));
        }

        $blockId = (int) ($_POST['time_block_id'] ?? 0);
        $blocks = $this->blocks((int) $day['id']);
        if (!isset($blocks[$blockId])) {
            throw new HttpException(404, 'Diesen Zeitblock gibt es nicht.');
        }

        // Feste Einschreibung (z. B. durch die Orga) → keine Wünsche für diesen Block
        $fixed = $this->ctx->db->fetchValue(
            "SELECT 1 FROM enrollments WHERE user_id = ? AND time_block_id = ? AND status = 'assigned' LIMIT 1",
            [(int) $student['id'], $blockId],
        );
        if ($fixed !== null) {
            $this->flash('error', 'In diesem Zeitblock bist du bereits fest eingeschrieben.');
            $this->redirect($this->ctx->url('/einschreibung') . '#block-' . $blockId);
        }

        $raw = $_POST['stations'] ?? [];
        $stationIds = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $stationIds, true)) {
                $stationIds[] = $id;
            }
        }
        $stationIds = array_slice($stationIds, 0, max(1, (int) $day['wishes_per_block']));

        $errors = $this->enrollments()->replaceWishes($day, (int) $student['id'], $blockId, $stationIds, true);
        if ($errors !== []) {
            $this->flash('error', LimitCheck::messages($errors));
        } elseif ($stationIds === []) {
            $this->flash('info', 'Deine Wünsche für „' . $blocks[$blockId]['name'] . '“ wurden entfernt.');
        } else {
            $this->flash('success', 'Deine Wünsche für „' . $blocks[$blockId]['name'] . '“ sind gespeichert.');
        }

        $this->redirect($this->ctx->url('/einschreibung') . '#block-' . $blockId);
    }

    // ---------- Mein Plan ----------

    /** GET /mein-plan */
    public function plan(array $params): string
    {
        $student = $this->requireStudent();
        $day = $this->ctx->activeDay();
        if ($day === null) {
            return $this->noDay();
        }

        return $this->render('pages/student/plan', [
            'title' => 'Mein Plan',
            'pageScripts' => ['student.js'],
            'student' => $student,
            'day' => $day,
            'blocks' => $this->blocks((int) $day['id']),
            'plan' => $this->planRows((int) $day['id'], (int) $student['id']),
        ]);
    }

    /** GET /mein-plan.pdf */
    public function planPdf(array $params): string
    {
        $student = $this->requireStudent();
        $day = $this->ctx->requireActiveDay();
        $blocks = $this->blocks((int) $day['id']);
        $plan = $this->planRows((int) $day['id'], (int) $student['id']);

        $pdf = new Pdf('P', (string) $this->ctx->settings->get('school_name', ''), (string) $day['name'], 'Mein Plan');
        $pdf->AddPage();
        $pdf->heading(trim($student['firstname'] . ' ' . $student['lastname']) . ($student['class'] !== null && $student['class'] !== '' ? ' · Klasse ' . $student['class'] : ''), 13.0);
        $pdf->note('Gartenarbeitstag am ' . format_date($day['event_date']) . '. Bitte bring diesen Plan mit.');
        $pdf->Ln(2);

        $pdf->setColumns([
            ['Zeitblock', 40.0],
            ['Stand', 60.0],
            ['Ort', 45.0],
            ['Standleitung', 45.0],
        ]);
        $pdf->drawHead();
        foreach ($blocks as $block) {
            $row = $plan[(int) $block['id']] ?? null;
            $pdf->ensureSpace(8.0, true);
            $pdf->drawRow([
                $block['name'] . ' (' . substr((string) $block['start_time'], 0, 5) . '–' . substr((string) $block['end_time'], 0, 5) . ')',
                $row['station'] ?? '— (keine Einschreibung)',
                $row['location'] ?? '',
                $row['leaders'] ?? '',
            ], 7.0);
        }

        $materials = array_filter(array_map(static fn (array $r): string => trim((string) ($r['materials'] ?? '')), $plan));
        if ($materials !== []) {
            $pdf->Ln(4);
            $pdf->heading('Mitbringen / Material', 11.0);
            foreach ($plan as $row) {
                if (trim((string) ($row['materials'] ?? '')) === '') {
                    continue;
                }
                $pdf->band($row['station']);
                $pdf->note((string) $row['materials'], 9.0);
            }
        }

        $pdf->emit('Mein-Plan-' . $student['username'] . '.pdf', 'I');
    }

    // ---------- Guards ----------

    /** @return array<string, mixed> */
    private function requireStudent(): array
    {
        $user = $this->requireLogin();
        if ($user['role'] !== 'student') {
            throw new HttpException(403, 'Dieser Bereich ist nur für Schüler:innen.');
        }

        return $user;
    }

    /** Selbstbedienung erlaubt und Modus passend? Sonst Flash + Redirect. */
    private function requireSelfService(array $day, string $mode): void
    {
        if (!$this->ctx->settings->getBool('student_self_service', true)) {
            $this->flash('error', 'Die Einschreibung läuft über deine Lehrkraft.');
            $this->redirect($this->ctx->url('/einschreibung'));
        }
        if ($day['mode'] !== $mode) {
            $this->flash('error', 'Diese Aktion ist im aktuellen Anmeldemodus nicht möglich.');
            $this->redirect($this->ctx->url('/einschreibung'));
        }
    }

    private function noDay(): string
    {
        return $this->render('pages/student/no-day', ['title' => 'Kein Aktionstag']);
    }

    private function enrollments(): EnrollmentService
    {
        return new EnrollmentService($this->ctx->db, new LimitCheck($this->ctx->db), $this->ctx->audit);
    }

    // ---------- Daten ----------

    /**
     * Zeitblöcke des Tages, indiziert nach ID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(int $dayId): array
    {
        $blocks = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT * FROM time_blocks WHERE garden_day_id = ? ORDER BY sort_order, start_time, id',
            [$dayId],
        ) as $block) {
            $blocks[(int) $block['id']] = $block;
        }

        return $blocks;
    }

    /**
     * Aktive Stände des Tages mit ihren Block-Angeboten (Kapazität, belegt,
     * Warteliste), indiziert nach Stand-ID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stationsOfDay(int $dayId, ?int $onlyId = null): array
    {
        $sql = 'SELECT * FROM stations WHERE garden_day_id = ? AND is_active = 1';
        $args = [$dayId];
        if ($onlyId !== null) {
            $sql .= ' AND id = ?';
            $args[] = $onlyId;
        }
        $sql .= ' ORDER BY sort_order, name';

        $stations = [];
        foreach ($this->ctx->db->fetchAll($sql, $args) as $station) {
            $station['blocks'] = [];
            $stations[(int) $station['id']] = $station;
        }
        if ($stations === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stations), '?'));
        $rows = $this->ctx->db->fetchAll(
            "SELECT sb.station_id, sb.time_block_id, sb.capacity,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'assigned') AS assigned,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'waitlist') AS waitlist
             FROM station_blocks sb WHERE sb.station_id IN ($placeholders)",
            array_keys($stations),
        );
        foreach ($rows as $row) {
            $stations[(int) $row['station_id']]['blocks'][(int) $row['time_block_id']] = [
                'capacity' => (int) $row['capacity'],
                'assigned' => (int) $row['assigned'],
                'waitlist' => (int) $row['waitlist'],
                'free' => max(0, (int) $row['capacity'] - (int) $row['assigned']),
            ];
        }

        return $stations;
    }

    /**
     * Verfügbarkeit je Stand für diese Person — neutral formuliert, ohne
     * Kriteriennamen. Struktur: [stationId => ['available' => bool, 'reason' => string]].
     *
     * @param array<int, array<string, mixed>> $stations
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array{available: bool, reason: string}>
     */
    private function availability(array $student, array $day, array $stations, array $blocks): array
    {
        $limits = new LimitCheck($this->ctx->db);
        $result = [];
        foreach ($stations as $stationId => $station) {
            if (($student['role'] ?? 'student') !== 'student') {
                $result[$stationId] = ['available' => true, 'reason' => ''];
                continue;
            }
            // Irgendein angebotener Block reicht für die Grundprüfung
            $blockId = array_key_first($station['blocks']) ?? array_key_first($blocks);
            if ($blockId === null) {
                $result[$stationId] = ['available' => false, 'reason' => 'Wird aktuell nicht angeboten'];
                continue;
            }
            $violations = $limits->check($student, $station, (int) $blockId, $day, 'wish');
            $codes = array_column($violations, 'code');

            if (in_array(LimitCheck::EXCLUSION, $codes, true)) {
                $result[$stationId] = ['available' => false, 'reason' => 'Für dich nicht verfügbar'];
            } elseif (in_array(LimitCheck::GRADE_NOT_ALLOWED, $codes, true) || in_array(LimitCheck::CLASS_NOT_ALLOWED, $codes, true)) {
                $result[$stationId] = ['available' => false, 'reason' => 'Für deine Klasse/Stufe nicht geöffnet'];
            } elseif (in_array(LimitCheck::NO_BLOCK, $codes, true) || in_array(LimitCheck::STATION_INACTIVE, $codes, true)) {
                $result[$stationId] = ['available' => false, 'reason' => 'Wird aktuell nicht angeboten'];
            } else {
                $result[$stationId] = ['available' => true, 'reason' => ''];
            }
        }

        return $result;
    }

    /**
     * Eigene Einträge des Tages, gruppiert nach Block:
     * [blockId => ['assigned' => row|null, 'waitlist' => list, 'wishes' => list]].
     *
     * @return array<int, array{assigned: array<string, mixed>|null, waitlist: list<array<string, mixed>>, wishes: list<array<string, mixed>>}>
     */
    private function myEnrollments(int $dayId, int $userId): array
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT e.*, s.name AS station_name, s.location, s.materials, tb.name AS block_name, tb.start_time, tb.end_time
             FROM enrollments e
             JOIN stations s ON s.id = e.station_id
             JOIN time_blocks tb ON tb.id = e.time_block_id
             WHERE e.garden_day_id = ? AND e.user_id = ?
             ORDER BY tb.sort_order, tb.start_time, e.priority, e.id',
            [$dayId, $userId],
        );

        $mine = [];
        foreach ($rows as $row) {
            $blockId = (int) $row['time_block_id'];
            $mine[$blockId] ??= ['assigned' => null, 'waitlist' => [], 'wishes' => []];
            if ($row['status'] === 'assigned') {
                $mine[$blockId]['assigned'] = $row;
            } elseif ($row['status'] === 'waitlist') {
                $mine[$blockId]['waitlist'][] = $row;
            } else {
                $mine[$blockId]['wishes'][] = $row;
            }
        }

        return $mine;
    }

    /** @param array<int, array{assigned: array<string, mixed>|null}> $mine */
    private function assignedBlockCount(array $mine): int
    {
        return count(array_filter($mine, static fn (array $entry): bool => $entry['assigned'] !== null));
    }

    /**
     * Zeilen für den Tagesplan: [blockId => station, location, leaders, materials].
     *
     * @return array<int, array<string, string>>
     */
    private function planRows(int $dayId, int $userId): array
    {
        $rows = $this->ctx->db->fetchAll(
            "SELECT e.time_block_id, s.id AS station_id, s.name AS station, s.location, s.materials,
                    (SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) ORDER BY u.lastname SEPARATOR ', ')
                       FROM station_leaders sl JOIN users u ON u.id = sl.user_id WHERE sl.station_id = s.id) AS leaders
             FROM enrollments e JOIN stations s ON s.id = e.station_id
             WHERE e.garden_day_id = ? AND e.user_id = ? AND e.status = 'assigned'",
            [$dayId, $userId],
        );

        $plan = [];
        foreach ($rows as $row) {
            $plan[(int) $row['time_block_id']] = [
                'station_id' => (string) $row['station_id'],
                'station' => (string) $row['station'],
                'location' => (string) ($row['location'] ?? ''),
                'leaders' => (string) ($row['leaders'] ?? ''),
                'materials' => (string) ($row['materials'] ?? ''),
            ];
        }

        return $plan;
    }

    /**
     * Zustand des Anmeldefensters: 'open' | 'before' | 'after'.
     *
     * @return array{state: string, start: ?string, end: ?string}
     */
    private function windowState(array $day): array
    {
        $now = date('Y-m-d H:i:s');
        $state = 'open';
        if (!empty($day['registration_start']) && $now < $day['registration_start']) {
            $state = 'before';
        } elseif (!empty($day['registration_end']) && $now > $day['registration_end']) {
            $state = 'after';
        }

        return ['state' => $state, 'start' => $day['registration_start'] ?? null, 'end' => $day['registration_end'] ?? null];
    }
}
