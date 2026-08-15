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

function customer_clear_auth_session(bool $markInvalidated = true): void
{
    foreach ([
        'customer_id',
        'customer_name',
        'customer_auth_version',
        'customer_session_started_at',
        'customer_last_seen_at',
        'customer_session_fingerprint',
    ] as $key) {
        unset($_SESSION[$key]);
    }
    if ($markInvalidated) {
        $_SESSION['customer_auth_invalidated'] = true;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function customer_session_fingerprint(?string $userAgent = null): string
{
    $ua = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', 'v1|' . trim($ua));
}

function customer_session_valid(mysqli $conn, int $customerId): bool
{
    static $validationCache = [];
    if ($customerId <= 0) {
        return false;
    }
    if (array_key_exists($customerId, $validationCache)) {
        return $validationCache[$customerId];
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
        $stmt = $conn->prepare('SELECT is_active, auth_version FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || (isset($row['is_active']) && (int) $row['is_active'] !== 1)) {
            return $validationCache[$customerId] = false;
        }
        $databaseAuthVersion = max(1, (int) ($row['auth_version'] ?? 1));
        $sessionAuthVersion = (int) ($_SESSION['customer_auth_version'] ?? 0);
        if ($sessionAuthVersion <= 0 || $sessionAuthVersion !== $databaseAuthVersion) {
            return $validationCache[$customerId] = false;
        }
    } catch (Throwable $e) {
        error_log('[customer-auth] Session validation unavailable: ' . $e->getMessage());
        return $validationCache[$customerId] = false;
    }

    $_SESSION['customer_last_seen_at'] = $now;
    return $validationCache[$customerId] = true;
}

function require_customer(): void
{
    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($customerId <= 0 || !$conn || !customer_session_valid($conn, $customerId)) {
        if ($customerId > 0 || !empty($_SESSION['customer_auth_invalidated'])) {
            customer_clear_auth_session(false);
            unset($_SESSION['customer_auth_invalidated']);
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
        $conn->begin_transaction();
        $seed = $conn->prepare(
            "INSERT IGNORE INTO customer_login_attempts (attempt_key, attempts, blocked_until) VALUES (?, 0, NULL)"
        );
        $seed->bind_param('s', $key);
        $seed->execute();
        $stmt = $conn->prepare(
            "SELECT attempts, blocked_until FROM customer_login_attempts WHERE attempt_key = ? FOR UPDATE"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('[customer-auth] rate limit check unavailable: ' . $e->getMessage());
        return true;
    }

    $now = new DateTimeImmutable('now');
    $blockedUntil = !empty($row['blocked_until']) ? new DateTimeImmutable((string) $row['blocked_until']) : null;
    if ($blockedUntil && $now < $blockedUntil) {
        $conn->commit();
        return false;
    }
    $attempts = $blockedUntil ? 0 : (int) ($row['attempts'] ?? 0);
    $attempts++;
    $nextBlockedUntil = $attempts >= CUSTOMER_MAX_ATTEMPTS
        ? $now->modify('+' . CUSTOMER_LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s')
        : null;
    $update = $conn->prepare(
        "UPDATE customer_login_attempts SET attempts = ?, blocked_until = ? WHERE attempt_key = ?"
    );
    $update->bind_param('iss', $attempts, $nextBlockedUntil, $key);
    $update->execute();
    $conn->commit();
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

        // Failed attempts are consumed atomically by customer_check_rate_limit()
        // before password verification, so concurrent requests cannot bypass it.
    } catch (Throwable $e) {
        error_log('[customer-auth] rate limit write unavailable: ' . $e->getMessage());
    }
}

// Cart count for nav badge


