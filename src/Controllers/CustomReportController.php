<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\DayQueries;
use App\Services\Pdf;
use App\Services\ReportDefinitions;

/**
 * „Eigene Liste": freier Report-Baukasten der Druckzentrale — Nutzer:innen
 * wählen Datenquelle, Filter, Spalten (inkl. Reihenfolge über Positions-
 * nummern statt Drag & Drop, damit kein JavaScript nötig ist), Gruppierung
 * und Format selbst und können die Zusammenstellung als Vorlage speichern.
 *
 * Der komplette Formularzustand steckt in der Query-String (auch bei POST-
 * Aktionen, deren <form action> ihn mitträgt) — dadurch teilen sich index(),
 * pdf() und save() dieselbe parseState()-Logik und laufen nie auseinander.
 *
 * Sichtbarkeit: BERICHTE_SEHEN. Erzeugen/Vorlagen verwalten: BERICHTE_DRUCKEN.
 */
final class CustomReportController extends Controller
{
    /** GET /admin/druck/eigene-liste */
    public function index(array $params): string
    {
        $this->requirePermission(P::BERICHTE_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $db = $this->ctx->db;

        $templateId = (int) ($_GET['vorlage'] ?? 0);
        if ($templateId > 0 && !isset($_GET['quelle'])) {
            $template = $db->fetchOne('SELECT * FROM report_templates WHERE id = ?', [$templateId]);
            if ($template === null) {
                throw new HttpException(404, 'Vorlage nicht gefunden.');
            }
            $this->redirect($this->ctx->url('/admin/druck/eigene-liste') . '?' . $this->templateQuery($template));
        }

        $state = $this->buildState($day, $db);

        return $this->render('pages/print/eigene-liste', [
            'title' => 'Eigene Liste',
            'day' => $day,
            'sources' => ReportDefinitions::SOURCES,
            'source' => $state['source'],
            'fields' => ReportDefinitions::fields($state['source']),
            'groupFields' => ReportDefinitions::groupFields($state['source']),
            'columns' => $state['columns'],
            'filter' => $state['filters'],
            'groupBy' => $state['groupBy'],
            'orientation' => $state['orientation'],
            'rows' => $state['rows'],
            'currentQuery' => $this->stateQuery($state),
            'templateId' => $templateId,
            'templates' => $db->fetchAll('SELECT id, name, data_source FROM report_templates ORDER BY name'),
            'classes' => DayQueries::classesOf($db),
            'grades' => DayQueries::gradesOf($db),
            'stations' => DayQueries::stationsOf($db, (int) $day['id'], true),
            'blocks' => DayQueries::blocksOf($db, (int) $day['id']),
            'canPrint' => $this->ctx->auth->can(P::BERICHTE_DRUCKEN),
            'base' => $this->ctx->url('/admin/druck/eigene-liste'),
        ]);
    }

    /** GET /admin/druck/eigene-liste.pdf?quelle=&spalten[..]=1&position[..]=&gruppieren=&format=&… */
    public function pdf(array $params): never
    {
        $this->requirePermission(P::BERICHTE_DRUCKEN);
        $day = $this->ctx->requireActiveDay();
        $db = $this->ctx->db;

        $state = $this->buildState($day, $db);
        $pdf = $this->renderReportPdf($day, $state);

        $this->ctx->audit->log(
            'druck.eigene_liste_pdf',
            'info',
            sprintf(
                'Eigene Liste erzeugt (Quelle %s, %d Spalten, %d Zeilen)',
                $state['source'],
                count($state['columns']),
                count($state['rows']),
            ),
        );

        $pdf->emit('Eigene_Liste_' . $state['source'] . '_' . date('Y-m-d') . '.pdf');
    }

    /** POST /admin/druck/eigene-liste/speichern?quelle=&spalten[..]=1&… (Formular trägt den Zustand in der Action-URL) */
    public function save(array $params): never
    {
        $this->requirePermission(P::BERICHTE_DRUCKEN);
        $this->requireCsrf();
        $db = $this->ctx->db;

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->flash('error', 'Bitte einen Namen für die Vorlage angeben.');
            $this->redirect($this->ctx->url('/admin/druck/eigene-liste') . '?' . (string) ($_SERVER['QUERY_STRING'] ?? ''));
        }

        $source = $this->resolveSource();
        $state = $this->parseState($source);
        $config = [
            'columns' => $state['columns'],
            'filters' => $state['filters'],
            'group_by' => $state['groupBy'],
            'orientation' => $state['orientation'],
        ];
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $templateId = (int) ($_POST['vorlage_id'] ?? 0);
        $exists = $templateId > 0 && $db->fetchValue('SELECT COUNT(*) FROM report_templates WHERE id = ?', [$templateId]) > 0;

        if ($exists) {
            $db->run('UPDATE report_templates SET name = ?, data_source = ?, config = ? WHERE id = ?', [$name, $source, $json, $templateId]);
        } else {
            $db->run(
                'INSERT INTO report_templates (name, data_source, config, created_by) VALUES (?, ?, ?, ?)',
                [$name, $source, $json, $this->ctx->auth->id()],
            );
            $templateId = $db->lastInsertId();
        }

        $this->ctx->audit->log(
            'druck.eigene_liste_vorlage_speichern',
            'info',
            sprintf('Report-Vorlage „%s" %s (Quelle %s)', $name, $exists ? 'aktualisiert' : 'gespeichert', $source),
        );
        $this->flash('success', 'Vorlage „' . $name . '" gespeichert.');
        $this->redirect($this->ctx->url('/admin/druck/eigene-liste') . '?vorlage=' . $templateId);
    }

