-- Catalog performance indexes
-- Safe to run multiple times (checks INFORMATION_SCHEMA first).

SET @db_name := DATABASE();

-- Fabrics: scoped listing and created_at sorting.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabrics'
      AND INDEX_NAME = 'idx_fabrics_catalog_created'
);
SET @sql := IF(
    @idx_exists = 0,
    "CREATE INDEX idx_fabrics_catalog_created ON fabrics (status, category, created_at, id)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fabrics: scoped listing and name sorting.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabrics'
      AND INDEX_NAME = 'idx_fabrics_catalog_name'
);
SET @sql := IF(
    @idx_exists = 0,
    "CREATE INDEX idx_fabrics_catalog_name ON fabrics (status, category, name, id)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Variants: active join + color/size filtering.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabric_variants'
      AND INDEX_NAME = 'idx_fv_active_fabric_color_size'
);
SET @sql := IF(
    @idx_exists = 0,
    "CREATE INDEX idx_fv_active_fabric_color_size ON fabric_variants (is_active, fabric_id, color, size, id)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Variants: active join + price/stock-oriented scans.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabric_variants'
      AND INDEX_NAME = 'idx_fv_active_fabric_price_stock'
);
SET @sql := IF(
    @idx_exists = 0,
    "CREATE INDEX idx_fv_active_fabric_price_stock ON fabric_variants (is_active, fabric_id, price_override, stock, stock_meters)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Fabrics: fulltext storefront keyword search.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabrics'
      AND INDEX_NAME = 'ft_fabrics_catalog_search'
      AND INDEX_TYPE = 'FULLTEXT'
);
SET @sql := IF(
    @idx_exists = 0,
    "ALTER TABLE fabrics ADD FULLTEXT INDEX ft_fabrics_catalog_search (name, sku, material, category, dispatch_time, color, size)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Variants: fulltext variant keyword search.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'fabric_variants'
      AND INDEX_NAME = 'ft_fv_catalog_search'
      AND INDEX_TYPE = 'FULLTEXT'
);
SET @sql := IF(
    @idx_exists = 0,
    "ALTER TABLE fabric_variants ADD FULLTEXT INDEX ft_fv_catalog_search (color, size, sku, pack_label)",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
