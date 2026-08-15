-- Retryable activation-email delivery state. The processing timestamp is a
-- lease, so a request that crashes after claiming work cannot block retries.
ALTER TABLE orders
    ADD COLUMN activation_email_status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending' AFTER account_activation_sent_at,
    ADD COLUMN activation_email_claimed_at DATETIME NULL DEFAULT NULL AFTER activation_email_status,
    ADD COLUMN activation_email_claim_token CHAR(32) NULL DEFAULT NULL AFTER activation_email_claimed_at,
    ADD COLUMN activation_email_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER activation_email_claim_token,
    ADD COLUMN activation_email_last_error VARCHAR(500) NULL DEFAULT NULL AFTER activation_email_attempts;

UPDATE orders
SET activation_email_status = CASE
        WHEN account_activation_sent_at IS NOT NULL THEN 'sent'
        ELSE 'pending'
    END
WHERE account_activation_requested = 1;

CREATE INDEX idx_orders_activation_email_delivery
    ON orders (account_activation_requested, activation_email_status, activation_email_claimed_at);
