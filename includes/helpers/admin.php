<?php
/**
 * Require admin session.
 */
function admin_role_rank(string $role): int
{
    $map = [
        'viewer' => 10,
        'catalog_manager' => 20,
        'operations_manager' => 30,
        'super_admin' => 100,
    ];
    $role = strtolower(trim($role));
    return $map[$role] ?? 0;
}

function admin_activity_logs_table_ready(mysqli $conn): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked) {
        return $ready;
    }
    $checked = true;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'admin_activity_logs'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $ready = ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function log_admin_activity(
    mysqli $conn,
    int $adminId,
    string $action,
    string $targetType = '',
    int $targetId = 0,
    string $details = '',
    string $status = 'ok'
): void {
    if ($adminId <= 0 || $action === '' || !admin_activity_logs_table_ready($conn)) {
        return;
    }
    try {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $route = trim((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $targetType = trim($targetType);
        $details = trim($details);
        $status = in_array($status, ['ok', 'failed', 'denied'], true) ? $status : 'ok';
        $stmt = $conn->prepare(
            "INSERT INTO admin_activity_logs
            (admin_id, action, target_type, target_id, route, request_ip, user_agent, status, details, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param('ississsss', $adminId, $action, $targetType, $targetId, $route, $ip, $ua, $status, $details);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[app] admin activity log failed: ' . $e->getMessage());
    }
}

function admin_route_min_role(string $scriptName): string
{
    $script = strtolower(trim($scriptName));
    $base = basename($script);
    $superOnly = [
        'settings.php',
        'admins.php',
        'shipping-rates.php',
    ];
    $opsAndAbove = [
        'orders.php',
        'order-view.php',
        'returns.php',
        'customers.php',
        'customer-view.php',
        'expenses.php',
        'inquiries.php',
        'inquiry-view.php',
        'export-inquiries.php',
    ];
    if (in_array($base, $superOnly, true)) {
        return 'super_admin';
    }
    if (in_array($base, $opsAndAbove, true)) {
        return 'operations_manager';
    }
    return 'viewer';
}

function admin_session_valid(mysqli $conn, int $adminId, string $sessionRole): bool
{
    if ($adminId <= 0) {
        return false;
    }
    $timeoutIdleSec = max(300, (int) _cfg('ADMIN_SESSION_IDLE_TIMEOUT_SEC', '1800'));
    $timeoutAbsoluteSec = max(900, (int) _cfg('ADMIN_SESSION_ABSOLUTE_TIMEOUT_SEC', '28800'));
    $now = time();

    $startedAt = (int) ($_SESSION['admin_session_started_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['admin_last_seen_at'] ?? 0);
    if ($startedAt <= 0 || $lastSeen <= 0) {
        return false;
    }
    if (($now - $lastSeen) > $timeoutIdleSec || ($now - $startedAt) > $timeoutAbsoluteSec) {
        return false;
    }

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $fp = hash('sha256', $ip . '|' . $ua);
    $storedFp = trim((string) ($_SESSION['admin_session_fingerprint'] ?? ''));
    if ($storedFp === '' || !hash_equals($storedFp, $fp)) {
        return false;
    }

    try {
        $stmt = $conn->prepare("SELECT role, is_active FROM admins WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return false;
        }
        if (isset($row['is_active']) && (int) $row['is_active'] !== 1) {
            return false;
        }
        $dbRole = strtolower(trim((string) ($row['role'] ?? 'viewer')));
        if ($dbRole === '') {
            $dbRole = 'viewer';
        }
        if ($sessionRole !== '' && $dbRole !== strtolower($sessionRole)) {
            $_SESSION['admin_role'] = $dbRole;
        }
    } catch (Throwable $e) {
        return false;
    }

    $_SESSION['admin_last_seen_at'] = $now;
    return true;
}

function require_admin(): void
{
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $role = strtolower(trim((string) ($_SESSION['admin_role'] ?? 'viewer')));
    if ($adminId <= 0) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if (!$conn || !admin_session_valid($conn, $adminId, $role)) {
        if ($conn instanceof mysqli) {
            log_admin_activity($conn, $adminId, 'admin_session_invalidated', 'session', 0, 'Session failed security validation.', 'denied');
        }
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'Your admin session expired. Please log in again.');
        redirect('login.php');
    }

    $requiredRole = admin_route_min_role((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (admin_role_rank($role) < admin_role_rank($requiredRole)) {
        if ($conn instanceof mysqli) {
            log_admin_activity($conn, $adminId, 'admin_access_denied', 'route', 0, 'Required role: ' . $requiredRole . ', current role: ' . $role, 'denied');
        }
        http_response_code(403);
        exit('Forbidden');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {
        log_admin_activity($conn, $adminId, 'admin_post_action', 'route', 0, 'POST to ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), 'ok');
    }
}

function admin_utc_mysql_to_timestamp(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    return $dt ? $dt->getTimestamp() : null;
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
 * Public form rate-limit backed by DB when available (session fallback).
 */
function public_form_rate_limit_allow(string $scope, int $maxAttempts = 5, int $windowSeconds = 600): bool
{
    $scope = trim($scope);
    if ($scope === '') {
        $scope = 'public_form';
    }
    $maxAttempts = max(1, (int) $maxAttempts);
    $windowSeconds = max(60, (int) $windowSeconds);

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $uaKey = substr(hash('sha256', $ua), 0, 16);
    $key = hash('sha256', strtolower($scope) . '|' . $ip . '|' . $uaKey);

    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    if ($conn instanceof mysqli) {
        try {
            $conn->query(
                "CREATE TABLE IF NOT EXISTS public_form_attempts (
                    attempt_key CHAR(64) PRIMARY KEY,
                    scope VARCHAR(80) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent_hash CHAR(16) NOT NULL,
                    attempts INT NOT NULL DEFAULT 0,
                    window_started_at DATETIME NOT NULL,
                    blocked_until DATETIME DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_public_form_attempts_scope_updated (scope, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $stmt = $conn->prepare(
                "SELECT attempts, UNIX_TIMESTAMP(window_started_at) AS window_ts, UNIX_TIMESTAMP(blocked_until) AS blocked_ts
                 FROM public_form_attempts
                 WHERE attempt_key = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            $now = time();
            $windowStart = $now - $windowSeconds;
            $attempts = (int) ($row['attempts'] ?? 0);
            $windowTs = (int) ($row['window_ts'] ?? 0);
            $blockedTs = (int) ($row['blocked_ts'] ?? 0);

            if ($blockedTs > $now) {
                return false;
            }

            if ($windowTs < $windowStart) {
                $attempts = 0;
                $windowTs = $now;
            }

            if ($attempts >= $maxAttempts) {
                $blockedUntil = date('Y-m-d H:i:s', $now + $windowSeconds);
                $upd = $conn->prepare(
                    "INSERT INTO public_form_attempts (attempt_key, scope, ip_address, user_agent_hash, attempts, window_started_at, blocked_until)
                     VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?)
                     ON DUPLICATE KEY UPDATE
                        attempts = VALUES(attempts),
                        window_started_at = VALUES(window_started_at),
                        blocked_until = VALUES(blocked_until),
                        updated_at = CURRENT_TIMESTAMP"
                );
                $upd->bind_param('ssssiis', $key, $scope, $ip, $uaKey, $attempts, $windowTs, $blockedUntil);
                $upd->execute();
                $upd->close();
                return false;
            }

            $attempts++;
            $ins = $conn->prepare(
                "INSERT INTO public_form_attempts (attempt_key, scope, ip_address, user_agent_hash, attempts, window_started_at, blocked_until)
                 VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), NULL)
                 ON DUPLICATE KEY UPDATE
                    attempts = VALUES(attempts),
                    window_started_at = VALUES(window_started_at),
                    blocked_until = NULL,
                    updated_at = CURRENT_TIMESTAMP"
            );
            $ins->bind_param('ssssii', $key, $scope, $ip, $uaKey, $attempts, $windowTs);
            $ins->execute();
            $ins->close();

            // Lightweight cleanup of stale rows.
            $conn->query("DELETE FROM public_form_attempts WHERE updated_at < (NOW() - INTERVAL 7 DAY)");
            return true;
        } catch (Throwable $e) {
            error_log('[app] public form rate-limit fallback to session: ' . $e->getMessage());
        }
    }

    if (!isset($_SESSION['form_rate_limit']) || !is_array($_SESSION['form_rate_limit'])) {
        $_SESSION['form_rate_limit'] = [];
    }
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
        error_log('[app] WARNING: ADMIN_NOTIFICATION_EMAIL is not set. Admin notifications will not be sent.');
        return '';
    }
    return $email;
}

