-- Raw guest capabilities never reach the database; only SHA-256 hashes are persisted.
ALTER TABLE orders
    ADD COLUMN guest_capability_hash CHAR(64) DEFAULT NULL,
    ADD COLUMN guest_capability_expires_at DATETIME DEFAULT NULL,
    ADD INDEX idx_orders_guest_capability_expiry (guest_capability_expires_at);
