<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
$appRequestId = app_request_id();
require_once __DIR__ . '/security/customer-auth.php';
require_once __DIR__ . '/plugin-loader.php';
plugin_load_all();

$appEnv = strtolower(_cfg('APP_ENV', 'local'));
$appMode = strtolower((string) ($GLOBALS['_app_mode'] ?? 'local'));
$isProduction = $appMode === 'production';

$debugRaw = strtolower(trim((string) _cfg('APP_DEBUG', $isProduction ? '0' : '1')));
$debugEnabled = in_array($debugRaw, ['1', 'true', 'yes', 'on'], true);
if ($isProduction) {
    // Production never exposes runtime errors to clients.
    $debugEnabled = false;
}

ini_set('display_errors', $debugEnabled ? '1' : '0');
ini_set('display_startup_errors', $debugEnabled ? '1' : '0');
ini_set('log_errors', '1');
error_reporting($debugEnabled ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));

$cspNonce = base64_encode(random_bytes(16));
$GLOBALS['cspNonce'] = $cspNonce;

if (!headers_sent()) {
    header('X-Request-ID: ' . $appRequestId);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (app_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // cdn.jsdelivr.net used to sit in script-src for every request, so any HTML
    // that reached a page unescaped could pull an arbitrary bundle from it. Only
    // three pages actually load anything from that origin, so it is allowed only
    // there. Compared by realpath against SCRIPT_FILENAME rather than by
    // SCRIPT_NAME, because SCRIPT_NAME carries the deployment's URL prefix and
    // would stop matching under a subdirectory install.
    $cdnScriptPages = [
        __DIR__ . '/../invoice.php',        // html2pdf.js - customer invoice download
        __DIR__ . '/../admin/invoice.php',  // html2pdf.js - admin invoice download
        __DIR__ . '/../admin/dashboard.php', // chart.js - admin dashboard charts
    ];
    $currentScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $cdnScriptAllowed = false;
    if ($currentScript !== false) {
        foreach ($cdnScriptPages as $cdnScriptPage) {
            $resolved = realpath($cdnScriptPage);
            if ($resolved !== false && $resolved === $currentScript) {
                $cdnScriptAllowed = true;
                break;
            }
        }
    }

    $scriptSrc = ["'self'", 'https://*.razorpay.com', "'nonce-{$cspNonce}'"];
    if ($cdnScriptAllowed) {
        $scriptSrc[] = 'https://cdn.jsdelivr.net';
    }

    // connect-src, style-src and font-src previously allowed jsdelivr too, with
    // no consumer for any of them: nothing fetches from that origin, the
    // Bootstrap CDN stylesheet is gone, and the app ships no webfonts at all.
    $cspDirectives = apply_filters('security.csp_directives', [
        'default-src' => ["'self'"],
        'connect-src' => ["'self'", 'https://*.razorpay.com'],
        'img-src' => ["'self'", 'data:', 'https:'],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'script-src' => $scriptSrc,
        'font-src' => ["'self'", 'https://*.razorpay.com'],
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

if (isset($conn) && $conn instanceof mysqli) {
    $bootCustomerId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($bootCustomerId > 0 && !customer_session_valid($conn, $bootCustomerId)) {
        customer_clear_auth_session(true);
    }
}

do_action('app.init', [
    'app_env' => $appEnv,
    'app_mode' => $appMode,
    'is_production' => $isProduction,
    'debug_enabled' => $debugEnabled,
]);

if (isset($conn) && $conn instanceof mysqli) {
    CartService::session_ensure_cart_wishlist_arrays();
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
        app_log('critical', 'php_fatal', [
            'error_type' => (int) ($error['type'] ?? 0),
            'file' => (string) ($error['file'] ?? 'unknown'),
            'line' => (int) ($error['line'] ?? 0),
        ]);
        $adminEmail = function_exists('_cfg') ? _cfg('ADMIN_NOTIFICATION_EMAIL') : '';
        if ($adminEmail !== '' && function_exists('send_email')) {
            try {
                send_email(
                    $adminEmail,
                    'Fatal Error - ' . SiteContext::name(),
                    $message . "\n\nRequest ID: " . app_request_id()
                        . "\nURL: " . ($_SERVER['REQUEST_URI'] ?? 'cli')
                        . "\nServer: " . ($_SERVER['SERVER_NAME'] ?? gethostname())
                );
            } catch (Throwable $e) {
                app_log('error', 'fatal_notification_failed', [
                    'exception_type' => get_class($e),
                ]);
            }
        }
    });
}

/**
 * Setup helper is now in database/setup.php to avoid running DDL on every request.
 * Call ensure_tables($conn) from there when deploying to a fresh database.
 */
