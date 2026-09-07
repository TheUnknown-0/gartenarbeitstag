-- Gartenarbeitstag – Grundschema (eine Schule, mehrere Aktionstage)

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Aktionstage
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS garden_days (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    event_date DATE NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    mode ENUM('direct','wishlist') NOT NULL DEFAULT 'direct',
    registration_start DATETIME NULL,
    registration_end DATETIME NULL,
    min_blocks_per_student TINYINT UNSIGNED NOT NULL DEFAULT 1,
    max_blocks_per_student TINYINT UNSIGNED NULL,
    wishes_per_block TINYINT UNSIGNED NOT NULL DEFAULT 3,
    waitlist_enabled TINYINT(1) NOT NULL DEFAULT 1,
    assignment_done_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_garden_days_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS time_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    garden_day_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_time_blocks_day (garden_day_id, sort_order, start_time),
    CONSTRAINT fk_time_blocks_day FOREIGN KEY (garden_day_id) REFERENCES garden_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Benutzer & Rechte
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NULL,
    firstname VARCHAR(100) NULL,
    lastname VARCHAR(100) NULL,
    class VARCHAR(50) NULL,
    grade TINYINT UNSIGNED NULL,
    role ENUM('admin','orga','teacher','student') NOT NULL DEFAULT 'student',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_class (class),
    KEY idx_users_grade (grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_permissions (user_id, permission),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permission_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permission_group_items (
    group_id INT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    PRIMARY KEY (group_id, permission),
    CONSTRAINT fk_pgi_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permission_groups (
    user_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, group_id),
    CONSTRAINT fk_upg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_upg_group FOREIGN KEY (group_id) REFERENCES permission_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_upg_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ausschlusskriterien
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exclusion_criteria (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exclusion_criteria_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_exclusions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    criterion_id INT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    set_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_exclusions (user_id, criterion_id),
    KEY idx_user_exclusions_criterion (criterion_id),
    CONSTRAINT fk_user_exclusions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_exclusions_criterion FOREIGN KEY (criterion_id) REFERENCES exclusion_criteria(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_exclusions_set_by FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Stände
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    garden_day_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    location VARCHAR(200) NULL,
    materials TEXT NULL,
    max_per_class SMALLINT UNSIGNED NULL,
    max_per_grade SMALLINT UNSIGNED NULL,
    allowed_grades VARCHAR(255) NULL,
    allowed_classes VARCHAR(1000) NULL,
    min_students SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stations_day (garden_day_id, sort_order, name),
    CONSTRAINT fk_stations_day FOREIGN KEY (garden_day_id) REFERENCES garden_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    station_id INT UNSIGNED NOT NULL,
    time_block_id INT UNSIGNED NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    PRIMARY KEY (id),
    UNIQUE KEY uq_station_blocks (station_id, time_block_id),
    KEY idx_station_blocks_block (time_block_id),
    CONSTRAINT fk_station_blocks_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    CONSTRAINT fk_station_blocks_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_leaders (
    station_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (station_id, user_id),
    KEY idx_station_leaders_user (user_id),
    CONSTRAINT fk_station_leaders_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    CONSTRAINT fk_station_leaders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS station_exclusions (
    station_id INT UNSIGNED NOT NULL,
    criterion_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (station_id, criterion_id),
    KEY idx_station_exclusions_criterion (criterion_id),
    CONSTRAINT fk_station_exclusions_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    CONSTRAINT fk_station_exclusions_criterion FOREIGN KEY (criterion_id) REFERENCES exclusion_criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Einschreibungen (fest, Wunsch, Warteliste) & Anwesenheit
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    garden_day_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    station_id INT UNSIGNED NOT NULL,
    time_block_id INT UNSIGNED NOT NULL,
    status ENUM('assigned','wish','waitlist') NOT NULL DEFAULT 'assigned',
    priority TINYINT UNSIGNED NULL,
    source ENUM('self','auto','orga') NOT NULL DEFAULT 'self',
    override_note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Hilfsspalte: ein Schüler darf je Zeitblock nur EINE feste Einschreibung haben
    assigned_block_key INT UNSIGNED AS (CASE WHEN status = 'assigned' THEN time_block_id ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrollments_user_station_block (user_id, station_id, time_block_id),
    UNIQUE KEY uq_enrollments_assigned_block (user_id, assigned_block_key),
    KEY idx_enrollments_day_user (garden_day_id, user_id),
    KEY idx_enrollments_station_block (station_id, time_block_id, status),
    KEY idx_enrollments_block (time_block_id),
    CONSTRAINT fk_enrollments_day FOREIGN KEY (garden_day_id) REFERENCES garden_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_station FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_block FOREIGN KEY (time_block_id) REFERENCES time_blocks(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrollment_id INT UNSIGNED NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    marked_by INT UNSIGNED NULL,
    marked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_enrollment (enrollment_id),
    CONSTRAINT fk_attendance_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_marked_by FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Einstellungen, Audit, Login-Schutz
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created (created_at),
    KEY idx_audit_logs_action (action),
    KEY idx_audit_logs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_username (username, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
