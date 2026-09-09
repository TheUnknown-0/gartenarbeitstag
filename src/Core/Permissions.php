<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Katalog aller granularen Berechtigungen.
 *
 * Konvention: Jede Berechtigung wird ausschließlich über die Konstante
 * referenziert (nie als String-Literal), damit Umbenennungen sicher sind.
 * Rollen: admin hat implizit alle Rechte; orga/teacher tragen granulare
 * Rechte; student hat nur rollen-spezifische Seiten.
 */
final class Permissions
{
    /** Rollen, die granulare Berechtigungen tragen können. */
    public const GRANULAR_ROLES = ['orga', 'teacher'];

    // Dashboard & Berichte
    public const DASHBOARD_SEHEN = 'dashboard_sehen';
    public const BERICHTE_SEHEN = 'berichte_sehen';
    public const BERICHTE_DRUCKEN = 'berichte_drucken';

    // Aktionstage & Zeitblöcke
    public const AKTIONSTAGE_SEHEN = 'aktionstage_sehen';
    public const AKTIONSTAGE_BEARBEITEN = 'aktionstage_bearbeiten';

    // Stände
    public const STAENDE_SEHEN = 'staende_sehen';
    public const STAENDE_ERSTELLEN = 'staende_erstellen';
    public const STAENDE_BEARBEITEN = 'staende_bearbeiten';
    public const STAENDE_LOESCHEN = 'staende_loeschen';

    // Ausschlusskriterien
    public const KRITERIEN_SEHEN = 'kriterien_sehen';
    public const KRITERIEN_BEARBEITEN = 'kriterien_bearbeiten';
    public const KRITERIEN_ZUWEISEN = 'kriterien_zuweisen';

    // Einschreibungen & Zuteilung
    public const EINSCHREIBUNGEN_SEHEN = 'einschreibungen_sehen';
    public const EINSCHREIBUNGEN_BEARBEITEN = 'einschreibungen_bearbeiten';
    public const EINSCHREIBUNGEN_UEBERSTEUERN = 'einschreibungen_uebersteuern';
    public const EIGENE_STAENDE_BEARBEITEN = 'eigene_staende_bearbeiten';
    public const ZUTEILUNG_AUSFUEHREN = 'zuteilung_ausfuehren';
    public const ZUTEILUNG_ZURUECKSETZEN = 'zuteilung_zuruecksetzen';

    // Anwesenheit
    public const ANWESENHEIT_SEHEN = 'anwesenheit_sehen';
    public const ANWESENHEIT_BEARBEITEN = 'anwesenheit_bearbeiten';

    // Benutzer
    public const BENUTZER_SEHEN = 'benutzer_sehen';
    public const BENUTZER_ERSTELLEN = 'benutzer_erstellen';
    public const BENUTZER_BEARBEITEN = 'benutzer_bearbeiten';
    public const BENUTZER_LOESCHEN = 'benutzer_loeschen';
    public const BENUTZER_IMPORTIEREN = 'benutzer_importieren';
    public const BENUTZER_PASSWORT_ZURUECKSETZEN = 'benutzer_passwort_zuruecksetzen';

    // Berechtigungen
    public const BERECHTIGUNGEN_SEHEN = 'berechtigungen_sehen';
    public const BERECHTIGUNGEN_VERGEBEN = 'berechtigungen_vergeben';
    public const BERECHTIGUNGSGRUPPEN_VERWALTEN = 'berechtigungsgruppen_verwalten';

    // E-Mail
    public const EMAILS_SEHEN = 'emails_sehen';
    public const EMAILS_VERSENDEN = 'emails_versenden';

    // Einstellungen & Sonstiges
    public const EINSTELLUNGEN_SEHEN = 'einstellungen_sehen';
    public const EINSTELLUNGEN_BEARBEITEN = 'einstellungen_bearbeiten';
    public const AUDIT_LOGS_SEHEN = 'audit_logs_sehen';

