-- Order-scoped transactional WhatsApp consent and idempotent inbound webhook processing.

ALTER TABLE cod_confirmations
    ADD COLUMN whatsapp_consent_at DATETIME NULL AFTER message_attempts,
    ADD COLUMN whatsapp_consent_version VARCHAR(64) NULL AFTER whatsapp_consent_at;

CREATE TABLE IF NOT EXISTS cod_guard_webhook_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider_message_id VARCHAR(191) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    order_id INT NULL,
    reply VARCHAR(16) NULL,
    status ENUM('processing','processed','ignored','failed') NOT NULL DEFAULT 'processing',
    last_error VARCHAR(1000) NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_cod_guard_webhook_message (provider_message_id),
    INDEX idx_cod_guard_webhook_status_received (status, received_at),
    INDEX idx_cod_guard_webhook_order (order_id),
    CONSTRAINT fk_cod_guard_webhook_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
