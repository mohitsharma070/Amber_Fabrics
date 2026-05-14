CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(191) NOT NULL,
    signature VARCHAR(255) DEFAULT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_webhook_event (provider, event_id),
    INDEX idx_payment_webhook_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_activity_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    action VARCHAR(80) NOT NULL,
    actor_type ENUM('system','customer','admin','webhook') NOT NULL DEFAULT 'system',
    actor_id INT DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_activity_order_id (order_id),
    INDEX idx_order_activity_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refund_ledger (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(8) NOT NULL DEFAULT 'INR',
    status ENUM('initiated','processed','failed') NOT NULL DEFAULT 'initiated',
    gateway VARCHAR(32) DEFAULT NULL,
    gateway_refund_id VARCHAR(191) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_ledger_order_id (order_id),
    INDEX idx_refund_ledger_payment_id (payment_id),
    INDEX idx_refund_ledger_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE p1
FROM payments p1
JOIN payments p2
  ON p1.order_id = p2.order_id
 AND p1.payment_method = p2.payment_method
 AND p1.id < p2.id;

ALTER TABLE payments
    ADD UNIQUE KEY uq_payments_order_method (order_id, payment_method);

CREATE INDEX idx_payments_razorpay_order_id
    ON payments (razorpay_order_id);

DELETE r1
FROM returns r1
JOIN returns r2
  ON r1.order_id = r2.order_id
 AND r1.id < r2.id;

ALTER TABLE returns
    ADD UNIQUE KEY uq_returns_order_id (order_id);
