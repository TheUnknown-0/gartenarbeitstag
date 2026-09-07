<?php

declare(strict_types=1);

use App\Controllers\CriteriaController;
use App\Core\Router;

/** Ausschlusskriterien und deren Zuweisung an Schüler:innen. */
return static function (Router $r): void {
    $r->get('/admin/kriterien', [CriteriaController::class, 'index']);
    $r->post('/admin/kriterien', [CriteriaController::class, 'store']);
    $r->get('/admin/kriterien/{id}', [CriteriaController::class, 'edit']);
    $r->post('/admin/kriterien/{id}', [CriteriaController::class, 'update']);
    $r->post('/admin/kriterien/{id}/loeschen', [CriteriaController::class, 'delete']);
    $r->get('/admin/kriterien/{id}/personen', [CriteriaController::class, 'people']);
    $r->post('/admin/kriterien/{id}/personen', [CriteriaController::class, 'assign']);
    $r->post('/admin/kriterien/{id}/personen/{userId}/entfernen', [CriteriaController::class, 'unassign']);
};
