<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\PageBlocks;
use App\Services\Customization;
use App\Services\Uploads;

/**
 * „Darstellung" — Farben, Login-Hintergrund sowie Anordnung/Sichtbarkeit
 * der Navigation. Nur für Administrator:innen (siehe Sidebar/Topbar).
 *
 * Alle Formulare der Seite laufen über die eine POST-Route
 * (unterschieden per verstecktem Feld "section"), damit die Routenliste
 * schlank bleibt; die Block-Anordnung einzelner Seiten läuft separat über
 * die JSON-API (siehe savePageLayout, aufgerufen von arrange.js).
 */
final class CustomizeController extends Controller
{
    /** GET /admin/darstellung */
    public function index(array $params): string
    {
        $this->requireAdmin();
        $customization = new Customization($this->ctx->settings);

        return $this->render('pages/customize/index', [
            'title' => 'Darstellung',
            'theme' => $customization->theme(),
            'navLayout' => $customization->navLayout(),
            'navSections' => self::navCatalog(),
            'pageScripts' => ['customize.js'],
        ]);
    }

    /** POST /admin/darstellung */
    public function save(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/darstellung');
        $settings = $this->ctx->settings;
        $section = (string) ($_POST['section'] ?? '');

        if ($section === 'farben') {
            if (isset($_POST['reset'])) {
                $settings->delete('custom_primary');
                $settings->delete('custom_bg');
                $this->ctx->audit->log('darstellung.farben_zuruecksetzen', 'info', 'Farben auf Standard zurückgesetzt');
                $this->flash('success', 'Farben auf den Standard zurückgesetzt.');
                $this->redirect($back);
            }

            foreach (['custom_primary' => 'primary', 'custom_bg' => 'bg'] as $key => $field) {
                $useDefault = ($_POST[$field . '_default'] ?? '') === '1';
                $value = trim((string) ($_POST[$field] ?? ''));
                if ($useDefault || $value === '') {
                    $settings->delete($key);
                    continue;
                }
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                    $this->flash('error', 'Bitte gültige Farben im Format #rrggbb wählen.');
                    $this->redirect($back);
                }
                $settings->set($key, strtolower($value));
            }

            $this->ctx->audit->log('darstellung.farben', 'info', 'Farben geändert');
            $this->flash('success', 'Farben gespeichert.');
            $this->redirect($back);
        }

        if ($section === 'hintergrund') {
            $uploads = new Uploads($this->ctx->config['uploads']['dir']);
            $current = $settings->get('custom_login_image');

            if (isset($_POST['remove'])) {
                if ($current !== null) {
                    $uploads->delete('branding', $current);
                    $settings->delete('custom_login_image');
                }
                $this->ctx->audit->log('darstellung.hintergrund_entfernen', 'info', 'Login-Hintergrund entfernt');
                $this->flash('success', 'Hintergrundbild entfernt.');
                $this->redirect($back);
            }

            if ((int) ($_FILES['background']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $this->flash('error', 'Bitte eine Bilddatei auswählen.');
                $this->redirect($back);
            }
            $stored = $uploads->store($_FILES['background'] ?? [], 'branding', ['jpg', 'jpeg', 'png', 'webp'], 4 * 1024 * 1024);
            if ($current !== null) {
                $uploads->delete('branding', $current);
            }
            $settings->set('custom_login_image', $stored['filename']);

            $this->ctx->audit->log('darstellung.hintergrund', 'info', $stored['original_name']);
            $this->flash('success', 'Hintergrundbild gespeichert.');
            $this->redirect($back);
        }

        if ($section === 'navigation') {
            $catalog = self::navCatalog();
            $clean = [];
            foreach (Customization::NAV_SECTIONS as $sectionKey) {
                $valid = array_keys($catalog[$sectionKey][1] ?? []);

                $order = [];
                foreach ((array) ($_POST['order'][$sectionKey] ?? []) as $key) {
                    if (is_string($key) && in_array($key, $valid, true) && !in_array($key, $order, true)) {
                        $order[] = $key;
                    }
                }
                $hidden = [];
                foreach ((array) ($_POST['hidden'][$sectionKey] ?? []) as $key) {
                    if (is_string($key) && in_array($key, $valid, true) && !in_array($key, $hidden, true)) {
                        $hidden[] = $key;
                    }
                }
                $clean[$sectionKey] = ['order' => $order, 'hidden' => $hidden];
            }

            $settings->set('nav_layout', json_encode($clean, JSON_UNESCAPED_UNICODE));
            $this->ctx->audit->log('darstellung.navigation', 'info', 'Navigation angepasst');
            $this->flash('success', 'Navigation gespeichert.');
            $this->redirect($back);
        }

        $this->flash('error', 'Unbekannter Speichervorgang.');
        $this->redirect($back);
    }

