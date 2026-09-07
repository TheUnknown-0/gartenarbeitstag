<?php

declare(strict_types=1);

use App\Controllers\CustomizeController;
use App\Core\Router;

/** Darstellung: Farben, Login-Hintergrund, Navigation; Seiten-Anordnung per JSON-API. */
return static function (Router $r): void {
    $r->get('/admin/darstellung', [CustomizeController::class, 'index']);
    $r->post('/admin/darstellung', [CustomizeController::class, 'save']);
    $r->post('/admin/darstellung/zuruecksetzen', [CustomizeController::class, 'reset']);

    $r->post('/api/darstellung/seite', [CustomizeController::class, 'savePageLayout']);
};
