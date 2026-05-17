<?php
/**
 * Enable optional plugins here.
 *
 * Example:
 * 'enabled' => ['utm-attribution', 'meta-pixel'],
 */
return [
    'enabled' => ['cod-guard', 'utm-attribution', 'meta-pixel', 'meta-capi', 'abandoned-cart-email', 'product-feed', 'inventory-alert', 'shipping-rto-risk', 'review-rating', 'order-timeline'],
    'settings' => [
        'cod-guard' => [
            'whatsapp_threshold' => (float) (function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_THRESHOLD', '1000') : '1000'),
            'call_threshold' => (float) (function_exists('_cfg') ? _cfg('COD_GUARD_CALL_THRESHOLD', '2000') : '2000'),
            'confirmation_hours' => (int) (function_exists('_cfg') ? _cfg('COD_GUARD_CONFIRMATION_HOURS', '24') : '24'),
            'message_max_attempts' => (int) (function_exists('_cfg') ? _cfg('COD_GUARD_MESSAGE_MAX_ATTEMPTS', '3') : '3'),
            'whatsapp_provider' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_PROVIDER', 'whatsapp_cloud') : 'whatsapp_cloud',
            'whatsapp_api_base_url' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/v21.0') : 'https://graph.facebook.com/v21.0',
            'whatsapp_phone_number_id' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_PHONE_NUMBER_ID', '') : '',
            'whatsapp_access_token' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_ACCESS_TOKEN', '') : '',
            'whatsapp_template_name' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_TEMPLATE_NAME', '') : '',
            'whatsapp_template_language' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE', 'en') : 'en',
            'whatsapp_app_secret' => function_exists('_cfg') ? _cfg('COD_GUARD_WHATSAPP_APP_SECRET', '') : '',
            'webhook_verify_token' => function_exists('_cfg') ? _cfg('COD_GUARD_WEBHOOK_VERIFY_TOKEN', '') : '',
            'webhook_auth_token' => function_exists('_cfg') ? _cfg('COD_GUARD_WEBHOOK_TOKEN', '') : '',
        ],
        'utm-attribution' => [
            'cookie_days' => (int) (function_exists('_cfg') ? _cfg('UTM_COOKIE_DAYS', '30') : '30'),
        ],
        'meta-pixel' => [
            'pixel_id' => function_exists('_cfg') ? _cfg('META_PIXEL_ID', '') : '',
        ],
        'meta-capi' => [
            'pixel_id' => function_exists('_cfg') ? _cfg('META_CAPI_PIXEL_ID', '') : '',
            'access_token' => function_exists('_cfg') ? _cfg('META_CAPI_ACCESS_TOKEN', '') : '',
            'test_event_code' => function_exists('_cfg') ? _cfg('META_CAPI_TEST_EVENT_CODE', '') : '',
        ],
        'abandoned-cart-email' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('ABANDONED_CART_EMAIL_ENABLED', '1') : '1'),
            'delay_minutes' => (int) (function_exists('_cfg') ? _cfg('ABANDONED_CART_EMAIL_DELAY_MINUTES', '60') : '60'),
            'max_emails' => (int) (function_exists('_cfg') ? _cfg('ABANDONED_CART_EMAIL_MAX_EMAILS', '1') : '1'),
        ],
        'product-feed' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('PRODUCT_FEED_ENABLED', '1') : '1'),
            'base_path' => function_exists('_cfg') ? _cfg('PRODUCT_FEED_BASE_PATH', '/feeds') : '/feeds',
            'xml_file' => function_exists('_cfg') ? _cfg('PRODUCT_FEED_XML_FILE', 'products.xml') : 'products.xml',
            'json_file' => function_exists('_cfg') ? _cfg('PRODUCT_FEED_JSON_FILE', 'products.json') : 'products.json',
        ],
        'inventory-alert' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('INVENTORY_ALERT_ENABLED', '1') : '1'),
            'piece_threshold' => (float) (function_exists('_cfg') ? _cfg('INVENTORY_ALERT_PIECE_THRESHOLD', '5') : '5'),
            'meter_threshold' => (float) (function_exists('_cfg') ? _cfg('INVENTORY_ALERT_METER_THRESHOLD', '10') : '10'),
            'cooldown_hours' => (int) (function_exists('_cfg') ? _cfg('INVENTORY_ALERT_COOLDOWN_HOURS', '24') : '24'),
        ],
        'shipping-rto-risk' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('SHIPPING_RTO_RISK_ENABLED', '1') : '1'),
            'high_threshold' => (int) (function_exists('_cfg') ? _cfg('SHIPPING_RTO_RISK_HIGH_THRESHOLD', '70') : '70'),
            'medium_threshold' => (int) (function_exists('_cfg') ? _cfg('SHIPPING_RTO_RISK_MEDIUM_THRESHOLD', '40') : '40'),
        ],
        'review-rating' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('REVIEW_RATING_ENABLED', '1') : '1'),
            'auto_approve' => (int) (function_exists('_cfg') ? _cfg('REVIEW_RATING_AUTO_APPROVE', '1') : '1'),
            'min_length' => (int) (function_exists('_cfg') ? _cfg('REVIEW_RATING_MIN_LENGTH', '10') : '10'),
            'max_length' => (int) (function_exists('_cfg') ? _cfg('REVIEW_RATING_MAX_LENGTH', '800') : '800'),
        ],
        'order-timeline' => [
            'enabled' => (int) (function_exists('_cfg') ? _cfg('ORDER_TIMELINE_ENABLED', '1') : '1'),
            'show_internal_to_admin' => (int) (function_exists('_cfg') ? _cfg('ORDER_TIMELINE_SHOW_INTERNAL_TO_ADMIN', '1') : '1'),
        ],
    ],
];
