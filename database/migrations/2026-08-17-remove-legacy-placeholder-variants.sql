CREATE TEMPORARY TABLE tmp_legacy_placeholder_products (
    fabric_id INT PRIMARY KEY
) ENGINE=Memory;

INSERT IGNORE INTO tmp_legacy_placeholder_products (fabric_id)
SELECT DISTINCT fv.fabric_id
FROM fabric_variants fv
JOIN fabrics f ON f.id = fv.fabric_id
WHERE TRIM(COALESCE(fv.size, '')) = ''
  AND TRIM(COALESCE(fv.sku, '')) = ''
  AND TRIM(COALESCE(fv.image, '')) = ''
  AND TRIM(COALESCE(fv.image2, '')) = ''
  AND TRIM(COALESCE(fv.image3, '')) = ''
  AND TRIM(COALESCE(fv.image4, '')) = ''
  AND TRIM(COALESCE(fv.video, '')) = ''
  AND fv.price_override IS NULL
  AND COALESCE(fv.stock, 0) = 0
  AND COALESCE(fv.stock_meters, 0) = 0
  AND (
      TRIM(COALESCE(fv.color, '')) = ''
      OR LOWER(TRIM(fv.color)) = 'default'
      OR LOWER(TRIM(fv.color)) = LOWER(TRIM(COALESCE(f.color, '')))
  );

UPDATE fabric_variants fv
JOIN tmp_legacy_placeholder_products old ON old.fabric_id = fv.fabric_id
SET fv.is_active = 0
WHERE TRIM(COALESCE(fv.size, '')) = ''
  AND TRIM(COALESCE(fv.sku, '')) = ''
  AND TRIM(COALESCE(fv.image, '')) = ''
  AND TRIM(COALESCE(fv.image2, '')) = ''
  AND TRIM(COALESCE(fv.image3, '')) = ''
  AND TRIM(COALESCE(fv.image4, '')) = ''
  AND TRIM(COALESCE(fv.video, '')) = ''
  AND fv.price_override IS NULL
  AND COALESCE(fv.stock, 0) = 0
  AND COALESCE(fv.stock_meters, 0) = 0;

DELETE fv
FROM fabric_variants fv
JOIN tmp_legacy_placeholder_products old ON old.fabric_id = fv.fabric_id
LEFT JOIN order_items oi ON oi.variant_id = fv.id
WHERE oi.id IS NULL
  AND TRIM(COALESCE(fv.size, '')) = ''
  AND TRIM(COALESCE(fv.sku, '')) = ''
  AND TRIM(COALESCE(fv.image, '')) = ''
  AND TRIM(COALESCE(fv.image2, '')) = ''
  AND TRIM(COALESCE(fv.image3, '')) = ''
  AND TRIM(COALESCE(fv.image4, '')) = ''
  AND TRIM(COALESCE(fv.video, '')) = ''
  AND fv.price_override IS NULL
  AND COALESCE(fv.stock, 0) = 0
  AND COALESCE(fv.stock_meters, 0) = 0;

UPDATE fabrics f
JOIN tmp_legacy_placeholder_products old ON old.fabric_id = f.id
SET f.product_type = 'simple',
    f.is_available = CASE
        WHEN f.status = 'active'
         AND (CASE WHEN f.unit_type = 'meter' THEN f.stock_meters ELSE f.stock END) > 0
        THEN 1 ELSE 0 END
WHERE f.product_type = 'variable'
  AND NOT EXISTS (
      SELECT 1
      FROM fabric_variants fv
      WHERE fv.fabric_id = f.id
        AND (
            TRIM(COALESCE(fv.sku, '')) <> ''
            OR TRIM(COALESCE(fv.size, '')) <> ''
            OR TRIM(COALESCE(fv.image, '')) <> ''
            OR TRIM(COALESCE(fv.image2, '')) <> ''
            OR TRIM(COALESCE(fv.image3, '')) <> ''
            OR TRIM(COALESCE(fv.image4, '')) <> ''
            OR TRIM(COALESCE(fv.video, '')) <> ''
            OR fv.price_override IS NOT NULL
            OR COALESCE(fv.stock, 0) > 0
            OR COALESCE(fv.stock_meters, 0) > 0
        )
  );

DROP TEMPORARY TABLE tmp_legacy_placeholder_products;
