<?php

declare(strict_types=1);

use App\Controllers\AdminEnrollmentsController;
use App\Core\Router;

/** Einschreibungen (Orga-Sicht). */
return static function (Router $r): void {
    $r->get('/admin/einschreibungen', [AdminEnrollmentsController::class, 'index']);
    $r->get('/admin/einschreibungen/offen', [AdminEnrollmentsController::class, 'open']);
    $r->get('/admin/einschreibungen/neu', [AdminEnrollmentsController::class, 'create']);
    $r->post('/admin/einschreibungen/neu', [AdminEnrollmentsController::class, 'store']);
    $r->get('/admin/einschreibungen/{id}/umbuchen', [AdminEnrollmentsController::class, 'rebookForm']);
    $r->post('/admin/einschreibungen/{id}/umbuchen', [AdminEnrollmentsController::class, 'rebook']);
    $r->post('/admin/einschreibungen/{id}/loeschen', [AdminEnrollmentsController::class, 'delete']);
    $r->post('/admin/einschreibungen/{id}/nachruecken', [AdminEnrollmentsController::class, 'promote']);

    $r->get('/api/einschreibungen/pruefen', [AdminEnrollmentsController::class, 'check']);
};
