-- Store category-specific selling-unit rules as data instead of application constants.
-- This migration intentionally does not update fabrics.unit_type, so existing
-- products retain their current selling unit.

SET @add_category_default_unit_type = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'categories'
          AND COLUMN_NAME = 'default_unit_type'
    ),
    'SELECT 1',
    'ALTER TABLE categories ADD COLUMN default_unit_type ENUM(''meter'',''piece'',''set'') NULL DEFAULT NULL AFTER uses_variant_size'
);
PREPARE add_category_default_unit_type_stmt FROM @add_category_default_unit_type;
EXECUTE add_category_default_unit_type_stmt;
DEALLOCATE PREPARE add_category_default_unit_type_stmt;

UPDATE categories
SET default_unit_type = 'meter'
WHERE slug = 'fabric-by-meter'
  AND default_unit_type IS NULL;
