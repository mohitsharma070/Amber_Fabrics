-- Back-in-stock notification subscriptions.
-- Active duplicate prevention is handled by active_subscription_key because
-- MySQL unique indexes allow multiple NULL variant_id values.

CREATE TABLE IF NOT EXISTS back_in_stock_subscriptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    variant_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending','processing','sent','cancelled') NOT NULL DEFAULT 'pending',
    unsubscribe_token CHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME DEFAULT NULL,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_subscription_key CHAR(64) GENERATED ALWAYS AS (
        CASE
            WHEN status IN ('pending','processing')
            THEN SHA2(CONCAT(LOWER(TRIM(email)), ':', product_id, ':', COALESCE(variant_id, 0)), 256)
            ELSE NULL
        END
    ) STORED,
    UNIQUE KEY uq_bis_unsubscribe_token (unsubscribe_token),
    UNIQUE KEY uq_bis_active_subscription (active_subscription_key),
    INDEX idx_bis_product_status (product_id, status),
    INDEX idx_bis_variant_status (variant_id, status),
    INDEX idx_bis_customer (customer_id),
    INDEX idx_bis_email (email),
    INDEX idx_bis_status_requested (status, requested_at),
    CONSTRAINT fk_bis_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE,
    CONSTRAINT fk_bis_variant FOREIGN KEY (variant_id) REFERENCES fabric_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_bis_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
