<?php

declare(strict_types=1);

use App\Controllers\TeacherController;
use App\Core\Router;

/**
 * Lehrkräfte-Bereich: Klassenübersicht und „Meine Stände“ (Standleitung).
 *
 * Alle Guards (Rolle teacher/orga/admin, Standleitung, Rechte) prüft
 * TeacherController selbst am Anfang jeder Aktion — siehe dort.
 */
return static function (Router $r): void {
    $r->get('/klassen', [TeacherController::class, 'classes']);

    $r->get('/meine-staende', [TeacherController::class, 'myStations']);
    $r->get('/meine-staende/{id}', [TeacherController::class, 'station']);
    $r->get('/meine-staende/{id}/liste.pdf', [TeacherController::class, 'listPdf']);

    $r->post('/meine-staende/{id}/einschreiben', [TeacherController::class, 'enroll']);
    $r->post('/meine-staende/{id}/einschreibungen/{eid}/austragen', [TeacherController::class, 'withdraw']);
    $r->post('/meine-staende/{id}/einschreibungen/{eid}/nachruecken', [TeacherController::class, 'promote']);
};
