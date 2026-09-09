<?php

declare(strict_types=1);

use App\Controllers\CustomReportController;
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
    $r->get('/admin/druck/einschreibungen.pdf', [PrintController::class, 'enrollmentsPdf']);
    $r->get('/admin/druck/offen.pdf', [PrintController::class, 'openPdf']);
    $r->get('/admin/druck/anwesenheit.pdf', [PrintController::class, 'attendancePdf']);
    $r->get('/admin/druck/staende-uebersicht.pdf', [PrintController::class, 'stationsOverviewPdf']);

    $r->get('/admin/druck/einschreibungen.csv', [PrintController::class, 'enrollmentsCsv']);
    $r->get('/admin/druck/einschreibungen.xlsx', [PrintController::class, 'enrollmentsXlsx']);
    $r->get('/admin/druck/offen.csv', [PrintController::class, 'openCsv']);
    $r->get('/admin/druck/anwesenheit.csv', [PrintController::class, 'attendanceCsv']);

    $r->get('/admin/druck/eigene-liste', [CustomReportController::class, 'index']);
    $r->get('/admin/druck/eigene-liste.pdf', [CustomReportController::class, 'pdf']);
    $r->post('/admin/druck/eigene-liste/speichern', [CustomReportController::class, 'save']);
    $r->post('/admin/druck/eigene-liste/vorlagen/{id}/loeschen', [CustomReportController::class, 'deleteTemplate']);
};
