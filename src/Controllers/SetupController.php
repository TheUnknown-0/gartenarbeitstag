<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Erst-Einrichtung: Solange kein Benutzer existiert, führt /setup durch
 * das Anlegen des Admin-Kontos, des Schulnamens und des ersten Aktionstags.
 */
final class SetupController extends Controller
{
    public function show(array $params): string
    {
        $this->assertSetupNeeded();

        return $this->render('pages/auth/setup', ['title' => 'Einrichtung', 'wide' => true], 'minimal');
    }

    public function run(array $params): string
    {
        $this->assertSetupNeeded();
        $this->requireCsrf();

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $schoolName = trim((string) ($_POST['school_name'] ?? ''));
        $dayName = trim((string) ($_POST['day_name'] ?? ''));
        $dayDate = trim((string) ($_POST['day_date'] ?? ''));

        $back = $this->ctx->url('/setup');
        $this->ctx->session->rememberInput($_POST);

        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            $this->flash('error', 'Der Admin-Benutzername darf 3–50 Zeichen lang sein (Buchstaben, Zahlen, Punkt, Minus, Unterstrich).');
            $this->redirect($back);
        }
        if (mb_strlen($password) < AuthController::MIN_PASSWORD_LENGTH || $password !== $confirm) {
            $this->flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein und beide Eingaben müssen übereinstimmen.');
            $this->redirect($back);
        }
        if ($schoolName === '') {
            $this->flash('error', 'Bitte den Namen der Schule angeben.');
            $this->redirect($back);
        }
        if ($dayDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayDate)) {
            $this->flash('error', 'Bitte ein gültiges Datum für den Aktionstag angeben.');
            $this->redirect($back);
        }

        $this->ctx->db->transaction(function () use ($username, $password, $schoolName, $dayName, $dayDate): void {
            $this->ctx->settings->set('school_name', $schoolName);

            $this->ctx->db->run(
                "INSERT INTO garden_days (name, event_date, status, mode) VALUES (?, ?, 'active', 'direct')",
                [$dayName !== '' ? $dayName : ('Gartenarbeitstag ' . date('Y')), $dayDate !== '' ? $dayDate : null],
            );
            $dayId = $this->ctx->db->lastInsertId();
            $this->ctx->db->run(
                "INSERT INTO time_blocks (garden_day_id, name, start_time, end_time, sort_order)
                 VALUES (?, 'Vormittag', '08:00:00', '12:00:00', 1)",
                [$dayId],
            );

            $this->ctx->db->run(
                "INSERT INTO users (username, password, role, firstname, lastname)
                 VALUES (?, ?, 'admin', 'Admin', '')",
                [$username, password_hash($password, PASSWORD_DEFAULT)],
            );
        });

        $this->ctx->audit->log('Erst-Einrichtung abgeschlossen', 'info', "Admin: {$username}, Schule: {$schoolName}");

        $user = $this->ctx->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
        $this->ctx->auth->loginAs($user);
        $this->flash('success', 'Einrichtung abgeschlossen. Willkommen! Lege als Nächstes Stände an und importiere Schüler:innen.');
        $this->redirect($this->ctx->url('/admin/dashboard'));
    }

    private function assertSetupNeeded(): void
    {
        if ($this->ctx->db->fetchValue('SELECT 1 FROM users LIMIT 1') !== null) {
            throw new HttpException(404);
        }
    }
}
