ALTER TABLE fabrics MODIFY COLUMN status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft';
ALTER TABLE fabrics ADD COLUMN product_type ENUM('simple','variable') NOT NULL DEFAULT 'simple' AFTER category;
ALTER TABLE fabrics ADD COLUMN slug VARCHAR(191) NULL DEFAULT NULL AFTER sku;
ALTER TABLE fabrics ADD COLUMN seo_title VARCHAR(255) NULL DEFAULT NULL AFTER description;
ALTER TABLE fabrics ADD COLUMN seo_description VARCHAR(320) NULL DEFAULT NULL AFTER seo_title;
ALTER TABLE fabrics ADD COLUMN hsn_code VARCHAR(8) NULL DEFAULT NULL AFTER seo_description;
ALTER TABLE fabrics ADD COLUMN gst_rate DECIMAL(5,2) NULL DEFAULT NULL AFTER hsn_code;
ALTER TABLE fabrics ADD COLUMN shipping_weight_kg DECIMAL(10,3) NULL DEFAULT NULL AFTER gst_rate;
ALTER TABLE fabrics ADD COLUMN parcel_length_cm DECIMAL(10,2) NULL DEFAULT NULL AFTER shipping_weight_kg;
ALTER TABLE fabrics ADD COLUMN parcel_width_cm DECIMAL(10,2) NULL DEFAULT NULL AFTER parcel_length_cm;
ALTER TABLE fabrics ADD COLUMN parcel_height_cm DECIMAL(10,2) NULL DEFAULT NULL AFTER parcel_width_cm;
ALTER TABLE fabrics ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER created_at;
ALTER TABLE fabrics ADD COLUMN published_by INT NULL DEFAULT NULL AFTER published_at;
ALTER TABLE fabrics ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER published_by;

UPDATE fabrics f
SET product_type = CASE WHEN EXISTS (
    SELECT 1 FROM fabric_variants fv
    WHERE fv.fabric_id = f.id
      AND (TRIM(COALESCE(fv.size,'')) <> '' OR LOWER(TRIM(COALESCE(fv.color,''))) NOT IN ('','default'))
) THEN 'variable' ELSE 'simple' END;

UPDATE fabrics
SET slug = CONCAT(
    TRIM(BOTH '-' FROM LOWER(REPLACE(REPLACE(REPLACE(TRIM(name), ' ', '-'), '/', '-'), '_', '-'))),
    '-', id
)
WHERE slug IS NULL OR TRIM(slug) = '';

ALTER TABLE fabrics MODIFY COLUMN slug VARCHAR(191) NOT NULL;
CREATE UNIQUE INDEX uq_fabrics_slug ON fabrics (slug);

CREATE TABLE IF NOT EXISTS fabric_media (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    fabric_id INT NOT NULL,
    media_type ENUM('image','video') NOT NULL DEFAULT 'image',
    filename VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255) NOT NULL DEFAULT '',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fabric_media_product_sort (fabric_id, media_type, sort_order),
    CONSTRAINT fk_fabric_media_fabric FOREIGN KEY (fabric_id) REFERENCES fabrics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT id, 'image', image, name, 1, 0 FROM fabrics WHERE COALESCE(image,'') <> '';
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT id, 'image', image2, name, 0, 1 FROM fabrics WHERE COALESCE(image2,'') <> '';
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT id, 'image', image3, name, 0, 2 FROM fabrics WHERE COALESCE(image3,'') <> '';
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT id, 'image', image4, name, 0, 3 FROM fabrics WHERE COALESCE(image4,'') <> '';
INSERT INTO fabric_media (fabric_id, media_type, filename, alt_text, is_primary, sort_order)
SELECT id, 'video', video, name, 0, 0 FROM fabrics WHERE COALESCE(video,'') <> '';
