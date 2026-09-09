-- Dritter Zuteilungsmodus "Quote": Orga legt je Stand+Zeitblock+Klassenstufe
-- eine Zielzahl fest, ein Algorithmus verteilt zufaellig passende Schueler:innen
-- (angelehnt an github.com/schlaumischlumpf/Gartenarbeitstag-Tool).
-- Schueler:innen waehlen/wuenschen in diesem Modus nichts selbst.

SET NAMES utf8mb4;

ALTER TABLE garden_days
    MODIFY COLUMN mode ENUM('direct','wishlist','quota') NOT NULL DEFAULT 'direct';

ALTER TABLE enrollments
    MODIFY COLUMN source ENUM('self','auto','orga','quota') NOT NULL DEFAULT 'self';

-- Zielzahl je Stand, Zeitblock und Klassenstufe.
CREATE TABLE IF NOT EXISTS station_grade_demand (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id INT UNSIGNED NOT NULL,
    time_block_id INT UNSIGNED NOT NULL,
    grade TINYINT UNSIGNED NOT NULL,
    demand SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_station_grade_demand (station_id, time_block_id, grade),
    KEY idx_station_grade_demand_block (time_block_id),
    CONSTRAINT fk_sgd_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    CONSTRAINT fk_sgd_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optionale Gewichtung: Ist fuer einen Stand mindestens eine Zeile hinterlegt,
-- werden nur diese Klassen (gewichtet nach "weight") beruecksichtigt statt
-- Round-Robin ueber alle Klassen der Stufe.
CREATE TABLE IF NOT EXISTS station_class_preference (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id INT UNSIGNED NOT NULL,
    class VARCHAR(50) NOT NULL,
    weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_station_class_preference (station_id, class),
    CONSTRAINT fk_scp_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserviert eine ganze Klasse fuer einen Zeitblock+Stufe, damit sie dort
-- garantiert zuerst zum Zug kommt (z.B. Klassenausflug-Slot).
CREATE TABLE IF NOT EXISTS garden_day_slot_priority (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    garden_day_id INT UNSIGNED NOT NULL,
    time_block_id INT UNSIGNED NOT NULL,
    grade TINYINT UNSIGNED NOT NULL,
    class VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_garden_day_slot_priority (garden_day_id, time_block_id, grade),
    CONSTRAINT fk_gdsp_day FOREIGN KEY (garden_day_id) REFERENCES garden_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_gdsp_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
