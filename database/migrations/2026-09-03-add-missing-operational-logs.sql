-- 2026-09-03-add-missing-operational-logs.sql
-- Resolves divergence between upgrade path and fresh install schema
-- by adding operational tables that were originally missed in migration history.

CREATE TABLE IF NOT EXISTS customer_login_attempts (
    attempt_key   CHAR(64)  PRIMARY KEY,
    attempts      INT       NOT NULL DEFAULT 0,
    blocked_until DATETIME  DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    action      VARCHAR(120) NOT NULL,
    target_type VARCHAR(80) DEFAULT NULL,
    target_id   INT DEFAULT NULL,
    route       VARCHAR(255) DEFAULT NULL,
    request_ip  VARCHAR(45) DEFAULT NULL,
    user_agent  VARCHAR(500) DEFAULT NULL,
    status      ENUM('ok','failed','denied') NOT NULL DEFAULT 'ok',
    details     TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_activity_admin_created (admin_id, created_at),
    INDEX idx_admin_activity_action_created (action, created_at),
    INDEX idx_admin_activity_status_created (status, created_at),
    CONSTRAINT fk_admin_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

