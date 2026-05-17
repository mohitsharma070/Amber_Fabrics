-- Migration: Add Pack-of-N metadata for set products

ALTER TABLE fabric_variants
    ADD COLUMN pack_label VARCHAR(120) NULL DEFAULT NULL AFTER video,
    ADD COLUMN units_per_set INT NULL DEFAULT NULL AFTER pack_label;

ALTER TABLE order_items
    ADD COLUMN pack_label VARCHAR(120) NULL DEFAULT NULL AFTER meter_length,
    ADD COLUMN units_per_set INT NULL DEFAULT NULL AFTER pack_label;

UPDATE fabric_variants fv
JOIN fabrics f ON f.id = fv.fabric_id
SET fv.units_per_set = 1,
    fv.pack_label = COALESCE(NULLIF(TRIM(fv.pack_label), ''), 'Pack of 1')
WHERE f.unit_type = 'set' AND (fv.units_per_set IS NULL OR fv.units_per_set < 1);
