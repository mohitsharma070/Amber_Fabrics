CREATE TABLE IF NOT EXISTS stock_ledger (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT DEFAULT NULL,
    order_item_id INT DEFAULT NULL,
    return_id INT DEFAULT NULL,
    return_item_id INT DEFAULT NULL,
    fabric_id INT DEFAULT NULL,
    variant_id INT DEFAULT NULL,
    unit_type ENUM('meter','piece','set') NOT NULL DEFAULT 'meter',
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    movement ENUM('reserve','release','return_restock','adjustment') NOT NULL DEFAULT 'adjustment',
    direction ENUM('in','out') NOT NULL DEFAULT 'in',
    source VARCHAR(64) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_ledger_order (order_id),
    INDEX idx_stock_ledger_return (return_id),
    INDEX idx_stock_ledger_fabric_variant (fabric_id, variant_id),
    INDEX idx_stock_ledger_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE return_items
    ADD COLUMN variant_id INT NULL DEFAULT NULL AFTER quantity,
    ADD COLUMN restocked_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER variant_id,
    ADD COLUMN refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER restocked_qty,
    ADD COLUMN restocked_at DATETIME NULL DEFAULT NULL AFTER refund_amount;
