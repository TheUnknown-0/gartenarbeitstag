<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\DayCloner;

/**
 * Aktionstage: Anlegen, Bearbeiten, Status, Zeitblöcke, Klonen, Löschen.
 */
final class GardenDaysController extends Controller
{
    private const MODES = ['direct', 'wishlist', 'quota'];
    private const STATUSES = ['draft', 'active', 'archived'];

    public function index(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_SEHEN);

        $days = $this->ctx->db->fetchAll(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM stations s WHERE s.garden_day_id = d.id) AS station_count,
                    (SELECT COUNT(*) FROM time_blocks tb WHERE tb.garden_day_id = d.id) AS block_count,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.garden_day_id = d.id AND e.status = 'assigned') AS assigned_count
             FROM garden_days d
             ORDER BY FIELD(d.status, 'active', 'draft', 'archived'), d.event_date DESC, d.id DESC",
        );

        return $this->render('pages/gardendays/index', [
            'title' => 'Aktionstage',
            'days' => $days,
        ]);
    }

    public function create(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);

        return $this->render('pages/gardendays/form', [
            'title' => 'Neuer Aktionstag',
            'day' => null,
            'blocks' => [],
            'old' => $this->ctx->session->pullOldInput(),
            'pageScripts' => ['gardendays.js'],
        ]);
    }

    public function store(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();

        $data = $this->validated($this->ctx->url('/admin/aktionstage/neu'));

        $this->ctx->db->run(
            'INSERT INTO garden_days (name, event_date, status, mode, registration_start, registration_end, min_blocks_per_student, max_blocks_per_student, wishes_per_block, waitlist_enabled, notes)
             VALUES (?, ?, \'draft\', ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'], $data['event_date'], $data['mode'], $data['registration_start'], $data['registration_end'],
                $data['min_blocks_per_student'], $data['max_blocks_per_student'], $data['wishes_per_block'], $data['waitlist_enabled'], $data['notes'],
            ],
        );
        $id = $this->ctx->db->lastInsertId();
        $this->ctx->audit->log('gardenday.create', 'info', 'Aktionstag angelegt: ' . $data['name'] . ' (#' . $id . ')');
        $this->flash('success', 'Aktionstag angelegt. Lege jetzt die Zeitblöcke fest.');
        $this->redirect($this->ctx->url('/admin/aktionstage/' . $id));
    }

    public function edit(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $day = $this->findDay((int) ($params['id'] ?? 0));

        $blocks = $this->ctx->db->fetchAll(
            'SELECT tb.*,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.time_block_id = tb.id) AS enrollment_count,
                    (SELECT COUNT(*) FROM station_blocks sb WHERE sb.time_block_id = tb.id) AS station_count
             FROM time_blocks tb WHERE tb.garden_day_id = ? ORDER BY tb.sort_order, tb.start_time, tb.id',
            [(int) $day['id']],
        );
        $stationCount = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM stations WHERE garden_day_id = ?', [(int) $day['id']]);

        return $this->render('pages/gardendays/form', [
            'title' => $day['name'],
            'day' => $day,
            'blocks' => $blocks,
            'stationCount' => $stationCount,
            'old' => $this->ctx->session->pullOldInput(),
            'pageScripts' => ['gardendays.js'],
        ]);
    }

    public function update(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));

        $data = $this->validated($this->ctx->url('/admin/aktionstage/' . $day['id']));

        $this->ctx->db->run(
            'UPDATE garden_days SET name = ?, event_date = ?, mode = ?, registration_start = ?, registration_end = ?,
                    min_blocks_per_student = ?, max_blocks_per_student = ?, wishes_per_block = ?, waitlist_enabled = ?, notes = ?
             WHERE id = ?',
            [
                $data['name'], $data['event_date'], $data['mode'], $data['registration_start'], $data['registration_end'],
                $data['min_blocks_per_student'], $data['max_blocks_per_student'], $data['wishes_per_block'], $data['waitlist_enabled'], $data['notes'],
                (int) $day['id'],
            ],
        );
        $this->ctx->resetActiveDay();
        $this->ctx->audit->log('gardenday.update', 'info', 'Aktionstag bearbeitet: ' . $data['name'] . ' (#' . $day['id'] . ')');
        $this->flash('success', 'Aktionstag gespeichert.');
        $this->redirect($this->ctx->url('/admin/aktionstage/' . $day['id']));
    }

    public function status(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            throw new HttpException(400, 'Ungültiger Status.');
        }

        $this->ctx->db->transaction(function () use ($day, $status): void {
            if ($status === 'active') {
                // Es kann nur einen aktiven Tag geben: den bisherigen ablösen.
                $others = $this->ctx->db->fetchAll("SELECT id, name FROM garden_days WHERE status = 'active' AND id <> ?", [(int) $day['id']]);
                foreach ($others as $other) {
                    $hasEnrollments = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ?', [(int) $other['id']]) > 0;
                    $this->ctx->db->run('UPDATE garden_days SET status = ? WHERE id = ?', [$hasEnrollments ? 'archived' : 'draft', (int) $other['id']]);
                }
            }
            $this->ctx->db->run('UPDATE garden_days SET status = ? WHERE id = ?', [$status, (int) $day['id']]);
        });
        $this->ctx->resetActiveDay();

        $labels = ['draft' => 'Entwurf', 'active' => 'aktiv', 'archived' => 'archiviert'];
        $this->ctx->audit->log('gardenday.status', 'warning', 'Aktionstag „' . $day['name'] . '“ (#' . $day['id'] . ') → ' . $labels[$status]);
        $this->flash('success', 'Aktionstag „' . $day['name'] . '“ ist jetzt ' . $labels[$status] . '.');
        $this->redirect($this->ctx->url('/admin/aktionstage'));
    }

    // ---------- Zeitblöcke ----------

    public function storeBlock(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));
        $back = $this->ctx->url('/admin/aktionstage/' . $day['id']);

        $block = $this->validatedBlock($back);
        $offerAll = (int) ($_POST['offer_all'] ?? 0) === 1;
        $capacity = max(0, min(999, (int) ($_POST['offer_capacity'] ?? 10)));

        $this->ctx->db->transaction(function () use ($day, $block, $offerAll, $capacity): void {
            $this->ctx->db->run(
                'INSERT INTO time_blocks (garden_day_id, name, start_time, end_time, sort_order) VALUES (?, ?, ?, ?, ?)',
                [(int) $day['id'], $block['name'], $block['start_time'], $block['end_time'], $block['sort_order']],
            );
            $blockId = $this->ctx->db->lastInsertId();
            if ($offerAll) {
                $this->ctx->db->run(
                    'INSERT INTO station_blocks (station_id, time_block_id, capacity) SELECT id, ?, ? FROM stations WHERE garden_day_id = ?',
                    [$blockId, $capacity, (int) $day['id']],
                );
            }
        });

        $this->ctx->audit->log('gardenday.block.create', 'info', 'Zeitblock „' . $block['name'] . '“ zu „' . $day['name'] . '“ hinzugefügt' . ($offerAll ? ' (für alle Stände, Kapazität ' . $capacity . ')' : ''));
        $this->flash('success', 'Zeitblock hinzugefügt.');
        $this->redirect($back);
    }

    public function updateBlock(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));
        $back = $this->ctx->url('/admin/aktionstage/' . $day['id']);
        $existing = $this->findBlock($day, (int) ($params['blockId'] ?? 0));

        $block = $this->validatedBlock($back);
        $this->ctx->db->run(
            'UPDATE time_blocks SET name = ?, start_time = ?, end_time = ?, sort_order = ? WHERE id = ?',
            [$block['name'], $block['start_time'], $block['end_time'], $block['sort_order'], (int) $existing['id']],
        );
        $this->ctx->audit->log('gardenday.block.update', 'info', 'Zeitblock #' . $existing['id'] . ' („' . $block['name'] . '“) bearbeitet');
        $this->flash('success', 'Zeitblock gespeichert.');
        $this->redirect($back);
    }

    public function deleteBlock(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));
        $back = $this->ctx->url('/admin/aktionstage/' . $day['id']);
        $block = $this->findBlock($day, (int) ($params['blockId'] ?? 0));

        $count = (int) $this->ctx->db->fetchValue('SELECT COUNT(*) FROM enrollments WHERE time_block_id = ?', [(int) $block['id']]);
        if ($count > 0) {
            $this->flash('error', 'Der Zeitblock „' . $block['name'] . '“ hat ' . $count . ' Einschreibungen und kann nicht gelöscht werden.');
            $this->redirect($back);
        }

        $this->ctx->db->run('DELETE FROM time_blocks WHERE id = ?', [(int) $block['id']]);
        $this->ctx->audit->log('gardenday.block.delete', 'warning', 'Zeitblock „' . $block['name'] . '“ (#' . $block['id'] . ') von „' . $day['name'] . '“ gelöscht');
        $this->flash('success', 'Zeitblock gelöscht.');
        $this->redirect($back);
    }

    // ---------- Klonen ----------

    public function showClone(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $day = $this->findDay((int) ($params['id'] ?? 0));

        return $this->render('pages/gardendays/clone', [
            'title' => 'Aktionstag klonen',
            'day' => $day,
            'parts' => DayCloner::PARTS,
            'old' => $this->ctx->session->pullOldInput(),
        ]);
    }

    public function clone(array $params): string
    {
        $this->requirePermission(P::AKTIONSTAGE_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));
        $back = $this->ctx->url('/admin/aktionstage/' . $day['id'] . '/klonen');
        $this->ctx->session->rememberInput($_POST);

        $name = trim((string) ($_POST['name'] ?? ''));
        $date = trim((string) ($_POST['event_date'] ?? ''));
        $parts = array_values(array_intersect(array_keys(DayCloner::PARTS), array_map('strval', (array) ($_POST['parts'] ?? []))));

        if ($name === '' || mb_strlen($name) > 150) {
            $this->flash('error', 'Bitte einen Namen (max. 150 Zeichen) angeben.');
            $this->redirect($back);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->flash('error', 'Bitte ein gültiges Datum für den neuen Aktionstag angeben.');
            $this->redirect($back);
        }

        $result = (new DayCloner($this->ctx->db))->clone((int) $day['id'], $name, $date, $parts);

        $statsText = [];
        foreach ($result['stats'] as $key => $count) {
            $statsText[] = (DayCloner::PARTS[$key] ?? $key) . ': ' . $count;
        }
        $this->ctx->audit->log('gardenday.clone', 'info', 'Aktionstag „' . $day['name'] . '“ → „' . $name . '“ (#' . $result['id'] . ') geklont. ' . implode(', ', $statsText));
        $this->flash('success', 'Aktionstag geklont (' . ($statsText !== [] ? implode(', ', $statsText) : 'nur Grunddaten') . '). Der neue Tag ist ein Entwurf.');
        $this->redirect($this->ctx->url('/admin/aktionstage/' . $result['id']));
    }

    public function delete(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $day = $this->findDay((int) ($params['id'] ?? 0));

        if ($day['status'] === 'active') {
            $this->flash('error', 'Der aktive Aktionstag kann nicht gelöscht werden. Bitte zuerst archivieren.');
            $this->redirect($this->ctx->url('/admin/aktionstage'));
        }

        // Alle abhängigen Daten hängen per ON DELETE CASCADE am Aktionstag.
        $this->ctx->db->run('DELETE FROM garden_days WHERE id = ?', [(int) $day['id']]);
        $this->ctx->resetActiveDay();
        $this->ctx->audit->log('gardenday.delete', 'critical', 'Aktionstag „' . $day['name'] . '“ (#' . $day['id'] . ') samt Ständen und Einschreibungen gelöscht');
        $this->flash('success', 'Aktionstag „' . $day['name'] . '“ gelöscht.');
        $this->redirect($this->ctx->url('/admin/aktionstage'));
    }

    // ---------- Hilfen ----------

    /** @return array<string, mixed> */
    private function findDay(int $id): array
    {
        $day = $id > 0 ? $this->ctx->db->fetchOne('SELECT * FROM garden_days WHERE id = ?', [$id]) : null;
        if ($day === null) {
            throw new HttpException(404, 'Aktionstag nicht gefunden.');
        }

        return $day;
    }

    /** @return array<string, mixed> */
    private function findBlock(array $day, int $blockId): array
    {
        $block = $blockId > 0
            ? $this->ctx->db->fetchOne('SELECT * FROM time_blocks WHERE id = ? AND garden_day_id = ?', [$blockId, (int) $day['id']])
            : null;
        if ($block === null) {
            throw new HttpException(404, 'Zeitblock nicht gefunden.');
        }

        return $block;
    }

    /**
     * Validiert das Aktionstag-Formular; bei Fehlern Flash + Redirect.
     *
     * @return array<string, mixed>
     */
    private function validated(string $back): array
    {
        $this->ctx->session->rememberInput($_POST);

        $name = trim((string) ($_POST['name'] ?? ''));
        $date = trim((string) ($_POST['event_date'] ?? ''));
        $mode = (string) ($_POST['mode'] ?? 'direct');
        $regStart = $this->normalizeDateTime((string) ($_POST['registration_start'] ?? ''));
        $regEnd = $this->normalizeDateTime((string) ($_POST['registration_end'] ?? ''));
        $min = (int) ($_POST['min_blocks_per_student'] ?? 1);
        $maxRaw = trim((string) ($_POST['max_blocks_per_student'] ?? ''));
        $max = $maxRaw === '' ? null : (int) $maxRaw;
        $wishes = (int) ($_POST['wishes_per_block'] ?? 3);
        $waitlist = (int) ($_POST['waitlist_enabled'] ?? 0) === 1 ? 1 : 0;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $error = null;
        if ($name === '' || mb_strlen($name) > 150) {
            $error = 'Bitte einen Namen (max. 150 Zeichen) angeben.';
        } elseif ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Bitte ein gültiges Datum angeben.';
        } elseif (!in_array($mode, self::MODES, true)) {
            $error = 'Ungültiger Anmeldemodus.';
        } elseif ($regStart === false || $regEnd === false) {
            $error = 'Bitte gültige Zeitpunkte für das Anmeldefenster angeben.';
        } elseif ($regStart !== null && $regEnd !== null && $regEnd < $regStart) {
            $error = 'Das Ende des Anmeldefensters liegt vor dem Beginn.';
        } elseif ($min < 0 || $min > 50) {
            $error = 'Die Mindestzahl Blöcke muss zwischen 0 und 50 liegen.';
        } elseif ($max !== null && ($max < 1 || $max > 50)) {
            $error = 'Die Höchstzahl Blöcke muss zwischen 1 und 50 liegen (oder leer für unbegrenzt).';
        } elseif ($max !== null && $max < $min) {
            $error = 'Die Höchstzahl Blöcke darf nicht kleiner als die Mindestzahl sein.';
        } elseif ($wishes < 1 || $wishes > 10) {
            $error = 'Die Anzahl Wünsche je Zeitblock muss zwischen 1 und 10 liegen.';
        }
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        return [
            'name' => $name,
            'event_date' => $date !== '' ? $date : null,
            'mode' => $mode,
            'registration_start' => $regStart,
            'registration_end' => $regEnd,
            'min_blocks_per_student' => $min,
            'max_blocks_per_student' => $max,
            'wishes_per_block' => $wishes,
            'waitlist_enabled' => $mode === 'direct' ? $waitlist : 0,
            'notes' => $notes !== '' ? $notes : null,
        ];
    }

    /** @return array{name: string, start_time: ?string, end_time: ?string, sort_order: int} */
    private function validatedBlock(string $back): array
    {
        $name = trim((string) ($_POST['block_name'] ?? ''));
        $start = trim((string) ($_POST['start_time'] ?? ''));
        $end = trim((string) ($_POST['end_time'] ?? ''));
        $sort = (int) ($_POST['sort_order'] ?? 0);

        $timeOk = static fn (string $t): bool => $t === '' || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t) === 1;
        $error = null;
        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Bitte eine Bezeichnung für den Zeitblock (max. 100 Zeichen) angeben.';
        } elseif (!$timeOk($start) || !$timeOk($end)) {
            $error = 'Bitte gültige Uhrzeiten angeben.';
        } elseif ($start !== '' && $end !== '' && $end <= $start) {
            $error = 'Das Ende des Zeitblocks muss nach dem Beginn liegen.';
        }
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        return [
            'name' => $name,
            'start_time' => $start !== '' ? $start : null,
            'end_time' => $end !== '' ? $end : null,
            'sort_order' => max(0, min(999, $sort)),
        ];
    }

    /**
     * datetime-local ("2026-05-04T08:00") → "2026-05-04 08:00:00".
     * Leer → null, ungültig → false.
     */
    private function normalizeDateTime(string $raw): string|null|false
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return false;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