    /**
     * Katalog: Gruppe => (Key => Label).
     *
     * @return array<string, array<string, string>>
     */
    public static function catalog(): array
    {
        return [
            'Dashboard & Berichte' => [
                self::DASHBOARD_SEHEN => 'Dashboard sehen',
                self::BERICHTE_SEHEN => 'Berichte & Listen sehen',
                self::BERICHTE_DRUCKEN => 'Listen drucken & exportieren',
            ],
            'Aktionstage' => [
                self::AKTIONSTAGE_SEHEN => 'Aktionstage & Zeitblöcke sehen',
                self::AKTIONSTAGE_BEARBEITEN => 'Aktionstage & Zeitblöcke bearbeiten',
            ],
            'Stände' => [
                self::STAENDE_SEHEN => 'Stände sehen',
                self::STAENDE_ERSTELLEN => 'Stände erstellen',
                self::STAENDE_BEARBEITEN => 'Stände bearbeiten (Limits, Leitung, Ausschlüsse)',
                self::STAENDE_LOESCHEN => 'Stände löschen',
            ],
            'Ausschlusskriterien' => [
                self::KRITERIEN_SEHEN => 'Ausschlusskriterien sehen',
                self::KRITERIEN_BEARBEITEN => 'Ausschlusskriterien anlegen & bearbeiten',
                self::KRITERIEN_ZUWEISEN => 'Kriterien Schüler:innen zuweisen',
            ],
            'Einschreibungen & Zuteilung' => [
                self::EINSCHREIBUNGEN_SEHEN => 'Einschreibungen sehen',
                self::EINSCHREIBUNGEN_BEARBEITEN => 'Einschreibungen anlegen, umbuchen & löschen',
                self::EINSCHREIBUNGEN_UEBERSTEUERN => 'Kapazitäts- & Klassenlimits übersteuern',
                self::EIGENE_STAENDE_BEARBEITEN => 'Einschreibungen der eigenen Stände ändern (Standleitung)',
                self::ZUTEILUNG_AUSFUEHREN => 'Automatische Zuteilung ausführen',
                self::ZUTEILUNG_ZURUECKSETZEN => 'Zuteilung zurücksetzen',
            ],
            'Anwesenheit' => [
                self::ANWESENHEIT_SEHEN => 'Anwesenheit sehen',
                self::ANWESENHEIT_BEARBEITEN => 'Anwesenheit erfassen',
            ],
            'Benutzer' => [
                self::BENUTZER_SEHEN => 'Benutzer sehen',
                self::BENUTZER_ERSTELLEN => 'Benutzer erstellen',
                self::BENUTZER_BEARBEITEN => 'Benutzer bearbeiten',
                self::BENUTZER_LOESCHEN => 'Benutzer löschen',
                self::BENUTZER_IMPORTIEREN => 'Benutzer importieren (CSV)',
                self::BENUTZER_PASSWORT_ZURUECKSETZEN => 'Passwörter zurücksetzen',
            ],
            'Berechtigungen' => [
                self::BERECHTIGUNGEN_SEHEN => 'Berechtigungen sehen',
                self::BERECHTIGUNGEN_VERGEBEN => 'Berechtigungen vergeben',
                self::BERECHTIGUNGSGRUPPEN_VERWALTEN => 'Berechtigungsgruppen verwalten',
            ],
            'E-Mail' => [
                self::EMAILS_SEHEN => 'E-Mail-Versand & Protokoll sehen',
                self::EMAILS_VERSENDEN => 'Erinnerungs- & Test-Mails versenden',
            ],
            'Einstellungen & Sonstiges' => [
                self::EINSTELLUNGEN_SEHEN => 'Einstellungen sehen',
                self::EINSTELLUNGEN_BEARBEITEN => 'Einstellungen bearbeiten',
                self::AUDIT_LOGS_SEHEN => 'Audit-Log sehen',
            ],
        ];
    }

