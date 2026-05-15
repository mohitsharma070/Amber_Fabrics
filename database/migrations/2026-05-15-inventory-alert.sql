CREATE TABLE IF NOT EXISTS inventory_alert_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'piece',
    stock_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_alert_product_sent (product_id, sent_at),
    CONSTRAINT fk_inventory_alert_product FOREIGN KEY (product_id) REFERENCES fabrics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

