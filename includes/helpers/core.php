<?php
function app_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    $appEnv = strtolower(trim((string) ($GLOBALS['_app_config']['APP_ENV'] ?? '')));
    $appUrl = strtolower(trim((string) ($GLOBALS['_app_config']['APP_URL'] ?? '')));
    $forceHttps = strtolower(trim((string) ($GLOBALS['_app_config']['APP_FORCE_HTTPS'] ?? '')));

    return $forceHttps === '1' ||
        $forceHttps === 'true' ||
        ($appEnv === 'production' && strpos($appUrl, 'https://') === 0);
}

// Harden session cookie settings before starting the session.
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $secure = app_request_is_https();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    } elseif (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }
}

/**
 * CSRF token helpers.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    $token = e(csrf_token());
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verify_csrf(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) &&
        hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Marketing consent helpers (Pixel/CAPI/UTM).
 */
function marketing_consent_cookie_name(): string
{
    return 'amber_marketing_consent';
}

function marketing_consent_status(): string
{
    $sessionStatus = strtolower(trim((string) ($_SESSION['marketing_consent'] ?? '')));
    if (in_array($sessionStatus, ['granted', 'denied'], true)) {
        return $sessionStatus;
    }

    $cookieStatus = strtolower(trim((string) ($_COOKIE[marketing_consent_cookie_name()] ?? '')));
    if (in_array($cookieStatus, ['granted', 'denied'], true)) {
        $_SESSION['marketing_consent'] = $cookieStatus;
        return $cookieStatus;
    }

    return 'unknown';
}

function marketing_consent_granted(): bool
{
    return marketing_consent_status() === 'granted';
}

function marketing_consent_denied(): bool
{
    return marketing_consent_status() === 'denied';
}

function marketing_consent_clear_cookie(string $cookieName): void
{
    if (headers_sent() || $cookieName === '') {
        return;
    }

    $secure = app_request_is_https();
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$cookieName]);
}

function marketing_consent_clear_tracking_data(): void
{
    unset($_SESSION['utm_attribution'], $_SESSION['meta_fbp'], $_SESSION['meta_fbc']);
    marketing_consent_clear_cookie('amber_utm');
    marketing_consent_clear_cookie('_fbp');
    marketing_consent_clear_cookie('_fbc');
}

function marketing_consent_set(string $status, int $days = 180): bool
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['granted', 'denied'], true)) {
        return false;
    }

    $_SESSION['marketing_consent'] = $status;
    if (headers_sent()) {
        return false;
    }

    $secure = app_request_is_https();
    setcookie(marketing_consent_cookie_name(), $status, [
        'expires' => time() + (max(1, $days) * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($status === 'denied') {
        marketing_consent_clear_tracking_data();
    }

    return true;
}



/**
 * Basic redirect helper.
 */
function redirect(string $path): void
{
    header("Location: {$path}");
    exit;
}

/**
 * Consistent JSON API response helper.
 */
function api_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Flash messaging stored in session.
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }

    return null;
}

/**
 * Escape output for HTML contexts.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function site_name(): string
{
    return SiteContext::name();
}

function contact_email(): string
{
    return SiteContext::contactEmail();
}

function app_url(string $path = ''): string
{
    return SiteContext::url($path);
}

function money($amount, string $currency = 'INR', bool $withCode = false): string
{
    $code = strtoupper(trim($currency));
    if ($code === '') {
        $code = 'INR';
    }
    $symbol = $code === 'USD' ? '$' : 'Rs ';
    $formatted = $symbol . number_format((float) $amount, 2);
    return $withCode ? ($formatted . ' ' . $code) : $formatted;
}

/**
 * Enforce a baseline password policy for customer credentials.
 */
function checkout_validation_constraints(): array
{
    return [
        'phone_pattern' => '^[0-9+\\-\\s()]{7,20}$',
        'pincode_pattern' => '^[1-9][0-9]{5}$',
        'address_max_length' => 500,
        'notes_max_length' => 500,
        'password_min_length' => 10,
        'password_uppercase_pattern' => '[A-Z]',
        'password_lowercase_pattern' => '[a-z]',
        'password_number_pattern' => '\\d',
    ];
}

function password_strength_error(string $password): ?string
{
    $rules = checkout_validation_constraints();
    if (strlen($password) < $rules['password_min_length']) {
        return 'Password must be at least ' . $rules['password_min_length'] . ' characters.';
    }
    if (!preg_match('/' . $rules['password_uppercase_pattern'] . '/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/' . $rules['password_lowercase_pattern'] . '/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/' . $rules['password_number_pattern'] . '/', $password)) {
        return 'Password must include at least one number.';
    }
    return null;
}

/**
 * Normalize meter quantities to 2 decimals with a minimum of 1 meter.
 */
function normalize_meter_quantity($value, float $min = 1.0): float
{
    $qty = is_numeric($value) ? (float) $value : $min;
    if ($qty < $min) {
        $qty = $min;
    }
    return round($qty, 2);
}

/**
 * Display meter quantities without unnecessary trailing zeros.
 */
function format_meter_quantity($value): string
{
    $qty = normalize_meter_quantity($value);
    $formatted = number_format($qty, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Normalize piece quantities to whole numbers with a minimum quantity.
 */
function normalize_piece_quantity($value, int $min = 1): int
{
    $qty = is_numeric($value) ? (int) round((float) $value) : $min;
    return max($min, $qty);
}

/**
 * Normalize quantities based on unit type.
 *
 * For piece/set units, $minQty is treated as whole-number minimum quantity.
 * For meter units, $minQty is treated as minimum meter quantity.
 */
function normalize_quantity_by_unit($value, string $unitType, float $minQty = 1.0)
{
    if ($unitType === 'piece' || $unitType === 'set') {
        return normalize_piece_quantity($value, max(1, (int) round($minQty)));
    }
    return normalize_meter_quantity($value, $minQty);
}

/**
 * Validate that a meter quantity respects a configured step.
 * Example: min=1.00, step=0.25 allows 1.00, 1.25, 1.50, ...
 */
function meter_qty_respects_step(float $qty, float $minQty, float $step): bool
{
    $step = round($step, 4);
    if ($step <= 0) {
        return true;
    }
    if ($qty < $minQty) {
        return false;
    }
    $delta = $qty - $minQty;
    $ratio = $delta / $step;
    return abs($ratio - round($ratio)) < 0.0001;
}

/**
 * Format quantities based on unit type.
 */
function format_quantity_by_unit($value, string $unitType): string
{
    return ($unitType === 'piece' || $unitType === 'set')
        ? (string) normalize_piece_quantity($value, 1)
        : format_meter_quantity($value);
}

function normalize_units_per_set($value): int
{
    $n = is_numeric($value) ? (int) round((float) $value) : 1;
    return max(1, $n);
}

function format_pack_label(int $unitsPerSet): string
{
    return 'Pack of ' . max(1, (int) $unitsPerSet);
}
