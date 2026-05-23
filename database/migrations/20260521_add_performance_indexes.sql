-- Performance indexes for high-frequency filter columns.
-- Safe to re-run: uses CREATE INDEX IF NOT EXISTS (MySQL 5.7+).
-- Apply: APP_MODE=production php database/migrate.php

CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders(payment_status);
CREATE INDEX IF NOT EXISTS idx_orders_order_status   ON orders(order_status);
CREATE INDEX IF NOT EXISTS idx_orders_created_at     ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_fabrics_status        ON fabrics(status);
CREATE INDEX IF NOT EXISTS idx_fabrics_category      ON fabrics(category);
