ALTER TABLE order_items
    ADD COLUMN taxable_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER units_per_set,
    ADD COLUMN discount_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER taxable_amount,
    ADD COLUMN gst_rate_snapshot DECIMAL(6,3) NULL DEFAULT NULL AFTER discount_amount,
    ADD COLUMN gst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER gst_rate_snapshot,
    ADD COLUMN cgst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER gst_amount,
    ADD COLUMN sgst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER cgst_amount,
    ADD COLUMN igst_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER sgst_amount,
    ADD COLUMN tax_type ENUM('none','cgst_sgst','igst') NOT NULL DEFAULT 'none' AFTER igst_amount,
    ADD COLUMN hsn_code_snapshot VARCHAR(32) NULL DEFAULT NULL AFTER tax_type;
