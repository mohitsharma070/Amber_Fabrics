-- P2 performance indexes for catalog/admin query paths.
-- Safe to run with IF NOT EXISTS on MySQL 8+.

CREATE INDEX IF NOT EXISTS idx_fabrics_created_id ON fabrics(created_at, id);
CREATE INDEX IF NOT EXISTS idx_orders_status_payment ON orders(order_status, payment_status);
