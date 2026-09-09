-- Stände, die nur durch manuelle Einschreibung (Standleitung/Orga mit
-- Berechtigung) besetzt werden dürfen — Selbstbedienung und automatische
-- Zuteilung lassen diese Stände aussen vor.

SET NAMES utf8mb4;

ALTER TABLE stations
    ADD COLUMN manual_only TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
