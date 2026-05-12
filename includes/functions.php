<?php

// Harden session cookie settings before starting the session.
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
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
 * Basic redirect helper.
 */
function redirect(string $path): void
{
    header("Location: {$path}");
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

/**
 * Enforce a baseline password policy for customer/admin credentials.
 */
function password_strength_error(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
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
 */
function normalize_quantity_by_unit($value, string $unitType, float $meterMin = 1.0)
{
    return ($unitType === 'piece' || $unitType === 'set')
        ? normalize_piece_quantity($value, 1)
        : normalize_meter_quantity($value, $meterMin);
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

/**
 * Unit suffix for display.
 */
function quantity_unit_suffix(string $unitType): string
{
    if ($unitType === 'piece') return ' pc';
    if ($unitType === 'set') return ' set';
    return 'm';
}

/**
 * Allow only absolute http/https URLs for outbound links.
 */
function safe_external_url(?string $value): string
{
    $url = trim((string) $value);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return $url;
}

/**
 * Generate a safe filename for uploads.
 */
function random_filename(string $originalName): string
{
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    return uniqid('fabric_', true) . ($ext ? ".{$ext}" : '');
}

/**
 * Require admin session.
 */
function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    if (!empty($_SESSION['must_reset_password']) && basename($_SERVER['PHP_SELF']) !== 'password-reset.php') {
        flash('error', 'Please set a new password before continuing.');
        redirect('password-reset.php');
    }
}

/**
 * Convert mysqli result row to array safely.
 */
function fetch_all_assoc(mysqli_result $result): array
{
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Shared list/filter helpers.
 */
function list_sanitize_sort(string $sort, array $sortMap, string $default = 'newest'): string
{
    return isset($sortMap[$sort]) ? $sort : $default;
}

function list_sanitize_per_page(int $perPage, array $options): int
{
    return in_array($perPage, $options, true) ? $perPage : (int) $options[0];
}

function list_sanitize_page(int $page): int
{
    return max(1, $page);
}

function list_clamp_page(int $page, int $pages): int
{
    return min(max(1, $page), max(1, $pages));
}

function list_build_query(array $params, bool $dropEmpty = true): string
{
    if ($dropEmpty) {
        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
    }
    return http_build_query($params);
}

/**
 * Lightweight session-based rate limit for public form endpoints.
 */
function public_form_rate_limit_allow(string $scope, int $maxAttempts = 5, int $windowSeconds = 600): bool
{
    if (!isset($_SESSION['form_rate_limit']) || !is_array($_SESSION['form_rate_limit'])) {
        $_SESSION['form_rate_limit'] = [];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = hash('sha256', $scope . '|' . $ip);
    $now = time();
    $windowStart = $now - $windowSeconds;
    $hits = $_SESSION['form_rate_limit'][$key] ?? [];
    if (!is_array($hits)) {
        $hits = [];
    }

    $hits = array_values(array_filter($hits, static fn($ts) => is_int($ts) && $ts >= $windowStart));
    if (count($hits) >= $maxAttempts) {
        $_SESSION['form_rate_limit'][$key] = $hits;
        return false;
    }

    $hits[] = $now;
    $_SESSION['form_rate_limit'][$key] = $hits;
    return true;
}

/**
 * Get recipient for operational notifications.
 */
function admin_notification_email(): string
{
    $email = _cfg('ADMIN_NOTIFICATION_EMAIL', _cfg('ADMIN_EMAIL', ''));
    if ($email === '') {
        error_log('[amberfabrics] WARNING: ADMIN_NOTIFICATION_EMAIL is not set. Admin notifications will not be sent.');
        return '';
    }
    return $email;
}

/**
 * Build normalized SKU from category, material, color, GSM.
 */
function build_fabric_sku_base(string $category, string $material, string $color, string $gsm): string
{
    $parts = [$category, $material, $color, $gsm];
    $clean = [];
    foreach ($parts as $part) {
        $p = strtoupper(trim($part));
        $p = preg_replace('/[^A-Z0-9]+/', '-', $p ?? '');
        $p = trim((string) $p, '-');
        if ($p !== '') {
            $clean[] = $p;
        }
    }
    if (empty($clean)) {
        return 'SKU';
    }
    return implode('-', $clean);
}

/**
 * Generate a unique fabrics.sku value by appending -2, -3... when needed.
 */
function generate_unique_fabric_sku(mysqli $conn, string $category, string $material, string $color, string $gsm, int $excludeId = 0): string
{
    $base = build_fabric_sku_base($category, $material, $color, $gsm);
    $candidate = $base;
    $n = 1;

    while (true) {
        if ($excludeId > 0) {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? AND id <> ? LIMIT 1");
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare("SELECT id FROM fabrics WHERE sku = ? LIMIT 1");
            $stmt->bind_param('s', $candidate);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return $candidate;
        }
        $n++;
        $candidate = $base . '-' . $n;
    }
}

/**
 * Send a basic email notification for new inquiry submissions.
 */
function send_inquiry_notification(array $inquiry): bool
{
    $to = admin_notification_email();
    if ($to === '') {
        return false;
    }

    $subject = 'New Inquiry Received #' . (int) ($inquiry['id'] ?? 0);
    $lines = [
        'A new inquiry was submitted.',
        '',
        'ID: ' . ((int) ($inquiry['id'] ?? 0)),
        'Name: ' . ((string) ($inquiry['name'] ?? '')),
        'Email: ' . ((string) ($inquiry['email'] ?? '')),
        'Country: ' . ((string) ($inquiry['country'] ?? '')),
        'Fabric: ' . ((string) ($inquiry['fabric_type'] ?? '')),
        'Quantity: ' . ((string) ($inquiry['quantity'] ?? '')),
        'Meters: ' . ((string) ($inquiry['meters'] ?? '')),
        'Incoterm: ' . ((string) ($inquiry['incoterm'] ?? '')),
        'Destination: ' . ((string) ($inquiry['destination'] ?? '')),
        'Pin Code: ' . ((string) ($inquiry['pincode'] ?? '')),
        'Timeline: ' . ((string) ($inquiry['timeline'] ?? '')),
        '',
        'Message:',
        ((string) ($inquiry['message'] ?? '')),
    ];

    $replyTo = filter_var($inquiry['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ? (string) $inquiry['email']
        : '';

    try {
        $mail = _mailer_base();
        $mail->addAddress($to);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->Subject = $subject;
        $mail->Body    = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] inquiry notification email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Persist inquiry activity entries for audit and follow-up context.
 */
function log_inquiry_activity(
    mysqli $conn,
    int $inquiryId,
    string $action,
    ?int $adminId = null,
    string $actorName = 'system',
    string $details = ''
): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO inquiry_activity_logs (inquiry_id, admin_id, actor_name, action, details)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisss', $inquiryId, $adminId, $actorName, $action, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[fabric-export] inquiry activity log failed: ' . $e->getMessage());
    }
}

function site_settings_defaults(): array
{
    return [
        'site_name' => 'Amber Fabrics',
        'site_description' => 'Premium woven and blended fabrics for global brands, importers, and distributors.',
        'contact_email' => 'amberfabricstextiles@gmail.com',
        'branding_logo' => 'images/logo-brand-light.svg',
        'hero_swatch_1' => '',
        'hero_swatch_2' => '',
        'hero_swatch_3' => '',
        'hero_swatch_4' => '',
        'hero_swatch_5' => '',
        'hero_swatch_6' => '',
        'announcement_1_text' => 'Free Shipping on orders above ₹999',
        'announcement_1_enabled' => '1',
        'announcement_2_text' => 'New arrivals added every week',
        'announcement_2_enabled' => '1',
        'announcement_3_text' => '',
        'announcement_3_enabled' => '0',
        'announcement_4_text' => '',
        'announcement_4_enabled' => '0',
        'announcement_5_text' => '',
        'announcement_5_enabled' => '0',
    ];
}

function ensure_site_settings_table(mysqli $conn): bool
{
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'site_settings'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $available = ((int) ($row['total'] ?? 0)) > 0;
        if (!$available) {
            error_log('[amberfabrics] site_settings table missing. Run: php database/setup.php');
        }
    } catch (Throwable $e) {
        $available = false;
        error_log('[amberfabrics] site_settings table check failed: ' . $e->getMessage());
    }

    return $available;
}

function load_site_settings_from_db(mysqli $conn): array
{
    if (!ensure_site_settings_table($conn)) {
        return [];
    }
    $rows = $conn->query("SELECT setting_key, setting_value FROM site_settings");
    $settings = [];
    while ($row = $rows->fetch_assoc()) {
        $key = (string) ($row['setting_key'] ?? '');
        if ($key !== '') {
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }
    }
    return $settings;
}

function save_site_settings_to_db(mysqli $conn, array $settings): void
{
    if (!ensure_site_settings_table($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        "INSERT INTO site_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    foreach ($settings as $key => $value) {
        $settingKey = (string) $key;
        $settingValue = is_scalar($value) ? (string) $value : '';
        $stmt->bind_param('ss', $settingKey, $settingValue);
        $stmt->execute();
    }
}

/**
 * Load site settings with DB-first strategy and JSON fallback.
 */
function get_site_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = site_settings_defaults();

    $settingsFile = __DIR__ . '/../config/site-settings.json';
    if (file_exists($settingsFile)) {
        $json = @file_get_contents($settingsFile);
        if ($json !== false) {
            $loaded = @json_decode($json, true);
            if (is_array($loaded)) {
                $settings = array_merge($settings, $loaded);
            }
        }
    }

    try {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            $dbSettings = load_site_settings_from_db($GLOBALS['conn']);
            if (!empty($dbSettings)) {
                $settings = array_merge($settings, $dbSettings);
            }
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] load site settings from db failed: ' . $e->getMessage());
    }

    return $settings;
}

/**
 * Ensure announcement dismissal table exists.
 */
function ensure_announcement_dismissals_table(mysqli $conn): bool
{
    static $checked = false;
    static $available = false;
    if ($checked) {
        return $available;
    }
    $checked = true;

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'announcement_dismissals'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $available = ((int) ($row['total'] ?? 0)) > 0;
        if (!$available) {
            error_log('[amberfabrics] announcement_dismissals table missing. Run: php database/setup.php');
        }
    } catch (Throwable $e) {
        $available = false;
        error_log('[amberfabrics] announcement_dismissals table check failed: ' . $e->getMessage());
    }

    return $available;
}

/**
 * Build a stable server-side key for current visitor session.
 */
function announcement_session_key(): string
{
    $sid = session_id();
    if ($sid === '') {
        $sid = 'no-session';
    }
    return hash('sha256', $sid . '|announcement');
}

/**
 * Check if current announcement set has been dismissed by this visitor.
 */
function announcement_is_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $stmt = $conn->prepare(
        "SELECT id
         FROM announcement_dismissals
         WHERE session_key = ? AND announcement_key = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $sessionKey, $announcementKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !empty($row);
}

/**
 * Persist dismissal for the current visitor + announcement key.
 */
function announcement_mark_dismissed(mysqli $conn, string $announcementKey): bool
{
    if ($announcementKey === '') {
        return false;
    }

    if (!ensure_announcement_dismissals_table($conn)) {
        return false;
    }
    $sessionKey = announcement_session_key();
    $customerId = !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;

    $stmt = $conn->prepare(
        "INSERT INTO announcement_dismissals (session_key, customer_id, announcement_key)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            customer_id = VALUES(customer_id),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('sis', $sessionKey, $customerId, $announcementKey);
    return $stmt->execute();
}

// Cart Persistence Helpers

/**
 * Get (or create) a DB cart row for a logged-in customer.
 */
function cart_get_or_create_db_cart(mysqli $conn, int $customerId): int
{
    $stmt = $conn->prepare("SELECT id FROM cart WHERE customer_id = ? LIMIT 1");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return (int) $row['id'];
    }
    $ins = $conn->prepare("INSERT INTO cart (customer_id) VALUES (?)");
    $ins->bind_param('i', $customerId);
    $ins->execute();
    return (int) $conn->insert_id;
}

/**
 * Save the current session cart to the database for the logged-in customer.
 * Replaces any previously saved cart items.
 */
function cart_items_supports_meter_length(mysqli $conn): bool
{
    static $checked = false;
    static $supported = false;
    if ($checked) {
        return $supported;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cart_items'
               AND COLUMN_NAME = 'meter_length'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $supported = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $supported = false;
    }
    return $supported;
}

function cart_save_to_db(mysqli $conn, int $customerId, array $cart, ?array $meterMap = null): void
{
    try {
        if ($meterMap === null) {
            $meterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']))
                ? $_SESSION['cart_meter_length']
                : [];
        }
        $cartId = cart_get_or_create_db_cart($conn, $customerId);
        $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $del->bind_param('i', $cartId);
        $del->execute();
        if (empty($cart)) {
            return;
        }
        $supportsMeterLength = cart_items_supports_meter_length($conn);
        $ins = $supportsMeterLength
            ? $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters, meter_length)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )
            : $conn->prepare(
                "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters)
                 VALUES (?, ?, ?, ?, ?)"
            );
        foreach ($cart as $productId => $qty) {
            $pid = (int) $productId;
            $q   = normalize_meter_quantity($qty);
            $meterLength = (isset($meterMap[$pid]) && is_numeric($meterMap[$pid]) && (float) $meterMap[$pid] > 0)
                ? round((float) $meterMap[$pid], 2)
                : null;
            if ($supportsMeterLength) {
                $ins->bind_param('iididd', $cartId, $pid, $q, $pid, $q, $meterLength);
            } else {
                $ins->bind_param('iidid', $cartId, $pid, $q, $pid, $q);
            }
            $ins->execute();
        }
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_save_to_db failed: ' . $e->getMessage());
    }
}

