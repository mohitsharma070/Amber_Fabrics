-- Coupon capacity is claimed before an online payment session is created.
CREATE TABLE IF NOT EXISTS coupon_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL,
    state ENUM('reserved','consumed','released') NOT NULL DEFAULT 'reserved',
    reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    released_at TIMESTAMP NULL DEFAULT NULL,
    release_reason VARCHAR(120) DEFAULT NULL,
    UNIQUE KEY uq_coupon_reservations_order (order_id),
    INDEX idx_coupon_reservations_coupon_state (coupon_id, state),
    INDEX idx_coupon_reservations_customer_state (customer_id, state),
    CONSTRAINT fk_coupon_reservations_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_reservations_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_reservations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_reconciliation_failures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    payment_id INT DEFAULT NULL,
    failure_type VARCHAR(80) NOT NULL,
    details TEXT NOT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_reconciliation_failure (order_id, failure_type),
    INDEX idx_payment_reconciliation_open (resolved_at, created_at),
    CONSTRAINT fk_payment_reconciliation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_reconciliation_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
