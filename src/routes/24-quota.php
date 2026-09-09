<?php

declare(strict_types=1);

use App\Controllers\QuotaController;
use App\Core\Router;

/** Quotenmodus: Bedarf/Präferenzen/Priorität pflegen, Zuteilung erzeugen. */
return static function (Router $r): void {
    $r->get('/admin/quote', [QuotaController::class, 'index']);
    $r->post('/admin/quote/bedarf', [QuotaController::class, 'saveDemand']);
    $r->post('/admin/quote/praeferenzen', [QuotaController::class, 'savePreferences']);
    $r->post('/admin/quote/prioritaet', [QuotaController::class, 'savePriority']);
    $r->post('/admin/quote/probelauf', [QuotaController::class, 'simulate']);
    $r->post('/admin/quote/generieren', [QuotaController::class, 'run']);
    $r->post('/admin/quote/zuruecksetzen', [QuotaController::class, 'reset']);
};
