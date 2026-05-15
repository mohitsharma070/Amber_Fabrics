CREATE TABLE IF NOT EXISTS cod_confirmations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    channel ENUM('auto','whatsapp','call') NOT NULL DEFAULT 'auto',
    status ENUM('pending','confirmed','cancelled','auto_cancelled') NOT NULL DEFAULT 'pending',
    deadline_at DATETIME DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    notes TEXT,
    confirmed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cod_confirmations_order_id (order_id),
    INDEX idx_cod_confirmations_status_deadline (status, deadline_at),
    CONSTRAINT fk_cod_confirmations_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

