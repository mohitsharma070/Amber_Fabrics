SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'shiprocket_order_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE shipments ADD COLUMN shiprocket_order_id VARCHAR(64) DEFAULT NULL AFTER order_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'shiprocket_shipment_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE shipments ADD COLUMN shiprocket_shipment_id VARCHAR(64) DEFAULT NULL AFTER shiprocket_order_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND COLUMN_NAME = 'awb_code'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE shipments ADD COLUMN awb_code VARCHAR(255) DEFAULT NULL AFTER shiprocket_shipment_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND INDEX_NAME = 'idx_shipments_shiprocket_order_id'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE shipments ADD INDEX idx_shipments_shiprocket_order_id (shiprocket_order_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND INDEX_NAME = 'idx_shipments_shiprocket_shipment_id'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE shipments ADD INDEX idx_shipments_shiprocket_shipment_id (shiprocket_shipment_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipments' AND INDEX_NAME = 'idx_shipments_awb_code'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE shipments ADD INDEX idx_shipments_awb_code (awb_code)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
