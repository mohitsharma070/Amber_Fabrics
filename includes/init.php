<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/customer-auth.php';
require_once __DIR__ . '/plugin-loader.php';
plugin_load_all();

$appEnv = strtolower(_cfg('APP_ENV', 'local'));
$isProduction = in_array($appEnv, ['production', 'prod'], true);

// Show errors locally, hide them on production-like runtimes.
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
error_reporting($isProduction ? E_ALL & ~E_DEPRECATED & ~E_STRICT : E_ALL);

$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['cspNonce'] = $cspNonce;

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $cspDirectives = apply_filters('security.csp_directives', [
        'default-src' => ["'self'"],
        'connect-src' => ["'self'", 'https://cdn.jsdelivr.net', 'https://*.razorpay.com'],
        'img-src' => ["'self'", 'data:', 'https:'],
        'style-src' => ["'self'", 'https://cdn.jsdelivr.net', "'unsafe-inline'"],
        'script-src' => ["'self'", 'https://cdn.jsdelivr.net', 'https://*.razorpay.com', "'nonce-{$cspNonce}'"],
        'font-src' => ["'self'", 'https://cdn.jsdelivr.net', 'https://*.razorpay.com'],
        'frame-src' => ['https://*.razorpay.com'],
        'object-src' => ["'none'"],
        'frame-ancestors' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
    ], ['nonce' => $cspNonce]);
    if ($isProduction && app_request_is_https()) {
        $cspDirectives['upgrade-insecure-requests'] = [];
    }
    $cspParts = [];
    foreach ($cspDirectives as $directive => $values) {
        $values = is_array($values) ? array_values(array_unique(array_filter(array_map('strval', $values)))) : [(string) $values];
        $cspParts[] = trim($directive . ' ' . implode(' ', $values));
    }
    header('Content-Security-Policy: ' . implode('; ', $cspParts));
}

do_action('app.init', [
    'app_env' => $appEnv,
    'is_production' => $isProduction,
]);

if (isset($conn) && $conn instanceof mysqli) {
    session_ensure_cart_wishlist_arrays();
    wishlist_bootstrap_session($conn);
}

// Register a shutdown handler that emails the admin on fatal PHP errors in production.
if ($isProduction) {
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!is_array($error)) {
            return;
        }
        $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR];
        if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
            return;
        }
        $message = sprintf(
            '[amber] Fatal error: %s in %s on line %d',
            $error['message'] ?? 'Unknown',
            $error['file'] ?? 'unknown',
            (int) ($error['line'] ?? 0)
        );
        error_log($message);
        $adminEmail = function_exists('_cfg') ? _cfg('ADMIN_NOTIFICATION_EMAIL') : '';
        if ($adminEmail !== '' && function_exists('send_email')) {
            try {
                send_email(
                    $adminEmail,
                    'Fatal Error — Amber Fabrics',
                    $message . "\n\nURL: " . ($_SERVER['REQUEST_URI'] ?? 'cli')
                        . "\nServer: " . ($_SERVER['SERVER_NAME'] ?? gethostname())
                );
            } catch (Throwable $e) {
                error_log('[amber] Could not send fatal error notification: ' . $e->getMessage());
            }
        }
    });
}

/**
 * Setup helper is now in database/setup.php to avoid running DDL on every request.
 * Call ensure_tables($conn) from there when deploying to a fresh database.
 */
