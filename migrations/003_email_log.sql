-- Protokoll versendeter E-Mails (Erinnerungen, Test-Mails) — verhindert
-- Doppelversand und macht Fehlschläge nachvollziehbar.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    garden_day_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template VARCHAR(100) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_email_log_day_template (garden_day_id, template),
    KEY idx_email_log_user (user_id),
    CONSTRAINT fk_email_log_day FOREIGN KEY (garden_day_id) REFERENCES garden_days(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
