<?php
/**
 * Centralized database + app credential bootstrap.
 *
 * Runtime config precedence, lowest to highest:
 * 1. config/app-config.php mode block
 * 2. secure-config.php from a server-only location, if present
 * 3. server environment variables
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function app_bootstrap_fail(string $publicMessage, ?string $logMessage = null, int $cliExitCode = 1): void
{
    if ($logMessage !== null && $logMessage !== '') {
        error_log($logMessage);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $publicMessage . PHP_EOL);
        throw new RuntimeException($publicMessage, max(1, $cliExitCode));
    }

    http_response_code(500);
    exit($publicMessage);
}

function app_config_env(string $key): ?string
{
    $value = getenv($key);
    if ($value === false && isset($_SERVER[$key])) {
        $value = $_SERVER[$key];
    }
    return ($value === false || $value === null) ? null : (string) $value;
}

function app_config_load_file(string $path, string $mode): array
{
    if (!is_file($path)) {
        return [];
    }

    $config = require $path;
    if (!is_array($config)) {
        app_bootstrap_fail('Server configuration error. Invalid app config.');
    }

    if (isset($config[$mode]) && is_array($config[$mode])) {
        return $config[$mode];
    }

    return $config;
}

function app_config_apply_env_overrides(array $config): array
{
    $keys = [
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_FORCE_HTTPS',
        'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME',
        'ADMIN_NOTIFICATION_EMAIL', 'CRON_RUN_TOKEN',
        'ADMIN_LOGIN_PASSPHRASE', 'ADMIN_SESSION_IDLE_TIMEOUT_SEC', 'ADMIN_SESSION_ABSOLUTE_TIMEOUT_SEC',
        'MAIL_DRIVER', 'MAIL_FROM', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_PASSWORD',
        'RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET',
        'COD_GUARD_WHATSAPP_THRESHOLD', 'COD_GUARD_CALL_THRESHOLD',
        'COD_GUARD_CONFIRMATION_HOURS', 'COD_GUARD_MESSAGE_MAX_ATTEMPTS',
        'COD_GUARD_WHATSAPP_PROVIDER', 'COD_GUARD_WHATSAPP_API_BASE_URL',
        'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID', 'COD_GUARD_WHATSAPP_ACCESS_TOKEN',
        'COD_GUARD_WHATSAPP_TEMPLATE_NAME', 'COD_GUARD_WHATSAPP_TEMPLATE_LANGUAGE',
        'COD_GUARD_WHATSAPP_APP_SECRET', 'COD_GUARD_WEBHOOK_VERIFY_TOKEN',
        'COD_GUARD_WEBHOOK_TOKEN',
        'UTM_COOKIE_DAYS', 'META_PIXEL_ID', 'META_CAPI_PIXEL_ID',
        'META_CAPI_ACCESS_TOKEN', 'META_CAPI_TEST_EVENT_CODE',
        'GOOGLE_ANALYTICS_ENABLED', 'GOOGLE_ANALYTICS_MEASUREMENT_ID',
        'GOOGLE_ANALYTICS_DEBUG_MODE', 'GOOGLE_ANALYTICS_ENHANCED_ECOMMERCE_ENABLED',
        'GOOGLE_ANALYTICS_CONSENT_REQUIRED',
        'ABANDONED_CART_EMAIL_ENABLED', 'ABANDONED_CART_EMAIL_DELAY_MINUTES',
        'ABANDONED_CART_EMAIL_MAX_EMAILS',
        'PRODUCT_FEED_ENABLED', 'PRODUCT_FEED_BASE_PATH',
        'PRODUCT_FEED_XML_FILE', 'PRODUCT_FEED_JSON_FILE',
        'IMAGE_UPLOAD_MAX_MB', 'IMAGE_MIN_WIDTH', 'IMAGE_MIN_HEIGHT',
        'IMAGE_WEBP_QUALITY', 'IMAGE_MAX_WIDTH', 'IMAGE_RESPONSIVE_WIDTHS',
        'IMAGE_THUMB_WIDTH', 'IMAGE_THUMB_HEIGHT',
        'INVENTORY_ALERT_ENABLED', 'INVENTORY_ALERT_PIECE_THRESHOLD',
        'INVENTORY_ALERT_METER_THRESHOLD', 'INVENTORY_ALERT_COOLDOWN_HOURS',
        'SHIPPING_RTO_RISK_ENABLED', 'SHIPPING_RTO_RISK_HIGH_THRESHOLD',
        'SHIPPING_RTO_RISK_MEDIUM_THRESHOLD',
        'SHIPPING_COURIER_ENABLED', 'SHIPPING_COURIER_PROVIDER',
        'SHIPPING_COURIER_TEST_MODE', 'SHIPPING_COURIER_AUTO_CREATE',
        'SHIPPING_COURIER_TRACKING_SYNC', 'SHIPPING_COURIER_WEBHOOK_SECRET',
        'SHIPPING_COURIER_API_BASE_URL', 'SHIPPING_COURIER_API_KEY',
        'SHIPPING_COURIER_API_SECRET',
        'REVIEW_RATING_ENABLED', 'REVIEW_RATING_AUTO_APPROVE',
        'REVIEW_RATING_MIN_LENGTH', 'REVIEW_RATING_MAX_LENGTH',
        'ORDER_TIMELINE_ENABLED', 'ORDER_TIMELINE_SHOW_INTERNAL_TO_ADMIN',
    ];

    foreach ($keys as $key) {
        $value = app_config_env($key);
        if ($value !== null) {
            $config[$key] = $value;
        }
    }

    return $config;
}

function app_config_is_placeholder(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $lower = strtolower($value);
    foreach (['replace-with', 'your-', 'your_', 'xxxxx', 'example.com', 'yourdomain', 'db-host-from-provider', 'db-username', 'db-name'] as $needle) {
        if (strpos($lower, $needle) !== false) {
            return true;
        }
    }

    return in_array($value, ['YOUR_ACCESS_TOKEN', 'YOUR_APP_SECRET', 'YOUR_PHONE_NUMBER_ID'], true);
}

function app_config_validate_production(array $config): void
{
    $required = [
        'APP_URL',
        'DB_HOST',
        'DB_PORT',
        'DB_USER',
        'DB_PASSWORD',
        'DB_NAME',
        'ADMIN_NOTIFICATION_EMAIL',
        'MAIL_FROM',
        'CRON_RUN_TOKEN',
        'RAZORPAY_KEY_ID',
        'RAZORPAY_KEY_SECRET',
        'RAZORPAY_WEBHOOK_SECRET',
    ];

    if (strtolower(trim((string) ($config['MAIL_DRIVER'] ?? 'smtp'))) !== 'mail') {
        $required[] = 'SMTP_HOST';
        $required[] = 'SMTP_PORT';
        $required[] = 'SMTP_PASSWORD';
    }

    $metaPixelId = trim((string) ($config['META_PIXEL_ID'] ?? ''));
    if ($metaPixelId !== '') {
        $required[] = 'META_PIXEL_ID';
    }

    $metaCapiToken = trim((string) ($config['META_CAPI_ACCESS_TOKEN'] ?? ''));
    $metaCapiPixelId = trim((string) ($config['META_CAPI_PIXEL_ID'] ?? ''));
    if ($metaCapiToken !== '' || $metaCapiPixelId !== '') {
        $required[] = 'META_CAPI_ACCESS_TOKEN';
        if ($metaCapiPixelId !== '') {
            $required[] = 'META_CAPI_PIXEL_ID';
        } else {
            $required[] = 'META_PIXEL_ID';
        }
    }

    $codGuardWhatsappKeys = [
        'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID',
        'COD_GUARD_WHATSAPP_ACCESS_TOKEN',
        'COD_GUARD_WHATSAPP_APP_SECRET',
        'COD_GUARD_WEBHOOK_TOKEN',
        'COD_GUARD_WEBHOOK_VERIFY_TOKEN',
    ];
    $codGuardConfigured = false;
    foreach ($codGuardWhatsappKeys as $key) {
        if (trim((string) ($config[$key] ?? '')) !== '') {
            $codGuardConfigured = true;
            break;
        }
    }
    if ($codGuardConfigured) {
        $required[] = 'COD_GUARD_WHATSAPP_PHONE_NUMBER_ID';
        $required[] = 'COD_GUARD_WHATSAPP_ACCESS_TOKEN';
        $required[] = 'COD_GUARD_WEBHOOK_VERIFY_TOKEN';
        if (trim((string) ($config['COD_GUARD_WHATSAPP_APP_SECRET'] ?? '')) === '') {
            $required[] = 'COD_GUARD_WEBHOOK_TOKEN';
        }
    }

    $unsafe = [];
    $required = array_values(array_unique($required));
    foreach ($required as $key) {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value === '' || app_config_is_placeholder($value)) {
            $unsafe[] = $key;
        }
    }

    $placeholderWarnings = [];
    foreach ($config as $key => $value) {
        if (is_scalar($value) && app_config_is_placeholder((string) $value) && !in_array((string) $key, $required, true)) {
            $placeholderWarnings[] = (string) $key;
        }
    }
    if (!empty($placeholderWarnings)) {
        error_log('[amber] WARNING: optional production placeholder keys are present but not active: ' . implode(', ', array_values(array_unique($placeholderWarnings))));
    }

    $unsafe = array_values(array_unique($unsafe));
    if (!empty($unsafe)) {
        app_bootstrap_fail(
            'Server configuration error. Production configuration is incomplete.',
            '[fabric-export] FATAL: unsafe production configuration keys: ' . implode(', ', $unsafe),
            2
        );
    }
}

$configFile = __DIR__ . '/app-config.php';
$allConfig = is_file($configFile) ? require $configFile : [];
if (!is_array($allConfig)) {
    app_bootstrap_fail('Server configuration error. Invalid app config.');
}

$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost =
    $httpHost === 'localhost' ||
    $httpHost === '127.0.0.1' ||
    strpos($httpHost, 'localhost:') === 0 ||
    strpos($httpHost, '127.0.0.1:') === 0;
$isCliServer = PHP_SAPI === 'cli-server';
$isCli = PHP_SAPI === 'cli';

// CLI should default to local mode so setup/migration scripts don't target production by accident.
$mode = ($isLocalHost || $isCliServer || $isCli) ? 'local' : 'production';
$modeOverride = strtolower(trim((string) (getenv('APP_MODE') ?: ($_SERVER['APP_MODE'] ?? ''))));
if ($modeOverride === 'prod') {
    $modeOverride = 'production';
}
if ($modeOverride !== '') {
    if (!in_array($modeOverride, ['local', 'production'], true)) {
        app_bootstrap_fail('Server configuration error. Invalid APP_MODE.');
    }
    $mode = $modeOverride;
}
$activeConfig = $allConfig[$mode] ?? [];
if (!is_array($activeConfig)) {
    app_bootstrap_fail('Server configuration error. Missing mode config.');
}

$secureConfigFiles = array_filter([
    app_config_env('APP_CONFIG_FILE'),
    dirname(__DIR__) . '/secure-config.php',
    dirname(__DIR__, 2) . '/secure-config.php',
    __DIR__ . '/secure-config.php',
]);
foreach ($secureConfigFiles as $secureConfigFile) {
    $activeConfig = array_replace($activeConfig, app_config_load_file((string) $secureConfigFile, $mode));
}
$activeConfig = app_config_apply_env_overrides($activeConfig);
$activeConfig['APP_ENV'] = (string) ($activeConfig['APP_ENV'] ?? $mode);

if ($mode === 'production') {
    // Warn if any secret is loaded from the config file rather than an environment variable.
    // Move secrets to server env vars (SetEnv / IIS app settings) before going live.
    foreach (['DB_PASSWORD', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET', 'SMTP_PASSWORD'] as $_warnKey) {
        $_warnVal = trim((string) ($activeConfig[$_warnKey] ?? ''));
        if ($_warnVal !== '' && app_config_env($_warnKey) === null && !app_config_is_placeholder($_warnVal)) {
            error_log('[amber] WARNING: production secret "' . $_warnKey . '" is loaded from the config file, not an environment variable. Move secrets to server environment variables.');
        }
    }
    unset($_warnKey, $_warnVal);
}

if ($mode === 'production') {
    app_config_validate_production($activeConfig);
}

// Keep active config globally accessible for downstream helpers.
$GLOBALS['_app_config'] = $activeConfig;
$GLOBALS['_app_mode'] = $mode;

$dbHost = trim((string) ($activeConfig['DB_HOST'] ?? ''));
$dbPort = trim((string) ($activeConfig['DB_PORT'] ?? '3306'));
$dbUser = trim((string) ($activeConfig['DB_USER'] ?? ''));
$dbPass = (string) ($activeConfig['DB_PASSWORD'] ?? '');
$dbName = trim((string) ($activeConfig['DB_NAME'] ?? ''));

if ($dbHost === '' || $dbUser === '' || $dbName === '') {
    app_bootstrap_fail(
        'Server configuration error. Missing database settings.',
        '[fabric-export] FATAL: database configuration is incomplete in config/app-config.php',
        3
    );
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) $dbPort);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    app_bootstrap_fail(
        'Server configuration error. Database connection failed.',
        '[fabric-export] DB connection failed: ' . $e->getMessage(),
        4
    );
}
