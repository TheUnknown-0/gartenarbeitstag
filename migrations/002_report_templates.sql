-- „Eigene Liste": gespeicherte Konfigurationen des freien Report-Baukastens
-- (Datenquelle, Spaltenauswahl/-reihenfolge, Filter, Gruppierung, Format).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS report_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    data_source VARCHAR(30) NOT NULL,
    config JSON NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_report_templates_source (data_source),
    CONSTRAINT fk_report_templates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
