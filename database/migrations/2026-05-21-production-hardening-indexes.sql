CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(191) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fabrics'
      AND INDEX_NAME = 'idx_fabrics_storefront'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_fabrics_storefront ON fabrics (status, category, is_available, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fabrics'
      AND INDEX_NAME = 'idx_fabrics_material'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_fabrics_material ON fabrics (material)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fabric_variants'
      AND INDEX_NAME = 'idx_fv_fabric_active_sort'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_fv_fabric_active_sort ON fabric_variants (fabric_id, is_active, sort_order, id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_payment_lifecycle'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_payment_lifecycle ON orders (payment_method, payment_status, order_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_customer_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_customer_created ON orders (customer_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_status_created'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_status_created ON orders (order_status, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'idx_payments_method_status'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_payments_method_status ON payments (payment_method, payment_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
