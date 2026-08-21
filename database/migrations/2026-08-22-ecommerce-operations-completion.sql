-- Ecommerce operational tooling, variant-aware alerts, reverse-pickup claims,
-- and retirement of the newsletter subscriber store.

ALTER TABLE inventory_alert_logs
    ADD COLUMN variant_id INT NULL AFTER product_id,
    ADD INDEX idx_inventory_alert_product_variant_sent (product_id, variant_id, sent_at);

ALTER TABLE shipping_courier_reverse_pickups
    ADD COLUMN initialization_status ENUM('idle','claiming','created','failed') NOT NULL DEFAULT 'idle' AFTER provider,
    ADD COLUMN claim_token CHAR(32) NULL AFTER initialization_status,
    ADD COLUMN claimed_at DATETIME NULL AFTER claim_token,
    ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER claimed_at,
    ADD COLUMN last_attempt_at DATETIME NULL AFTER attempt_count,
    ADD COLUMN last_error VARCHAR(1000) NULL AFTER last_attempt_at,
    ADD INDEX idx_shipping_courier_reverse_claim (initialization_status, claimed_at),
    ADD INDEX idx_shipping_courier_reverse_sync (provider, provider_status, updated_at);

CREATE TABLE IF NOT EXISTS cron_run_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('success','degraded','failed','skipped') NOT NULL,
    jobs_total INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_degraded INT UNSIGNED NOT NULL DEFAULT 0,
    critical_jobs_failed INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cron_run_history_started (started_at),
    INDEX idx_cron_run_history_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only rewrite the exact legacy application wording. Custom policy content is
-- otherwise left untouched.
UPDATE site_settings
SET setting_value = '<h3>Refund Return Request Window</h3><p>Return requests must be raised within 7 calendar days of confirmed delivery for India ecommerce orders.</p><h3>Eligible Cases</h3><p>Refund returns are considered for verified cases of wrong item delivered, transit damage, or major manufacturing defect.</p><h3>Mandatory Evidence</h3><p>Customers must share clear parcel opening photos/videos, product photos, and order details. Claims without adequate evidence may be declined.</p><h3>Non-Returnable Cases</h3><p>Products are not eligible if they are used, washed, altered, cut, stitched, or missing tags or original packaging. Minor shade variation, artisanal print irregularity, weave texture variation, and slight measurement tolerance are not considered defects.</p><h3>Refund Flow</h3><p>After approval and quality validation, eligible refunds are processed to the original payment source. Bank settlement may take 5 to 10 business days depending on payment method.</p><h3>International / Bulk Orders</h3><p>International and bulk claims follow the finalized quote or proforma terms.</p>'
WHERE setting_key = 'return_policy_body_html'
  AND SHA2(setting_value, 256) = 'a3b1c153e355acbf601300e61a37416caa10947b0f0dae5ad51a597337296e2b';

UPDATE site_settings
SET setting_value = REPLACE(REPLACE(REPLACE(setting_value,
    'Returns and Exchanges', 'Refund Returns'),
    'Returns and exchanges are governed', 'Eligible refund returns are governed'),
    'Return &amp; Exchange Policy', 'Return Policy')
WHERE setting_key = 'terms_policy_body_html'
  AND SHA2(setting_value, 256) = 'ee9e057eef5b165a0594f579b9a8d591d2604ba38992ad508bb0b674a2287dde';

UPDATE site_settings
SET setting_value = REPLACE(REPLACE(setting_value,
    'Returns and Replacements', 'Claims and Refunds'),
    'International returns/replacements', 'International claims and refunds')
WHERE setting_key = 'international_policy_body_html'
  AND SHA2(setting_value, 256) = '494ec60e26f3ce26e1d126d9b26b471516c40dae09354504c635c1b052733042';

UPDATE site_settings
SET setting_value = REPLACE(REPLACE(REPLACE(setting_value,
    'Can I return or exchange products?', 'Can I request a refund return?'),
    'Yes, for eligible cases such as wrong item, transit damage, or major defect, within the policy window.',
    'Yes, for eligible cases such as a wrong item, transit damage, or major defect within 7 calendar days of confirmed delivery.'),
    'Return &amp; Exchange Policy', 'Return Policy')
WHERE setting_key = 'faq_body_html'
  AND SHA2(setting_value, 256) = '15aa921b10869813506df1049c3ee6cf6dd9ef2c8b747218408b2c5ec1bb1bff';

-- Newsletter subscribers are not retained by this application.
DROP TABLE IF EXISTS newsletter_subscribers;