    /** @return list<string> Alle gültigen Keys. */
    public static function all(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            $keys = [...$keys, ...array_keys($group)];
        }

        return $keys;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** Darf diese Rolle granulare Rechte tragen? */
    public static function allowsGranular(?string $role): bool
    {
        return $role !== null && in_array($role, self::GRANULAR_ROLES, true);
    }

    /**
     * Voraussetzungen: Key => Liste direkt benötigter Berechtigungen.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        return [
            self::BERICHTE_DRUCKEN => [self::BERICHTE_SEHEN],

            self::AKTIONSTAGE_BEARBEITEN => [self::AKTIONSTAGE_SEHEN],

            self::STAENDE_ERSTELLEN => [self::STAENDE_SEHEN],
            self::STAENDE_BEARBEITEN => [self::STAENDE_SEHEN],
            self::STAENDE_LOESCHEN => [self::STAENDE_SEHEN],

            self::KRITERIEN_BEARBEITEN => [self::KRITERIEN_SEHEN],
            self::KRITERIEN_ZUWEISEN => [self::KRITERIEN_SEHEN, self::BENUTZER_SEHEN],

            self::EINSCHREIBUNGEN_BEARBEITEN => [self::EINSCHREIBUNGEN_SEHEN],
            self::EINSCHREIBUNGEN_UEBERSTEUERN => [self::EINSCHREIBUNGEN_BEARBEITEN],
            self::ZUTEILUNG_AUSFUEHREN => [self::EINSCHREIBUNGEN_SEHEN],
            self::ZUTEILUNG_ZURUECKSETZEN => [self::EINSCHREIBUNGEN_SEHEN],

            self::ANWESENHEIT_BEARBEITEN => [self::ANWESENHEIT_SEHEN],

            self::BENUTZER_ERSTELLEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_BEARBEITEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_LOESCHEN => [self::BENUTZER_SEHEN],
            self::BENUTZER_IMPORTIEREN => [self::BENUTZER_ERSTELLEN],
            self::BENUTZER_PASSWORT_ZURUECKSETZEN => [self::BENUTZER_SEHEN],

            self::BERECHTIGUNGEN_SEHEN => [self::BENUTZER_SEHEN],
            self::BERECHTIGUNGEN_VERGEBEN => [self::BERECHTIGUNGEN_SEHEN],
            self::BERECHTIGUNGSGRUPPEN_VERWALTEN => [self::BERECHTIGUNGEN_SEHEN],

            self::EMAILS_VERSENDEN => [self::EMAILS_SEHEN],

            self::EINSTELLUNGEN_BEARBEITEN => [self::EINSTELLUNGEN_SEHEN],
        ];
    }

    /**
     * Alle Voraussetzungen einer Berechtigung, rekursiv aufgelöst
     * (ohne die Berechtigung selbst).
     *
     * @return list<string>
     */
    public static function requiredFor(string $permission): array
    {
        $deps = self::dependencies();
        $result = [];
        $stack = $deps[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($deps[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Alle Berechtigungen, die (direkt oder indirekt) von der gegebenen
     * abhängen — diese müssen beim Entziehen mit entzogen werden.
     *
     * @return list<string>
     */
    public static function dependentsOf(string $permission): array
    {
        $reverse = [];
        foreach (self::dependencies() as $key => $requires) {
            foreach ($requires as $req) {
                $reverse[$req][] = $key;
            }
        }

        $result = [];
        $stack = $reverse[$permission] ?? [];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($reverse[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return $result;
    }

    /**
     * Standard-Berechtigungen je Rolle (zusätzlich zur Rollenlogik in Auth;
     * admin hat implizit alle Rechte).
     *
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'teacher' => [
                self::ANWESENHEIT_SEHEN,
                self::ANWESENHEIT_BEARBEITEN,
                self::BERICHTE_SEHEN,
                self::BERICHTE_DRUCKEN,
            ],
            default => [],
        };
    }
}
