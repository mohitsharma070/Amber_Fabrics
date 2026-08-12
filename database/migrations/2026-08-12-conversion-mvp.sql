CREATE TABLE IF NOT EXISTS guest_order_access_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('manage','activate') NOT NULL DEFAULT 'manage',
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    created_ip VARCHAR(45) DEFAULT NULL,
    created_user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guest_order_access_token_hash (token_hash),
    INDEX idx_guest_order_access_order_purpose (order_id, purpose, expires_at),
    INDEX idx_guest_order_access_expiry (expires_at),
    CONSTRAINT fk_guest_order_access_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE returns MODIFY customer_id INT NULL;
ALTER TABLE support_tickets MODIFY customer_id INT NULL;
ALTER TABLE support_tickets ADD COLUMN requester_name VARCHAR(255) DEFAULT NULL AFTER customer_id;
ALTER TABLE support_tickets ADD COLUMN requester_email VARCHAR(255) DEFAULT NULL AFTER requester_name;
ALTER TABLE fabrics ADD COLUMN dispatch_min_days SMALLINT UNSIGNED DEFAULT NULL AFTER dispatch_time;
ALTER TABLE fabrics ADD COLUMN dispatch_max_days SMALLINT UNSIGNED DEFAULT NULL AFTER dispatch_min_days;
ALTER TABLE shipping_quotes ADD COLUMN serviceability_status ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated' AFTER courier_id;
ALTER TABLE shipping_quotes ADD COLUMN estimated_dispatch_start DATE DEFAULT NULL AFTER serviceability_status;
ALTER TABLE shipping_quotes ADD COLUMN estimated_dispatch_end DATE DEFAULT NULL AFTER estimated_dispatch_start;
ALTER TABLE shipping_quotes ADD COLUMN estimated_delivery_start DATE DEFAULT NULL AFTER estimated_dispatch_end;
ALTER TABLE shipping_quotes ADD COLUMN estimated_delivery_end DATE DEFAULT NULL AFTER estimated_delivery_start;
ALTER TABLE orders ADD COLUMN serviceability_status ENUM('live','estimated','unavailable') NOT NULL DEFAULT 'estimated' AFTER shipping_source;
ALTER TABLE orders ADD COLUMN estimated_dispatch_start DATE DEFAULT NULL AFTER serviceability_status;
ALTER TABLE orders ADD COLUMN estimated_dispatch_end DATE DEFAULT NULL AFTER estimated_dispatch_start;
ALTER TABLE orders ADD COLUMN estimated_delivery_start DATE DEFAULT NULL AFTER estimated_dispatch_end;
ALTER TABLE orders ADD COLUMN estimated_delivery_end DATE DEFAULT NULL AFTER estimated_delivery_start;
