-- Returns module migration (hosting-safe)
CREATE TABLE IF NOT EXISTS returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(32) NOT NULL UNIQUE,
    order_id INT NOT NULL,
    customer_id INT NOT NULL,
    status ENUM('requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled') NOT NULL DEFAULT 'requested',
    reason VARCHAR(255) NOT NULL,
    customer_note TEXT DEFAULT NULL,
    image_1 VARCHAR(255) DEFAULT NULL,
    image_2 VARCHAR(255) DEFAULT NULL,
    admin_note TEXT DEFAULT NULL,
    refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME DEFAULT NULL,
    rejected_at DATETIME DEFAULT NULL,
    received_at DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_returns_order_id (order_id),
    INDEX idx_returns_customer_id (customer_id),
    INDEX idx_returns_status (status),
    INDEX idx_returns_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    order_item_id INT DEFAULT NULL,
    fabric_id INT DEFAULT NULL,
    product_name VARCHAR(255) NOT NULL,
    unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    INDEX idx_return_items_return_id (return_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE returns ADD COLUMN IF NOT EXISTS image_1 VARCHAR(255) DEFAULT NULL;
ALTER TABLE returns ADD COLUMN IF NOT EXISTS image_2 VARCHAR(255) DEFAULT NULL;
