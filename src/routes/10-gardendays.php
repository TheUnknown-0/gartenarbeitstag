<?php

declare(strict_types=1);

use App\Controllers\GardenDaysController;
use App\Core\Router;

/** Aktionstage inkl. Zeitblöcke und Klonen. */
return static function (Router $r): void {
    $r->get('/admin/aktionstage', [GardenDaysController::class, 'index']);
    $r->get('/admin/aktionstage/neu', [GardenDaysController::class, 'create']);
    $r->post('/admin/aktionstage/neu', [GardenDaysController::class, 'store']);
    $r->get('/admin/aktionstage/{id}', [GardenDaysController::class, 'edit']);
    $r->post('/admin/aktionstage/{id}', [GardenDaysController::class, 'update']);
    $r->post('/admin/aktionstage/{id}/status', [GardenDaysController::class, 'status']);
    $r->post('/admin/aktionstage/{id}/loeschen', [GardenDaysController::class, 'delete']);

    $r->get('/admin/aktionstage/{id}/klonen', [GardenDaysController::class, 'showClone']);
    $r->post('/admin/aktionstage/{id}/klonen', [GardenDaysController::class, 'clone']);

    $r->post('/admin/aktionstage/{id}/zeitbloecke', [GardenDaysController::class, 'storeBlock']);
    $r->post('/admin/aktionstage/{id}/zeitbloecke/{blockId}', [GardenDaysController::class, 'updateBlock']);
    $r->post('/admin/aktionstage/{id}/zeitbloecke/{blockId}/loeschen', [GardenDaysController::class, 'deleteBlock']);
};
