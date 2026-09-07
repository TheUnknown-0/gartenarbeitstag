<?php

declare(strict_types=1);

use App\Controllers\StudentController;
use App\Core\Router;

/** Schüler:innen-Bereich. */
return static function (Router $r): void {
    $r->get('/uebersicht', [StudentController::class, 'overview']);

    $r->get('/staende', [StudentController::class, 'stations']);
    $r->get('/staende/{id}', [StudentController::class, 'station']);

    $r->get('/einschreibung', [StudentController::class, 'enrollment']);
    $r->post('/einschreibung', [StudentController::class, 'enroll']);
    $r->post('/einschreibung/wuensche', [StudentController::class, 'wishes']);
    $r->post('/einschreibung/{id}/austragen', [StudentController::class, 'withdraw']);

    $r->get('/mein-plan', [StudentController::class, 'plan']);
    $r->get('/mein-plan.pdf', [StudentController::class, 'planPdf']);
};
