<?php
/**
 * Production configuration template.
 *
 * Copy ordinary values into server environment variables or an ignored
 * config/secure-config.production.php. ADMIN_LOGIN_PASSPHRASE and
 * APP_IDENTITY_HASH_KEY must use environment variables or the protected
 * /home/<account>/.app-secrets shared-hosting file. Never commit credentials.
 */
return [
    'APP_ENV' => 'production',
    'APP_DEBUG' => '0',
    'APP_URL' => 'https://replace-with-your-domain.example',
    'APP_FORCE_HTTPS' => '1',
    'APP_IDENTITY_HASH_KEY' => 'replace-with-at-least-32-random-characters',
    'DB_HOST' => 'replace-with-db-host',
    'DB_PORT' => '3306',
    'DB_USER' => 'replace-with-db-user',
    'DB_PASSWORD' => 'replace-with-db-password',
    'DB_NAME' => 'replace-with-db-name',
    'ADMIN_NOTIFICATION_EMAIL' => 'replace-with-admin-email@example.invalid',
    'ADMIN_LOGIN_PASSPHRASE' => 'replace-with-at-least-16-random-characters',
    'CRON_RUN_TOKEN' => 'replace-with-random-cron-token',
    'MAIL_DRIVER' => 'smtp',
    'MAIL_FROM' => 'replace-with-mailbox@example.invalid',
    'SMTP_HOST' => 'replace-with-smtp-host',
    'SMTP_PORT' => '587',
    'SMTP_PASSWORD' => 'replace-with-smtp-password',
    'RAZORPAY_KEY_ID' => 'replace-with-razorpay-key-id',
    'RAZORPAY_KEY_SECRET' => 'replace-with-razorpay-key-secret',
    'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-razorpay-webhook-secret',
    'RAZORPAY_HTTP_TIMEOUT_SEC' => '15',
    'RAZORPAY_HTTP_CONNECT_TIMEOUT_SEC' => '5',
    'RAZORPAY_HTTP_CA_BUNDLE' => '',
    'RAZORPAY_HTTP_SKIP_TLS_VERIFY' => '0',
];
