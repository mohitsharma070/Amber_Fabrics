SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN coupon_id INT DEFAULT NULL AFTER order_notes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_code'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(50) DEFAULT NULL AFTER coupon_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_discount'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN coupon_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER coupon_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'shipping_quote_token'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN shipping_quote_token VARCHAR(64) DEFAULT NULL AFTER coupon_discount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'shipping_source'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN shipping_source VARCHAR(40) DEFAULT NULL AFTER shipping_quote_token', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'courier_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN courier_id INT DEFAULT NULL AFTER shipping_source', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'courier_name'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN courier_name VARCHAR(255) DEFAULT NULL AFTER courier_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cod_fee'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN cod_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER courier_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'base_shipping'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN base_shipping DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cod_fee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_coupon_id'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_coupon_id ON orders (coupon_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_coupon_code'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_coupon_code ON orders (coupon_code)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_shipping_quote_token'
);
SET @sql := IF(@idx_exists = 0, 'CREATE INDEX idx_orders_shipping_quote_token ON orders (shipping_quote_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
