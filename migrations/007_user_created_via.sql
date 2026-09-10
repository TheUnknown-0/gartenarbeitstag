-- Woher stammt ein Benutzerkonto? 'manual' (Formular/Setup) oder 'import' (CSV).
-- Ermoeglicht das gesammelte, vollstaendige Loeschen aller per CSV importierten
-- Schueler:innen durch Administratoren.

ALTER TABLE users
    ADD COLUMN created_via ENUM('manual', 'import') NOT NULL DEFAULT 'manual' AFTER role;

-- Bestandsdaten: bislang war der CSV-Import der einzige Weg, Schueler:innen in
-- groesserer Zahl anzulegen. Alle bereits vorhandenen Schueler:innen gelten
-- daher als importiert. (Bei einer frischen Installation existiert zu diesem
-- Zeitpunkt nur das Setup-Admin-Konto, die Zeile trifft also niemanden.)
UPDATE users SET created_via = 'import' WHERE role = 'student';
