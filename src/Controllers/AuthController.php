<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit;
use App\Services\EmailService;
use App\Services\LoginCodeService;
use App\Services\LoginThrottle;
use App\Services\Mailer;

/**
 * Login, Logout, Seitenpasswort, Passwortwechsel, Anmeldung per E-Mail-Code.
 */
final class AuthController extends Controller
{
    public const MIN_PASSWORD_LENGTH = 8;

    private const CODE_SESSION_KEY = '_login_code_username';
    private const CODE_LOGIN_FLAG = '_login_via_code';

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
            'codeLoginAvailable' => (string) ($this->ctx->config['mail']['host'] ?? '') !== '',
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

    // ---------- Anmeldung per Code ----------

    /** GET /login-code */
    public function showCodeRequest(array $params): string
    {
        if ($this->ctx->auth->check()) {
            $this->redirect($this->ctx->url('/'));
        }

        return $this->render('pages/auth/login-code', [
            'title' => 'Anmeldung per Code',
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
            'username' => (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? ''),
        ], 'minimal');
    }

    /** POST /login-code */
    public function requestCode(array $params): string
    {
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $redirect = $this->safeRedirectTarget($_POST['redirect'] ?? null);
        $ip = Audit::clientIp();

        if ($username === '') {
            $this->flash('error', 'Bitte einen Benutzernamen eingeben.');
            $this->redirect($this->ctx->url('/login-code'));
        }

        $throttle = new LoginThrottle($this->ctx->db);
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Code-Anmeldung blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}");
            $this->flash('error', 'Zu viele Versuche. Bitte warte 5 Minuten.');
            $this->redirect($this->ctx->url('/login-code'));
        }
        // Jede Anfrage zählt als "Fehlversuch" für das Rate-Limit — sonst
        // könnte man beliebig oft Codes an ein fremdes Postfach schicken.
        $throttle->recordFailure($username, $ip);

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
        if ($user !== null && (int) $user['is_active'] === 1 && !empty($user['email'])) {
            $code = (new LoginCodeService($this->ctx->db))->issue((int) $user['id'], $ip);
            $this->emailService()->sendLoginCode($user, $code);
            $this->ctx->audit->log('Anmeldecode angefordert', 'info', "Benutzer: {$username}", (int) $user['id'], $username);
        } else {
            // Absichtlich keine Unterscheidung nach außen: unbekannter Benutzername,
            // deaktiviertes Konto oder fehlende E-Mail sehen für den Aufrufer gleich aus.
            $this->ctx->audit->log('Anmeldecode angefordert (kein Versand möglich)', 'info', "Benutzer: {$username}");
        }

        $this->ctx->session->set(self::CODE_SESSION_KEY, $username);
        $this->flash('info', 'Falls das Konto existiert und eine E-Mail-Adresse hinterlegt ist, haben wir einen Code verschickt.');
        $this->redirect($this->ctx->url('/login-code/bestaetigen') . ($redirect !== null ? '?redirect=' . rawurlencode($redirect) : ''));
    }

    /** GET /login-code/bestaetigen */
    public function showCodeVerify(array $params): string
    {
        if ($this->ctx->auth->check()) {
            $this->redirect($this->ctx->url('/'));
        }
        $username = (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? '');
        if ($username === '') {
            $this->redirect($this->ctx->url('/login-code'));
        }

        return $this->render('pages/auth/login-code-verify', [
            'title' => 'Code eingeben',
            'username' => $username,
            'redirect' => $this->safeRedirectTarget($_GET['redirect'] ?? null),
        ], 'minimal');
    }

    /** POST /login-code/bestaetigen */
    public function verifyCode(array $params): string
    {
        $this->requireCsrf();

        $username = (string) ($this->ctx->session->get(self::CODE_SESSION_KEY) ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));
        $ip = Audit::clientIp();

        if ($username === '') {
            $this->redirect($this->ctx->url('/login-code'));
        }

        $throttle = new LoginThrottle($this->ctx->db);
        if ($throttle->isBlocked($username, $ip)) {
            $this->ctx->audit->log('Code-Anmeldung blockiert (Rate-Limit)', 'warning', "Benutzer: {$username}");
            $this->flash('error', 'Zu viele Versuche. Bitte warte 5 Minuten.');
            $this->redirect($this->ctx->url('/login-code/bestaetigen'));
        }

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
        $valid = $code !== '' && $user !== null && (int) $user['is_active'] === 1
            && (new LoginCodeService($this->ctx->db))->verify((int) $user['id'], $code);

        if (!$valid) {
            $throttle->recordFailure($username, $ip);
            $this->ctx->audit->log('Code-Anmeldung fehlgeschlagen', 'warning', "Benutzer: {$username}");
            $this->flash('error', 'Der Code ist ungültig oder abgelaufen.');
            $this->redirect($this->ctx->url('/login-code/bestaetigen'));
        }

        $throttle->clear($username);
        $this->ctx->session->remove(self::CODE_SESSION_KEY);
        $this->ctx->db->run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);
        $this->ctx->auth->loginAs($user);
        // Merkt sich für diese Session, dass die Anmeldung per Code kam — damit
        // in /passwort-aendern ein neues Passwort ohne Kenntnis des alten
        // (eben vergessenen) gesetzt werden kann.
        $this->ctx->session->set(self::CODE_LOGIN_FLAG, true);
        $this->ctx->audit->log(
            'Code-Anmeldung erfolgreich',
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
            'viaCode' => $this->ctx->session->get(self::CODE_LOGIN_FLAG) === true,
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
        // Wer per Code angemeldet ist, hat sein Passwort per Definition vergessen —
        // das alte Passwort abzufragen wäre hier sinnlos.
        $skipCurrent = $forced || $this->ctx->session->get(self::CODE_LOGIN_FLAG) === true;
        if (!$skipCurrent && ($user['password'] === null || !password_verify($current, (string) $user['password']))) {
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

    private function emailService(): EmailService
    {
        return new EmailService($this->ctx->db, new Mailer($this->ctx->config['mail']), $this->ctx->view);
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
