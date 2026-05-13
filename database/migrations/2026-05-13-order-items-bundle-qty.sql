-- Migration: 2026-05-13-order-items-bundle-qty
-- Add bundle_quantity and meter_length to order_items so invoices can show
-- "1 × 5m" format instead of just "5m" for meter-type products.

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS bundle_quantity INT          DEFAULT NULL COMMENT 'Number of bundles/rolls ordered (piece count for meter items)',
    ADD COLUMN IF NOT EXISTS meter_length    DECIMAL(10,2) DEFAULT NULL COMMENT 'Meters per bundle as selected by customer';
