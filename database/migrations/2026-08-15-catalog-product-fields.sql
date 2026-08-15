ALTER TABLE fabrics ADD COLUMN product_code VARCHAR(100) NULL DEFAULT NULL AFTER id;
ALTER TABLE fabrics ADD COLUMN amazon_asin VARCHAR(20) NULL DEFAULT NULL AFTER product_code;
ALTER TABLE fabrics ADD COLUMN catalog_data LONGTEXT NULL DEFAULT NULL AFTER seo_description;
CREATE UNIQUE INDEX uq_fabrics_product_code ON fabrics (product_code);

