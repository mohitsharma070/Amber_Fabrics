-- Retry-safe webhook lifecycle storage for Razorpay and Shiprocket events.
-- Adds status machine fields and payload tracing so failed events can be retried safely.

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider     VARCHAR(32)  NOT NULL,
    event_id     VARCHAR(191) NOT NULL,
    signature    VARCHAR(255) DEFAULT NULL,
    payload_hash CHAR(64)     DEFAULT NULL,
    raw_payload  LONGTEXT     DEFAULT NULL,
    status       ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received',
    attempts     INT          NOT NULL DEFAULT 0,
    last_error   TEXT         DEFAULT NULL,
    processed_at DATETIME     DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_webhook_event (provider, event_id),
    INDEX idx_payment_webhook_status (provider, status),
    INDEX idx_payment_webhook_processed_at (processed_at),
    INDEX idx_payment_webhook_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'payload_hash'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN payload_hash CHAR(64) DEFAULT NULL AFTER signature", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'raw_payload'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN raw_payload LONGTEXT DEFAULT NULL AFTER payload_hash", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'status'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN status ENUM('received','processing','processed','failed') NOT NULL DEFAULT 'received' AFTER raw_payload", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'attempts'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER status", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'last_error'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN last_error TEXT DEFAULT NULL AFTER attempts", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'processed_at'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN processed_at DATETIME DEFAULT NULL AFTER last_error", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'created_at'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER processed_at", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'updated_at'
);
SET @sql := IF(@col_exists = 0, "ALTER TABLE payment_webhook_events ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill default status for pre-existing rows and carry old received_at into new timestamps where possible.
UPDATE payment_webhook_events
SET status = 'processed'
WHERE status IS NULL OR status = '';

SET @received_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND COLUMN_NAME = 'received_at'
);
SET @sql := IF(@received_at_exists = 1,
    "UPDATE payment_webhook_events
     SET created_at = COALESCE(created_at, received_at),
         updated_at = COALESCE(updated_at, received_at),
         processed_at = COALESCE(processed_at, received_at)
     WHERE received_at IS NOT NULL",
    "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND INDEX_NAME = 'idx_payment_webhook_status'
);
SET @sql := IF(@idx_exists = 0, "CREATE INDEX idx_payment_webhook_status ON payment_webhook_events (provider, status)", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND INDEX_NAME = 'idx_payment_webhook_processed_at'
);
SET @sql := IF(@idx_exists = 0, "CREATE INDEX idx_payment_webhook_processed_at ON payment_webhook_events (processed_at)", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_webhook_events'
      AND INDEX_NAME = 'idx_payment_webhook_created_at'
);
SET @sql := IF(@idx_exists = 0, "CREATE INDEX idx_payment_webhook_created_at ON payment_webhook_events (created_at)", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
