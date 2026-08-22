-- Move the category variant-size flag out of request-time DDL.
-- Existing installations may already have this column because the legacy
-- admin categories page attempted the ALTER on every request.

SET @add_category_variant_size = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'categories'
          AND COLUMN_NAME = 'uses_variant_size'
    ),
    'SELECT 1',
    'ALTER TABLE categories ADD COLUMN uses_variant_size TINYINT(1) NOT NULL DEFAULT 0 AFTER status'
);
PREPARE add_category_variant_size_stmt FROM @add_category_variant_size;
EXECUTE add_category_variant_size_stmt;
DEALLOCATE PREPARE add_category_variant_size_stmt;
