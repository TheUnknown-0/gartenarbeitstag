<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\DayQueries;

/**
 * Verwaltungs-Dashboard: Überblick über den aktiven Aktionstag.
 *
 * Jeder Abschnitt (Block) erfordert zusätzlich zu DASHBOARD_SEHEN das
 * jeweils fachlich zuständige Recht — wer z. B. keine Stände sehen darf,
 * bekommt auf dem Dashboard auch keine Stand-Belegung angezeigt.
 */
final class DashboardController extends Controller
{
    /** GET /admin/dashboard */
    public function index(array $params): string
    {
        $this->requirePermission(P::DASHBOARD_SEHEN);
        $auth = $this->ctx->auth;
        $day = $this->ctx->activeDay();

        $can = [
            'day' => $auth->can(P::AKTIONSTAGE_SEHEN),
            'stats' => $auth->can(P::EINSCHREIBUNGEN_SEHEN),
            'blocks' => $auth->can(P::EINSCHREIBUNGEN_SEHEN),
            'stations' => $auth->can(P::STAENDE_SEHEN),
            'classes' => $auth->can(P::BENUTZER_SEHEN),
            'audit' => $auth->can(P::AUDIT_LOGS_SEHEN),
        ];

        if ($day === null) {
            return $this->render('pages/dashboard/index', [
                'title' => 'Dashboard',
                'day' => null,
                'can' => $can,
                'canSeeDays' => $auth->can(P::AKTIONSTAGE_SEHEN),
            ]);
        }

        $db = $this->ctx->db;
        $dayId = (int) $day['id'];

        $stats = null;
        if ($can['stats']) {
            $stats = [
                'students' => (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1"),
                'assigned' => (int) $db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND status = 'assigned'", [$dayId]),
                'waitlist' => (int) $db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND status = 'waitlist'", [$dayId]),
                'wish' => (int) $db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND status = 'wish'", [$dayId]),
                'under' => count(DayQueries::underEnrolled($db, $day)),
            ];
        }

        // Belegung je Stand und Block — Grundlage für die Blöcke "blocks" und "stations"
        $occupancy = [];
        if ($can['blocks'] || $can['stations']) {
            foreach ($db->fetchAll(
                "SELECT sb.station_id, sb.time_block_id, sb.capacity,
                        SUM(CASE WHEN e.status = 'assigned' THEN 1 ELSE 0 END) AS assigned
                 FROM station_blocks sb
                 JOIN stations s ON s.id = sb.station_id
                 LEFT JOIN enrollments e ON e.station_id = sb.station_id AND e.time_block_id = sb.time_block_id
                 WHERE s.garden_day_id = ?
                 GROUP BY sb.station_id, sb.time_block_id, sb.capacity",
                [$dayId],
            ) as $row) {
                $occupancy[(int) $row['station_id']][(int) $row['time_block_id']] = [
                    'capacity' => (int) $row['capacity'],
                    'assigned' => (int) $row['assigned'],
                ];
            }
        }

        $blockRows = [];
        if ($can['blocks']) {
            foreach (DayQueries::blocksOf($db, $dayId) as $block) {
                $bid = (int) $block['id'];
                $capacity = 0;
                $assigned = 0;
                foreach ($occupancy as $stationOcc) {
                    if (isset($stationOcc[$bid])) {
                        $capacity += $stationOcc[$bid]['capacity'];
                        $assigned += $stationOcc[$bid]['assigned'];
                    }
                }
                $blockRows[] = [
                    'block' => $block,
                    'capacity' => $capacity,
                    'assigned' => $assigned,
                    'free' => max(0, $capacity - $assigned),
                ];
            }
        }

        $stations = [];
        if ($can['stations']) {
            $stations = DayQueries::stationsOf($db, $dayId, true);
        }

        $classes = [];
        if ($can['classes']) {
            $classes = $db->fetchAll(
                "SELECT u.class,
                        COUNT(*) AS total,
                        SUM(CASE WHEN (
                            SELECT COUNT(DISTINCT e.time_block_id) FROM enrollments e
                            WHERE e.user_id = u.id AND e.garden_day_id = ? AND e.status = 'assigned'
                        ) >= ? THEN 1 ELSE 0 END) AS complete
                 FROM users u
                 WHERE u.role = 'student' AND u.is_active = 1 AND u.class IS NOT NULL AND u.class <> ''
                 GROUP BY u.class
                 ORDER BY u.class",
                [$dayId, (int) $day['min_blocks_per_student']],
            );
        }

        $auditRows = [];
        if ($can['audit']) {
            $auditRows = $db->fetchAll('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 10');
        }

        return $this->render('pages/dashboard/index', [
            'title' => 'Dashboard',
            'day' => $day,
            'can' => $can,
            'stats' => $stats,
            'blockRows' => $blockRows,
            'stations' => $stations,
            'occupancy' => $occupancy,
            'classes' => $classes,
            'auditRows' => $auditRows,
            'canRunAssignment' => $auth->can(P::ZUTEILUNG_AUSFUEHREN),
        ]);
    }
}
