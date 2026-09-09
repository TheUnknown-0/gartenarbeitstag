<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\View;

/**
 * Orchestriert die drei E-Mail-Aktionen der Verwaltung: Test-Mail,
 * Erinnerung an Standleitungen, Erinnerung/Plan an Schüler:innen.
 *
 * Jeder Versand wird in email_log protokolliert; Sammelversände
 * überspringen Empfänger:innen, die für denselben Aktionstag und dieselbe
 * Vorlage bereits erfolgreich eine Mail bekommen haben (außer bei $force),
 * damit ein zweiter Klick nicht versehentlich alle erneut zuspammt.
 */
final class EmailService
{
    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly View $view,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /** @return array{success: bool, error: ?string} */
    public function sendTest(string $toEmail): array
    {
        $subject = 'Test-Mail — Gartenarbeitstag';
        $text = "Das ist eine Test-Mail vom Gartenarbeitstag-System.\n\n"
            . 'Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.';
        $html = '<p>Das ist eine Test-Mail vom Gartenarbeitstag-System.</p>'
            . '<p>Wenn du diese Mail liest, ist der E-Mail-Versand korrekt eingerichtet.</p>';

        try {
            $this->mailer->send($toEmail, '', $subject, $text, $html);
            $this->log(null, null, $toEmail, $subject, 'test', 'sent', null);

            return ['success' => true, 'error' => null];
        } catch (\RuntimeException $e) {
            $this->log(null, null, $toEmail, $subject, 'test', 'failed', mb_substr($e->getMessage(), 0, 500));

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $day
     * @param callable(string): string $urlFor Baut aus einem Pfad eine öffentliche, absolute URL.
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendStationLeaderReminders(array $day, callable $urlFor, bool $force = false): array
    {
        $dayId = (int) $day['id'];
        $leaders = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
             FROM station_leaders sl
             JOIN stations s ON s.id = sl.station_id AND s.garden_day_id = ? AND s.is_active = 1
             JOIN users u ON u.id = sl.user_id AND u.is_active = 1
             WHERE u.email IS NOT NULL AND u.email <> ''
             ORDER BY u.lastname, u.firstname",
            [$dayId],
        );

        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($leaders as $leader) {
            $userId = (int) $leader['id'];
            if (!$force && $this->alreadySent($dayId, $userId, 'standleitung_erinnerung')) {
                $result['skipped']++;
                continue;
            }

            $stations = $this->db->fetchAll(
                'SELECT s.id, s.name, s.location FROM station_leaders sl
                 JOIN stations s ON s.id = sl.station_id
                 WHERE sl.user_id = ? AND s.garden_day_id = ? AND s.is_active = 1
                 ORDER BY s.sort_order, s.name',
                [$userId, $dayId],
            );
            if ($stations === []) {
                $result['skipped']++;
                continue;
            }

            $name = trim((string) $leader['firstname'] . ' ' . (string) $leader['lastname']);
            $subject = 'Gartenarbeitstag „' . $day['name'] . '“ — deine Stände';
            $text = $this->stationLeaderText($day, $stations, $urlFor);
            $html = $this->view->renderPartial('emails/standleitung-erinnerung', [
                'name' => $name,
                'day' => $day,
                'stations' => $stations,
                'urlFor' => $urlFor,
            ]);

            $this->deliver($userId, $dayId, (string) $leader['email'], $name, $subject, $text, $html, 'standleitung_erinnerung', $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $day
     * @param callable(string): string $urlFor
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendStudentReminders(array $day, callable $urlFor, bool $force = false): array
    {
        $dayId = (int) $day['id'];
        $students = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
             FROM enrollments e
             JOIN users u ON u.id = e.user_id AND u.is_active = 1
             WHERE e.garden_day_id = ? AND e.status = 'assigned'
               AND u.email IS NOT NULL AND u.email <> ''
             ORDER BY u.lastname, u.firstname",
            [$dayId],
        );
        $blocks = DayQueries::blocksOf($this->db, $dayId);

        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($students as $student) {
            $userId = (int) $student['id'];
            if (!$force && $this->alreadySent($dayId, $userId, 'schueler_erinnerung')) {
                $result['skipped']++;
                continue;
            }

            $byBlock = [];
            foreach ($this->db->fetchAll(
                "SELECT e.time_block_id, s.name AS station, s.location
                 FROM enrollments e JOIN stations s ON s.id = e.station_id
                 WHERE e.garden_day_id = ? AND e.user_id = ? AND e.status = 'assigned'",
                [$dayId, $userId],
            ) as $row) {
                $byBlock[(int) $row['time_block_id']] = $row;
            }
            if ($byBlock === []) {
                $result['skipped']++;
                continue;
            }

            $name = trim((string) $student['firstname'] . ' ' . (string) $student['lastname']);
            $subject = 'Gartenarbeitstag „' . $day['name'] . '“ — dein Zeitplan';
            $text = $this->studentText($day, $blocks, $byBlock, $urlFor);
            $html = $this->view->renderPartial('emails/schueler-erinnerung', [
                'name' => $name,
                'day' => $day,
                'blocks' => $blocks,
                'byBlock' => $byBlock,
                'urlFor' => $urlFor,
            ]);

            $this->deliver($userId, $dayId, (string) $student['email'], $name, $subject, $text, $html, 'schueler_erinnerung', $result);
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function recentLog(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->db->fetchAll(
            'SELECT el.*, u.firstname, u.lastname FROM email_log el
             LEFT JOIN users u ON u.id = el.user_id
             ORDER BY el.created_at DESC LIMIT ' . $limit,
        );
    }

    // ---------- Helfer ----------

    private function alreadySent(int $dayId, int $userId, string $template): bool
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM email_log WHERE garden_day_id = ? AND user_id = ? AND template = ? AND status = 'sent'",
            [$dayId, $userId, $template],
        ) > 0;
    }

    /** @param array{sent: int, failed: int, skipped: int} $result */
    private function deliver(
        int $userId,
        int $dayId,
        string $email,
        string $name,
        string $subject,
        string $text,
        string $html,
        string $template,
        array &$result,
    ): void {
        try {
            $this->mailer->send($email, $name, $subject, $text, $html);
            $this->log($dayId, $userId, $email, $subject, $template, 'sent', null);
            $result['sent']++;
        } catch (\RuntimeException $e) {
            $this->log($dayId, $userId, $email, $subject, $template, 'failed', mb_substr($e->getMessage(), 0, 500));
            $result['failed']++;
        }
    }

    private function log(?int $dayId, ?int $userId, string $recipient, string $subject, string $template, string $status, ?string $error): void
    {
        $this->db->run(
            'INSERT INTO email_log (garden_day_id, user_id, recipient, subject, template, status, error) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$dayId, $userId, $recipient, mb_substr($subject, 0, 255), $template, $status, $error],
        );
    }

    /** @param array<string, mixed> $day @param list<array<string, mixed>> $stations @param callable(string): string $urlFor */
    private function stationLeaderText(array $day, array $stations, callable $urlFor): string
    {
        $lines = [
            'Hallo,',
            '',
            'kurze Erinnerung an den Gartenarbeitstag „' . $day['name'] . '“'
                . (!empty($day['event_date']) ? ' am ' . format_date($day['event_date']) : '') . '.',
            '',
            'Deine Stände:',
        ];
        foreach ($stations as $station) {
            $lines[] = '- ' . $station['name'] . (!empty($station['location']) ? ' (' . $station['location'] . ')' : '');
        }
        $lines[] = '';
        $lines[] = 'Teilnehmerlisten: ' . $urlFor('/meine-staende');
        $lines[] = '';
        $lines[] = 'Viele Grüße';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $day
     * @param list<array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $byBlock
     * @param callable(string): string $urlFor
     */
    private function studentText(array $day, array $blocks, array $byBlock, callable $urlFor): string
    {
        $lines = [
            'Hallo,',
            '',
            'dein Zeitplan für den Gartenarbeitstag „' . $day['name'] . '“'
                . (!empty($day['event_date']) ? ' am ' . format_date($day['event_date']) : '') . ':',
            '',
        ];
        foreach ($blocks as $block) {
            $row = $byBlock[(int) $block['id']] ?? null;
            $lines[] = DayQueries::blockLabel($block) . ': '
                . ($row !== null ? $row['station'] . (!empty($row['location']) ? ' (' . $row['location'] . ')' : '') : '–');
        }
        $lines[] = '';
        $lines[] = 'Dein Plan online: ' . $urlFor('/mein-plan');
        $lines[] = '';
        $lines[] = 'Viele Grüße';

        return implode("\n", $lines);
    }
}
