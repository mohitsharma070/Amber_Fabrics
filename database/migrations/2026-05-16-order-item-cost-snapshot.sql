ALTER TABLE order_items
    ADD COLUMN cost_price_snapshot DECIMAL(12,2) NULL DEFAULT NULL AFTER line_total;
