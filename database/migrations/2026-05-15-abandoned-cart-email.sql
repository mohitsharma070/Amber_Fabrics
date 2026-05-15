CREATE TABLE IF NOT EXISTS abandoned_cart_reminders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    customer_email VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    cart_hash CHAR(64) NOT NULL,
    items_count INT NOT NULL DEFAULT 0,
    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cart_summary TEXT,
    status ENUM('active','completed','recovered') NOT NULL DEFAULT 'active',
    emails_sent_count INT NOT NULL DEFAULT 0,
    next_send_at DATETIME DEFAULT NULL,
    last_sent_at DATETIME DEFAULT NULL,
    last_activity_at DATETIME DEFAULT NULL,
    recovered_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_abandoned_cart_customer (customer_id),
    INDEX idx_abandoned_cart_status_next (status, next_send_at),
    CONSTRAINT fk_abandoned_cart_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

