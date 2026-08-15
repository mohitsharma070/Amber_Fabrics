-- This table was previously added by editing the already-released
-- 2026-06-20 migration. Keep historical migration checksums immutable and
-- apply the addition through this independently tracked migration instead.
CREATE TABLE IF NOT EXISTS shipping_courier_reference_cache (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider       VARCHAR(64) NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    segment_type   VARCHAR(32) NOT NULL DEFAULT '',
    payload_json   JSON NOT NULL,
    fetched_at     DATETIME NOT NULL,
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_courier_reference (provider, reference_type, segment_type),
    INDEX idx_shipping_courier_reference_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
