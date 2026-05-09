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


