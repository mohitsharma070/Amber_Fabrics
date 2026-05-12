<?php
/**
 * Example credentials/config map.
 * Copy to config/app-config.php and fill with real values on each environment.
 * Do not commit config/app-config.php with secrets.
 */
return [
    'local' => [
        'APP_ENV' => 'local',
        'APP_URL' => 'http://localhost:8000',

        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_USER' => 'root',
        'DB_PASSWORD' => '',
        'DB_NAME' => 'fabric_export',

        'ADMIN_NOTIFICATION_EMAIL' => 'you@example.com',
        'MAIL_FROM' => 'you@example.com',
        'SMTP_HOST' => 'smtp.example.com',
        'SMTP_PORT' => '587',
        'SMTP_PASSWORD' => 'replace-with-local-smtp-password',

        'RAZORPAY_KEY_ID' => 'rzp_test_xxxxxxxxxx',
        'RAZORPAY_KEY_SECRET' => 'replace-with-test-secret',
        'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-test-webhook-secret',
    ],

    'production' => [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://yourdomain.com',

        'DB_HOST' => 'db-host-from-provider',
        'DB_PORT' => '3306',
        'DB_USER' => 'db-username',
        'DB_PASSWORD' => 'replace-with-production-db-password',
        'DB_NAME' => 'db-name',

        'ADMIN_NOTIFICATION_EMAIL' => 'ops@yourdomain.com',
        'MAIL_FROM' => 'noreply@yourdomain.com',
        'SMTP_HOST' => 'smtp.provider.com',
        'SMTP_PORT' => '587',
        'SMTP_PASSWORD' => 'replace-with-production-smtp-password',

        'RAZORPAY_KEY_ID' => 'rzp_live_xxxxxxxxxx',
        'RAZORPAY_KEY_SECRET' => 'replace-with-live-secret',
        'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-live-webhook-secret',
    ],
];
