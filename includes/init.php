<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/customer-auth.php';

$appEnv = strtolower((string) (getenv('APP_ENV') ?: 'local'));
$isProduction = in_array($appEnv, ['production', 'prod'], true);

// Show errors locally, hide them on production-like runtimes.
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
error_reporting($isProduction ? E_ALL & ~E_DEPRECATED & ~E_STRICT : E_ALL);

$cspNonce = base64_encode(random_bytes(16));

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://cdn.jsdelivr.net https://*.razorpay.com; img-src 'self' data: https:; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' https://cdn.jsdelivr.net https://*.razorpay.com 'nonce-{$cspNonce}'; font-src 'self' https://cdn.jsdelivr.net https://*.razorpay.com; frame-src https://*.razorpay.com; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

/**
 * Setup helper is now in database/setup.php to avoid running DDL on every request.
 * Call ensure_tables($conn) from there when deploying to a fresh database.
 */