    /** POST /admin/darstellung/zuruecksetzen — Farben & Anordnung auf Standard. */
    public function reset(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        foreach (['custom_primary', 'custom_bg', 'nav_layout'] as $key) {
            $this->ctx->settings->delete($key);
        }
        // Alle gespeicherten Seiten-Anordnungen entfernen (Logo & Login-Hintergrund bleiben erhalten)
        $this->ctx->db->run("DELETE FROM settings WHERE setting_key LIKE 'page\\_layout:%'");

        $this->ctx->audit->log('darstellung.zuruecksetzen', 'warning', 'Darstellung komplett zurückgesetzt');
        $this->flash('success', 'Darstellung auf den Standard zurückgesetzt (Logo und Hintergrundbild bleiben erhalten).');
        $this->redirect($this->ctx->url('/admin/darstellung'));
    }

    /**
     * POST /api/darstellung/seite — Block-Anordnung einer Seite speichern
     * (JSON: {page, role, order, hidden} bzw. {..., reset: true}), aufgerufen
     * aus dem „Anordnen"-Modus (siehe public/assets/js/arrange.js).
     */
    public function savePageLayout(array $params): array
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $payload = $this->jsonInput();

        $page = (string) ($payload['page'] ?? '');
        if (preg_match('/^[a-z0-9-]{1,64}$/', $page) !== 1 || $page === 'darstellung') {
            return $this->jsonError('Ungültige Seite.');
        }

        $role = (string) ($payload['role'] ?? '');
        if ($role !== '' && !in_array($role, PageBlocks::ROLE_GROUPS, true)) {
            return $this->jsonError('Ungültige Rolle.');
        }
        $settingKey = 'page_layout:' . $page . ($role !== '' ? ':' . $role : '');
        $detail = 'Seite: ' . $page . ($role !== '' ? ' (Rolle: ' . $role . ')' : ' (Basis)');

        if (($payload['reset'] ?? false) === true) {
            $this->ctx->settings->delete($settingKey);
            $this->ctx->audit->log('darstellung.seite_zuruecksetzen', 'info', $detail);

            return ['success' => true];
        }

        $order = [];
        foreach ((array) ($payload['order'] ?? []) as $key) {
            if (is_string($key) && preg_match('/^[a-z0-9-]{1,40}$/', $key) === 1 && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $hidden = [];
        foreach ((array) ($payload['hidden'] ?? []) as $key) {
            if (is_string($key) && preg_match('/^[a-z0-9-]{1,40}$/', $key) === 1 && !in_array($key, $hidden, true)) {
                $hidden[] = $key;
            }
        }

        $this->ctx->settings->set($settingKey, json_encode(['order' => $order, 'hidden' => $hidden], JSON_UNESCAPED_UNICODE));
        $this->ctx->audit->log('darstellung.seite', 'info', $detail);

        return ['success' => true];
    }

    /**
     * Katalog aller anpassbaren Navigationseinträge je Bereich — muss mit
     * templates/partials/sidebar.php übereinstimmen.
     *
     * @return array<string, array{string, array<string, array{string, string}>}>
     */
    public static function navCatalog(): array
    {
        return [
            'student' => ['Schüler:innen', [
                'uebersicht' => ['🏠', 'Übersicht'],
                'staende' => ['🌱', 'Stände'],
                'einschreibung' => ['📝', 'Einschreibung'],
                'mein-plan' => ['🗓️', 'Mein Plan'],
            ]],
            'teacher' => ['Lehrkräfte / Standleitung', [
                'meine-staende' => ['🌱', 'Meine Stände'],
                'klassen' => ['🧑‍🏫', 'Klassen'],
                'staende' => ['📋', 'Alle Stände'],
            ]],
            'admin' => ['Verwaltung', [
                'dashboard' => ['📊', 'Dashboard'],
                'aktionstage' => ['📅', 'Aktionstage'],
                'staende' => ['🌱', 'Stände'],
                'einschreibungen' => ['📝', 'Einschreibungen'],
                'zuteilung' => ['🎯', 'Zuteilung'],
                'anwesenheit' => ['✅', 'Anwesenheit'],
                'kriterien' => ['🚫', 'Ausschlusskriterien'],
                'benutzer' => ['👥', 'Benutzer'],
                'berechtigungen' => ['🔑', 'Berechtigungen'],
                'druck' => ['🖨️', 'Listen & Export'],
                'einstellungen' => ['⚙️', 'Einstellungen'],
                'darstellung' => ['🎨', 'Darstellung'],
                'audit-log' => ['📜', 'Audit-Log'],
            ]],
        ];
    }
}
