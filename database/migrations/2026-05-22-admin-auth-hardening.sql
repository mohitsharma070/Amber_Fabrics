-- Admin auth hardening: roles, active flag, login metadata, audit log.

SET @db_name := DATABASE();

-- admins.role
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'role'
);
SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admins ADD COLUMN role ENUM('viewer','catalog_manager','operations_manager','super_admin') NOT NULL DEFAULT 'viewer' AFTER email",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- admins.is_active
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'is_active'
);
SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- admins.last_login_at
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'last_login_at'
);
SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admins ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER is_active",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- admins.last_login_ip
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'last_login_ip'
);
SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admins ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL AFTER last_login_at",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- admins.last_login_user_agent
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'last_login_user_agent'
);
SET @sql := IF(
    @col_exists = 0,
    "ALTER TABLE admins ADD COLUMN last_login_user_agent VARCHAR(500) DEFAULT NULL AFTER last_login_ip",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill existing admins as super_admin to avoid lockout.
UPDATE admins
SET role = 'super_admin'
WHERE role IS NULL OR role = '' OR role = 'viewer';

-- Role/active index
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'admins'
      AND INDEX_NAME = 'idx_admins_role_active'
);
SET @sql := IF(
    @idx_exists = 0,
    "CREATE INDEX idx_admins_role_active ON admins (role, is_active)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    action      VARCHAR(120) NOT NULL,
    target_type VARCHAR(80) DEFAULT NULL,
    target_id   INT DEFAULT NULL,
    route       VARCHAR(255) DEFAULT NULL,
    request_ip  VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(500) DEFAULT NULL,
    status      ENUM('ok','failed','denied') NOT NULL DEFAULT 'ok',
    details     TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_activity_admin_created (admin_id, created_at),
    INDEX idx_admin_activity_action_created (action, created_at),
    INDEX idx_admin_activity_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @db_name
      AND TABLE_NAME = 'admin_activity_logs'
      AND CONSTRAINT_NAME = 'fk_admin_activity_admin'
);
SET @sql := IF(
    @fk_exists = 0,
    "ALTER TABLE admin_activity_logs ADD CONSTRAINT fk_admin_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
