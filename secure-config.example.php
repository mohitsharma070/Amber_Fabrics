<?php
// Copy this file to ../secure-config.php (one level above htdocs/public folder)
// and fill with your real production values.
return [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://example.com',
    'APP_FORCE_HTTPS' => '1',
    'DB_HOST' => 'sqlXXX.epizy.com',
    'DB_PORT' => '3306',
    'DB_USER' => 'epiz_xxxxx',
    'DB_PASSWORD' => 'your-db-password',
    'DB_NAME' => 'epiz_xxxxx_db',
    'RAZORPAY_KEY_ID' => 'rzp_live_xxxxx',
    'RAZORPAY_KEY_SECRET' => 'your-razorpay-secret',
    'RAZORPAY_WEBHOOK_SECRET' => 'your-webhook-secret',
    'SMTP_HOST' => 'smtp.gmail.com',
    'SMTP_PORT' => '587',
    'SMTP_PASSWORD' => 'your-smtp-password',
    'MAIL_FROM' => 'noreply@example.com',
    'ADMIN_NOTIFICATION_EMAIL' => 'owner@example.com',
    'CRON_RUN_TOKEN' => 'long-random-cron-token',
];
