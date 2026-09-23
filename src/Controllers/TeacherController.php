<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\EnrollmentService;
use App\Services\LimitCheck;
use App\Services\LimitViolation;
use App\Services\Pdf;

/**
 * Lehrkräfte-Bereich: Klassenübersicht und „Meine Stände“ für Standleitungen
 * (Teilnehmerlisten, Anwesenheit, Warteliste, kleine Einschreibungsänderungen).
 *
 * Zugriff auf einen Stand: Standleitung (station_leaders) ODER Recht
 * ANWESENHEIT_SEHEN. Änderungen an Einschreibungen: Standleitung mit
 * EIGENE_STAENDE_BEARBEITEN oder EINSCHREIBUNGEN_BEARBEITEN — nie mit Override.
 */
final class TeacherController extends Controller
{
    private const STAFF_ROLES = ['teacher', 'orga', 'admin'];

    // ---------- Klassen ----------

    /** GET /klassen?klasse=… */
    public function classes(array $params): string
    {
        $this->requireStaff();
        $day = $this->ctx->activeDay();
        $classes = array_map(
            'strval',
            $this->ctx->db->fetchAll(
                "SELECT DISTINCT class FROM users WHERE role = 'student' AND is_active = 1 AND class IS NOT NULL AND class <> '' ORDER BY class",
            ) === [] ? [] : array_column($this->ctx->db->fetchAll(
                "SELECT DISTINCT class FROM users WHERE role = 'student' AND is_active = 1 AND class IS NOT NULL AND class <> '' ORDER BY class",
            ), 'class'),
        );

        $selected = trim((string) ($_GET['klasse'] ?? ''));
        if ($selected !== '' && !in_array($selected, $classes, true)) {
            $selected = '';
        }

        $students = [];
        $matrix = [];
        $blocks = [];
        if ($day !== null && $selected !== '') {
            $blocks = $this->blocks((int) $day['id']);
            $students = $this->ctx->db->fetchAll(
                "SELECT id, firstname, lastname, class FROM users
                 WHERE role = 'student' AND is_active = 1 AND class = ?
                 ORDER BY lastname, firstname, id",
                [$selected],
            );
            $rows = $this->ctx->db->fetchAll(
                "SELECT e.user_id, e.time_block_id, e.status, e.priority, s.name AS station_name
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN stations s ON s.id = e.station_id
                 WHERE e.garden_day_id = ? AND u.class = ?
                 ORDER BY e.priority, e.id",
                [(int) $day['id'], $selected],
            );
            foreach ($rows as $row) {
                $uid = (int) $row['user_id'];
                $bid = (int) $row['time_block_id'];
                $matrix[$uid][$bid] ??= ['assigned' => null, 'waitlist' => [], 'wishes' => 0];
                if ($row['status'] === 'assigned') {
                    $matrix[$uid][$bid]['assigned'] = (string) $row['station_name'];
                } elseif ($row['status'] === 'waitlist') {
                    $matrix[$uid][$bid]['waitlist'][] = (string) $row['station_name'];
                } else {
                    $matrix[$uid][$bid]['wishes']++;
                }
            }
        }

        return $this->render('pages/teacher/classes', [
            'title' => 'Klassen',
            'day' => $day,
            'classes' => $classes,
            'selected' => $selected,
            'blocks' => $blocks,
            'students' => $students,
            'matrix' => $matrix,
        ]);
    }

    // ---------- Meine Stände ----------

    /** GET /meine-staende */
    public function myStations(array $params): string
    {
        $user = $this->requireLogin();
        $day = $this->ctx->activeDay();

        $stations = [];
        $blocks = [];
        if ($day !== null) {
            $blocks = $this->blocks((int) $day['id']);
            // Bewusst direkt über station_leaders — Auth::leadsStation() gäbe Admins alles.
            $stations = $this->stationsWithCounts(
                'SELECT s.* FROM stations s
                 JOIN station_leaders sl ON sl.station_id = s.id AND sl.user_id = ?
                 WHERE s.garden_day_id = ?
                 ORDER BY s.sort_order, s.name',
                [(int) $user['id'], (int) $day['id']],
            );
        }

        return $this->render('pages/teacher/stations', [
            'title' => 'Meine Stände',
            'day' => $day,
            'blocks' => $blocks,
            'stations' => $stations,
        ]);
    }

