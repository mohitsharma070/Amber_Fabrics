CREATE TABLE IF NOT EXISTS shipping_rto_risks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    risk_score INT NOT NULL DEFAULT 0,
    risk_band ENUM('low','medium','high') NOT NULL DEFAULT 'low',
    reasons_json JSON DEFAULT NULL,
    signals_json JSON DEFAULT NULL,
    assessed_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_rto_risks_order (order_id),
    INDEX idx_shipping_rto_risks_band_score (risk_band, risk_score),
    CONSTRAINT fk_shipping_rto_risks_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

