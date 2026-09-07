<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit;
use App\Services\LoginThrottle;

/**
 * Login, Logout, Seitenpasswort, Passwortwechsel.
 */
final class AuthController extends Controller
{
    public const MIN_PASSWORD_LENGTH = 8;

    // ---------- Login ----------

    /** GET /login */
    public function showLogin(array $params): string
    {
        if ($this->ctx->auth->check()) {
            $this->redirect($this->ctx->url('/'));
        }

        return $this->render('pages/auth/login', [
            'title' => 'Anmelden',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
        ], 'minimal');
    }

    /** POST /login */
    public function login(array $params): string
    {
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = Audit::clientIp();

        $throttle = new LoginThrottle($this->ctx->db);
        if ($username === '' || $password === '') {
            return $this->loginFailed('Bitte Benutzername und Passwort eingeben.');
        }
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Login blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}");

            return $this->loginFailed('Zu viele Fehlversuche. Bitte warte 5 Minuten.');
        }

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);

        if ($user === null || $user['password'] === null || !password_verify($password, (string) $user['password'])) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('Login fehlgeschlagen', 'warning', "Benutzer: {$username}");

            return $this->loginFailed('Benutzername oder Passwort ist falsch.');
        }
        if ((int) $user['is_active'] !== 1) {
            $this->ctx->audit->log('Login abgelehnt (Konto deaktiviert)', 'warning', "Benutzer: {$username}");

            return $this->loginFailed('Dieses Konto ist deaktiviert.');
        }

        // Bcrypt-Rehash bei veraltetem Algorithmus/Kostenfaktor
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->ctx->db->run(
                'UPDATE users SET password = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']],
            );
        }

        $throttle->clear($username);
        $this->ctx->db->run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);
        $this->ctx->auth->loginAs($user);
        $this->ctx->audit->log(
            'Login erfolgreich',
            'info',
            "Benutzer: {$username} (Rolle: {$user['role']})",
            (int) $user['id'],
            $username,
        );

        $redirect = $this->safeRedirectTarget($_POST['redirect'] ?? null);
        $this->redirect($redirect ?? $this->ctx->url('/'));
    }

    /** POST /logout */
    public function logout(array $params): string
    {
        $this->requireCsrf();
        if ($this->ctx->auth->check()) {
            $this->ctx->audit->log('Logout');
        }
        $this->ctx->auth->logout();
        $this->redirect($this->ctx->url('/login'));
    }

    // ---------- Seitenpasswort ----------

    /** GET /zugang */
    public function showSitePassword(array $params): string
    {
        if (!$this->ctx->settings->getBool('site_password_enabled')
            || $this->ctx->session->get('site_authenticated') === true) {
            $this->redirect($this->ctx->url('/'));
        }

        return $this->render('pages/auth/site-password', [
            'title' => 'Zugangscode',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
        ], 'minimal');
    }

    /** POST /zugang */
    public function sitePassword(array $params): string
    {
        $this->requireCsrf();
        $hash = $this->ctx->settings->get('site_password');
        $code = (string) ($_POST['code'] ?? '');

        if ($hash === null || !password_verify($code, $hash)) {
            $this->flash('error', 'Der Zugangscode ist falsch.');
            $this->redirect($this->ctx->url('/zugang'));
        }

        $this->ctx->session->set('site_authenticated', true);
        $this->redirect($this->safeRedirectTarget($_POST['redirect'] ?? null) ?? $this->ctx->url('/'));
    }

    // ---------- Passwortwechsel ----------

    /** GET /passwort-aendern */
    public function showChangePassword(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            $this->redirect($this->ctx->url('/login'));
        }

        return $this->render('pages/auth/change-password', [
            'title' => 'Passwort ändern',
            'forced' => (int) $user['must_change_password'] === 1,
        ], 'minimal');
    }

    /** POST /passwort-aendern */
    public function changePassword(array $params): string
    {
        $user = $this->ctx->auth->user();
        if ($user === null) {
            $this->redirect($this->ctx->url('/login'));
        }
        $this->requireCsrf();

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $forced = (int) $user['must_change_password'] === 1;
        if (!$forced && ($user['password'] === null || !password_verify($current, (string) $user['password']))) {
            $this->flash('error', 'Das aktuelle Passwort ist falsch.');
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }
        if ($error = self::validateNewPassword($new, $confirm)) {
            $this->flash('error', $error);
            $this->redirect($this->ctx->url('/passwort-aendern'));
        }

        $this->ctx->db->run(
            'UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $user['id']],
        );
        $this->ctx->audit->log('Passwort geändert');
        $this->flash('success', 'Dein Passwort wurde geändert.');
        $this->redirect($this->ctx->url('/'));
    }

    // ---------- Intern ----------

    /** Erlaubt nur relative Pfade innerhalb der App als Redirect-Ziel. */
    private function safeRedirectTarget(mixed $target): ?string
    {
        if (!is_string($target) || $target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }

        return $target;
    }

    private function loginFailed(string $message): string
    {
        $this->flash('error', $message);
        $this->redirect($this->ctx->url('/login'));
    }

    public static function validateNewPassword(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
        }
        if ($password !== $confirm) {
            return 'Die Passwörter stimmen nicht überein.';
        }

        return null;
    }
}
