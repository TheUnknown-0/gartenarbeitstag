<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;

/**
 * Rollenabhängiger Einstieg.
 */
final class HomeController extends Controller
{
    /** GET / */
    public function index(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') === null) {
                $this->redirect($this->ctx->url('/setup'));
            }
            $this->redirect($this->ctx->url('/login'));
        }
        if ((int) $user['must_change_password'] === 1) {
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        $this->redirect($this->ctx->url($this->homePathFor($user)));
    }

    /** Erster sinnvoller Bereich für die Rolle bzw. die vorhandenen Rechte. */
    private function homePathFor(array $user): string
    {
        $auth = $this->ctx->auth;

        if ($user['role'] === 'student') {
            return '/uebersicht';
        }

        if ($user['role'] === 'teacher') {
            if ($auth->leadsAnyStation($this->ctx->activeDayId())) {
                return '/meine-staende';
            }
            if ($auth->can(P::DASHBOARD_SEHEN)) {
                return '/admin/dashboard';
            }

            return '/klassen';
        }

        // admin / orga: erste Verwaltungsseite mit Berechtigung
        $candidates = [
            P::DASHBOARD_SEHEN => '/admin/dashboard',
            P::EINSCHREIBUNGEN_SEHEN => '/admin/einschreibungen',
            P::STAENDE_SEHEN => '/admin/staende',
            P::AKTIONSTAGE_SEHEN => '/admin/aktionstage',
            P::BENUTZER_SEHEN => '/admin/benutzer',
            P::ANWESENHEIT_SEHEN => '/admin/anwesenheit',
            P::KRITERIEN_SEHEN => '/admin/kriterien',
            P::BERICHTE_SEHEN => '/admin/druck',
            P::EINSTELLUNGEN_SEHEN => '/admin/einstellungen',
            P::AUDIT_LOGS_SEHEN => '/admin/audit-log',
        ];
        foreach ($candidates as $permission => $path) {
            if ($auth->can($permission)) {
                return $path;
            }
        }

        return $auth->leadsAnyStation() ? '/meine-staende' : '/keine-rechte';
    }

    /** GET /keine-rechte — Konto ohne jegliche Berechtigung. */
    public function noPermissions(array $params): string
    {
        $this->requireLogin();

        return $this->render('pages/no-permissions', ['title' => 'Keine Berechtigungen']);
    }
}