/**
 * Load the saved cart from DB for a logged-in customer.
 * Returns an associative array [product_id => quantity].
 */
function cart_load_from_db(mysqli $conn, int $customerId): array
{
    $bundle = cart_load_from_db_bundle($conn, $customerId);
    return $bundle['cart'];
}

/**
 * Load the saved cart and meter metadata from DB for a logged-in customer.
 * Returns ['cart' => [product_id => quantity], 'meter_map' => [product_id => meter_length]].
 */
function cart_load_from_db_bundle(mysqli $conn, int $customerId): array
{
    try {
        $supportsMeterLength = cart_items_supports_meter_length($conn);
        $stmt = $supportsMeterLength
            ? $conn->prepare(
                "SELECT ci.product_id, ci.quantity, ci.meter_length
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            )
            : $conn->prepare(
                "SELECT ci.product_id, ci.quantity
                 FROM cart c
                 JOIN cart_items ci ON ci.cart_id = c.id
                 WHERE c.customer_id = ?"
            );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cart = [];
        $meterMap = [];
        foreach ($rows as $row) {
            if ((int) $row['product_id'] > 0) {
                $pid = (int) $row['product_id'];
                $cart[$pid] = normalize_meter_quantity($row['quantity'] ?? 1);
                if ($supportsMeterLength && isset($row['meter_length']) && is_numeric($row['meter_length']) && (float) $row['meter_length'] > 0) {
                    $meterMap[$pid] = round((float) $row['meter_length'], 2);
                }
            }
        }
        return ['cart' => $cart, 'meter_map' => $meterMap];
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_load_from_db failed: ' . $e->getMessage());
        return ['cart' => [], 'meter_map' => []];
    }
}

