CREATE TABLE IF NOT EXISTS marketing_attributions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    customer_id INT DEFAULT NULL,
    utm_source VARCHAR(255) DEFAULT NULL,
    utm_medium VARCHAR(255) DEFAULT NULL,
    utm_campaign VARCHAR(255) DEFAULT NULL,
    utm_term VARCHAR(255) DEFAULT NULL,
    utm_content VARCHAR(255) DEFAULT NULL,
    fbclid VARCHAR(500) DEFAULT NULL,
    gclid VARCHAR(500) DEFAULT NULL,
    landing_url VARCHAR(1000) DEFAULT NULL,
    referrer VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_attributions_order_id (order_id),
    INDEX idx_marketing_attributions_source_campaign (utm_source, utm_campaign),
    INDEX idx_marketing_attributions_customer_id (customer_id),
    CONSTRAINT fk_marketing_attributions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

