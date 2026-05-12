-- Add verification-link expiry support for customer email verification
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS email_verify_expires DATETIME DEFAULT NULL AFTER email_verify_token;