/**
 * Clear the customer's saved DB cart (called after order is placed).
 */
function cart_clear_db(mysqli $conn, int $customerId): void
{
    try {
        $stmt = $conn->prepare("SELECT id FROM cart WHERE customer_id = ? LIMIT 1");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return;
        }
        $cartId = (int) $row['id'];
        $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $del->bind_param('i', $cartId);
        $del->execute();
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_clear_db failed: ' . $e->getMessage());
    }
}

/**
 * For cancelled + paid Razorpay orders, create a real gateway refund first.
 * Mark local order/payment as refunded only when gateway reports processed.
 * Returns ['ok' => bool, 'message' => string].
 */
function admin_mark_order_refunded(mysqli $conn, int $orderId): array
{
    try {
        $conn->begin_transaction();

        $orderStmt = $conn->prepare(
            "SELECT id, order_number, payment_method, payment_status, order_status
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $method = strtolower((string) ($order['payment_method'] ?? ''));
        $payStatus = strtolower((string) ($order['payment_status'] ?? ''));
        $ordStatus = strtolower((string) ($order['order_status'] ?? ''));

        if ($ordStatus !== 'cancelled' || $payStatus !== 'paid') {
            throw new RuntimeException('Order is not eligible for refund update.');
        }

        $payStmt = $conn->prepare(
            "SELECT id, amount, razorpay_payment_id, transaction_id
             FROM payments
             WHERE order_id = ? AND payment_method = ?
             LIMIT 1
             FOR UPDATE"
        );
        $payStmt->bind_param('is', $orderId, $method);
        $payStmt->execute();
        $payment = $payStmt->get_result()->fetch_assoc();

        if ($method === 'razorpay') {
            if (!$payment) {
                throw new RuntimeException('Payment record not found for Razorpay order.');
            }

            $paymentId = trim((string) ($payment['razorpay_payment_id'] ?? ''));
            if ($paymentId === '') {
                $paymentId = trim((string) ($payment['transaction_id'] ?? ''));
            }
            if ($paymentId === '') {
                throw new RuntimeException('Missing Razorpay payment id.');
            }

            require_once __DIR__ . '/../vendor/autoload.php';
            $keyId = _cfg('RAZORPAY_KEY_ID', '');
            $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
            if ($keyId === '' || $keySecret === '') {
                throw new RuntimeException('Razorpay configuration missing.');
            }

            $amountPaise = 0;
            if (isset($payment['amount']) && is_numeric($payment['amount'])) {
                $amountPaise = max(0, (int) round(((float) $payment['amount']) * 100));
            }

            $refundStatus = '';
            $existingRefundId = '';
            $existingNotes = (string) ($order['notes'] ?? '');
            if (preg_match('/refund_id:\s*(rfnd_[A-Za-z0-9]+)/i', $existingNotes, $m)) {
                $existingRefundId = trim((string) ($m[1] ?? ''));
            }
            try {
                $api = new Razorpay\Api\Api($keyId, $keySecret);
                $refundId = '';
                if ($existingRefundId !== '') {
                    $refund = $api->refund->fetch($existingRefundId);
                    $refundId = $existingRefundId;
                } else {
                    $payload = ['speed' => 'normal'];
                    if ($amountPaise > 0) {
                        $payload['amount'] = $amountPaise;
                    }
                    $refund = $api->payment->fetch($paymentId)->refund($payload);
                    $refundId = trim((string) ($refund['id'] ?? ''));
                }
                $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));
                if ($existingRefundId === '') {
                    $refundNote = '[System] Razorpay refund initiated';
                    if ($refundId !== '') {
                        $refundNote .= ' (refund_id: ' . $refundId . ')';
                    }
                    if ($refundStatus !== '') {
                        $refundNote .= ' [status: ' . $refundStatus . ']';
                    }
                    $refundNote .= ' on ' . date('d M Y, H:i');

                    $updNotes = $conn->prepare(
                        "UPDATE orders
                         SET notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END
                         WHERE id = ?"
                    );
                    $updNotes->bind_param('ssi', $refundNote, $refundNote, $orderId);
                    $updNotes->execute();
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Razorpay refund failed: ' . $e->getMessage());
            }

            if ($refundStatus !== 'processed') {
                $conn->commit();
                return [
                    'ok' => true,
                    'message' => 'Refund initiated in Razorpay (status: ' . ($refundStatus !== '' ? $refundStatus : 'processing') . '). Keep payment as Paid until processed.',
                ];
            }
        }

        $updOrder = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'refunded',
                 order_status = CASE WHEN order_status = 'cancelled' THEN 'refunded' ELSE order_status END,
                 status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE status END,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updOrder->bind_param('i', $orderId);
        $updOrder->execute();

        if ($payment) {
            $updPayment = $conn->prepare(
                "UPDATE payments
                 SET payment_status = 'refunded'
                 WHERE id = ?"
            );
            $paymentRowId = (int) ($payment['id'] ?? 0);
            $updPayment->bind_param('i', $paymentRowId);
            $updPayment->execute();
        }

        $conn->commit();
        return ['ok' => true, 'message' => 'Order marked as refunded.'];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund failed.'];
    }
}

