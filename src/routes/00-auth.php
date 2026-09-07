<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\SetupController;
use App\Core\Router;

/** Einstieg, Auth, Setup. */
return static function (Router $r): void {
    $r->get('/healthz', [HealthController::class, 'live']);
    $r->get('/readyz', [HealthController::class, 'ready']);

    $r->get('/', [HomeController::class, 'index']);
    $r->get('/keine-rechte', [HomeController::class, 'noPermissions']);

    $r->get('/setup', [SetupController::class, 'show']);
    $r->post('/setup', [SetupController::class, 'run']);

    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout']);

    $r->get('/zugang', [AuthController::class, 'showSitePassword']);
    $r->post('/zugang', [AuthController::class, 'sitePassword']);

    $r->get('/passwort-aendern', [AuthController::class, 'showChangePassword']);
    $r->post('/passwort-aendern', [AuthController::class, 'changePassword']);
};
