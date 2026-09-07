<?php

declare(strict_types=1);

use App\Controllers\AssignmentController;
use App\Core\Router;

/** Automatische Zuteilung (Wunschmodus). */
return static function (Router $r): void {
    $r->get('/admin/zuteilung', [AssignmentController::class, 'index']);
    $r->post('/admin/zuteilung/probelauf', [AssignmentController::class, 'simulate']);
    $r->post('/admin/zuteilung/ausfuehren', [AssignmentController::class, 'run']);
    $r->post('/admin/zuteilung/zuruecksetzen', [AssignmentController::class, 'reset']);
};
