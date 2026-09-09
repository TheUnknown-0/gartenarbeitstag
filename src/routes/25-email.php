<?php

declare(strict_types=1);

use App\Controllers\EmailController;
use App\Core\Router;

/** E-Mail-Verwaltung: Test-Mail, Erinnerungen, Versandprotokoll. */
return static function (Router $r): void {
    $r->get('/admin/emails', [EmailController::class, 'index']);
    $r->post('/admin/emails/test', [EmailController::class, 'sendTest']);
    $r->post('/admin/emails/standleitungen', [EmailController::class, 'sendStationLeaders']);
    $r->post('/admin/emails/schueler', [EmailController::class, 'sendStudents']);
};
