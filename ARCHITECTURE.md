# Gartenarbeitstag — Architektur & verbindliche Konventionen

Diese Datei ist die Referenz für alle, die an der Anwendung arbeiten. Sie beschreibt den
Aufbau, die fachlichen Regeln (Limits, Ausschlüsse, Zeitkonflikte) und die Konventionen,
die jeder neue Code einhalten muss.

## Überblick

- **Stack:** PHP 8.3, eigenes schlankes MVC (kein Framework), MariaDB 11.4, serverseitig
  gerenderte PHP-Templates, Vanilla-JS, strikte Content-Security-Policy (`script-src 'self'`).
- **Ein Mandant:** Genau eine Schule. Es gibt keine Schul-Slugs in URLs.
- **Mehrere Aktionstage:** `garden_days` (Entwurf → aktiv → archiviert). Genau ein Tag ist
  aktiv; alle Schüler-/Orga-Seiten beziehen sich auf ihn (`Context::activeDay()`).
- **Keine externen Laufzeit-Abhängigkeiten:** FPDF liegt unter `lib/`, Fonts und JS lokal
  unter `public/assets/`. Die App läuft vollständig offline im Schulnetz.

```
public/index.php        Front-Controller (CSP, Fehlerseiten, Routing)
src/bootstrap.php       baut Context (config, db, session, csrf, auth, view, settings, audit)
src/Core/               Router, Database, Session, Csrf, Auth, Permissions, Context, PageBlocks, View
src/Controllers/        ein Controller je Modul
src/Services/           Fachlogik (LimitCheck, EnrollmentService, AutoAssign, DayCloner, Pdf, Exports …)
src/routes/NN-modul.php Routen je Modul (Reihenfolge = Ladereihenfolge)
templates/              layouts/, partials/, pages/<modul>/
migrations/             versionierte SQL-Migrationen, laufen beim Container-Start
tests/                  PHPUnit (Unit + Integration gegen eigene Test-DB)
```

## Request-Fluss

`public/index.php` → `src/bootstrap.php` (liefert `Context $ctx`) → registriert alle
`src/routes/*.php` → Router matcht → `new Controller($ctx)` → Aktion `name(array $params)`.

Rückgabe einer Aktion: `string` = HTML, `array` = JSON, oder `$this->redirect(...)`.
Fehler via `throw new HttpException(403)`; bei Pfaden mit `/api/` antwortet der
Front-Controller mit JSON statt einer Fehlerseite.

## Datenmodell (Kern)

Vollständig in `migrations/001_init.sql`. Die wichtigsten Tabellen:

| Tabelle | Zweck |
|---|---|
| `garden_days` | Aktionstag: Datum, Status, **Modus** (`direct` / `wishlist`), Anmeldefenster, `min_blocks_per_student`, `max_blocks_per_student`, `wishes_per_block`, `waitlist_enabled`, `assignment_done_at` |
| `time_blocks` | Zeitblöcke eines Tages (z. B. Vormittag, Nachmittag) |
| `stations` | Stände: Beschreibung, Ort, Material, **Limits** (`max_per_class`, `max_per_grade`, `allowed_grades`, `allowed_classes`, `min_students`), aktiv |
| `station_blocks` | Welcher Stand wird in welchem Block mit welcher **Kapazität** angeboten |
| `station_leaders` | Standleitungen (Lehrkräfte/Orga, n:m) |
| `exclusion_criteria` / `user_exclusions` / `station_exclusions` | Ausschlusskriterien, ihre Zuweisung an Personen und an Stände |
| `enrollments` | Einschreibungen: `status` = `assigned` (fest) / `waitlist` / `wish` (Prio), `source` = `self` / `auto` / `orga`, `override_note` |
| `attendance` | Anwesenheit je fester Einschreibung |
| `users`, `user_permissions`, `permission_groups`, … | Konten (Rollen `admin`, `orga`, `teacher`, `student`), granulare Rechte |
| `settings`, `audit_logs`, `login_attempts` | Einstellungen (key/value), Protokoll, Login-Drossel |

**Datenbankseitige Garantie:** `enrollments.assigned_block_key` ist eine generierte Spalte
(`time_block_id`, wenn `status = 'assigned'`, sonst NULL) mit UNIQUE über
`(user_id, assigned_block_key)`. Damit kann eine Person **niemals** zwei feste Einschreibungen
im selben Zeitblock haben — auch nicht durch einen Bug oder direkten INSERT.

## Fachregeln: Limits, Ausschlüsse, Zeitkonflikte

Es gibt **genau eine Stelle**, die entscheidet, ob eine Person an einen Stand darf:
`Services\LimitCheck::check($student, $station, $timeBlockId, $day, $status, $options)`.
Sie liefert eine Liste von Verstößen `{code, message, hard}`.

