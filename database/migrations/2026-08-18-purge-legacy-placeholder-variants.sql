UPDATE order_items oi
JOIN fabric_variants fv ON fv.id = oi.variant_id
JOIN fabrics f ON f.id = fv.fabric_id
SET oi.variant_id = NULL
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

DELETE fv
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
