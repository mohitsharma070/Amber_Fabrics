-- ============================================================
-- Migration: 2026-05-21-meter-pricing-rules.sql
-- Purpose  : Explicit meter pricing rules (qty_step already exists)
-- Run once : mysql -u <user> -p fabric_export < this-file.sql
-- Notes    : Safe defaults (0 / NULL) so existing pricing unchanged.
-- ============================================================

-- Fabrics: optional wastage percent (informational / future pricing + cutting logic)
ALTER TABLE fabrics
    ADD COLUMN IF NOT EXISTS wastage_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER qty_step;

-- Variants: optional override for wastage percent (NULL = inherit fabric)
ALTER TABLE fabric_variants
    ADD COLUMN IF NOT EXISTS wastage_percent_override DECIMAL(5,2) DEFAULT NULL AFTER price_override;

