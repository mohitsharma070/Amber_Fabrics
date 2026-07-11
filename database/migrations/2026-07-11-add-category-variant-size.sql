-- Move the category variant-size flag out of the admin request path and into
-- the versioned schema lifecycle. The prepared statement keeps this migration
-- idempotent across MySQL and MariaDB installations.
SET @uses_variant_size_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'uses_variant_size'
);

SET @uses_variant_size_sql = IF(
    @uses_variant_size_exists = 0,
    'ALTER TABLE categories ADD COLUMN uses_variant_size TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);

PREPARE uses_variant_size_stmt FROM @uses_variant_size_sql;
EXECUTE uses_variant_size_stmt;
DEALLOCATE PREPARE uses_variant_size_stmt;
