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
    $email = getenv('ADMIN_NOTIFICATION_EMAIL') ?: (getenv('ADMIN_EMAIL') ?: '');
    if ($email === '') {
        error_log('[amberfabrics] WARNING: ADMIN_NOTIFICATION_EMAIL env var is not set. Admin notifications will not be sent.');
        return '';
    }
    return $email;
}

/**
 * Send a basic email notification for new inquiry submissions.
 */
function send_inquiry_notification(array $inquiry): bool
{
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

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
    $message = implode("\r\n", $lines);

    $from = getenv('MAIL_FROM') ?: 'no-reply@localhost';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // SMTP settings for Gmail
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $from; // Your Gmail address
        $mail->Password = getenv('SMTP_PASSWORD') ?: ''; // Gmail App Password - set SMTP_PASSWORD environment variable
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

        $mail->setFrom($from, 'Fabric Export Notification');
        $mail->addAddress($to);
        $mail->addReplyTo($inquiry['email'] ?? $from);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log('[fabric-export] PHPMailer failed: ' . $e->getMessage());
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

function ensure_site_settings_table(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(120) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ready = true;
}

function load_site_settings_from_db(mysqli $conn): array
{
    ensure_site_settings_table($conn);
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
    ensure_site_settings_table($conn);
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
function ensure_announcement_dismissals_table(mysqli $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS announcement_dismissals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_key CHAR(64) NOT NULL,
            customer_id INT DEFAULT NULL,
            announcement_key CHAR(32) NOT NULL,
            dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_announce_dismissal (session_key, announcement_key),
            INDEX idx_announce_customer_id (customer_id),
            INDEX idx_announce_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ready = true;
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

    ensure_announcement_dismissals_table($conn);
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

    ensure_announcement_dismissals_table($conn);
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
function cart_save_to_db(mysqli $conn, int $customerId, array $cart): void
{
    try {
        $cartId = cart_get_or_create_db_cart($conn, $customerId);
        $del = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $del->bind_param('i', $cartId);
        $del->execute();
        if (empty($cart)) {
            return;
        }
        $ins = $conn->prepare(
            "INSERT INTO cart_items (cart_id, product_id, quantity, fabric_id, quantity_meters)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($cart as $productId => $qty) {
            $pid = (int) $productId;
            $q   = normalize_meter_quantity($qty);
            $ins->bind_param('iidid', $cartId, $pid, $q, $pid, $q);
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
    try {
        $stmt = $conn->prepare(
            "SELECT ci.product_id, ci.quantity
             FROM cart c
             JOIN cart_items ci ON ci.cart_id = c.id
             WHERE c.customer_id = ?"
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cart = [];
        foreach ($rows as $row) {
            if ((int) $row['product_id'] > 0) {
                $cart[(int) $row['product_id']] = normalize_meter_quantity($row['quantity'] ?? 1);
            }
        }
        return $cart;
    } catch (Throwable $e) {
        error_log('[amberfabrics] cart_load_from_db failed: ' . $e->getMessage());
        return [];
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

// E-Commerce Email Helpers

function _mailer_base(): PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host      = getenv('SMTP_HOST')     ?: 'smtp.gmail.com';
    $mail->SMTPAuth  = true;
    $mail->Username  = getenv('MAIL_FROM')     ?: '';
    $mail->Password  = getenv('SMTP_PASSWORD') ?: '';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port      = (int)(getenv('SMTP_PORT') ?: 587);
    $mail->setFrom(getenv('MAIL_FROM') ?: 'no-reply@amberfabrics.com', 'Amber Fabrics');
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

    $lines = [
        'Dear ' . $order['cname'] . ',',
        '',
        'Your order ' . $order['order_number'] . ' status has been updated to: ' . strtoupper($newStatus),
        '',
        'Log in to your account to view full order details.',
        '',
        'Regards,',
        'Amber Fabrics',
    ];

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
    $appUrl   = rtrim((string) (getenv('APP_URL') ?: ''), '/');
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
    $appUrl  = rtrim((string) (getenv('APP_URL') ?: ''), '/');
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
