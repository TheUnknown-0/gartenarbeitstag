<?php

declare(strict_types=1);

use App\Controllers\PrintController;
use App\Core\Router;

/**
 * Druckzentrale: Übersichtsseite, PDF-Berichte (BERICHTE_DRUCKEN) und
 * Tabellen-Exporte (BERICHTE_SEHEN).
 */
return static function (Router $r): void {
    $r->get('/admin/druck', [PrintController::class, 'index']);

    $r->get('/admin/druck/staende.pdf', [PrintController::class, 'stationLists']);
    $r->get('/admin/druck/klassen.pdf', [PrintController::class, 'classLists']);
    $r->get('/admin/druck/belegung.pdf', [PrintController::class, 'occupancy']);

    $r->get('/admin/druck/einschreibungen.csv', [PrintController::class, 'enrollmentsCsv']);
    $r->get('/admin/druck/einschreibungen.xlsx', [PrintController::class, 'enrollmentsXlsx']);
    $r->get('/admin/druck/offen.csv', [PrintController::class, 'openCsv']);
    $r->get('/admin/druck/anwesenheit.csv', [PrintController::class, 'attendanceCsv']);
};
