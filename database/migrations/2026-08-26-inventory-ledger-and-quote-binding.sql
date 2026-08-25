-- Bring inventory reservation tracking, the stock ledger, and shipping-quote
-- binding onto the migration path.
--
-- orders.inventory_reserved_at, orders.inventory_restored_at and stock_ledger
-- previously existed only in database/schema.sql (fresh installs) and
-- database/setup.php (CLI provisioning). Environments upgraded through
-- database/migrate.php never received them, so
-- InventoryService::orders_supports_inventory_tracking() returned false and both
-- reserve/restore idempotency guards silently degraded to no-ops while every
-- stock_ledger insert was swallowed by a catch. Money-path guards must not
-- depend on optional DDL.
--
-- shipping_quotes.cart_fingerprint / consumed_at bind an issued shipping quote
-- to the cart composition it was priced for, and make the quote single-use.

-- 1. orders.inventory_reserved_at
SET @add_orders_inventory_reserved_at = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'inventory_reserved_at'
    ),
    'SELECT 1',
    'ALTER TABLE orders ADD COLUMN inventory_reserved_at DATETIME NULL DEFAULT NULL'
);
PREPARE add_orders_inventory_reserved_at_stmt FROM @add_orders_inventory_reserved_at;
EXECUTE add_orders_inventory_reserved_at_stmt;
DEALLOCATE PREPARE add_orders_inventory_reserved_at_stmt;

-- 2. orders.inventory_restored_at
SET @add_orders_inventory_restored_at = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'inventory_restored_at'
    ),
    'SELECT 1',
    'ALTER TABLE orders ADD COLUMN inventory_restored_at DATETIME NULL DEFAULT NULL'
);
PREPARE add_orders_inventory_restored_at_stmt FROM @add_orders_inventory_restored_at;
EXECUTE add_orders_inventory_restored_at_stmt;
DEALLOCATE PREPARE add_orders_inventory_restored_at_stmt;

-- 3. Backfill live orders so the reserve guard recognises stock already held.
--    Mirrors database/setup.php. Cancelled/refunded/returned orders are left
--    NULL on purpose: a NULL inventory_reserved_at makes restore_order_inventory
--    return early, which is the safe direction for stock that was already
--    released before this migration ran.
UPDATE orders
SET inventory_reserved_at = COALESCE(updated_at, created_at, NOW())
WHERE inventory_reserved_at IS NULL
  AND order_status IN ('pending','confirmed','packed','shipped','delivered');

-- 4. stock_ledger (mirrors database/setup.php and database/schema.sql)
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

-- 5. shipping_quotes.cart_fingerprint - binds the quote to the cart it priced.
SET @add_shipping_quotes_cart_fingerprint = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shipping_quotes'
          AND COLUMN_NAME = 'cart_fingerprint'
    ),
    'SELECT 1',
    'ALTER TABLE shipping_quotes ADD COLUMN cart_fingerprint CHAR(64) NULL DEFAULT NULL AFTER payment_method'
);
PREPARE add_shipping_quotes_cart_fingerprint_stmt FROM @add_shipping_quotes_cart_fingerprint;
EXECUTE add_shipping_quotes_cart_fingerprint_stmt;
DEALLOCATE PREPARE add_shipping_quotes_cart_fingerprint_stmt;

-- 6. shipping_quotes.consumed_at - makes an accepted quote single-use.
SET @add_shipping_quotes_consumed_at = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'shipping_quotes'
          AND COLUMN_NAME = 'consumed_at'
    ),
    'SELECT 1',
    'ALTER TABLE shipping_quotes ADD COLUMN consumed_at DATETIME NULL DEFAULT NULL AFTER expires_at'
);
PREPARE add_shipping_quotes_consumed_at_stmt FROM @add_shipping_quotes_consumed_at;
EXECUTE add_shipping_quotes_consumed_at_stmt;
DEALLOCATE PREPARE add_shipping_quotes_consumed_at_stmt;
