<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions as P;
use App\Services\EmailService;
use App\Services\Mailer;

/**
 * E-Mail-Verwaltung: Konfigurationsstatus, Test-Mail, Erinnerungen an
 * Standleitungen/Schüler:innen und das Versandprotokoll.
 *
 * Sichtbarkeit: EMAILS_SEHEN. Versenden: EMAILS_VERSENDEN.
 */
final class EmailController extends Controller
{
    /** GET /admin/emails */
    public function index(array $params): string
    {
        $this->requirePermission(P::EMAILS_SEHEN);
        $day = $this->ctx->requireActiveDay();
        $service = $this->emailService();

        return $this->render('pages/email/index', [
            'title' => 'E-Mail',
            'day' => $day,
            'configured' => $service->isConfigured(),
            'canSend' => $this->ctx->auth->can(P::EMAILS_VERSENDEN),
            'log' => $service->recentLog(50),
        ]);
    }

    /** POST /admin/emails/test */
    public function sendTest(array $params): never
    {
        $this->requirePermission(P::EMAILS_VERSENDEN);
        $this->requireCsrf();

        $to = trim((string) ($_POST['to'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash('error', 'Bitte eine gültige E-Mail-Adresse angeben.');
            $this->redirect($this->ctx->url('/admin/emails'));
        }

        $result = $this->emailService()->sendTest($to);
        $this->ctx->audit->log(
            'email.test',
            $result['success'] ? 'info' : 'warning',
            'Test-Mail an ' . $to . ($result['success'] ? ' gesendet' : ' fehlgeschlagen: ' . $result['error']),
        );
        $this->flash(
            $result['success'] ? 'success' : 'error',
            $result['success'] ? 'Test-Mail an ' . $to . ' gesendet.' : 'Test-Mail fehlgeschlagen: ' . $result['error'],
        );
        $this->redirect($this->ctx->url('/admin/emails'));
    }

    /** POST /admin/emails/standleitungen */
    public function sendStationLeaders(array $params): never
    {
        $this->requirePermission(P::EMAILS_VERSENDEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();

        $result = $this->emailService()->sendStationLeaderReminders(
            $day,
            fn (string $path): string => $this->ctx->publicUrl($path),
            ($_POST['force'] ?? '') === '1',
        );

        $this->ctx->audit->log(
            'email.standleitungen',
            $result['failed'] > 0 ? 'warning' : 'info',
            sprintf('Standleitungs-Erinnerung: %d gesendet, %d fehlgeschlagen, %d übersprungen', $result['sent'], $result['failed'], $result['skipped']),
        );
        $this->flash(
            $result['failed'] > 0 ? 'warning' : 'success',
            sprintf('%d Erinnerungen an Standleitungen gesendet, %d fehlgeschlagen, %d übersprungen (bereits versendet).', $result['sent'], $result['failed'], $result['skipped']),
        );
        $this->redirect($this->ctx->url('/admin/emails'));
    }

    /** POST /admin/emails/schueler */
    public function sendStudents(array $params): never
    {
        $this->requirePermission(P::EMAILS_VERSENDEN);
        $this->requireCsrf();
        $day = $this->ctx->requireActiveDay();

        $result = $this->emailService()->sendStudentReminders(
            $day,
            fn (string $path): string => $this->ctx->publicUrl($path),
            ($_POST['force'] ?? '') === '1',
        );

        $this->ctx->audit->log(
            'email.schueler',
            $result['failed'] > 0 ? 'warning' : 'info',
            sprintf('Schüler:innen-Erinnerung: %d gesendet, %d fehlgeschlagen, %d übersprungen', $result['sent'], $result['failed'], $result['skipped']),
        );
        $this->flash(
            $result['failed'] > 0 ? 'warning' : 'success',
            sprintf('%d Erinnerungen an Schüler:innen gesendet, %d fehlgeschlagen, %d übersprungen (bereits versendet).', $result['sent'], $result['failed'], $result['skipped']),
        );
        $this->redirect($this->ctx->url('/admin/emails'));
    }

    private function emailService(): EmailService
    {
        return new EmailService($this->ctx->db, new Mailer($this->ctx->config['mail']), $this->ctx->view);
    }
}