| Code | Bedeutung | hart? |
|---|---|---|
| `exclusion` | Person trägt ein Ausschlusskriterium, das am Stand hinterlegt ist | **ja** |
| `time_conflict` | Person ist im selben Block bereits fest eingeschrieben | **ja** |
| `duplicate` | Eintrag für Person+Stand+Block existiert bereits | **ja** |
| `station_inactive`, `no_block`, `day_not_active` | Stand deaktiviert / nicht in diesem Block angeboten / Tag nicht aktiv | **ja** |
| `capacity` | Stand im Block voll | nein |
| `class_limit`, `grade_limit` | max. X aus derselben Klasse / Stufe erreicht | nein |
| `grade_not_allowed`, `class_not_allowed` | Stand nur für bestimmte Stufen/Klassen geöffnet | nein |
| `max_blocks` | Höchstzahl Blöcke je Person erreicht | nein |
| `window_closed` | Anmeldefenster (nur bei Selbstbedienung durch Schüler:innen) | nein |

- **Harte Verstöße sind nie übersteuerbar.** Ein Ausschlusskriterium kann nur dadurch
  aufgehoben werden, dass es der Person entfernt wird (Benutzerverwaltung / Kriterien-Seite).
- **Weiche Verstöße** dürfen Orga-Kräfte mit `EINSCHREIBUNGEN_UEBERSTEUERN` nach expliziter
  Bestätigung und mit Begründung übersteuern. Das wird in `enrollments.override_note` und im
  Audit-Log (Severity `warning`) festgehalten.
- Wünsche (`wish`) werden nur gegen Ausschluss, Whitelist und Duplikate geprüft — Kapazität
  und Limits greifen erst bei der Zuteilung.
- Mindestbesetzung (`min_students`) ist nur eine Warnung (Dashboard, Standliste, Bericht).

Alle schreibenden Operationen laufen über `Services\EnrollmentService`
(`create`, `delete`, `rebook`, `promoteWaitlist`, `replaceWishes`). Wird ein Verstoß nicht
übersteuert, wirft der Service `LimitViolation` (`violations()`, `overridable()`).

## Anmeldemodi

- **`direct` (Sofortbuchung):** Schüler:innen schreiben sich selbst ein; ist der Stand voll
  und `waitlist_enabled`, landen sie auf der Warteliste. Wird ein Platz frei, rückt der
  älteste regelkonforme Wartelisten-Eintrag automatisch nach.
- **`wishlist` (Wunschmodus):** Schüler:innen geben je Block bis zu `wishes_per_block`
  Wünsche mit Priorität ab. Die Orga startet `Services\AutoAssign` — zuerst als **Probelauf**
  (gleiche Logik in einer Transaktion, die zurückgerollt wird), dann scharf. Der Algorithmus
  arbeitet blockweise in Prioritätsrunden, mit fairer Reihenfolge (wer bisher schlechter
  bedient wurde, kommt zuerst dran) und reproduzierbarem Seed. Bestehende Orga-Einschreibungen
  bleiben erhalten; „Zurücksetzen“ entfernt nur automatisch vergebene Plätze.

## Controller-Pattern (Pflicht)

```php
final class XyController extends Controller
{
    /** GET /admin/xy */
    public function index(array $params): string
    {
        $this->requirePermission(P::XY_SEHEN);          // enthält requireLogin()
        $day = $this->ctx->requireActiveDay();          // 404, wenn kein Tag aktiv

        $rows = $this->ctx->db->fetchAll('SELECT … WHERE garden_day_id = ?', [(int) $day['id']]);
        return $this->render('pages/xy/index', ['title' => 'Xy', 'rows' => $rows]);
    }

    /** POST /admin/xy */
    public function store(array $params): string
    {
        $this->requirePermission(P::XY_BEARBEITEN);
        $this->requireCsrf();                           // bei JEDEM schreibenden Request
        // … validieren, $this->ctx->db->run(...), $this->ctx->audit->log('xy.create', 'info', '…');
        $this->flash('success', 'Gespeichert.');
        $this->redirect($this->ctx->url('/admin/xy'));
    }
}
```

Guards/Helfer aus `Controller`: `requireLogin()`, `requirePermission($p)`, `requireAdmin()`,
`requireCsrf()`, `render($tpl, $data, $layout = 'app')`, `redirect($url)`,
`flash($type, $msg)`, `jsonInput()`, `jsonError($msg, $status)`.

Kontext (`$this->ctx`): `db`, `auth` (`user()`, `id()`, `role()`, `can($p)`, `isAdmin()`,
`leadsStation($id)`), `settings` (`get/getBool/getInt/set/delete`), `audit->log($action, $severity, $details)`,
`csrf`, `session`, `view`, `config`, `activeDay()`, `activeDayId()`, `requireActiveDay()`,
`resetActiveDay()`, `url($path)`, `publicUrl($path)`.

