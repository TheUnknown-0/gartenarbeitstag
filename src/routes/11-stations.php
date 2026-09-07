<?php

declare(strict_types=1);

use App\Controllers\StationsController;
use App\Core\Router;

/** Stände des aktiven (oder per ?tag= gewählten) Aktionstags. */
return static function (Router $r): void {
    $r->get('/admin/staende', [StationsController::class, 'index']);
    $r->get('/admin/staende/neu', [StationsController::class, 'create']);
    $r->post('/admin/staende/neu', [StationsController::class, 'store']);
    $r->get('/admin/staende/{id}', [StationsController::class, 'edit']);
    $r->post('/admin/staende/{id}', [StationsController::class, 'update']);
    $r->post('/admin/staende/{id}/aktiv', [StationsController::class, 'toggleActive']);
    $r->post('/admin/staende/{id}/loeschen', [StationsController::class, 'delete']);
    $r->get('/admin/staende/{id}/teilnehmer', [StationsController::class, 'participants']);
};
