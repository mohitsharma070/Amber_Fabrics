-- Admin authentication is OTP-only. Remove legacy password fields from existing installs.
SET @admin_password_hash_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'password_hash'
);
SET @admin_password_hash_sql := IF(
    @admin_password_hash_exists > 0,
    'ALTER TABLE admins DROP COLUMN password_hash',
    'SELECT 1'
);
PREPARE stmt FROM @admin_password_hash_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @admin_force_reset_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'force_password_reset'
);
SET @admin_force_reset_sql := IF(
    @admin_force_reset_exists > 0,
    'ALTER TABLE admins DROP COLUMN force_password_reset',
    'SELECT 1'
);
PREPARE stmt FROM @admin_force_reset_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
