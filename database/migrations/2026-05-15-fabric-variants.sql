-- ============================================================
-- Migration: 2026-05-15-fabric-variants.sql
-- Purpose  : Variant-level inventory (Color × Size per fabric)
-- Run once : mysql -u <user> -p fabric_export < this-file.sql
-- ============================================================

-- 1. Per-colour × size stock table ─────────────────────────────
CREATE TABLE IF NOT EXISTS fabric_variants (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    fabric_id      INT           NOT NULL,
    color          VARCHAR(100)  NOT NULL DEFAULT '',
    size           VARCHAR(100)  NOT NULL DEFAULT '',
    sku            VARCHAR(100)  UNIQUE DEFAULT NULL,
    image          VARCHAR(255)  DEFAULT NULL,
    image2         VARCHAR(255)  DEFAULT NULL,
    image3         VARCHAR(255)  DEFAULT NULL,
    image4         VARCHAR(255)  DEFAULT NULL,
    video          VARCHAR(255)  DEFAULT NULL,
    pack_label     VARCHAR(120)  DEFAULT NULL,
    units_per_set  INT           DEFAULT NULL,
    price_override DECIMAL(10,2) DEFAULT NULL COMMENT 'Overrides fabric base price when set',
    stock          DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Piece / set units',
    stock_meters   DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Meter units',
    is_active      TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order     SMALLINT      NOT NULL DEFAULT 0,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fv_fabric (fabric_id),
    UNIQUE KEY uq_fabric_color_size (fabric_id, color, size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Link cart_items to a specific variant ─────────────────────
ALTER TABLE cart_items
    ADD COLUMN variant_id INT DEFAULT NULL AFTER fabric_id;

ALTER TABLE cart_items
    ADD INDEX idx_cart_items_variant (variant_id);

-- 3. Link order_items to a specific variant ────────────────────
ALTER TABLE order_items
    ADD COLUMN variant_id INT DEFAULT NULL AFTER fabric_id;

ALTER TABLE order_items
    ADD INDEX idx_order_items_variant (variant_id);

-- 4. Seed one default variant per existing fabric ──────────────
-- stock = 0; admin must set per-variant stock manually.
-- color defaults to the fabric's existing color field (or '').
-- size = '' because old fabrics used a single comma-separated size
-- field rather than per-variant sizes.
INSERT IGNORE INTO fabric_variants
    (fabric_id, color, size, stock, stock_meters, is_active)
SELECT
    id,
    COALESCE(NULLIF(TRIM(color), ''), ''),
    '',
    0,
    0,
    1
FROM fabrics;