## Sicherheits-Pflichten

1. **Jede** Ausgabe in Templates durch `e(...)`.
2. **Jeder** POST → `requireCsrf()` (Formularfeld `<?= $csrf->field() ?>` oder Header `X-CSRF-Token`, den `BM.fetchJson` setzt).
3. **Nur** Prepared Statements mit `?`-Parametern.
4. **Jede** Route ist login- und berechtigungsgeschützt (`requirePermission`/`requireAdmin`
   oder ausdrückliche Rollenprüfung mit 403). `tests/Unit/RouteGuardsTest.php` prüft das.
5. Berechtigungen nur über `Permissions::KONSTANTEN`. Granulare Rechte tragen nur
   `orga` und `teacher`; `admin` hat immer alles, `student` nie etwas.
6. Datenzugriff auf Einschreibungen immer mit Eigentums-/Tagesbindung
   (`WHERE id = ? AND user_id = ?` bzw. `AND garden_day_id = ?`).
7. Uploads nur über `Services\Uploads` (Subdirs `logos`, `branding`), Auslieferung über `/medien/…`.
8. Passwort-Mindestlänge 8 (`AuthController::MIN_PASSWORD_LENGTH`).
9. CSP strikt: kein Inline-JS, keine `onclick=`. Seiten-JS unter `public/assets/js/`, eingebunden
   über `'pageScripts' => ['modul.js']`.
10. Ausschlusskriterien sind sensibel: Schüler:innen bekommen Kriteriennamen **nie** angezeigt,
    nur eine neutrale Markierung „für dich nicht verfügbar“.

## Routen

| Bereich | Pfade |
|---|---|
| Global | `/`, `/login`, `/logout`, `/zugang`, `/setup`, `/passwort-aendern`, `/keine-rechte`, `/medien/logos/{file}`, `/healthz`, `/readyz` |
| Schüler:innen | `/uebersicht`, `/staende`, `/staende/{id}`, `/einschreibung`, `/mein-plan` |
| Lehrkräfte / Standleitung | `/klassen`, `/meine-staende`, `/meine-staende/{id}` |
| Verwaltung | `/admin/` + `dashboard`, `aktionstage`, `staende`, `einschreibungen`, `zuteilung`, `anwesenheit`, `kriterien`, `benutzer`, `berechtigungen`, `druck`, `einstellungen`, `darstellung`, `audit-log` |
| APIs | `/api/…` (JSON, CSRF via Header) |

`GET /` leitet rollenabhängig weiter (siehe `HomeController`).

## Templates & Design-System

- Template je Seite unter `templates/pages/<modul>/name.php`; immer `'title'` mitgeben.
- Verfügbar: `$ctx`, `$auth`, `$csrf`, `$view` + übergebene Daten. `e()`, `format_date()`, `format_datetime()`.
- CSS ausschließlich aus `public/assets/css/app.css` (Bausteine: `page-header`, `card`,
  `stat-grid`, `btn-*`, `field`/`input`, `form-grid`, `table-wrap`/`data-table`, `badge-*`,
  `alert-*`, `tabs`, `grid-2/3`, `stack`, `cluster`, `empty-state`, `progress`, `modal`).
- Bestätigungen per `data-confirm="…"`, Modals per `data-open-modal`.
- Icons: Emoji.

## Seiten-Blöcke (Anordnen-Modus)

Inhaltliche Übersichtsseiten deklarieren ihre Abschnitte als Blöcke, damit Admins sie per
Drag & Drop anordnen/ausblenden können (`?anordnen=1`, Kern `src/Core/PageBlocks.php`,
Speicherung als Setting `page_layout:{pageKey}`):

```php
<?php foreach (page_blocks('seiten-key', ['a' => 'Abschnitt A', 'b' => 'Abschnitt B']) as $key => $label): ?>
<?= block_open($key, $label) ?>
<?php if ($key === 'a'): ?> … <?php elseif ($key === 'b'): ?> … <?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
```

`page-header`, Modals und Datalists bleiben außerhalb der Blöcke.

## Tests

- `tests/Unit/` läuft ohne Datenbank (Rechtekatalog, Routen-Guards).
- `tests/Integration/` läuft gegen eine **eigene** Datenbank (`gartenarbeitstag_test`), das
  Schema wird aus `migrations/` aufgebaut, jeder Test in einer zurückgerollten Transaktion.
- Ausführen siehe README („Tests“).

## Deutsch & Stil

UI durchgehend Deutsch (Du-Form für Schüler:innen, neutral in der Verwaltung).
`declare(strict_types=1);` in jeder PHP-Datei, typisierte Signaturen, kurze deutsche Kommentare.