/**
 * Sync local refund status with Razorpay using stored refund_id in order notes.
 * Useful for previously mismatched orders.
 * Returns ['ok' => bool, 'message' => string].
 */
function admin_sync_razorpay_refund_status(mysqli $conn, int $orderId): array
{
    try {
        $conn->begin_transaction();

        $orderStmt = $conn->prepare(
            "SELECT id, payment_method, payment_status, order_status, status, notes
             FROM orders
             WHERE id = ?
             FOR UPDATE"
        );
        $orderStmt->bind_param('i', $orderId);
        $orderStmt->execute();
        $order = $orderStmt->get_result()->fetch_assoc();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $method = strtolower((string) ($order['payment_method'] ?? ''));
        if ($method !== 'razorpay') {
            throw new RuntimeException('Sync is available only for Razorpay orders.');
        }

        $notes = (string) ($order['notes'] ?? '');
        if (!preg_match('/refund_id:\s*(rfnd_[A-Za-z0-9]+)/i', $notes, $m)) {
            throw new RuntimeException('No Razorpay refund_id found in order notes.');
        }
        $refundId = trim((string) ($m[1] ?? ''));
        if ($refundId === '') {
            throw new RuntimeException('Invalid refund_id in order notes.');
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        $keyId = _cfg('RAZORPAY_KEY_ID', '');
        $keySecret = _cfg('RAZORPAY_KEY_SECRET', '');
        if ($keyId === '' || $keySecret === '') {
            throw new RuntimeException('Razorpay configuration missing.');
        }

        $api = new Razorpay\Api\Api($keyId, $keySecret);
        $refund = $api->refund->fetch($refundId);
        $refundStatus = strtolower(trim((string) ($refund['status'] ?? '')));

        $paymentRowStmt = $conn->prepare(
            "SELECT id FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1 FOR UPDATE"
        );
        $paymentRowStmt->bind_param('i', $orderId);
        $paymentRowStmt->execute();
        $payment = $paymentRowStmt->get_result()->fetch_assoc();

        if ($refundStatus === 'processed') {
            $updOrder = $conn->prepare(
                "UPDATE orders
                 SET payment_status = 'refunded',
                     order_status = 'refunded',
                     status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE status END,
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $updOrder->bind_param('i', $orderId);
            $updOrder->execute();

            if ($payment) {
                $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'refunded' WHERE id = ?");
                $paymentId = (int) $payment['id'];
                $updPayment->bind_param('i', $paymentId);
                $updPayment->execute();
            }

            $conn->commit();
            return ['ok' => true, 'message' => 'Refund processed in Razorpay. Local status updated to Refunded.'];
        }

        // Not processed yet: keep "refund initiated" state locally.
        $updOrder = $conn->prepare(
            "UPDATE orders
             SET payment_status = 'paid',
                 order_status = 'cancelled',
                 status = 'cancelled',
                 updated_at = NOW()
             WHERE id = ?"
        );
        $updOrder->bind_param('i', $orderId);
        $updOrder->execute();

        if ($payment) {
            $updPayment = $conn->prepare("UPDATE payments SET payment_status = 'paid' WHERE id = ?");
            $paymentId = (int) $payment['id'];
            $updPayment->bind_param('i', $paymentId);
            $updPayment->execute();
        }

        $conn->commit();
        return ['ok' => true, 'message' => 'Refund is still ' . ($refundStatus !== '' ? $refundStatus : 'processing') . ' in Razorpay. Local status corrected to Refund Initiated state.'];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
            // ignore rollback errors
        }
        return ['ok' => false, 'message' => $e->getMessage() ?: 'Refund sync failed.'];
    }
}

// E-Commerce Email Helpers

/**
 * Read active app config from config/db.php bootstrap.
 */
function _cfg(string $key, string $default = ''): string
{
    if (isset($GLOBALS['_app_config'][$key]) && $GLOBALS['_app_config'][$key] !== '') {
        return (string) $GLOBALS['_app_config'][$key];
    }
    return $default;
}

function _mailer_base(): PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    $driver = strtolower(trim(_cfg('MAIL_DRIVER', 'smtp')));

    if ($driver === 'mail') {
        // Use PHP's built-in mail() — required on hosts that block outbound SMTP
        // (e.g. InfinityFree). The host's sendmail handles delivery.
        $mail->isMail();
    } else {
        // Full SMTP (default) — for Gmail App Password, Mailgun, etc.
        $mail->isSMTP();
        $mail->Host       = _cfg('SMTP_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = _cfg('MAIL_FROM');
        $mail->Password   = _cfg('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) _cfg('SMTP_PORT', '587');
    }

    $fromAddress = _cfg('MAIL_FROM', 'no-reply@amberfabrics.com');
    $mail->setFrom($fromAddress, 'Amber Fabrics');
    $mail->CharSet = 'UTF-8';
    return $mail;
}

/**
 * Send order confirmation to the customer after payment.
 */
function send_order_confirmation_email(mysqli $conn, int $orderId): bool
{
    $row = $conn->prepare(
        "SELECT o.*, c.name AS cname, c.email AS cemail
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ?"
    );
    $row->bind_param('i', $orderId);
    $row->execute();
    $order = $row->get_result()->fetch_assoc();
    if (!$order) { return false; }

    $iStmt = $conn->prepare(
        "SELECT unit_type, fabric_name_snapshot, quantity, quantity_meters, price, price_per_meter, total, line_total
         FROM order_items WHERE order_id = ?"
    );
    $iStmt->bind_param('i', $orderId);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $sym = $order['currency'] === 'USD' ? '$' : 'Rs ';
    $lines = [
        'Dear ' . $order['cname'] . ',',
        '',
        'Thank you for your order! Your order has been received and is being processed.',
        '',
        'Order Number : ' . $order['order_number'],
        'Date         : ' . date('d M Y', strtotime($order['created_at'])),
        'Currency     : ' . $order['currency'],
        '',
        '--- Items ---',
    ];
    foreach ($items as $it) {
        $unitType = in_array((string) ($it['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $it['unit_type'] : 'meter';
        $qty = (($it['quantity'] ?? 0) > 0) ? $it['quantity'] : ($it['quantity_meters'] ?? 1);
        $unitPrice = (($it['price'] ?? 0) > 0) ? $it['price'] : ($it['price_per_meter'] ?? 0);
        $lineTotal = (($it['total'] ?? 0) > 0) ? $it['total'] : ($it['line_total'] ?? 0);
        $lines[] = $it['fabric_name_snapshot'] . ' - ' . format_quantity_by_unit($qty, $unitType)
            . quantity_unit_suffix($unitType) . ' x '
            . $sym . number_format((float) $unitPrice, 2)
            . (($unitType === 'piece' || $unitType === 'set') ? ' each = ' : '/m = ')
            . $sym . number_format((float) $lineTotal, 2);
    }
    $lines[] = '';
    $lines[] = 'Subtotal : ' . $sym . number_format((float)$order['subtotal'], 2);
    $lines[] = 'Shipping : ' . $sym . number_format((float)$order['shipping_cost'], 2);
    $lines[] = 'Total    : ' . $sym . number_format((float)$order['total'], 2) . ' ' . $order['currency'];
    $lines[] = '';
    $lines[] = 'We will notify you once your order is shipped.';
    $lines[] = '';
    $lines[] = 'Regards,';
    $lines[] = 'Amber Fabrics';

    try {
        $mail = _mailer_base();
        $mail->addAddress($order['cemail'], $order['cname']);
        $mail->Subject = 'Order Confirmed - ' . $order['order_number'];
        $mail->Body    = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] order confirmation email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify customer when admin changes order status.
 */
function send_order_status_update_email(mysqli $conn, int $orderId, string $newStatus): bool
{
    $row = $conn->prepare(
        "SELECT o.order_number, o.created_at, c.name AS cname, c.email AS cemail
         FROM orders o JOIN customers c ON c.id = o.customer_id
         WHERE o.id = ?"
    );
    $row->bind_param('i', $orderId);
    $row->execute();
    $order = $row->get_result()->fetch_assoc();
    if (!$order) { return false; }

    $statusLower = strtolower(trim($newStatus));
    $lines = [
        'Dear ' . $order['cname'] . ',',
        '',
        'Your order ' . $order['order_number'] . ' status has been updated to: ' . strtoupper($newStatus),
        '',
    ];

    if (in_array($statusLower, ['shipped', 'delivered'], true)) {
        $shipStmt = $conn->prepare(
            "SELECT courier_name, tracking_id, tracking_url, shipped_at, delivered_at
             FROM shipments
             WHERE order_id = ?
             LIMIT 1"
        );
        $shipStmt->bind_param('i', $orderId);
        $shipStmt->execute();
        $shipment = $shipStmt->get_result()->fetch_assoc() ?: [];

        $courier = trim((string) ($shipment['courier_name'] ?? ''));
        $trackingId = trim((string) ($shipment['tracking_id'] ?? ''));
        $trackingUrl = safe_external_url((string) ($shipment['tracking_url'] ?? ''));
        $shippedAt = trim((string) ($shipment['shipped_at'] ?? ''));
        $deliveredAt = trim((string) ($shipment['delivered_at'] ?? ''));

        if ($courier !== '' || $trackingId !== '' || $trackingUrl !== '' || $shippedAt !== '' || $deliveredAt !== '') {
            $lines[] = 'Shipment Details:';
            if ($courier !== '') { $lines[] = 'Courier: ' . $courier; }
            if ($trackingId !== '') { $lines[] = 'Tracking ID: ' . $trackingId; }
            if ($trackingUrl !== '') { $lines[] = 'Tracking URL: ' . $trackingUrl; }
            if ($shippedAt !== '') { $lines[] = 'Shipped At: ' . $shippedAt; }
            if ($deliveredAt !== '') { $lines[] = 'Delivered At: ' . $deliveredAt; }
            $lines[] = '';
        }
    }

    $lines[] = 'Log in to your account to view full order details.';
    $lines[] = '';
    $lines[] = 'Regards,';
    $lines[] = 'Amber Fabrics';

    try {
        $mail = _mailer_base();
        $mail->addAddress($order['cemail'], $order['cname']);
        $mail->Subject = 'Order Update - ' . $order['order_number'] . ' is now ' . ucfirst($newStatus);
        $mail->Body    = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] order status email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset link to a customer.
 */
function send_customer_password_reset_email(string $email, string $token): bool
{
    $appUrl   = rtrim(_cfg('APP_URL', ''), '/');
    if ($appUrl === '') {
        // Fallback: build from server vars but never trust HTTP_HOST for security-sensitive URLs.
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl   = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    $resetUrl = $appUrl . '/customer/reset-password.php?token=' . urlencode($token);

    $lines = [
        'Hi,',
        '',
        'We received a request to reset the password for your Amber Fabrics account.',
        '',
        'Click the link below to set a new password (valid for 1 hour):',
        $resetUrl,
        '',
        'If you did not request this, please ignore this email.',
        '',
        'Regards,',
        'Amber Fabrics',
    ];

    try {
        $mail = _mailer_base();
        $mail->addAddress($email);
        $mail->Subject = 'Password Reset - Amber Fabrics';
        $mail->Body    = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] password reset email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send email address verification link to a newly registered customer.
 */
function send_customer_verification_email(string $email, string $name, string $token): bool
{
    $appUrl  = rtrim(_cfg('APP_URL', ''), '/');
    if ($appUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl   = $protocol . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    $verifyUrl = $appUrl . '/customer/verify-email.php?token=' . urlencode($token);

    $lines = [
        'Hi ' . $name . ',',
        '',
        'Thank you for registering with Amber Fabrics!',
        '',
        'Please verify your email address by clicking the link below (valid for 24 hours):',
        $verifyUrl,
        '',
        'If you did not create an account, please ignore this email.',
        '',
        'Regards,',
        'Amber Fabrics',
    ];

    try {
        $mail = _mailer_base();
        $mail->addAddress($email, $name);
        $mail->Subject = 'Verify your email - Amber Fabrics';
        $mail->Body    = implode("\r\n", $lines);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[amberfabrics] verification email failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// Form helpers — Bootstrap 5 inline validation
// ---------------------------------------------------------------------------

/**
 * Returns the appropriate Bootstrap input CSS classes.
 * $base is 'form-control' for <input>/<textarea>, 'form-select' for <select>.
 */
function form_class(array $errors, string $field, string $base = 'form-control'): string
{
    return $base . (empty($errors[$field]) ? '' : ' is-invalid');
}

/**
 * Returns an invalid-feedback <div> with the field error, or '' if none.
 */
function form_error(array $errors, string $field): string
{
    if (empty($errors[$field])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>';
}

// ---------------------------------------------------------------------------
// Pagination helper — Bootstrap 5 pagination component
// ---------------------------------------------------------------------------

/**
 * Renders a Bootstrap 5 pagination nav.
 *
 * @param int    $page       Current page (1-based)
 * @param int    $pages      Total pages
 * @param array  $queryState Existing query params to preserve in page links
 * @param string $pageKey    URL parameter name for the page number
 * @param int    $total      Total records (used for "Showing X–Y of Z" info line)
 * @param int    $perPage    Records per page (used for info line)
 */
function render_pagination(int $page, int $pages, array $queryState, string $pageKey = 'page', int $total = 0, int $perPage = 0): string
{
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));

    // Info line
    $info = '';
    if ($total > 0 && $perPage > 0) {
        $from = ($page - 1) * $perPage + 1;
        $to   = min($page * $perPage, $total);
        $info = '<p class="text-muted small text-center mb-1">Showing ' . $from . '&ndash;' . $to . ' of ' . $total . ' results</p>';
    }

    // Build an even-sized visible window of numeric page links.
    // This keeps pagination rhythm consistent across the site.
    $visibleCount = min(10, $pages); // always even
    $leftCount = (int) ($visibleCount / 2);
    $start = $page - $leftCount + 1;
    $end = $start + $visibleCount - 1;

    if ($start < 1) {
        $start = 1;
        $end = $visibleCount;
    }
    if ($end > $pages) {
        $end = $pages;
        $start = max(1, $end - $visibleCount + 1);
    }

    $pool = range($start, $end);

    $mkUrl = static fn(int $p): string => '?' . list_build_query(array_merge($queryState, [$pageKey => $p]));

    $html  = '<nav aria-label="Pagination" class="mt-3">';
    $html .= '<ul class="pagination justify-content-center flex-wrap mb-0">';

    // Prev button
    if ($page <= 1) {
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page - 1)) . '">&laquo; Prev</a></li>';
    }

    // Optional first-page shortcut with ellipsis
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }

    // Page numbers in even-sized window
    foreach ($pool as $p) {
        if ($p === $page) {
            $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($p)) . '">' . $p . '</a></li>';
        }
    }

    // Optional last-page shortcut with ellipsis
    if ($end < $pages) {
        if ($end < $pages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($pages)) . '">' . $pages . '</a></li>';
    }

    // Next button
    if ($page >= $pages) {
        $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
    } else {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($mkUrl($page + 1)) . '">Next &raquo;</a></li>';
    }

    $html .= '</ul></nav>';

    return $info . $html;
}
