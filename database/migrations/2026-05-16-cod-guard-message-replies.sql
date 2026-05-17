SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'response_token'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN response_token CHAR(32) DEFAULT NULL AFTER attempts', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_provider'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_provider VARCHAR(40) DEFAULT NULL AFTER response_token', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_id VARCHAR(191) DEFAULT NULL AFTER message_provider', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_status'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_status VARCHAR(40) DEFAULT ''queued'' AFTER message_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_error'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_error TEXT AFTER message_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_sent_at'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_sent_at DATETIME DEFAULT NULL AFTER message_error', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'message_attempts'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN message_attempts INT NOT NULL DEFAULT 0 AFTER message_sent_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'last_inbound_message_id'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN last_inbound_message_id VARCHAR(191) DEFAULT NULL AFTER message_attempts', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'last_inbound_text'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN last_inbound_text TEXT AFTER last_inbound_message_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND COLUMN_NAME = 'last_inbound_at'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE cod_confirmations ADD COLUMN last_inbound_at DATETIME DEFAULT NULL AFTER last_inbound_text', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND INDEX_NAME = 'uq_cod_confirmations_response_token'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE cod_confirmations ADD UNIQUE KEY uq_cod_confirmations_response_token (response_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cod_confirmations' AND INDEX_NAME = 'idx_cod_confirmations_message_status'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE cod_confirmations ADD INDEX idx_cod_confirmations_message_status (message_status, message_attempts)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
