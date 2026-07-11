-- Newsletter subscriptions.
-- This is intentionally separate from customers, abandoned cart reminders,
-- analytics tracking, and back-in-stock subscriptions.

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    email_normalized VARCHAR(255) GENERATED ALWAYS AS (LOWER(TRIM(email))) STORED,
    name VARCHAR(191) DEFAULT NULL,
    status ENUM('pending','subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
    source VARCHAR(80) NOT NULL DEFAULT 'footer',
    consent_ip VARCHAR(45) DEFAULT NULL,
    consent_user_agent VARCHAR(255) DEFAULT NULL,
    confirmed_at DATETIME DEFAULT NULL,
    unsubscribed_at DATETIME DEFAULT NULL,
    unsubscribe_token CHAR(64) NOT NULL,
    verify_token CHAR(64) DEFAULT NULL,
    subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_newsletter_email_normalized (email_normalized),
    UNIQUE KEY uq_newsletter_unsubscribe_token (unsubscribe_token),
    UNIQUE KEY uq_newsletter_verify_token (verify_token),
    INDEX idx_newsletter_customer (customer_id),
    INDEX idx_newsletter_status (status, created_at),
    CONSTRAINT fk_newsletter_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
