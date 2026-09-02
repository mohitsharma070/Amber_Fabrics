-- Catalog category/newest plan measured against representative joined variant rows.
-- Fresh installs already receive this index from database/schema.sql.

SET @add_catalog_created_index = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fabrics'
          AND INDEX_NAME = 'idx_fabrics_catalog_created'
    ),
    'SELECT 1',
    'ALTER TABLE fabrics ADD INDEX idx_fabrics_catalog_created (status, category, created_at, id)'
);
PREPARE add_catalog_created_index_stmt FROM @add_catalog_created_index;
EXECUTE add_catalog_created_index_stmt;
DEALLOCATE PREPARE add_catalog_created_index_stmt;