    /** POST /admin/druck/eigene-liste/vorlagen/{id}/loeschen */
    public function deleteTemplate(array $params): never
    {
        $this->requirePermission(P::BERICHTE_DRUCKEN);
        $this->requireCsrf();

        $id = (int) ($params['id'] ?? 0);
        $template = $this->ctx->db->fetchOne('SELECT * FROM report_templates WHERE id = ?', [$id]);
        if ($template === null) {
            throw new HttpException(404, 'Vorlage nicht gefunden.');
        }

        $this->ctx->db->run('DELETE FROM report_templates WHERE id = ?', [$id]);
        $this->ctx->audit->log('druck.eigene_liste_vorlage_loeschen', 'warning', 'Report-Vorlage „' . $template['name'] . '" gelöscht');
        $this->flash('success', 'Vorlage „' . $template['name'] . '" gelöscht.');
        $this->redirect($this->ctx->url('/admin/druck/eigene-liste'));
    }

    // =====================================================================
    // Formularzustand
    // =====================================================================

    /**
     * @return array{source: string, filters: array<string, mixed>, columns: list<string>, groupBy: string, orientation: string, rows: list<array<string, string>>}
     */
    private function buildState(array $day, Database $db): array
    {
        $source = $this->resolveSource();
        $state = $this->parseState($source);
        $state['rows'] = ReportDefinitions::rows($db, (int) $day['id'], $source, $state['filters']);

        return $state;
    }

    /** @return array{source: string, filters: array<string, mixed>, columns: list<string>, groupBy: string, orientation: string} */
    private function parseState(string $source): array
    {
        return [
            'source' => $source,
            'filters' => $this->parseFilters($source),
            'columns' => $this->parseColumns($source),
            'groupBy' => $this->parseGroupBy($source),
            'orientation' => ((string) ($_GET['format'] ?? 'L')) === 'P' ? 'P' : 'L',
        ];
    }

    private function resolveSource(): string
    {
        $source = (string) ($_GET['quelle'] ?? 'enrollments');

        return array_key_exists($source, ReportDefinitions::SOURCES) ? $source : 'enrollments';
    }

    /** @return array<string, mixed> */
    private function parseFilters(string $source): array
    {
        return match ($source) {
            'enrollments' => [
                'stand' => (int) ($_GET['stand'] ?? 0),
                'block' => (int) ($_GET['block'] ?? 0),
                'klasse' => trim((string) ($_GET['klasse'] ?? '')),
                'stufe' => (int) ($_GET['stufe'] ?? 0),
                'status' => in_array($_GET['status'] ?? '', ['assigned', 'waitlist', 'wish', 'alle'], true) ? (string) $_GET['status'] : 'alle',
                'q' => trim((string) ($_GET['q'] ?? '')),
            ],
            'students' => [
                'klasse' => trim((string) ($_GET['klasse'] ?? '')),
                'stufe' => (int) ($_GET['stufe'] ?? 0),
                'aktiv' => (string) ($_GET['aktiv'] ?? '') === '1' ? '1' : '',
            ],
            'stations' => [
                'q' => trim((string) ($_GET['q'] ?? '')),
                'aktiv' => (string) ($_GET['aktiv'] ?? '') === '1' ? '1' : '',
            ],
            'attendance' => [
                'block' => (int) ($_GET['block'] ?? 0),
                'stand' => (int) ($_GET['stand'] ?? 0),
                'klasse' => trim((string) ($_GET['klasse'] ?? '')),
                'stufe' => (int) ($_GET['stufe'] ?? 0),
            ],
            default => [],
        };
    }

    /** @return list<string> Ausgewählte Spalten in Anzeigereihenfolge. */
    private function parseColumns(string $source): array
    {
        $fields = array_keys(ReportDefinitions::fields($source));

        if (!isset($_GET['spalten_submitted'])) {
            // Erstaufruf ohne Formularinteraktion: alle Felder in Standardreihenfolge vorauswählen.
            return $fields;
        }

        $checked = array_values(array_intersect(array_keys((array) ($_GET['spalten'] ?? [])), $fields));
        $positions = (array) ($_GET['position'] ?? []);
        usort($checked, static fn (string $a, string $b): int => ((int) ($positions[$a] ?? 0)) <=> ((int) ($positions[$b] ?? 0)));

        return $checked;
    }

