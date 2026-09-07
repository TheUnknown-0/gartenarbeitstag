<?php

declare(strict_types=1);

use App\Controllers\AttendanceController;
use App\Core\Router;

/** Anwesenheit (Orga-Sicht) + JSON-API (auch von Standleitungen genutzt). */
return static function (Router $r): void {
    $r->get('/admin/anwesenheit', [AttendanceController::class, 'index']);
    $r->post('/admin/anwesenheit/speichern', [AttendanceController::class, 'save']);

    $r->post('/api/anwesenheit', [AttendanceController::class, 'mark']);
};
