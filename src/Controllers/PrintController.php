<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\DayQueries;
use App\Services\Exports;
use App\Services\Pdf;

/**
 * Druckzentrale: PDF-Berichte und Datenexporte rund um einen Aktionstag.
 *
 * Alle Aktionen, die eine Datei ausliefern, beenden den Request selbst
 * (Pdf::emit()/Exports::* haben Rückgabetyp never) — danach folgt nichts mehr.
 *
 * Sichtbarkeit der Übersichtsseite und der Tabellen-Exporte: BERICHTE_SEHEN.
 * PDF-Berichte erzeugen: zusätzlich BERICHTE_DRUCKEN.
 */
final class PrintController extends Controller
{
    private const STATUS_LABELS = [
        'assigned' => 'fest',
        'waitlist' => 'Warteliste',
        'wish' => 'Wunsch',
    ];

    private const SOURCE_LABELS = [
        'self' => 'Selbst',
        'auto' => 'Automatisch',
        'orga' => 'Orga',
    ];

    // =====================================================================
    // Übersicht
    // =====================================================================

    /** GET /admin/druck */
    public function index(array $params): string
    {
        $this->requirePermission(P::BERICHTE_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $stations = DayQueries::stationsOf($db, $dayId, true);
        $classes = DayQueries::classesOf($db);

        return $this->render('pages/print/index', [
            'title' => 'Listen & Druck',
            'day' => $day,
            'stations' => $stations,
            'classes' => $classes,
            'base' => $this->ctx->url('/admin/druck'),
            'canPrint' => $this->ctx->auth->can(P::BERICHTE_DRUCKEN),
        ]);
    }

    // =====================================================================
    // 1. Standlisten (PDF)
    // =====================================================================

    /** GET /admin/druck/staende.pdf?stand=… */
    public function stationLists(array $params): never
    {
        $day = $this->boot();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $stationId = (int) ($_GET['stand'] ?? 0);
        if ($stationId > 0) {
            $station = $db->fetchOne('SELECT * FROM stations WHERE id = ? AND garden_day_id = ?', [$stationId, $dayId]);
            if ($station === null) {
                throw new HttpException(404, 'Stand nicht gefunden.');
            }
            $stations = [$station];
        } else {
            $stations = DayQueries::stationsOf($db, $dayId, true);
        }

        $blocks = DayQueries::blocksOf($db, $dayId);
        $blockById = [];
        foreach ($blocks as $block) {
            $blockById[(int) $block['id']] = $block;
        }

        $pdf = $this->newPdf($day, 'Standlisten');
        $this->ctx->audit->log('druck.staende', 'info', sprintf('Standlisten-PDF erzeugt (%d Stand/Stände)', count($stations)));

        if ($stations === []) {
            $pdf->AddPage();
            $pdf->emptyState('Keine aktiven Stände für diesen Aktionstag.');
            $pdf->emit('Standlisten_' . date('Y-m-d') . '.pdf');
        }

        foreach ($stations as $station) {
            $this->stationPage($pdf, (int) $station['id'], $station, $blockById);
        }

        $name = count($stations) === 1 ? 'Standliste_' . $this->slug((string) $stations[0]['name']) : 'Standlisten';
        $pdf->emit($name . '_' . date('Y-m-d') . '.pdf');
    }

    /** @param array<string, mixed> $station @param array<int, array<string, mixed>> $blockById */
    private function stationPage(Pdf $pdf, int $stationId, array $station, array $blockById): void
    {
        $db = $this->ctx->db;

        $leaders = implode(', ', array_map(
            static fn (array $l): string => trim($l['firstname'] . ' ' . $l['lastname']),
            $db->fetchAll(
                'SELECT u.firstname, u.lastname FROM station_leaders sl JOIN users u ON u.id = sl.user_id
                 WHERE sl.station_id = ? ORDER BY u.lastname, u.firstname',
                [$stationId],
            ),
        ));

        $stationBlocks = $db->fetchAll(
            'SELECT sb.time_block_id, sb.capacity FROM station_blocks sb
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             WHERE sb.station_id = ? ORDER BY tb.sort_order, tb.start_time',
            [$stationId],
        );

        $pdf->AddPage();
        $pdf->heading((string) $station['name'], 13.0);
        $meta = [];
        if (!empty($station['location'])) {
            $meta[] = 'Ort: ' . $station['location'];
        }
        if ($leaders !== '') {
            $meta[] = 'Standleitung: ' . $leaders;
        }
        if (!empty($station['materials'])) {
            $meta[] = 'Material: ' . $station['materials'];
        }
        if ($meta !== []) {
            $pdf->note(implode(' · ', $meta));
        }
        $pdf->Ln(2);

        if ($stationBlocks === []) {
            $pdf->emptyState('Dieser Stand wird in keinem Zeitblock angeboten.');

            return;
        }

        $nrW = 10.0;
        $boxW = 14.0;
        $classW = 22.0;
        $nameW = $pdf->contentWidth() - $nrW - $boxW - $classW;

        foreach ($stationBlocks as $sb) {
            $blockId = (int) $sb['time_block_id'];
            $block = $blockById[$blockId] ?? null;
            if ($block === null) {
                continue;
            }

            $assigned = $db->fetchAll(
                "SELECT u.firstname, u.lastname, u.class FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 WHERE e.station_id = ? AND e.time_block_id = ? AND e.status = 'assigned'
                 ORDER BY u.lastname, u.firstname, u.id",
                [$stationId, $blockId],
            );
            $waitlist = $db->fetchAll(
                "SELECT u.firstname, u.lastname, u.class FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 WHERE e.station_id = ? AND e.time_block_id = ? AND e.status = 'waitlist'
                 ORDER BY e.priority IS NULL, e.priority, e.created_at",
                [$stationId, $blockId],
            );

            $pdf->ensureSpace(24.0);
            $pdf->band(DayQueries::blockLabel($block), count($assigned) . ' / ' . (int) $sb['capacity'] . ' Plätze');

            if ($assigned === []) {
                $pdf->note('Keine festen Teilnehmenden.');
            } else {
                $pdf->setColumns([
                    ['Nr.', $nrW, 'R'],
                    ['', $boxW, 'C'],
                    ['Name', $nameW, 'L'],
                    ['Klasse', $classW, 'C'],
                ]);
                $pdf->drawHead();

                foreach ($assigned as $i => $row) {
                    $pdf->ensureSpace(8.0, true);
                    $y = $pdf->GetY();
                    $pdf->drawRow([
                        (string) ($i + 1),
                        '',
                        trim($row['lastname'] . ', ' . $row['firstname']),
                        (string) ($row['class'] ?? ''),
                    ], 7.0);

                    $boxSize = 4.0;
                    $boxX = 10.0 + $nrW + ($boxW - $boxSize) / 2;
                    $boxY = $y + (7.0 - $boxSize) / 2;
                    $pdf->Rect($boxX, $boxY, $boxSize, $boxSize);
                }
            }

            if ($waitlist !== []) {
                $pdf->Ln(1);
                $pdf->SetFont('Helvetica', 'I', 7.5);
                $pdf->SetTextColor(110, 118, 128);
                $names = implode(', ', array_map(
                    static fn (array $w): string => trim($w['lastname'] . ', ' . $w['firstname']) . ' (' . ($w['class'] ?? '') . ')',
                    $waitlist,
                ));
                $pdf->MultiCell(0, 4.0, $pdf->t('Warteliste (' . count($waitlist) . '): ' . $names), 0, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Helvetica', '', 9);
            }

            $pdf->Ln(3);
        }
    }

    // =====================================================================
    // 2. Klassenlisten (PDF)
    // =====================================================================

    /** GET /admin/druck/klassen.pdf?klasse=… */
    public function classLists(array $params): never
    {
        $day = $this->boot();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $allClasses = DayQueries::classesOf($db);
        $class = trim((string) ($_GET['klasse'] ?? ''));
        if ($class !== '' && !in_array($class, $allClasses, true)) {
            $class = '';
        }
        $classes = $class !== '' ? [$class] : $allClasses;

        $blocks = DayQueries::blocksOf($db, $dayId);

        $byUserBlock = [];
        foreach ($db->fetchAll(
            "SELECT e.user_id, e.time_block_id, s.name AS station, s.location FROM enrollments e
             JOIN stations s ON s.id = e.station_id
             WHERE e.garden_day_id = ? AND e.status = 'assigned'",
            [$dayId],
        ) as $row) {
            $text = (string) $row['station'];
            if (!empty($row['location'])) {
                $text .= ' (' . $row['location'] . ')';
            }
            $byUserBlock[(int) $row['user_id']][(int) $row['time_block_id']] = $text;
        }

        $pdf = $this->newPdf($day, 'Klassenlisten', 'L');
        $this->ctx->audit->log('druck.klassen', 'info', sprintf('Klassenlisten-PDF erzeugt (%d Klasse(n))', count($classes)));

        if ($classes === [] || $blocks === []) {
            $pdf->AddPage('L');
            $pdf->emptyState($blocks === [] ? 'Für diesen Aktionstag sind keine Zeitblöcke angelegt.' : 'Keine Klassen vorhanden.');
            $pdf->emit('Klassenlisten_' . date('Y-m-d') . '.pdf');
        }

        $nrW = 10.0;
        $nameW = 62.0;
        $blockW = ($pdf->contentWidth() - $nrW - $nameW) / count($blocks);

        $columns = [['Nr.', $nrW, 'R'], ['Name', $nameW, 'L']];
        foreach ($blocks as $block) {
            $columns[] = [DayQueries::blockLabel($block), $blockW, 'L'];
        }

        foreach ($classes as $className) {
            $students = DayQueries::studentsOf($db, $className);

            $pdf->AddPage('L');
            $pdf->heading('Klasse ' . $className, 13.0);
            $pdf->Ln(1);

            if ($students === []) {
                $pdf->emptyState('Keine Schüler:innen in dieser Klasse.');
                continue;
            }

            $pdf->setColumns($columns);
            $pdf->drawHead();

            foreach ($students as $i => $student) {
                $uid = (int) $student['id'];
                $hasAny = isset($byUserBlock[$uid]) && $byUserBlock[$uid] !== [];

                $values = [(string) ($i + 1), trim($student['lastname'] . ', ' . $student['firstname'])];
                foreach ($blocks as $block) {
                    $values[] = $byUserBlock[$uid][(int) $block['id']] ?? '—';
                }

                $pdf->ensureSpace(8.0, true);
                $pdf->drawRow($values, 7.0, !$hasAny);
            }
        }

        $pdf->emit(($class !== '' ? 'Klassenliste_' . $this->slug($class) : 'Klassenlisten') . '_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 3. Belegungsübersicht (PDF)
    // =====================================================================

    /** GET /admin/druck/belegung.pdf */
    public function occupancy(array $params): never
    {
        $day = $this->boot();
        $dayId = (int) $day['id'];

        $rows = $this->ctx->db->fetchAll(
            "SELECT s.id AS station_id, s.name AS station, s.location, s.min_students,
                    tb.id AS block_id, tb.name AS block_name, tb.start_time, tb.end_time, sb.capacity,
                    SUM(CASE WHEN e.status = 'assigned' THEN 1 ELSE 0 END) AS assigned,
                    SUM(CASE WHEN e.status = 'waitlist' THEN 1 ELSE 0 END) AS waitlist
             FROM stations s
             JOIN station_blocks sb ON sb.station_id = s.id
             JOIN time_blocks tb ON tb.id = sb.time_block_id
             LEFT JOIN enrollments e ON e.station_id = s.id AND e.time_block_id = tb.id
             WHERE s.garden_day_id = ? AND s.is_active = 1
             GROUP BY s.id, s.name, s.location, s.min_students, tb.id, tb.name, tb.start_time, tb.end_time, sb.capacity
             ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name",
            [$dayId],
        );

        $pdf = $this->newPdf($day, 'Belegungsübersicht');
        $pdf->AddPage();
        $pdf->heading('Belegung je Stand und Zeitblock');
        $pdf->note('Fett markierte Zeilen unterschreiten die hinterlegte Mindestbesetzung.');
        $pdf->Ln(2);

        $this->ctx->audit->log('druck.belegung', 'info', 'Belegungsübersicht-PDF erzeugt.');

        if ($rows === []) {
            $pdf->emptyState('Keine aktiven Stände mit Zeitblöcken vorhanden.');
            $pdf->emit('Belegungsuebersicht_' . date('Y-m-d') . '.pdf');
        }

        $pdf->setColumns([
            ['Stand', 48.0, 'L'],
            ['Ort', 28.0, 'L'],
            ['Zeitblock', 40.0, 'L'],
            ['Belegt / Kap.', 26.0, 'C'],
            ['Warteliste', 22.0, 'C'],
            ['Mindest.', 22.0, 'C'],
        ]);
        $pdf->drawHead();

        $belowMin = 0;
        foreach ($rows as $row) {
            $min = $row['min_students'] !== null ? (int) $row['min_students'] : null;
            $assigned = (int) $row['assigned'];
            $under = $min !== null && $min > 0 && $assigned < $min;
            if ($under) {
                $belowMin++;
            }

            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                (string) $row['station'],
                (string) ($row['location'] ?? ''),
                DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']]),
                $assigned . ' / ' . (int) $row['capacity'],
                (string) (int) $row['waitlist'],
                $min !== null ? (string) $min : '–',
            ], 6.4, $under);
        }

        $pdf->Ln(3);
        $pdf->note($belowMin === 0
            ? 'Alle Stände erreichen ihre Mindestbesetzung.'
            : $belowMin . ' von ' . count($rows) . ' Zeilen liegen unter der Mindestbesetzung.');

        $pdf->emit('Belegungsuebersicht_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 4. Einschreibungen (PDF, gefiltert wie die Übersichtsseite)
    // =====================================================================

    /** GET /admin/druck/einschreibungen.pdf?stand=&block=&klasse=&stufe=&status=&q= */
    public function enrollmentsPdf(array $params): never
    {
        $day = $this->boot();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

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

        $rows = DayQueries::filteredEnrollments($db, $dayId, $filter, null);

        $pdf = $this->newPdf($day, 'Einschreibungen', 'L');
        $pdf->AddPage('L');
        $pdf->heading('Einschreibungen');
        $pdf->note($this->filterSummary($filter, $rows));
        $pdf->Ln(2);
        $this->ctx->audit->log('druck.einschreibungen_pdf', 'info', sprintf('Einschreibungen-PDF erzeugt (%d Zeilen)', count($rows)));

        if ($rows === []) {
            $pdf->emptyState('Keine Einschreibungen für diese Auswahl.');
            $pdf->emit('Einschreibungen_' . date('Y-m-d') . '.pdf');
        }

        $pdf->setColumns([
            ['Name', 65.0, 'L'],
            ['Klasse', 18.0, 'C'],
            ['Stufe', 14.0, 'C'],
            ['Stand', 55.0, 'L'],
            ['Ort', 30.0, 'L'],
            ['Zeitblock', 50.0, 'L'],
            ['Status', 25.0, 'C'],
            ['Quelle', 20.0, 'C'],
        ]);
        $pdf->drawHead();

        foreach ($rows as $row) {
            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                trim((string) $row['lastname'] . ', ' . (string) $row['firstname']),
                (string) ($row['class'] ?? ''),
                $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                (string) $row['station_name'],
                (string) ($row['location'] ?? ''),
                DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']]),
                self::STATUS_LABELS[(string) $row['status']] ?? (string) $row['status'],
                self::SOURCE_LABELS[(string) $row['source']] ?? (string) $row['source'],
            ], 6.2);
        }

        $pdf->emit('Einschreibungen_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 5. Offene Einschreibungen (PDF)
    // =====================================================================

    /** GET /admin/druck/offen.pdf?klasse=&stufe= */
    public function openPdf(array $params): never
    {
        $day = $this->boot();
        $db = $this->ctx->db;

        $class = trim((string) ($_GET['klasse'] ?? ''));
        $grade = (int) ($_GET['stufe'] ?? 0);
        $min = (int) ($day['min_blocks_per_student'] ?? 1);

        $rows = DayQueries::underEnrolled($db, $day, $class !== '' ? $class : null, $grade > 0 ? $grade : null);

        $pdf = $this->newPdf($day, 'Offene Einschreibungen');
        $pdf->AddPage();
        $pdf->heading('Offene Einschreibungen');
        $pdf->note(
            'Schüler:innen mit weniger als ' . $min . ' festen Zeitblöcken.'
            . ($class !== '' ? ' Klasse ' . $class . '.' : '')
            . ($grade > 0 ? ' Stufe ' . $grade . '.' : ''),
        );
        $pdf->Ln(2);
        $this->ctx->audit->log('druck.offen_pdf', 'info', sprintf('Offene-Einschreibungen-PDF erzeugt (%d Zeilen)', count($rows)));

        if ($rows === []) {
            $pdf->emptyState('Alle Schüler:innen haben die Mindestzahl an Blöcken erreicht.');
            $pdf->emit('Offene_Einschreibungen_' . date('Y-m-d') . '.pdf');
        }

        $pdf->setColumns([
            ['Nr.', 10.0, 'R'],
            ['Name', 70.0, 'L'],
            ['Klasse', 25.0, 'C'],
            ['Stufe', 20.0, 'C'],
            ['Feste Blöcke', 30.0, 'C'],
            ['Fehlend', 25.0, 'C'],
        ]);
        $pdf->drawHead();

        foreach ($rows as $i => $row) {
            $assigned = (int) $row['assigned_blocks'];
            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                (string) ($i + 1),
                DayQueries::personName($row),
                (string) ($row['class'] ?? ''),
                $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                (string) $assigned,
                (string) max(0, $min - $assigned),
            ]);
        }

        $pdf->emit('Offene_Einschreibungen_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 6. Anwesenheitsliste (PDF, optional gefiltert)
    // =====================================================================

    /** GET /admin/druck/anwesenheit.pdf?block=&stand=&klasse=&stufe= */
    public function attendancePdf(array $params): never
    {
        $day = $this->boot();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $blockId = (int) ($_GET['block'] ?? 0);
        $stationId = (int) ($_GET['stand'] ?? 0);
        $class = trim((string) ($_GET['klasse'] ?? ''));
        $grade = (int) ($_GET['stufe'] ?? 0);

        $sql = "SELECT u.lastname, u.firstname, u.class, u.grade,
                       s.name AS station, tb.sort_order AS block_sort,
                       tb.name AS block_name, tb.start_time, tb.end_time,
                       a.present, a.note
                FROM enrollments e
                JOIN users u ON u.id = e.user_id
                JOIN stations s ON s.id = e.station_id
                JOIN time_blocks tb ON tb.id = e.time_block_id
                LEFT JOIN attendance a ON a.enrollment_id = e.id
                WHERE e.garden_day_id = ? AND e.status = 'assigned'";
        $args = [$dayId];
        if ($blockId > 0) {
            $sql .= ' AND e.time_block_id = ?';
            $args[] = $blockId;
        }
        if ($stationId > 0) {
            $sql .= ' AND e.station_id = ?';
            $args[] = $stationId;
        }
        if ($class !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $class;
        }
        if ($grade > 0) {
            $sql .= ' AND u.grade = ?';
            $args[] = $grade;
        }
        $sql .= ' ORDER BY tb.sort_order, tb.start_time, s.sort_order, s.name, u.class, u.lastname, u.firstname';

        $rows = $db->fetchAll($sql, $args);

        $pdf = $this->newPdf($day, 'Anwesenheitsliste', 'L');
        $pdf->AddPage('L');
        $pdf->heading('Anwesenheitsliste');
        $meta = [];
        if ($blockId > 0 && $rows !== []) {
            $meta[] = DayQueries::blockLabel($rows[0]);
        }
        if ($stationId > 0 && $rows !== []) {
            $meta[] = 'Stand ' . $rows[0]['station'];
        }
        if ($class !== '') {
            $meta[] = 'Klasse ' . $class;
        }
        if ($grade > 0) {
            $meta[] = 'Stufe ' . $grade;
        }
        $pdf->note($meta === [] ? 'Alle festen Einschreibungen des Aktionstags.' : implode(' · ', $meta));
        $pdf->Ln(2);
        $this->ctx->audit->log('druck.anwesenheit_pdf', 'info', sprintf('Anwesenheitsliste-PDF erzeugt (%d Zeilen)', count($rows)));

        if ($rows === []) {
            $pdf->emptyState('Keine festen Einschreibungen für diese Auswahl.');
            $pdf->emit('Anwesenheitsliste_' . date('Y-m-d') . '.pdf');
        }

        $pdf->setColumns([
            ['Zeitblock', 45.0, 'L'],
            ['Stand', 55.0, 'L'],
            ['Name', 60.0, 'L'],
            ['Klasse', 20.0, 'C'],
            ['Stufe', 16.0, 'C'],
            ['Anwesend', 26.0, 'C'],
            ['Notiz', 55.0, 'L'],
        ]);
        $pdf->drawHead();

        foreach ($rows as $row) {
            $present = $row['present'] === null ? null : (int) $row['present'] === 1;
            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                DayQueries::blockLabel($row),
                (string) $row['station'],
                trim((string) $row['lastname'] . ', ' . (string) $row['firstname']),
                (string) ($row['class'] ?? ''),
                $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                $present === null ? 'offen' : ($present ? 'ja' : 'nein'),
                (string) ($row['note'] ?? ''),
            ], 6.2, $present === false);
        }

        $pdf->emit('Anwesenheitsliste_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 7. Stände-Übersicht (Stammdaten, PDF)
    // =====================================================================

    /** GET /admin/druck/staende-uebersicht.pdf?q=&aktiv=1 */
    public function stationsOverviewPdf(array $params): never
    {
        $this->requirePermission(P::BERICHTE_DRUCKEN);
        $day = $this->resolveDay();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $q = trim((string) ($_GET['q'] ?? ''));
        $onlyActive = (string) ($_GET['aktiv'] ?? '') === '1';

        $sql = 'SELECT s.* FROM stations s WHERE s.garden_day_id = ?';
        $args = [$dayId];
        if ($q !== '') {
            $sql .= ' AND (s.name LIKE ? OR s.location LIKE ? OR s.description LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like);
        }
        if ($onlyActive) {
            $sql .= ' AND s.is_active = 1';
        }
        $sql .= ' ORDER BY s.sort_order, s.name';
        $stations = $db->fetchAll($sql, $args);

        $leaders = [];
        foreach ($db->fetchAll(
            'SELECT sl.station_id, u.firstname, u.lastname, u.username FROM station_leaders sl
             JOIN users u ON u.id = sl.user_id
             JOIN stations s ON s.id = sl.station_id
             WHERE s.garden_day_id = ? ORDER BY u.lastname, u.firstname',
            [$dayId],
        ) as $row) {
            $name = trim($row['firstname'] . ' ' . $row['lastname']);
            $leaders[(int) $row['station_id']][] = $name !== '' ? $name : $row['username'];
        }

        $criteria = [];
        foreach ($db->fetchAll(
            'SELECT se.station_id, ec.name FROM station_exclusions se
             JOIN exclusion_criteria ec ON ec.id = se.criterion_id
             JOIN stations s ON s.id = se.station_id
             WHERE s.garden_day_id = ? ORDER BY ec.sort_order, ec.name',
            [$dayId],
        ) as $row) {
            $criteria[(int) $row['station_id']][] = $row['name'];
        }

        $capacity = [];
        foreach ($db->fetchAll(
            'SELECT sb.station_id, SUM(sb.capacity) AS total FROM station_blocks sb
             JOIN stations s ON s.id = sb.station_id
             WHERE s.garden_day_id = ? GROUP BY sb.station_id',
            [$dayId],
        ) as $row) {
            $capacity[(int) $row['station_id']] = (int) $row['total'];
        }

        $pdf = $this->newPdf($day, 'Stände-Übersicht', 'L');
        $pdf->AddPage('L');
        $pdf->heading('Stände-Übersicht');
        $meta = [];
        if ($q !== '') {
            $meta[] = 'Suche „' . $q . '"';
        }
        if ($onlyActive) {
            $meta[] = 'nur aktive Stände';
        }
        $pdf->note($meta === [] ? 'Alle Stände dieses Aktionstags.' : implode(' · ', $meta));
        $pdf->Ln(2);
        $this->ctx->audit->log('druck.staende_uebersicht_pdf', 'info', sprintf('Stände-Übersicht-PDF erzeugt (%d Stände)', count($stations)));

        if ($stations === []) {
            $pdf->emptyState('Keine Stände für diese Auswahl.');
            $pdf->emit('Staende_Uebersicht_' . date('Y-m-d') . '.pdf');
        }

        $pdf->setColumns([
            ['Stand', 60.0, 'L'],
            ['Ort', 35.0, 'L'],
            ['Standleitung', 60.0, 'L'],
            ['Kapazität gesamt', 30.0, 'C'],
            ['Ausschlusskriterien', 92.0, 'L'],
        ]);
        $pdf->drawHead();

        foreach ($stations as $station) {
            $sid = (int) $station['id'];
            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                (string) $station['name'] . ((int) $station['is_active'] !== 1 ? ' (inaktiv)' : ''),
                (string) ($station['location'] ?? ''),
                implode(', ', $leaders[$sid] ?? []),
                isset($capacity[$sid]) ? (string) $capacity[$sid] : '-',
                implode(', ', $criteria[$sid] ?? []),
            ], 6.4);
        }

        $pdf->emit('Staende_Uebersicht_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 8. Exporte (CSV / XLSX)
    // =====================================================================

    /** GET /admin/druck/einschreibungen.csv */
    public function enrollmentsCsv(array $params): never
    {
        $this->deliverEnrollments('csv');
    }

    /** GET /admin/druck/einschreibungen.xlsx */
    public function enrollmentsXlsx(array $params): never
    {
        $this->deliverEnrollments('xlsx');
    }

    private function deliverEnrollments(string $format): never
    {
        $this->requirePermission(P::BERICHTE_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $dayId = (int) $day['id'];

        $rows = $this->ctx->db->fetchAll(
            "SELECT u.username, u.firstname, u.lastname, u.class, u.grade,
                    s.name AS station, s.location,
                    tb.name AS block_name, tb.start_time, tb.end_time,
                    e.status, e.priority, e.source, e.created_at
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN stations s ON s.id = e.station_id
             JOIN time_blocks tb ON tb.id = e.time_block_id
             WHERE e.garden_day_id = ?
             ORDER BY u.class, u.lastname, u.firstname, tb.sort_order, tb.start_time",
            [$dayId],
        );

        $header = ['Benutzername', 'Vorname', 'Nachname', 'Klasse', 'Stufe', 'Stand', 'Ort', 'Zeitblock', 'Status', 'Priorität', 'Quelle', 'Angelegt'];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                (string) $row['username'],
                (string) $row['firstname'],
                (string) $row['lastname'],
                (string) ($row['class'] ?? ''),
                $row['grade'] !== null ? (string) (int) $row['grade'] : '',
                (string) $row['station'],
                (string) ($row['location'] ?? ''),
                DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']]),
                self::STATUS_LABELS[(string) $row['status']] ?? (string) $row['status'],
                $row['priority'] !== null ? (string) (int) $row['priority'] : '',
                self::SOURCE_LABELS[(string) $row['source']] ?? (string) $row['source'],
                format_datetime($row['created_at']),
            ];
        }

        $this->ctx->audit->log('druck.einschreibungen', 'info', sprintf('Einschreibungen exportiert (%s, %d Zeilen)', $format, count($out)));
        Exports::deliver($format, $header, $out, 'Einschreibungen');
    }

    /** GET /admin/druck/offen.csv?klasse=… */
    public function openCsv(array $params): never
    {
        $this->requirePermission(P::BERICHTE_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $class = trim((string) ($_GET['klasse'] ?? ''));

        $rows = DayQueries::underEnrolled($this->ctx->db, $day, $class !== '' ? $class : null);
        $min = (int) ($day['min_blocks_per_student'] ?? 1);

        $header = ['Benutzername', 'Name', 'Klasse', 'Feste Blöcke', 'Fehlend'];
        $out = [];
        foreach ($rows as $row) {
            $assigned = (int) $row['assigned_blocks'];
            $out[] = [
                (string) $row['username'],
                DayQueries::personName($row),
                (string) ($row['class'] ?? ''),
                (string) $assigned,
                (string) max(0, $min - $assigned),
            ];
        }

        $this->ctx->audit->log('druck.offen', 'info', sprintf('Offene Einschreibungen exportiert (%d Zeilen)', count($out)));
        Exports::deliver('csv', $header, $out, 'Offene_Einschreibungen');
    }

    /** GET /admin/druck/anwesenheit.csv */
    public function attendanceCsv(array $params): never
    {
        $this->requirePermission(P::BERICHTE_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $dayId = (int) $day['id'];

        $rows = $this->ctx->db->fetchAll(
            "SELECT u.firstname, u.lastname, u.class,
                    s.name AS station,
                    tb.name AS block_name, tb.start_time, tb.end_time,
                    a.present, a.note, a.marked_at,
                    m.firstname AS marker_first, m.lastname AS marker_last
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN stations s ON s.id = e.station_id
             JOIN time_blocks tb ON tb.id = e.time_block_id
             LEFT JOIN attendance a ON a.enrollment_id = e.id
             LEFT JOIN users m ON m.id = a.marked_by
             WHERE e.garden_day_id = ? AND e.status = 'assigned'
             ORDER BY tb.sort_order, tb.start_time, u.class, u.lastname, u.firstname",
            [$dayId],
        );

        $header = ['Name', 'Klasse', 'Stand', 'Zeitblock', 'Anwesend', 'Notiz', 'Markiert von', 'Markiert am'];
        $out = [];
        foreach ($rows as $row) {
            $present = $row['present'] === null ? null : (int) $row['present'] === 1;
            $marker = trim((string) ($row['marker_first'] ?? '') . ' ' . (string) ($row['marker_last'] ?? ''));

            $out[] = [
                trim($row['lastname'] . ', ' . $row['firstname']),
                (string) ($row['class'] ?? ''),
                (string) $row['station'],
                DayQueries::blockLabel(['name' => $row['block_name'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']]),
                $present === null ? 'offen' : ($present ? 'ja' : 'nein'),
                (string) ($row['note'] ?? ''),
                $marker,
                format_datetime($row['marked_at']),
            ];
        }

        $this->ctx->audit->log('druck.anwesenheit', 'info', sprintf('Anwesenheit exportiert (%d Zeilen)', count($out)));
        Exports::deliver('csv', $header, $out, 'Anwesenheit');
    }

    // =====================================================================
    // Helfer
    // =====================================================================

    /**
     * Beschreibungstext der aktiven Filter für die Kopfnotiz des Einschreibungen-PDFs.
     *
     * @param array{stand: int, block: int, klasse: string, stufe: int, status: string, q: string} $filter
     * @param list<array<string, mixed>> $rows Erste passende Zeile liefert Stand-/Blocknamen für die Anzeige.
     */
    private function filterSummary(array $filter, array $rows): string
    {
        $parts = [];
        $parts[] = 'Status: ' . ($filter['status'] === 'alle' ? 'alle' : (self::STATUS_LABELS[$filter['status']] ?? $filter['status']));
        if ($filter['stand'] > 0 && $rows !== []) {
            $parts[] = 'Stand ' . $rows[0]['station_name'];
        }
        if ($filter['block'] > 0 && $rows !== []) {
            $parts[] = DayQueries::blockLabel(['name' => $rows[0]['block_name'], 'start_time' => $rows[0]['start_time'], 'end_time' => $rows[0]['end_time']]);
        }
        if ($filter['klasse'] !== '') {
            $parts[] = 'Klasse ' . $filter['klasse'];
        }
        if ($filter['stufe'] > 0) {
            $parts[] = 'Stufe ' . $filter['stufe'];
        }
        if ($filter['q'] !== '') {
            $parts[] = 'Suche „' . $filter['q'] . '"';
        }

        return implode(' · ', $parts);
    }

    /**
     * Wie StationsController::resolveDay() — Stände-Übersicht kann sich wahlweise
     * auf einen per ?tag= gewählten Entwurfstag statt den aktiven Tag beziehen.
     *
     * @return array<string, mixed>
     */
    private function resolveDay(): array
    {
        $tag = (int) ($_GET['tag'] ?? 0);
        if ($tag > 0) {
            $day = $this->ctx->db->fetchOne("SELECT * FROM garden_days WHERE id = ? AND status <> 'archived'", [$tag]);
            if ($day === null) {
                throw new HttpException(404, 'Aktionstag nicht gefunden oder archiviert.');
            }

            return $day;
        }

        return $this->ctx->requireActiveDay();
    }

    /** Standard-Guards für PDF-Berichte. @return array<string, mixed> Aktiver Aktionstag. */
    private function boot(): array
    {
        $this->requirePermission(P::BERICHTE_DRUCKEN);

        return $this->ctx->requireActiveDay();
    }

    /** @param array<string, mixed> $day */
    private function newPdf(array $day, string $documentTitle, string $orientation = 'P'): Pdf
    {
        return new Pdf(
            $orientation,
            (string) ($this->ctx->settings->get('school_name') ?? ''),
            (string) $day['name'],
            $documentTitle,
        );
    }

    /** Dateiname-tauglicher Kurzname (ohne Sonderzeichen). */
    private function slug(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $ascii = $ascii === false ? $value : $ascii;
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii), '_');

        return $ascii === '' ? 'Stand' : $ascii;
    }
}