    /** GET /meine-staende/{id} */
    public function station(array $params): string
    {
        $this->requireLogin();
        $day = $this->ctx->requireActiveDay();
        $stationId = (int) ($params['id'] ?? 0);
        $station = $this->loadStation($stationId, $day);
        $leads = $this->ctx->auth->leadsStation($stationId);
        if (!$leads && !$this->ctx->auth->can(P::ANWESENHEIT_SEHEN)) {
            throw new HttpException(403, 'Sie haben keinen Zugriff auf diesen Stand.');
        }

        $blocks = $this->blocks((int) $day['id']);
        $offered = $this->offeredBlocks($stationId, $blocks);

        $participants = [];
        $waitlist = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT e.id, e.user_id, e.time_block_id, e.status, e.source, e.created_at,
                    u.firstname, u.lastname, u.class,
                    a.present, a.note, a.marked_at
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             LEFT JOIN attendance a ON a.enrollment_id = e.id
             WHERE e.station_id = ? AND e.garden_day_id = ? AND e.status IN ('assigned', 'waitlist')
             ORDER BY u.lastname, u.firstname, e.id",
            [$stationId, (int) $day['id']],
        ) as $row) {
            $bid = (int) $row['time_block_id'];
            if ($row['status'] === 'assigned') {
                $participants[$bid][] = $row;
            } else {
                $waitlist[$bid][] = $row;
            }
        }
        // Warteliste in Reihenfolge des Eintrags (wer zuerst kommt, rückt zuerst nach)
        foreach ($waitlist as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
        }
        unset($list);

        $canEdit = ($leads && $this->ctx->auth->can(P::EIGENE_STAENDE_BEARBEITEN))
            || $this->ctx->auth->can(P::EINSCHREIBUNGEN_BEARBEITEN);

        return $this->render('pages/teacher/station', [
            'title' => $station['name'],
            'pageScripts' => ['combobox.js', 'attendance.js'],
            'day' => $day,
            'station' => $station,
            'leaders' => $this->leaders($stationId),
            'blocks' => $blocks,
            'offered' => $offered,
            'participants' => $participants,
            'waitlist' => $waitlist,
            'canMark' => $leads || $this->ctx->auth->can(P::ANWESENHEIT_BEARBEITEN),
            'canEdit' => $canEdit,
            'students' => $canEdit ? $this->studentsByClass() : [],
        ]);
    }

    /** POST /meine-staende/{id}/einschreiben */
    public function enroll(array $params): string
    {
        $this->requireCsrf();
        $user = $this->requireLogin();
        $day = $this->ctx->requireActiveDay();
        $stationId = (int) ($params['id'] ?? 0);
        $station = $this->loadStation($stationId, $day);
        $this->requireEdit($stationId);

        $userId = (int) ($_POST['user_id'] ?? 0);
        $blockId = (int) ($_POST['time_block_id'] ?? 0);
        $student = $userId > 0 ? $this->ctx->db->fetchOne(
            "SELECT id, firstname, lastname FROM users WHERE id = ? AND role = 'student' AND is_active = 1",
            [$userId],
        ) : null;
        if ($student === null || $blockId <= 0) {
            $this->flash('error', 'Bitte Schüler:in und Zeitblock auswählen.');
            $this->redirect($this->ctx->url('/meine-staende/' . $stationId));
        }

        try {
            $result = $this->enrollments()->create($day, $userId, $stationId, $blockId, [
                'source' => 'orga',
                'created_by' => (int) $user['id'],
                'auto_waitlist' => (int) $day['waitlist_enabled'] === 1,
                'manual' => true,
            ]);
            $name = trim($student['firstname'] . ' ' . $student['lastname']);
            $this->flash(
                $result['status'] === 'waitlist' ? 'warning' : 'success',
                $result['status'] === 'waitlist'
                    ? $name . ' steht auf der Warteliste — der Stand ist in diesem Zeitblock voll.'
                    : $name . ' wurde bei „' . $station['name'] . '“ eingeschrieben.',
            );
        } catch (LimitViolation $e) {
            $this->flash('error', 'Einschreibung nicht möglich: ' . $e->getMessage()
                . ($e->overridable() ? ' Eine Übersteuerung ist nur in der Verwaltung möglich.' : ''));
        }

        $this->redirect($this->ctx->url('/meine-staende/' . $stationId) . '#block-' . $blockId);
    }

    /** POST /meine-staende/{id}/einschreibungen/{eid}/austragen */
    public function withdraw(array $params): string
    {
        $this->requireCsrf();
        $user = $this->requireLogin();
        $day = $this->ctx->requireActiveDay();
        $stationId = (int) ($params['id'] ?? 0);
        $this->loadStation($stationId, $day);
        $this->requireEdit($stationId);

        $enrollment = $this->loadEnrollment((int) ($params['eid'] ?? 0), $stationId, $day);
        $promoted = $this->enrollments()->delete((int) $enrollment['id'], $day, 'Standleitung ' . $user['username']);

        $name = trim($enrollment['firstname'] . ' ' . $enrollment['lastname']);
        $this->flash('success', $name . ' wurde ausgetragen.' . ($promoted !== null ? ' Von der Warteliste ist jemand nachgerückt.' : ''));
        $this->redirect($this->ctx->url('/meine-staende/' . $stationId) . '#block-' . (int) $enrollment['time_block_id']);
    }

    /** POST /meine-staende/{id}/einschreibungen/{eid}/nachruecken */
    public function promote(array $params): string
    {
        $this->requireCsrf();
        $user = $this->requireLogin();
        $day = $this->ctx->requireActiveDay();
        $stationId = (int) ($params['id'] ?? 0);
        $station = $this->loadStation($stationId, $day);
        $this->requireEdit($stationId);

        $enrollment = $this->loadEnrollment((int) ($params['eid'] ?? 0), $stationId, $day);
        $blockId = (int) $enrollment['time_block_id'];
        $name = trim($enrollment['firstname'] . ' ' . $enrollment['lastname']);
        if ($enrollment['status'] !== 'waitlist') {
            $this->flash('error', $name . ' steht nicht auf der Warteliste.');
            $this->redirect($this->ctx->url('/meine-staende/' . $stationId) . '#block-' . $blockId);
        }

        $student = $this->ctx->db->fetchOne('SELECT * FROM users WHERE id = ?', [(int) $enrollment['user_id']]);
        if ($student === null) {
            throw new HttpException(404, 'Schüler:in nicht gefunden.');
        }

        $violations = (new LimitCheck($this->ctx->db))->check($student, $station, $blockId, $day, 'assigned', [
            'ignore_enrollment_id' => (int) $enrollment['id'],
            'manual' => true,
        ]);
        if ($violations !== []) {
            // Ohne Override blockieren auch weiche Verstöße — Übersteuern nur in der Verwaltung.
            $this->flash('error', 'Nachrücken nicht möglich: ' . implode(' ', LimitCheck::messages($violations)));
            $this->redirect($this->ctx->url('/meine-staende/' . $stationId) . '#block-' . $blockId);
        }

        $this->ctx->db->run(
            "UPDATE enrollments SET status = 'assigned', source = 'orga', priority = NULL WHERE id = ? AND status = 'waitlist'",
            [(int) $enrollment['id']],
        );
        $this->ctx->audit->log(
            'enrollment.promote',
            'info',
            sprintf('%s (#%d) von Warteliste nachgerückt: Stand „%s“ (#%d), Block #%d — durch %s', $name, (int) $enrollment['user_id'], $station['name'], $stationId, $blockId, $user['username']),
        );

        $this->flash('success', $name . ' ist nachgerückt und jetzt fest eingeschrieben.');
        $this->redirect($this->ctx->url('/meine-staende/' . $stationId) . '#block-' . $blockId);
    }

    /** GET /meine-staende/{id}/liste.pdf */
    public function listPdf(array $params): string
    {
        $this->requireLogin();
        $day = $this->ctx->requireActiveDay();
        $stationId = (int) ($params['id'] ?? 0);
        $station = $this->loadStation($stationId, $day);
        if (!$this->ctx->auth->leadsStation($stationId)
            && !$this->ctx->auth->canAny(P::ANWESENHEIT_SEHEN, P::BERICHTE_DRUCKEN)) {
            throw new HttpException(403, 'Sie haben keinen Zugriff auf diesen Stand.');
        }

        $blocks = $this->blocks((int) $day['id']);
        $offered = $this->offeredBlocks($stationId, $blocks);
        $rows = $this->ctx->db->fetchAll(
            "SELECT e.time_block_id, u.firstname, u.lastname, u.class
             FROM enrollments e JOIN users u ON u.id = e.user_id
             WHERE e.station_id = ? AND e.garden_day_id = ? AND e.status = 'assigned'
             ORDER BY u.lastname, u.firstname, u.id",
            [$stationId, (int) $day['id']],
        );
        $byBlock = [];
        foreach ($rows as $row) {
            $byBlock[(int) $row['time_block_id']][] = $row;
        }

        $leaders = implode(', ', array_map(
            static fn (array $l): string => trim($l['firstname'] . ' ' . $l['lastname']),
            $this->leaders($stationId),
        ));

        $pdf = new Pdf('P', (string) $this->ctx->settings->get('school_name', ''), (string) $day['name'], 'Teilnehmerliste');
        $pdf->AddPage();
        $pdf->heading($station['name'], 13.0);
        $meta = [];
        if (!empty($station['location'])) {
            $meta[] = 'Ort: ' . $station['location'];
        }
        if ($leaders !== '') {
            $meta[] = 'Standleitung: ' . $leaders;
        }
        $meta[] = format_date($day['event_date']);
        $pdf->note(implode(' · ', $meta));
        $pdf->Ln(2);

        $pdf->setColumns([
            ['', 8.0, 'C'],
            ['Nr.', 10.0, 'R'],
            ['Name', 70.0],
            ['Klasse', 22.0],
            ['Bemerkung', 80.0],
        ]);

        if ($offered === []) {
            $pdf->emptyState('Dieser Stand wird in keinem Zeitblock angeboten.');
        }
        foreach ($offered as $blockId => $block) {
            $list = $byBlock[$blockId] ?? [];
            $pdf->ensureSpace(24.0);
            $pdf->band(
                $block['name'] . ' (' . substr((string) $block['start_time'], 0, 5) . '–' . substr((string) $block['end_time'], 0, 5) . ')',
                count($list) . ' / ' . $block['capacity'] . ' Plätze',
            );
            if ($list === []) {
                $pdf->note('Keine Teilnehmer:innen.');
                $pdf->Ln(2);
                continue;
            }
            $pdf->resetBanding();
            $pdf->drawHead();
            foreach ($list as $i => $row) {
                $pdf->ensureSpace(8.0, true);
                $pdf->drawRow(['', (string) ($i + 1), trim($row['lastname'] . ', ' . $row['firstname']), (string) ($row['class'] ?? ''), ''], 7.0);
            }
            $pdf->Ln(4);
        }

        $pdf->emit('Teilnehmerliste-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $station['name']) . '.pdf', 'I');
    }

    // ---------- Guards ----------

    /** @return array<string, mixed> */
    private function requireStaff(): array
    {
        $user = $this->requireLogin();
        if (!in_array($user['role'], self::STAFF_ROLES, true)) {
            throw new HttpException(403, 'Dieser Bereich ist nur für Lehrkräfte.');
        }

        return $user;
    }

    /** Einschreibungen ändern: Standleitung mit EIGENE_STAENDE_BEARBEITEN oder EINSCHREIBUNGEN_BEARBEITEN. */
    private function requireEdit(int $stationId): void
    {
        $auth = $this->ctx->auth;
        if (($auth->leadsStation($stationId) && $auth->can(P::EIGENE_STAENDE_BEARBEITEN))
            || $auth->can(P::EINSCHREIBUNGEN_BEARBEITEN)) {
            return;
        }
        throw new HttpException(403, 'Sie dürfen die Einschreibungen dieses Standes nicht ändern.');
    }

    /** @return array<string, mixed> Stand des aktiven Tages, sonst 404 */
    private function loadStation(int $stationId, array $day): array
    {
        $station = $stationId > 0
            ? $this->ctx->db->fetchOne('SELECT * FROM stations WHERE id = ? AND garden_day_id = ?', [$stationId, (int) $day['id']])
            : null;
        if ($station === null) {
            throw new HttpException(404, 'Stand nicht gefunden.');
        }

        return $station;
    }

    /** @return array<string, mixed> Einschreibung DIESES Standes und Tages (inkl. Name), sonst 404 */
    private function loadEnrollment(int $enrollmentId, int $stationId, array $day): array
    {
        $enrollment = $enrollmentId > 0 ? $this->ctx->db->fetchOne(
            "SELECT e.*, u.firstname, u.lastname FROM enrollments e JOIN users u ON u.id = e.user_id
             WHERE e.id = ? AND e.station_id = ? AND e.garden_day_id = ? AND e.status IN ('assigned', 'waitlist')",
            [$enrollmentId, $stationId, (int) $day['id']],
        ) : null;
        if ($enrollment === null) {
            throw new HttpException(404, 'Einschreibung nicht gefunden.');
        }

        return $enrollment;
    }

    private function enrollments(): EnrollmentService
    {
        return new EnrollmentService($this->ctx->db, new LimitCheck($this->ctx->db), $this->ctx->audit);
    }

    // ---------- Daten ----------

    /** @return array<int, array<string, mixed>> Zeitblöcke des Tages, nach ID */
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
     * Vom Stand angebotene Blöcke: [blockId => block + capacity/assigned/waitlist].
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function offeredBlocks(int $stationId, array $blocks): array
    {
        $offered = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT sb.time_block_id, sb.capacity,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'assigned') AS assigned,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'waitlist') AS waitlist
             FROM station_blocks sb WHERE sb.station_id = ?",
            [$stationId],
        ) as $row) {
            $bid = (int) $row['time_block_id'];
            if (!isset($blocks[$bid])) {
                continue;
            }
            $offered[$bid] = $blocks[$bid] + [
                'capacity' => (int) $row['capacity'],
                'assigned' => (int) $row['assigned'],
                'waitlist' => (int) $row['waitlist'],
            ];
        }
        uksort($offered, static fn (int $a, int $b): int => array_search($a, array_keys($blocks), true) <=> array_search($b, array_keys($blocks), true));

        return $offered;
    }

    /**
     * Stände mit Belegung je Block: station['blocks'][blockId] = capacity/assigned/waitlist.
     *
     * @param list<mixed> $args
     * @return array<int, array<string, mixed>>
     */
    private function stationsWithCounts(string $sql, array $args): array
    {
        $stations = [];
        foreach ($this->ctx->db->fetchAll($sql, $args) as $station) {
            $station['blocks'] = [];
            $stations[(int) $station['id']] = $station;
        }
        if ($stations === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stations), '?'));
        foreach ($this->ctx->db->fetchAll(
            "SELECT sb.station_id, sb.time_block_id, sb.capacity,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'assigned') AS assigned,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id AND e.status = 'waitlist') AS waitlist
             FROM station_blocks sb WHERE sb.station_id IN ($placeholders)",
            array_keys($stations),
        ) as $row) {
            $stations[(int) $row['station_id']]['blocks'][(int) $row['time_block_id']] = [
                'capacity' => (int) $row['capacity'],
                'assigned' => (int) $row['assigned'],
                'waitlist' => (int) $row['waitlist'],
            ];
        }

        return $stations;
    }

    /** @return list<array<string, mixed>> */
    private function leaders(int $stationId): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT u.id, u.firstname, u.lastname FROM station_leaders sl JOIN users u ON u.id = sl.user_id
             WHERE sl.station_id = ? ORDER BY u.lastname, u.firstname',
            [$stationId],
        );
    }

    /** @return array<string, list<array<string, mixed>>> aktive Schüler:innen, gruppiert nach Klasse */
    private function studentsByClass(): array
    {
        $groups = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT id, firstname, lastname, class FROM users WHERE role = 'student' AND is_active = 1
             ORDER BY class, lastname, firstname",
        ) as $row) {
            $groups[(string) ($row['class'] ?? '') ?: '– ohne Klasse –'][] = $row;
        }

        return $groups;
    }
}
