<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;
use App\Services\DayQueries;
use App\Services\QuotaAssign;

/**
 * Quotenmodus (dritter Aktionstag-Modus, siehe QuotaAssign): Bedarf je Stand/
 * Zeitblock/Stufe pflegen, optionale Klassen-Präferenz und Prioritätsklasse,
 * Zuteilung erzeugen/zurücksetzen.
 *
 * Sichtbarkeit: EINSCHREIBUNGEN_SEHEN. Bedarf/Präferenz/Priorität pflegen:
 * EINSCHREIBUNGEN_BEARBEITEN. Zuteilung ausführen/zurücksetzen: wie im
 * Wunschmodus ZUTEILUNG_AUSFUEHREN/ZUTEILUNG_ZURUECKSETZEN.
 */
final class QuotaController extends Controller
{
    /** GET /admin/quote */
    public function index(array $params): string
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_SEHEN);
        $day = $this->requireQuotaDay();

        return $this->renderPage($day, null);
    }

    /** POST /admin/quote/bedarf */
    public function saveDemand(array $params): never
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $validStationIds = array_map('intval', array_column(
            $db->fetchAll('SELECT id FROM stations WHERE garden_day_id = ? AND manual_only = 0', [$dayId]),
            'id',
        ));
        $validBlockIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM time_blocks WHERE garden_day_id = ?', [$dayId]), 'id'));
        $grades = DayQueries::gradesOf($db);

        $raw = (array) ($_POST['demand'] ?? []);
        $db->transaction(function () use ($db, $raw, $validStationIds, $validBlockIds, $grades): void {
            foreach ($raw as $stationId => $byBlock) {
                $stationId = (int) $stationId;
                if (!in_array($stationId, $validStationIds, true) || !is_array($byBlock)) {
                    continue;
                }
                foreach ($byBlock as $blockId => $byGrade) {
                    $blockId = (int) $blockId;
                    if (!in_array($blockId, $validBlockIds, true) || !is_array($byGrade)) {
                        continue;
                    }
                    foreach ($byGrade as $grade => $value) {
                        $grade = (int) $grade;
                        if (!in_array($grade, $grades, true)) {
                            continue;
                        }
                        $demand = max(0, min(999, (int) $value));
                        $db->run(
                            'INSERT INTO station_grade_demand (station_id, time_block_id, grade, demand) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE demand = VALUES(demand)',
                            [$stationId, $blockId, $grade, $demand],
                        );
                    }
                }
            }
        });

        $this->ctx->audit->log('quota.bedarf', 'info', 'Quoten-Bedarf für „' . $day['name'] . '" gespeichert.');
        $this->flash('success', 'Bedarf gespeichert.');
        $this->redirect($this->ctx->url('/admin/quote'));
    }

    /** POST /admin/quote/praeferenzen */
    public function savePreferences(array $params): never
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $validStationIds = array_map('intval', array_column(
            $db->fetchAll('SELECT id FROM stations WHERE garden_day_id = ? AND manual_only = 0', [$dayId]),
            'id',
        ));
        $validClasses = DayQueries::classesOf($db);

        $raw = (array) ($_POST['preference'] ?? []);
        $db->transaction(function () use ($db, $raw, $validStationIds, $validClasses): void {
            foreach ($validStationIds as $stationId) {
                $db->run('DELETE FROM station_class_preference WHERE station_id = ?', [$stationId]);
                foreach ((array) ($raw[$stationId] ?? []) as $class => $weight) {
                    $class = (string) $class;
                    $weight = max(0, min(999, (int) $weight));
                    if ($weight <= 0 || !in_array($class, $validClasses, true)) {
                        continue;
                    }
                    $db->run(
                        'INSERT INTO station_class_preference (station_id, class, weight) VALUES (?, ?, ?)',
                        [$stationId, $class, $weight],
                    );
                }
            }
        });

        $this->ctx->audit->log('quota.praeferenzen', 'info', 'Klassen-Präferenzen für „' . $day['name'] . '" gespeichert.');
        $this->flash('success', 'Klassen-Präferenzen gespeichert.');
        $this->redirect($this->ctx->url('/admin/quote'));
    }

    /** POST /admin/quote/prioritaet */
    public function savePriority(array $params): never
    {
        $this->requirePermission(P::EINSCHREIBUNGEN_BEARBEITEN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $validBlockIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM time_blocks WHERE garden_day_id = ?', [$dayId]), 'id'));
        $grades = DayQueries::gradesOf($db);
        $validClasses = DayQueries::classesOf($db);

        $raw = (array) ($_POST['priority'] ?? []);
        $db->transaction(function () use ($db, $dayId, $raw, $validBlockIds, $grades, $validClasses): void {
            $db->run('DELETE FROM garden_day_slot_priority WHERE garden_day_id = ?', [$dayId]);
            foreach ($raw as $blockId => $byGrade) {
                $blockId = (int) $blockId;
                if (!in_array($blockId, $validBlockIds, true) || !is_array($byGrade)) {
                    continue;
                }
                foreach ($byGrade as $grade => $class) {
                    $grade = (int) $grade;
                    $class = trim((string) $class);
                    if ($class === '' || !in_array($grade, $grades, true) || !in_array($class, $validClasses, true)) {
                        continue;
                    }
                    $db->run(
                        'INSERT INTO garden_day_slot_priority (garden_day_id, time_block_id, grade, class) VALUES (?, ?, ?, ?)',
                        [$dayId, $blockId, $grade, $class],
                    );
                }
            }
        });

        $this->ctx->audit->log('quota.prioritaet', 'info', 'Prioritätsklassen für „' . $day['name'] . '" gespeichert.');
        $this->flash('success', 'Prioritätsklassen gespeichert.');
        $this->redirect($this->ctx->url('/admin/quote'));
    }

    /** POST /admin/quote/probelauf */
    public function simulate(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();

        $report = (new QuotaAssign($this->ctx->db, $this->ctx->audit))->simulate($day);
        $this->ctx->audit->log(
            'quota.simulate',
            'info',
            sprintf('Probelauf „%s“: %d Plätze, %d Konflikte', $day['name'], $report['assigned_total'], count($report['conflicts'])),
        );

        return $this->renderPage($day, $report);
    }

    /** POST /admin/quote/generieren */
    public function run(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();
        if ((int) ($_POST['confirm'] ?? 0) !== 1) {
            $this->flash('error', 'Bitte bestätigen, dass der Probelauf geprüft wurde.');
            $this->redirect($this->ctx->url('/admin/quote'));
        }

        $report = (new QuotaAssign($this->ctx->db, $this->ctx->audit))->run($day);
        $this->ctx->resetActiveDay();
        $day = $this->ctx->requireActiveDay();
        $this->flash(
            $report['conflicts'] === [] ? 'success' : 'warning',
            sprintf('Zuteilung erzeugt: %d Plätze vergeben, %d Konflikte.', $report['assigned_total'], count($report['conflicts'])),
        );

        return $this->renderPage($day, $report);
    }

    /** POST /admin/quote/zuruecksetzen */
    public function reset(array $params): never
    {
        $this->requirePermission(P::ZUTEILUNG_ZURUECKSETZEN);
        $this->requireCsrf();
        $day = $this->requireQuotaDay();
        if ((int) ($_POST['confirm'] ?? 0) !== 1) {
            $this->flash('error', 'Bitte das Zurücksetzen bestätigen.');
            $this->redirect($this->ctx->url('/admin/quote'));
        }

        $count = (new QuotaAssign($this->ctx->db, $this->ctx->audit))->reset($day);
        $this->ctx->resetActiveDay();
        $this->flash('warning', sprintf('%d zugeteilte Plätze entfernt. Manuelle Einschreibungen bleiben erhalten.', $count));
        $this->redirect($this->ctx->url('/admin/quote'));
    }

    // ---------- Helfer ----------

    /** @param array<string, mixed>|null $report */
    private function renderPage(array $day, ?array $report): string
    {
        $dayId = (int) $day['id'];
        $db = $this->ctx->db;

        $stations = $db->fetchAll(
            'SELECT * FROM stations WHERE garden_day_id = ? AND is_active = 1 AND manual_only = 0 ORDER BY sort_order, name',
            [$dayId],
        );
        $blocks = DayQueries::blocksOf($db, $dayId);
        $grades = DayQueries::gradesOf($db);
        $classes = DayQueries::classesOf($db);

        $demand = [];
        foreach ($db->fetchAll(
            'SELECT sgd.station_id, sgd.time_block_id, sgd.grade, sgd.demand FROM station_grade_demand sgd
             JOIN stations s ON s.id = sgd.station_id WHERE s.garden_day_id = ?',
            [$dayId],
        ) as $row) {
            $demand[(int) $row['station_id']][(int) $row['time_block_id']][(int) $row['grade']] = (int) $row['demand'];
        }

        $preference = [];
        foreach ($db->fetchAll(
            'SELECT scp.station_id, scp.class, scp.weight FROM station_class_preference scp
             JOIN stations s ON s.id = scp.station_id WHERE s.garden_day_id = ?',
            [$dayId],
        ) as $row) {
            $preference[(int) $row['station_id']][(string) $row['class']] = (int) $row['weight'];
        }

        $priority = [];
        foreach ($db->fetchAll('SELECT * FROM garden_day_slot_priority WHERE garden_day_id = ?', [$dayId]) as $row) {
            $priority[(int) $row['time_block_id']][(int) $row['grade']] = (string) $row['class'];
        }

        $studentCounts = [];
        foreach ($db->fetchAll(
            "SELECT grade, class, COUNT(*) AS n FROM users
             WHERE role = 'student' AND is_active = 1 AND grade IS NOT NULL AND class IS NOT NULL AND class <> ''
             GROUP BY grade, class",
        ) as $row) {
            $studentCounts[(int) $row['grade']][(string) $row['class']] = (int) $row['n'];
        }

        $assignedCount = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND source = 'quota' AND status = 'assigned'",
            [$dayId],
        );

        return $this->render('pages/quota/index', [
            'title' => 'Quote',
            'day' => $day,
            'stations' => $stations,
            'blocks' => $blocks,
            'grades' => $grades,
            'classes' => $classes,
            'demand' => $demand,
            'preference' => $preference,
            'priority' => $priority,
            'studentCounts' => $studentCounts,
            'assignedCount' => $assignedCount,
            'report' => $report,
            'canEdit' => $this->ctx->auth->can(P::EINSCHREIBUNGEN_BEARBEITEN),
            'canRun' => $this->ctx->auth->can(P::ZUTEILUNG_AUSFUEHREN),
            'canReset' => $this->ctx->auth->can(P::ZUTEILUNG_ZURUECKSETZEN),
            'base' => $this->ctx->url('/admin/quote'),
        ]);
    }

    private function requireQuotaDay(): array
    {
        $day = $this->ctx->requireActiveDay();
        if ($day['mode'] !== 'quota') {
            throw new HttpException(400, 'Der aktive Aktionstag läuft nicht im Quotenmodus.');
        }

        return $day;
    }
}
