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

function customer_session_fingerprint(?string $userAgent = null): string
{
    $ua = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', 'v1|' . trim($ua));
}

function customer_session_valid(mysqli $conn, int $customerId): bool
{
    if ($customerId <= 0) {
        return false;
    }

    $now = time();
    $idleTimeout = max(3600, (int) _cfg('CUSTOMER_SESSION_IDLE_TIMEOUT_SEC', '604800'));
    $absoluteTimeout = max($idleTimeout, (int) _cfg('CUSTOMER_SESSION_ABSOLUTE_TIMEOUT_SEC', '2592000'));
    $startedAt = (int) ($_SESSION['customer_session_started_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['customer_last_seen_at'] ?? 0);
    $storedFingerprint = trim((string) ($_SESSION['customer_session_fingerprint'] ?? ''));

    // Upgrade customer sessions created before these validation fields existed
    // without dropping their cart or forcing an immediate logout at deployment.
    if ($startedAt <= 0) {
        $startedAt = $now;
        $_SESSION['customer_session_started_at'] = $now;
    }
    if ($lastSeen <= 0) {
        $lastSeen = $now;
        $_SESSION['customer_last_seen_at'] = $now;
    }
    if ($storedFingerprint === '') {
        $storedFingerprint = customer_session_fingerprint();
        $_SESSION['customer_session_fingerprint'] = $storedFingerprint;
    }
    if (($now - $lastSeen) > $idleTimeout || ($now - $startedAt) > $absoluteTimeout) {
        return false;
    }
    if (!hash_equals($storedFingerprint, customer_session_fingerprint())) {
        return false;
    }

    try {
        $stmt = $conn->prepare('SELECT is_active FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || (isset($row['is_active']) && (int) $row['is_active'] !== 1)) {
            return false;
        }
    } catch (Throwable $e) {
        error_log('[customer-auth] Session validation unavailable: ' . $e->getMessage());
        return false;
    }

    $_SESSION['customer_last_seen_at'] = $now;
    return true;
}

function require_customer(): void
{
    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($customerId <= 0 || !$conn || !customer_session_valid($conn, $customerId)) {
        if ($customerId > 0) {
            app_destroy_session(true);
            flash('error', 'Your session expired. Please log in again.');
        } else {
            flash('error', 'Please log in to continue.');
        }
        $returnTo = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect('/customer/login.php?return=' . $returnTo);
    }
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


