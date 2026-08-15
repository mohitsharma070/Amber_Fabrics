-- Product editor V2: permanently remove the legacy product catalogue columns.
-- Preserve values which have a direct replacement before dropping anything.

UPDATE fabrics
SET price = price_inr
WHERE (price IS NULL OR price <= 0) AND price_inr IS NOT NULL AND price_inr > 0;

UPDATE fabrics
SET catalog_data = JSON_SET(
    CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END,
    '$.attr_material', CASE
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_material')), '') <> ''
            THEN JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_material'))
        ELSE COALESCE(material, '')
    END,
    '$.attr_fabric', CASE
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_fabric')), '') <> ''
            THEN JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_fabric'))
        ELSE COALESCE(material, '')
    END,
    '$.attr_printing_type', CASE
        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_printing_type')), '') <> ''
            THEN JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(catalog_data) THEN catalog_data ELSE '{}' END, '$.attr_printing_type'))
        ELSE COALESCE(print_style, '')
    END
);

INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT f.id, 'image', f.image, f.name, 1, 0
FROM fabrics f
WHERE COALESCE(f.image, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' AND fm.filename=f.image);
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT f.id, 'image', f.image2, f.name, 0, 1
FROM fabrics f
WHERE COALESCE(f.image2, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' AND fm.filename=f.image2);
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT f.id, 'image', f.image3, f.name, 0, 2
FROM fabrics f
WHERE COALESCE(f.image3, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' AND fm.filename=f.image3);
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT f.id, 'image', f.image4, f.name, 0, 3
FROM fabrics f
WHERE COALESCE(f.image4, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' AND fm.filename=f.image4);
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT f.id, 'video', f.video, f.name, 0, 0
FROM fabrics f
WHERE COALESCE(f.video, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='video' AND fm.filename=f.video);

SET @drop_material_index = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fabrics' AND INDEX_NAME='idx_fabrics_material'),
    'ALTER TABLE fabrics DROP INDEX idx_fabrics_material',
    'SELECT 1'
);
PREPARE drop_material_index_stmt FROM @drop_material_index;
EXECUTE drop_material_index_stmt;
DEALLOCATE PREPARE drop_material_index_stmt;

SET @drop_fulltext_index = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fabrics' AND INDEX_NAME='ft_fabrics_catalog_search'),
    'ALTER TABLE fabrics DROP INDEX ft_fabrics_catalog_search',
    'SELECT 1'
);
PREPARE drop_fulltext_index_stmt FROM @drop_fulltext_index;
EXECUTE drop_fulltext_index_stmt;
DEALLOCATE PREPARE drop_fulltext_index_stmt;

ALTER TABLE fabrics
    DROP COLUMN print_style,
    DROP COLUMN material,
    DROP COLUMN gsm,
    DROP COLUMN width,
    DROP COLUMN moq,
    DROP COLUMN lead_time,
    DROP COLUMN dispatch_time,
    DROP COLUMN wash_care,
    DROP COLUMN seo_title,
    DROP COLUMN seo_description,
    DROP COLUMN image,
    DROP COLUMN image2,
    DROP COLUMN image3,
    DROP COLUMN image4,
    DROP COLUMN video,
    DROP COLUMN price_inr,
    DROP COLUMN price_usd,
    DROP COLUMN is_featured;
