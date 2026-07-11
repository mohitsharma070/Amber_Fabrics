<?php
/**
 * Production-only secret override example.
 *
 * Copy this file outside the deployed web root as secure-config.php, or point
 * APP_CONFIG_FILE to its absolute path. Never deploy real secrets publicly.
 */
return [
    'production' => [
        'APP_ENV' => 'production',
        'APP_URL' => 'https://yourdomain.com',
        'APP_FORCE_HTTPS' => '1',

        'DB_HOST' => 'db-host-from-provider',
        'DB_PORT' => '3306',
        'DB_USER' => 'db-username',
        'DB_PASSWORD' => 'replace-with-production-db-password',
        'DB_NAME' => 'db-name',

        'ADMIN_NOTIFICATION_EMAIL' => 'ops@yourdomain.com',
        'CRON_RUN_TOKEN' => 'replace-with-production-random-cron-token',

        'MAIL_DRIVER' => 'smtp',
        'MAIL_FROM' => 'noreply@yourdomain.com',
        'SMTP_HOST' => 'smtp.provider.com',
        'SMTP_PORT' => '587',
        'SMTP_PASSWORD' => 'replace-with-production-smtp-password',

        'RAZORPAY_KEY_ID' => 'rzp_live_xxxxxxxxxx',
        'RAZORPAY_KEY_SECRET' => 'replace-with-live-secret',
        'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-live-webhook-secret',

        // Enable only after WhatsApp webhook verification is configured.
        'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID' => '',
        'COD_GUARD_WHATSAPP_ACCESS_TOKEN' => '',
        'COD_GUARD_WHATSAPP_APP_SECRET' => '',
        'COD_GUARD_WEBHOOK_VERIFY_TOKEN' => '',
        'COD_GUARD_WEBHOOK_TOKEN' => '',

        // Enable only after marketing consent/legal setup is complete.
        'META_PIXEL_ID' => '',
        'META_CAPI_PIXEL_ID' => '',
        'META_CAPI_ACCESS_TOKEN' => '',
        'META_CAPI_TEST_EVENT_CODE' => '',
        'GOOGLE_ANALYTICS_ENABLED' => '1',
        'GOOGLE_ANALYTICS_MEASUREMENT_ID' => '',
        'GOOGLE_ANALYTICS_DEBUG_MODE' => '0',
        'GOOGLE_ANALYTICS_ENHANCED_ECOMMERCE_ENABLED' => '1',
        'GOOGLE_ANALYTICS_CONSENT_REQUIRED' => '1',
    ],
];
