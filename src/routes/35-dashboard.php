<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Core\Router;

/** Verwaltungs-Dashboard. */
return static function (Router $r): void {
    $r->get('/admin/dashboard', [DashboardController::class, 'index']);
};
