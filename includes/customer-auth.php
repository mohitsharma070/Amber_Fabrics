<?php
/**
 * Customer authentication helpers.
 * Mirrors the admin auth pattern: session-based, rate-limited, CSRF-protected.
 */

// Session helpers

function is_customer_logged_in(): bool
{
    return !empty($_SESSION['customer_id']);
}

function current_customer_id(): ?int
{
    return isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;
}

function require_customer(): void
{
    if (!is_customer_logged_in()) {
        flash('error', 'Please log in to continue.');
        $returnTo = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect('/customer/login.php?return=' . $returnTo);
    }
}

/** Guest order access is a 30-minute, session-held capability; only its hash is stored. */
function guest_order_capability_ttl_minutes(): int
{
    return 30;
}

function issue_guest_order_capability(mysqli $conn, int $orderId): void
{
    if ($orderId <= 0) throw new RuntimeException('Invalid order capability request.');
    $raw = bin2hex(random_bytes(32)); // 256 bits; raw secret is session-only.
    $hash = hash('sha256', $raw);
    $expires = (new DateTimeImmutable('now'))->modify('+' . guest_order_capability_ttl_minutes() . ' minutes')->format('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE orders SET guest_capability_hash = ?, guest_capability_expires_at = ? WHERE id = ?");
    $stmt->bind_param('ssi', $hash, $expires, $orderId);
    $stmt->execute();
    $_SESSION['guest_order_capabilities'][$orderId] = ['token' => $raw, 'expires_at' => $expires];
}

function guest_order_access_allowed(mysqli $conn, int $orderId): bool
{
    if ($orderId <= 0 || is_customer_logged_in()) return false;
    $cap = $_SESSION['guest_order_capabilities'][$orderId] ?? null;
    if (!is_array($cap) || empty($cap['token']) || empty($cap['expires_at']) || strtotime((string) $cap['expires_at']) < time()) {
        unset($_SESSION['guest_order_capabilities'][$orderId]);
        $clear = $conn->prepare("UPDATE orders SET guest_capability_hash = NULL, guest_capability_expires_at = NULL WHERE id = ? AND guest_capability_expires_at < NOW()");
        $clear->bind_param('i', $orderId); $clear->execute();
        return false;
    }
    $stmt = $conn->prepare("SELECT guest_capability_hash, guest_capability_expires_at FROM orders WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $dbExpiry = strtotime((string) ($row['guest_capability_expires_at'] ?? ''));
    $valid = $dbExpiry >= time() && !empty($row['guest_capability_hash'])
        && hash_equals((string) $row['guest_capability_hash'], hash('sha256', (string) $cap['token']));
    if (!$valid) {
        unset($_SESSION['guest_order_capabilities'][$orderId]);
        if ($dbExpiry < time()) {
            $clear = $conn->prepare("UPDATE orders SET guest_capability_hash = NULL, guest_capability_expires_at = NULL WHERE id = ? AND guest_capability_expires_at < NOW()");
            $clear->bind_param('i', $orderId); $clear->execute();
        }
    }
    return $valid;
}

/** Authenticated ownership is always preferred and remains strict; guests need the capability. */
function order_access_allowed(mysqli $conn, int $orderId): bool
{
    if (is_customer_logged_in()) {
        $stmt = $conn->prepare("SELECT 1 FROM orders WHERE id = ? AND customer_id = ? LIMIT 1");
        $customerId = (int) $_SESSION['customer_id']; $stmt->bind_param('ii', $orderId, $customerId); $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }
    return guest_order_access_allowed($conn, $orderId);
}

function require_order_access(mysqli $conn, int $orderId, string $fallback = '/checkout.php'): void
{
    if (!order_access_allowed($conn, $orderId)) {
        flash('error', 'Order access is unavailable or has expired.');
        redirect($fallback);
    }
}

function clear_guest_order_capability(mysqli $conn, int $orderId): void
{
    unset($_SESSION['guest_order_capabilities'][$orderId]);
    $stmt = $conn->prepare("UPDATE orders SET guest_capability_hash = NULL, guest_capability_expires_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $orderId); $stmt->execute();
}

function order_access_landing_url(string $orderNumber): string
{
    return is_customer_logged_in() ? '/customer/orders.php' : '/order-success.php?order=' . urlencode($orderNumber);
}

// Rate limiting (same algorithm as admin)

define('CUSTOMER_MAX_ATTEMPTS', 5);
define('CUSTOMER_LOCK_MINUTES', 5);

function customer_rate_limit_key(string $email, string $ip): string
{
    return hash('sha256', strtolower(trim($email)) . '|' . $ip);
}

function customer_check_rate_limit(mysqli $conn, string $email, string $ip): bool
{
    $key = customer_rate_limit_key($email, $ip);
    try {
        $stmt = $conn->prepare(
            "SELECT attempts, blocked_until FROM customer_login_attempts WHERE attempt_key = ?"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        error_log('[customer-auth] rate limit check unavailable: ' . $e->getMessage());
        return true;
    }

    if (!$row) {
        return true; // no record, allow
    }
    if ($row['blocked_until'] && new DateTime() < new DateTime($row['blocked_until'])) {
        return false; // still blocked
    }
    return true;
}

function customer_record_attempt(mysqli $conn, string $email, string $ip, bool $success): void
{
    $key = customer_rate_limit_key($email, $ip);
    try {
        if ($success) {
            $delete = $conn->prepare("DELETE FROM customer_login_attempts WHERE attempt_key = ?");
            $delete->bind_param('s', $key);
            $delete->execute();
            return;
        }

        $blocked = null;
        $stmt = $conn->prepare("SELECT attempts FROM customer_login_attempts WHERE attempt_key = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $attempts = $row ? (int) $row['attempts'] + 1 : 1;
        if ($attempts >= CUSTOMER_MAX_ATTEMPTS) {
            $blocked = (new DateTime())->modify('+' . CUSTOMER_LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        }

        $upsert = $conn->prepare(
            "INSERT INTO customer_login_attempts (attempt_key, attempts, blocked_until)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE attempts = ?, blocked_until = ?"
        );
        $upsert->bind_param('sisis', $key, $attempts, $blocked, $attempts, $blocked);
        $upsert->execute();
    } catch (Throwable $e) {
        error_log('[customer-auth] rate limit write unavailable: ' . $e->getMessage());
    }
}

// Cart count for nav badge


