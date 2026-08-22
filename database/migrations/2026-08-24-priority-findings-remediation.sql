-- Atomic post-commit work and privacy-safe guest coupon identities.

ALTER TABLE coupon_usages
    ADD COLUMN guest_identity_hash CHAR(64) NULL AFTER customer_id,
    ADD UNIQUE KEY uq_coupon_usages_coupon_guest (coupon_id, guest_identity_hash);

CREATE TABLE IF NOT EXISTS commerce_outbox (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(100) NOT NULL,
    aggregate_type VARCHAR(40) NOT NULL DEFAULT 'order',
    aggregate_id INT NOT NULL,
    dedupe_key VARCHAR(191) NOT NULL,
    payload_json LONGTEXT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token CHAR(32) NULL,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commerce_outbox_dedupe (dedupe_key),
    INDEX idx_commerce_outbox_ready (status, available_at, id),
    INDEX idx_commerce_outbox_aggregate (aggregate_type, aggregate_id),
    INDEX idx_commerce_outbox_claim (status, claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commerce_outbox_deliveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    outbox_id BIGINT NOT NULL,
    handler_key VARCHAR(191) NOT NULL,
    status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    claim_token CHAR(32) NULL,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commerce_outbox_delivery (outbox_id, handler_key),
    INDEX idx_commerce_outbox_delivery_claim (status, claimed_at),
    CONSTRAINT fk_commerce_outbox_delivery_event FOREIGN KEY (outbox_id) REFERENCES commerce_outbox(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
