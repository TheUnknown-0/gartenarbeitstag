<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\AutoAssign;
use App\Services\DayQueries;
use App\Services\LimitCheck;

/**
 * Automatische Zuteilung im Wunschmodus: Probelauf, Ausführen, Zurücksetzen.
 */
final class AssignmentController extends Controller
{
    /** GET /admin/zuteilung */
    public function index(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_AUSFUEHREN);
        $day = $this->ctx->requireActiveDay();

        return $this->renderPage($day, $this->defaultOptions(), null);
    }

    /** POST /admin/zuteilung/probelauf */
    public function simulate(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        if ($day['mode'] !== 'wishlist') {
            $this->flash('error', 'Der aktive Aktionstag läuft nicht im Wunschmodus.');
            $this->redirect($this->ctx->url('/admin/zuteilung'));
        }

        $options = $this->optionsFromPost();
        $report = $this->autoAssign()->simulate($day, $options);
        $options['seed'] = $report['seed'];
        $this->ctx->audit->log('assignment.simulate', 'info', sprintf('Probelauf „%s“: %d Plätze, %d offen, Seed %d', $day['name'], $report['assigned_total'], $report['unassigned_total'], $report['seed']));

        return $this->renderPage($day, $options, $report);
    }

    /** POST /admin/zuteilung/ausfuehren */
    public function run(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        if ($day['mode'] !== 'wishlist') {
            $this->flash('error', 'Der aktive Aktionstag läuft nicht im Wunschmodus.');
            $this->redirect($this->ctx->url('/admin/zuteilung'));
        }
        if ((int) ($_POST['confirm'] ?? 0) !== 1) {
            $this->flash('error', 'Bitte bestätigen, dass der Probelauf geprüft wurde.');
            $this->redirect($this->ctx->url('/admin/zuteilung'));
        }

        $options = $this->optionsFromPost();
        $report = $this->autoAssign()->run($day, $options);
        $this->ctx->resetActiveDay();
        $day = $this->ctx->requireActiveDay();

        $this->flash('success', sprintf('Zuteilung ausgeführt: %d Plätze vergeben, %d Wünsche blieben offen.', $report['assigned_total'], $report['unassigned_total']));

        return $this->renderPage($day, $options + ['seed' => $report['seed']], $report);
    }

    /** POST /admin/zuteilung/zuruecksetzen */
    public function reset(array $params): string
    {
        $this->requirePermission(P::ZUTEILUNG_ZURUECKSETZEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();
        if ((int) ($_POST['confirm'] ?? 0) !== 1) {
            $this->flash('error', 'Bitte das Zurücksetzen bestätigen.');
            $this->redirect($this->ctx->url('/admin/zuteilung'));
        }

        $count = $this->autoAssign()->reset($day);
        $this->ctx->resetActiveDay();
        $this->flash('warning', sprintf('%d automatisch vergebene Plätze entfernt. Wünsche und manuelle Einschreibungen bleiben erhalten.', $count));
        $this->redirect($this->ctx->url('/admin/zuteilung'));
    }

    // ---------- Helfer ----------

    private function autoAssign(): AutoAssign
    {
        $db = $this->ctx->db;

        return new AutoAssign($db, new LimitCheck($db), $this->ctx->audit);
    }

    /** @return array{seed: ?int, fill_unlucky: bool, fill_no_wishes: bool} */
    private function defaultOptions(): array
    {
        return ['seed' => null, 'fill_unlucky' => true, 'fill_no_wishes' => false];
    }

    /** @return array{seed?: int, fill_unlucky: bool, fill_no_wishes: bool} */
    private function optionsFromPost(): array
    {
        $options = [
            'fill_unlucky' => (int) ($_POST['fill_unlucky'] ?? 0) === 1,
            'fill_no_wishes' => (int) ($_POST['fill_no_wishes'] ?? 0) === 1,
        ];
        $seed = trim((string) ($_POST['seed'] ?? ''));
        if ($seed !== '' && ctype_digit($seed)) {
            $options['seed'] = (int) $seed;
        }

        return $options;
    }

    private function renderPage(array $day, array $options, ?array $report): string
    {
        $db = $this->ctx->db;
        $dayId = (int) $day['id'];

        $blocks = DayQueries::blocksOf($db, $dayId);
        $blockStats = [];
        foreach ($blocks as $block) {
            $blockId = (int) $block['id'];
            $blockStats[] = [
                'block' => $block,
                'wishers' => (int) $db->fetchValue("SELECT COUNT(DISTINCT user_id) FROM enrollments WHERE garden_day_id = ? AND time_block_id = ? AND status = 'wish'", [$dayId, $blockId]),
                'assigned' => (int) $db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND time_block_id = ? AND status = 'assigned'", [$dayId, $blockId]),
                'auto' => (int) $db->fetchValue("SELECT COUNT(*) FROM enrollments WHERE garden_day_id = ? AND time_block_id = ? AND status = 'assigned' AND source = 'auto'", [$dayId, $blockId]),
                'capacity' => (int) $db->fetchValue('SELECT COALESCE(SUM(sb.capacity), 0) FROM station_blocks sb JOIN stations s ON s.id = sb.station_id WHERE s.garden_day_id = ? AND s.is_active = 1 AND sb.time_block_id = ?', [$dayId, $blockId]),
            ];
        }

        return $this->render('pages/assignment/index', [
            'title' => 'Zuteilung',
            'day' => $day,
            'options' => $options,
            'report' => $report,
            'blockStats' => $blockStats,
            'students' => (int) $db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1"),
            'canReset' => $this->ctx->auth->can(P::ZUTEILUNG_ZURUECKSETZEN),
        ]);
    }
}
