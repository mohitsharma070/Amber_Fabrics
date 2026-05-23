ALTER TABLE fabrics
    ADD COLUMN IF NOT EXISTS low_stock_threshold_units INT DEFAULT NULL AFTER stock_meters,
    ADD COLUMN IF NOT EXISTS low_stock_threshold_meters DECIMAL(10,2) DEFAULT NULL AFTER low_stock_threshold_units;

CREATE TABLE IF NOT EXISTS ecommerce_event_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    customer_id INT DEFAULT NULL,
    order_id INT DEFAULT NULL,
    product_id INT DEFAULT NULL,
    unit_type ENUM('meter','piece','set') DEFAULT NULL,
    quantity DECIMAL(10,2) DEFAULT NULL,
    amount DECIMAL(12,2) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type_created (event_type, created_at),
    INDEX idx_event_customer (customer_id),
    INDEX idx_event_order (order_id),
    INDEX idx_event_product (product_id)
);
