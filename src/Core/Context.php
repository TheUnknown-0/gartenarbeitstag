<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Audit;
use App\Services\Settings;

/**
 * Anwendungs-Kontext: hält alle Kern-Dienste sowie den aktiven Aktionstag.
 */
final class Context
{
    /** @var array<string, mixed>|null|false false = noch nicht geladen */
    private array|null|false $activeDay = false;

    public function __construct(
        public readonly array $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Csrf $csrf,
        public readonly Auth $auth,
        public readonly View $view,
        public readonly Settings $settings,
        public readonly Audit $audit,
    ) {
    }

    /** Aktiver Aktionstag (status = active) oder null. */
    public function activeDay(): ?array
    {
        if ($this->activeDay === false) {
            $this->activeDay = $this->db->fetchOne(
                "SELECT * FROM garden_days WHERE status = 'active' ORDER BY event_date DESC, id DESC LIMIT 1",
            );
        }

        return $this->activeDay;
    }

    public function activeDayId(): ?int
    {
        $day = $this->activeDay();

        return $day === null ? null : (int) $day['id'];
    }

    /** Aktiver Aktionstag, der für Kernfunktionen zwingend vorhanden sein muss. */
    public function requireActiveDay(): array
    {
        $day = $this->activeDay();
        if ($day === null) {
            throw new HttpException(404, 'Es ist aktuell kein Aktionstag aktiv.');
        }

        return $day;
    }

    /** Cache verwerfen (nach Statuswechsel eines Aktionstags). */
    public function resetActiveDay(): void
    {
        $this->activeDay = false;
    }

    /** Basis-URL-bewusste absolute URL (Pfad muss mit / beginnen). */
    public function url(string $path = '/'): string
    {
        return ($this->config['app']['base_url'] ?? '') . $path;
    }

    /** Konfigurierte öffentliche Basis-Adresse (Schema + Host [+ Pfad]) oder null. */
    public function configuredPublicBase(): ?string
    {
        $candidate = $this->settings->get('public_base_url');
        if (is_string($candidate) && trim($candidate) !== '') {
            return rtrim(trim($candidate), '/');
        }

        return null;
    }

    /**
     * Öffentliche Adresse der Anwendungswurzel. Ein hinterlegter Wert gilt
     * vollständig; nur der Rückfall auf den Request-Host ergänzt BASE_URL.
     */
    public function publicBase(): string
    {
        $base = $this->configuredPublicBase();
        if ($base !== null) {
            return $base;
        }

        $scheme = (($_SERVER['HTTPS'] ?? 'off') !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . ($this->config['app']['base_url'] ?? '');
    }

    public function baseIsGuessed(): bool
    {
        return $this->configuredPublicBase() === null;
    }

    public function publicUrl(string $path = '/'): string
    {
        return $this->publicBase() . $path;
    }
}