    private function parseGroupBy(string $source): string
    {
        $groupBy = (string) ($_GET['gruppieren'] ?? '');

        return array_key_exists($groupBy, ReportDefinitions::groupFields($source)) ? $groupBy : '';
    }

    /**
     * Query-String, die den aktuellen Formularzustand vollständig reproduziert —
     * für den PDF-Link und die Speichern-Formular-Action auf derselben Seite.
     *
     * @param array{source: string, filters: array<string, mixed>, columns: list<string>, groupBy: string, orientation: string} $state
     */
    private function stateQuery(array $state): string
    {
        $params = [
            'quelle' => $state['source'],
            'spalten_submitted' => '1',
            'gruppieren' => $state['groupBy'],
            'format' => $state['orientation'],
        ];
        foreach ($state['columns'] as $i => $key) {
            $params['spalten'][$key] = '1';
            $params['position'][$key] = (string) ($i + 1);
        }
        foreach ($state['filters'] as $key => $value) {
            $params[$key] = $value;
        }

        return http_build_query($params);
    }

    /** Baut aus einer gespeicherten Vorlage die vollständige Query-String für den Builder. */
    private function templateQuery(array $template): string
    {
        $config = json_decode((string) $template['config'], true);
        $config = is_array($config) ? $config : [];

        $params = [
            'quelle' => (string) $template['data_source'],
            'spalten_submitted' => '1',
            'gruppieren' => (string) ($config['group_by'] ?? ''),
            'format' => (string) ($config['orientation'] ?? 'L'),
            'vorlage' => (string) $template['id'],
        ];

        foreach ((array) ($config['columns'] ?? []) as $i => $key) {
            $params['spalten'][(string) $key] = '1';
            $params['position'][(string) $key] = (string) ($i + 1);
        }

        foreach ((array) ($config['filters'] ?? []) as $key => $value) {
            $params[(string) $key] = $value;
        }

        return http_build_query($params);
    }

    // =====================================================================
    // PDF
    // =====================================================================

    /** @param array{source: string, filters: array<string, mixed>, columns: list<string>, groupBy: string, orientation: string, rows: list<array<string, string>>} $state */
    private function renderReportPdf(array $day, array $state): Pdf
    {
        ['source' => $source, 'rows' => $rows, 'columns' => $columns, 'groupBy' => $groupBy, 'orientation' => $orientation] = $state;
        $fieldDefs = ReportDefinitions::fields($source);

        $pdf = $this->newPdf($day, 'Eigene Liste', $orientation);
        $pdf->AddPage($orientation);
        $pdf->heading('Eigene Liste — ' . (ReportDefinitions::SOURCES[$source] ?? $source));

        $count = count($rows);
        $note = $count . ' ' . ($count === 1 ? 'Eintrag' : 'Einträge');
        if ($groupBy !== '') {
            $note .= ', gruppiert nach ' . (ReportDefinitions::groupFields($source)[$groupBy] ?? $groupBy);
        }
        $pdf->note($note);
        $pdf->Ln(2);

        if ($columns === []) {
            $pdf->emptyState('Bitte mindestens eine Spalte auswählen.');

            return $pdf;
        }
        if ($rows === []) {
            $pdf->emptyState('Keine Daten für diese Auswahl.');

            return $pdf;
        }

        $contentWidth = $pdf->contentWidth();
        $totalWeight = array_sum(array_map(static fn (string $key): float => $fieldDefs[$key]['weight'] ?? 1.0, $columns));

        $columnDefs = [];
        foreach ($columns as $key) {
            $def = $fieldDefs[$key] ?? ['label' => $key, 'weight' => 1.0, 'align' => 'L'];
            $columnDefs[] = [$def['label'], round($contentWidth * $def['weight'] / $totalWeight, 1), $def['align']];
        }
        $pdf->setColumns($columnDefs);

        if ($groupBy === '') {
            $pdf->drawHead();
            foreach ($rows as $row) {
                $pdf->ensureSpace(7.0, true);
                $pdf->drawRow(array_map(static fn (string $key): string => $row[$key] ?? '', $columns), 6.2);
            }

            return $pdf;
        }

        // usort ist seit PHP 8 stabil — die bestehende Sortierung je Gruppe bleibt erhalten.
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) ($a[$groupBy] ?? ''), (string) ($b[$groupBy] ?? '')));

        $current = null;
        foreach ($rows as $row) {
            $group = (string) ($row[$groupBy] ?? '');
            if ($group !== $current) {
                $pdf->ensureSpace(20.0);
                $pdf->band($group === '' ? '(ohne Angabe)' : $group);
                $pdf->drawHead();
                $current = $group;
            }
            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow(array_map(static fn (string $key): string => $row[$key] ?? '', $columns), 6.2);
        }

        return $pdf;
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
}
