<?php
/**
 * Example credentials/config map.
 * Copy to config/app-config.php and fill with real values on each environment.
 * Do not commit config/app-config.php with secrets.
 */
return [
    'local' => [
        // 1) Required first: APP_URL + DB_* + RAZORPAY_* + SMTP_* (if using SMTP)
        // 2) Then fill optional integrations (Meta CAPI, WhatsApp).

        // App
        'APP_ENV' => 'local',
        'APP_DEBUG' => '1',
        'APP_URL' => 'http://localhost:8000',
        'APP_FORCE_HTTPS' => '0',

        // Database
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_USER' => 'root',
        'DB_PASSWORD' => '',
        'DB_NAME' => 'fabric_export',

        // Mail / Notifications
        'ADMIN_NOTIFICATION_EMAIL' => 'you@example.com',
        'CRON_RUN_TOKEN' => 'replace-with-local-random-cron-token',
        'ADMIN_LOGIN_PASSPHRASE' => '',
        'ADMIN_SESSION_IDLE_TIMEOUT_SEC' => '1800',
        'ADMIN_SESSION_ABSOLUTE_TIMEOUT_SEC' => '28800',
        'MAIL_DRIVER' => 'smtp',
        'MAIL_FROM' => 'you@example.com',
        'SMTP_HOST' => 'smtp.example.com',
        'SMTP_PORT' => '587',
        'SMTP_PASSWORD' => 'replace-with-local-smtp-password',

        // Payments (Razorpay)
        'RAZORPAY_KEY_ID' => 'rzp_test_xxxxxxxxxx',
        'RAZORPAY_KEY_SECRET' => 'replace-with-test-secret',
        'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-test-webhook-secret',

        // COD guard / WhatsApp
        'COD_GUARD_WHATSAPP_THRESHOLD' => '1000',
        'COD_GUARD_CALL_THRESHOLD' => '2000',
        'COD_GUARD_CONFIRMATION_HOURS' => '24',
        'COD_GUARD_MESSAGE_MAX_ATTEMPTS' => '3',
        'COD_GUARD_WHATSAPP_PROVIDER' => 'whatsapp_cloud',
        'COD_GUARD_WHATSAPP_API_BASE_URL' => 'https://graph.facebook.com/v21.0',
        'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID' => '',
        'COD_GUARD_WHATSAPP_ACCESS_TOKEN' => '',
        'COD_GUARD_WHATSAPP_TEMPLATE_NAME' => '',
        'COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE' => 'en',
        'COD_GUARD_WHATSAPP_APP_SECRET' => '',
        'COD_GUARD_WEBHOOK_VERIFY_TOKEN' => 'replace-with-random-verify-token',
        'COD_GUARD_WEBHOOK_TOKEN' => 'replace-with-random-post-token',

        // Marketing / Tracking
        'UTM_COOKIE_DAYS' => '30',
        'META_PIXEL_ID' => 'replace-with-meta-pixel-id',
        'META_CAPI_PIXEL_ID' => 'optional-override-pixel-id',
        'META_CAPI_ACCESS_TOKEN' => 'replace-with-meta-capi-access-token',
        'META_CAPI_TEST_EVENT_CODE' => 'optional-test-event-code',
        // Google Analytics 4 (GA4)
        'GOOGLE_ANALYTICS_ENABLED' => '1',
        'GOOGLE_ANALYTICS_MEASUREMENT_ID' => 'G-XXXXXXXXXX',
        'GOOGLE_ANALYTICS_DEBUG_MODE' => '1',
        'GOOGLE_ANALYTICS_ENHANCED_ECOMMERCE_ENABLED' => '1',
        'GOOGLE_ANALYTICS_CONSENT_REQUIRED' => '1',

        // Plugins / automations
        'ABANDONED_CART_EMAIL_ENABLED' => '1',
        'ABANDONED_CART_EMAIL_DELAY_MINUTES' => '60',
        'ABANDONED_CART_EMAIL_MAX_EMAILS' => '1',
        'PRODUCT_FEED_ENABLED' => '1',
        'PRODUCT_FEED_BASE_PATH' => '/feeds',
        'PRODUCT_FEED_XML_FILE' => 'products.xml',
        'PRODUCT_FEED_JSON_FILE' => 'products.json',

        // Media
        'IMAGE_UPLOAD_MAX_MB' => '5',
        'IMAGE_MIN_WIDTH' => '600',
        'IMAGE_MIN_HEIGHT' => '800',
        'IMAGE_WEBP_QUALITY' => '82',
        'IMAGE_MAX_WIDTH' => '1920',
        'IMAGE_RESPONSIVE_WIDTHS' => '360,720,1200',
        'IMAGE_THUMB_WIDTH' => '360',
        'IMAGE_THUMB_HEIGHT' => '360',
        'INVENTORY_ALERT_ENABLED' => '1',
        'INVENTORY_ALERT_PIECE_THRESHOLD' => '5',
        'INVENTORY_ALERT_METER_THRESHOLD' => '10',
        'INVENTORY_ALERT_COOLDOWN_HOURS' => '24',
        'SHIPPING_RTO_RISK_ENABLED' => '1',
        'SHIPPING_RTO_RISK_HIGH_THRESHOLD' => '70',
        'SHIPPING_RTO_RISK_MEDIUM_THRESHOLD' => '40',
        'REVIEW_RATING_ENABLED' => '1',
        'REVIEW_RATING_AUTO_APPROVE' => '0',
        'REVIEW_RATING_MIN_LENGTH' => '10',
        'REVIEW_RATING_MAX_LENGTH' => '800',
        'ORDER_TIMELINE_ENABLED' => '1',
        'ORDER_TIMELINE_SHOW_INTERNAL_TO_ADMIN' => '1',
    ],

    'production' => [
        // 1) Required first: APP_URL + DB_* + RAZORPAY_* + SMTP_*
        // 2) Keep disabled optional integrations blank until you are ready to use them.

        // App
        'APP_ENV' => 'production',
        'APP_DEBUG' => '0',
        'APP_URL' => 'https://yourdomain.com',
        'APP_FORCE_HTTPS' => '1',

        // Database
        'DB_HOST' => 'db-host-from-provider',
        'DB_PORT' => '3306',
        'DB_USER' => 'db-username',
        'DB_PASSWORD' => 'replace-with-production-db-password',
        'DB_NAME' => 'db-name',

        // Mail / Notifications
        'ADMIN_NOTIFICATION_EMAIL' => 'ops@yourdomain.com',
        'CRON_RUN_TOKEN' => 'replace-with-production-random-cron-token',
        'ADMIN_LOGIN_PASSPHRASE' => '',
        'ADMIN_SESSION_IDLE_TIMEOUT_SEC' => '1800',
        'ADMIN_SESSION_ABSOLUTE_TIMEOUT_SEC' => '28800',
        'MAIL_DRIVER' => 'smtp',
        'MAIL_FROM' => 'noreply@yourdomain.com',
        'SMTP_HOST' => 'smtp.provider.com',
        'SMTP_PORT' => '587',
        'SMTP_PASSWORD' => 'replace-with-production-smtp-password',

        // Payments (Razorpay)
        'RAZORPAY_KEY_ID' => 'rzp_live_xxxxxxxxxx',
        'RAZORPAY_KEY_SECRET' => 'replace-with-live-secret',
        'RAZORPAY_WEBHOOK_SECRET' => 'replace-with-live-webhook-secret',

        // COD guard / WhatsApp
        'COD_GUARD_WHATSAPP_THRESHOLD' => '1000',
        'COD_GUARD_CALL_THRESHOLD' => '2000',
        'COD_GUARD_CONFIRMATION_HOURS' => '24',
        'COD_GUARD_MESSAGE_MAX_ATTEMPTS' => '3',
        'COD_GUARD_WHATSAPP_PROVIDER' => 'whatsapp_cloud',
        'COD_GUARD_WHATSAPP_API_BASE_URL' => 'https://graph.facebook.com/v21.0',
        'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID' => '',
        'COD_GUARD_WHATSAPP_ACCESS_TOKEN' => '',
        'COD_GUARD_WHATSAPP_TEMPLATE_NAME' => '',
        'COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE' => 'en',
        'COD_GUARD_WHATSAPP_APP_SECRET' => '',
        'COD_GUARD_WEBHOOK_VERIFY_TOKEN' => '',
        'COD_GUARD_WEBHOOK_TOKEN' => '',

        // Marketing / Tracking
        'UTM_COOKIE_DAYS' => '30',
        'META_PIXEL_ID' => '',
        'META_CAPI_PIXEL_ID' => '',
        'META_CAPI_ACCESS_TOKEN' => '',
        'META_CAPI_TEST_EVENT_CODE' => '',
        // Google Analytics 4 (GA4). Leave measurement ID blank until ready.
        'GOOGLE_ANALYTICS_ENABLED' => '1',
        'GOOGLE_ANALYTICS_MEASUREMENT_ID' => '',
        'GOOGLE_ANALYTICS_DEBUG_MODE' => '0',
        'GOOGLE_ANALYTICS_ENHANCED_ECOMMERCE_ENABLED' => '1',
        'GOOGLE_ANALYTICS_CONSENT_REQUIRED' => '1',

        // Plugins / automations
        'ABANDONED_CART_EMAIL_ENABLED' => '1',
        'ABANDONED_CART_EMAIL_DELAY_MINUTES' => '60',
        'ABANDONED_CART_EMAIL_MAX_EMAILS' => '1',
        'PRODUCT_FEED_ENABLED' => '1',
        'PRODUCT_FEED_BASE_PATH' => '/feeds',
        'PRODUCT_FEED_XML_FILE' => 'products.xml',
        'PRODUCT_FEED_JSON_FILE' => 'products.json',

        // Media
        'IMAGE_UPLOAD_MAX_MB' => '5',
        'IMAGE_MIN_WIDTH' => '600',
        'IMAGE_MIN_HEIGHT' => '800',
        'IMAGE_WEBP_QUALITY' => '82',
        'IMAGE_MAX_WIDTH' => '1920',
        'IMAGE_RESPONSIVE_WIDTHS' => '360,720,1200',
        'IMAGE_THUMB_WIDTH' => '360',
        'IMAGE_THUMB_HEIGHT' => '360',
        'INVENTORY_ALERT_ENABLED' => '1',
        'INVENTORY_ALERT_PIECE_THRESHOLD' => '5',
        'INVENTORY_ALERT_METER_THRESHOLD' => '10',
        'INVENTORY_ALERT_COOLDOWN_HOURS' => '24',
        'SHIPPING_RTO_RISK_ENABLED' => '1',
        'SHIPPING_RTO_RISK_HIGH_THRESHOLD' => '70',
        'SHIPPING_RTO_RISK_MEDIUM_THRESHOLD' => '40',
        'REVIEW_RATING_ENABLED' => '1',
        'REVIEW_RATING_AUTO_APPROVE' => '0',
        'REVIEW_RATING_MIN_LENGTH' => '10',
        'REVIEW_RATING_MAX_LENGTH' => '800',
        'ORDER_TIMELINE_ENABLED' => '1',
        'ORDER_TIMELINE_SHOW_INTERNAL_TO_ADMIN' => '1',
    ],
];
