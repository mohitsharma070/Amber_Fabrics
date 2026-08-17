-- Retry-safe scheduled notifications and indexes used by bounded cron scans.
ALTER TABLE abandoned_cart_reminders
    MODIFY COLUMN status ENUM('active','processing','completed','recovered','failed') NOT NULL DEFAULT 'active',
    ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER emails_sent_count,
    ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_attempts,
    ADD COLUMN last_attempt_at DATETIME NULL AFTER last_sent_at,
    ADD COLUMN last_error VARCHAR(1000) NULL AFTER last_attempt_at,
    ADD INDEX idx_abandoned_cart_processing (status, updated_at);

ALTER TABLE back_in_stock_subscriptions
    MODIFY COLUMN status ENUM('pending','processing','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN next_attempt_at DATETIME NULL AFTER requested_at,
    ADD INDEX idx_bis_status_next_attempt (status, next_attempt_at, id);

UPDATE back_in_stock_subscriptions
SET next_attempt_at = DATE_ADD(requested_at, INTERVAL 1 HOUR)
WHERE status = 'pending' AND next_attempt_at IS NULL;

ALTER TABLE public_form_attempts
    ADD INDEX idx_public_form_attempts_updated (updated_at);

ALTER TABLE shipping_courier_shipments
    ADD INDEX idx_shipping_courier_provider_updated (provider, updated_at);

ALTER TABLE support_tickets
    ADD INDEX idx_support_tickets_status_updated (status, updated_at);
