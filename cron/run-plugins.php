<?php
/**
 * Production cron runner.
 *
 * CLI:
 *   php cron/run-plugins.php
 *   php cron/run-plugins.php --local-smoke  (executes jobs against the local DB)
 *   php cron/run-plugins.php --check        (read-only readiness check)
 *
 * Exit codes: 0 healthy/degraded/overlap, 1 critical job failure, 2 runtime failure.
 */

$argvList = PHP_SAPI === 'cli' && isset($argv) && is_array($argv) ? $argv : [];
$isLocalSmoke = PHP_SAPI === 'cli' && in_array('--local-smoke', $argvList, true);
$isCheck = PHP_SAPI === 'cli' && in_array('--check', $argvList, true);
if (PHP_SAPI === 'cli' && !$isLocalSmoke) {
    putenv('APP_MODE=production');
    $_SERVER['APP_MODE'] = 'production';
}

require_once __DIR__ . '/../includes/init.php';

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        echo "Method Not Allowed\n";
        exit;
    }
    $expectedToken = trim((string) _cfg('CRON_RUN_TOKEN', ''));
    $headerToken = trim((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    $queryToken = trim((string) ($_GET['token'] ?? ''));
    $providedToken = $headerToken !== '' ? $headerToken : $queryToken;
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

function cron_log_event(string $level, string $event, array $fields = []): void
{
    $record = ['ts' => date('c'), 'level' => strtolower($level), 'event' => $event] + $fields;
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $line = '[cron] ' . ($encoded !== false ? $encoded : '{"event":"log_encode_failed"}');
    if (PHP_SAPI === 'cli') {
        fwrite(in_array($record['level'], ['error', 'warning'], true) ? STDERR : STDOUT, $line . PHP_EOL);
    } else {
        error_log($line);
    }
}

function cron_db_lock_state(mysqli $conn, string $lockName): string
{
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS got_lock');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $value = $stmt->get_result()->fetch_assoc()['got_lock'] ?? null;
    if ($value === null) {
        return 'error';
    }
    return (int) $value === 1 ? 'acquired' : 'busy';
}

function cron_db_lock_release(mysqli $conn, string $lockName): void
{
    try {
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
    } catch (Throwable $e) {
        cron_log_event('warning', 'db_lock_release_failed', ['error' => CronService::sanitizeError($e->getMessage())]);
    }
}

function cron_readiness_check(mysqli $conn): array
{
    $requiredTables = [
        'orders', 'payments', 'public_form_attempts', 'site_settings', 'cod_confirmations',
        'abandoned_cart_reminders', 'inventory_alert_logs', 'back_in_stock_subscriptions',
        'shipping_rto_risks', 'support_tickets', 'cron_run_history',
    ];
    $missing = [];
    foreach ($requiredTables as $table) {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        if ((int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) !== 1) {
            $missing[] = $table;
        }
    }

    $issues = [];
    if ($missing !== []) {
        $issues[] = 'Missing required tables: ' . implode(', ', $missing);
    }
    $requiredColumns = [
        'abandoned_cart_reminders' => ['delivery_attempts', 'consecutive_failures', 'last_attempt_at', 'last_error'],
        'back_in_stock_subscriptions' => ['delivery_attempts', 'next_attempt_at'],
        'inventory_alert_logs' => ['variant_id'],
        'shipping_courier_reverse_pickups' => ['initialization_status', 'claim_token', 'attempt_count', 'last_error'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->bind_param('ss', $table, $column);
            $stmt->execute();
            if ((int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) !== 1) {
                $issues[] = 'Missing required schema field: ' . $table . '.' . $column;
            }
        }
    }
    if (function_exists('plugin_load_config') && function_exists('plugin_base_path')) {
        foreach ((array) (plugin_load_config()['enabled'] ?? []) as $plugin) {
            if (!is_file(plugin_base_path() . '/' . $plugin . '/plugin.php')) {
                $issues[] = 'Enabled plugin entry is missing: ' . $plugin;
            }
        }
    }
    if (function_exists('product_feed_enabled') && product_feed_enabled()) {
        $dir = product_feed_filesystem_dir();
        $probe = is_dir($dir) ? $dir : dirname($dir);
        if (!is_dir($probe) || !is_writable($probe)) {
            $issues[] = 'Product feed directory is not writable.';
        }
    }
    if (function_exists('shipping_courier_enabled') && shipping_courier_enabled()
        && function_exists('shipping_courier_provider_configured') && !shipping_courier_provider_configured()) {
        $issues[] = 'Shipping courier is enabled but provider credentials are incomplete.';
    }
    $checkCount = count($requiredTables) + array_sum(array_map('count', $requiredColumns));
    return CronService::result($issues === [] ? 'success' : 'failed', $checkCount, max(0, $checkCount - count($issues)), count($issues), [
        'issues' => array_map([CronService::class, 'sanitizeError'], $issues),
    ]);
}

function cron_plugin_tick(mysqli $conn): array
{
    $report = do_action_report('cron.tick', ['conn' => $conn, 'ran_at' => date('Y-m-d H:i:s')]);
    $degraded = 0;
    $failed = 0;
    $criticalFailures = 0;
    foreach ($report as $row) {
        $status = (string) ($row['status'] ?? 'success');
        if ($status === 'degraded') {
            $degraded++;
        } elseif ($status === 'failed') {
            $failed++;
        }
        if (!empty($row['critical']) && in_array($status, ['degraded', 'failed'], true)) {
            $criticalFailures++;
        }
    }
    $status = $criticalFailures > 0 ? 'failed' : (($failed + $degraded) > 0 ? 'degraded' : 'success');
    return CronService::result($status, count($report), count($report) - $failed - $degraded, $failed + $degraded, [
        'callbacks_degraded' => $degraded,
        'callbacks_failed' => $failed,
        'critical_callback_failures' => $criticalFailures,
        'callbacks' => $report,
    ]);
}

function cron_save_health(mysqli $conn, array $summary, int $durationMs, string $startedAt): void
{
    $now = date('Y-m-d H:i:s');
    $summaryJson = json_encode($summary['details'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
    $settings = [
        'cron_last_run_at' => $now,
        'cron_last_status' => (string) $summary['status'],
        'cron_last_duration_ms' => (string) max(0, $durationMs),
        'cron_last_failed_jobs' => (string) (int) $summary['failed'],
        'cron_last_degraded_jobs' => (string) (int) $summary['degraded'],
        'cron_last_summary_json' => $summaryJson,
    ];
    if ($summary['status'] === 'success') {
        $settings['cron_last_success_at'] = $now;
    }
    SiteSettingsService::saveToDb($conn, $settings);

    $historyStmt = $conn->prepare(
        'INSERT INTO cron_run_history
            (started_at, finished_at, duration_ms, status, jobs_total, jobs_failed, jobs_degraded, critical_jobs_failed, summary_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $startedSql = date('Y-m-d H:i:s', strtotime($startedAt) ?: time());
    $status = CronService::normalizeStatus((string) ($summary['status'] ?? 'failed'));
    $jobsTotal = (int) ($summary['jobs_total'] ?? ((int) ($summary['failed'] ?? 0) + (int) ($summary['degraded'] ?? 0)));
    $failed = (int) ($summary['failed'] ?? 0);
    $degraded = (int) ($summary['degraded'] ?? 0);
    $critical = (int) ($summary['critical_failures'] ?? 0);
    $historyStmt->bind_param('ssisiiiis', $startedSql, $now, $durationMs, $status, $jobsTotal, $failed, $degraded, $critical, $summaryJson);
    $historyStmt->execute();

    $cleanup = $conn->prepare('DELETE FROM cron_run_history WHERE started_at < (NOW() - INTERVAL 30 DAY) ORDER BY started_at ASC LIMIT 500');
    $cleanup->execute();
}

$mode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
if ($mode !== 'production' && !$isLocalSmoke) {
    cron_log_event('error', 'bootstrap_mode_invalid', ['message' => 'APP_MODE must be production for cron runtime.', 'current_mode' => $mode ?: '(unknown)']);
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit(2);
}
if ($isLocalSmoke) {
    cron_log_event('warning', 'local_smoke_enabled', ['message' => 'Local smoke executes jobs against the configured local database.']);
}

$lockFile = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'amber-fabrics-cron.lock';
$lockFp = @fopen($lockFile, 'c+');
if (!$lockFp) {
    cron_log_event('error', 'lock_open_failed', ['lock_file' => $lockFile]);
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit(2);
}
if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
    cron_log_event('warning', 'overlap_skipped', ['lock_file' => $lockFile]);
    @fclose($lockFp);
    echo "OK\n";
    exit(0);
}

$dbLockName = 'amber_fabrics:cron:run_plugins';
$dbLockAcquired = false;
try {
    $lockState = cron_db_lock_state($conn, $dbLockName);
    if ($lockState === 'busy') {
        cron_log_event('warning', 'overlap_skipped_db_lock', ['db_lock' => $dbLockName]);
        echo "OK\n";
        exit(0);
    }
    if ($lockState !== 'acquired') {
        throw new RuntimeException('Unable to acquire cron database lock.');
    }
    $dbLockAcquired = true;

    $runStartedAt = date('c');
    $runStart = microtime(true);
    cron_log_event('info', 'cron_start', ['started_at' => $runStartedAt, 'pid' => function_exists('getmypid') ? getmypid() : 0, 'mode' => $mode, 'local_smoke' => $isLocalSmoke, 'check' => $isCheck]);
    $logger = static function (string $level, string $event, array $fields): void {
        cron_log_event($level, $event, $fields);
    };
    $jobs = [];

    if ($isCheck) {
        $jobs[] = CronService::runJob('readiness_check', true, static fn(): array => cron_readiness_check($conn), $logger);
    } else {
        $jobs[] = CronService::runJob('stale_razorpay_release', !$isLocalSmoke, static fn(): array => PaymentService::release_stale_pending_razorpay_orders_global_report($conn, 30, 200), $logger);
        $jobs[] = CronService::runJob('public_form_rate_limit_cleanup', false, static function () use ($conn): array {
            $stmt = $conn->prepare('DELETE FROM public_form_attempts WHERE updated_at < (NOW() - INTERVAL 7 DAY) ORDER BY updated_at ASC LIMIT 5000');
            $stmt->execute();
            return CronService::result('success', (int) $stmt->affected_rows, (int) $stmt->affected_rows, 0, ['limit' => 5000]);
        }, $logger);
        $pluginJob = CronService::runJob('plugin_tick', false, static fn(): array => cron_plugin_tick($conn), $logger);
        if ((int) ($pluginJob['result']['critical_callback_failures'] ?? 0) > 0 && !$isLocalSmoke) {
            $pluginJob['critical'] = true;
        }
        $jobs[] = $pluginJob;
    }

    $summary = CronService::summarize($jobs);
    $summary['jobs_total'] = count($jobs);
    $duration = (int) round((microtime(true) - $runStart) * 1000);
    if (!$isCheck) {
        try {
            cron_save_health($conn, $summary, $duration, $runStartedAt);
        } catch (Throwable $e) {
            $jobs[] = ['name' => 'cron_health_save', 'critical' => true, 'status' => 'failed', 'error' => CronService::sanitizeError($e->getMessage()), 'result' => null];
            $summary = CronService::summarize($jobs);
            $summary['jobs_total'] = count($jobs);
        }
    }

    cron_log_event($summary['critical_failures'] > 0 ? 'error' : ($summary['status'] === 'degraded' ? 'warning' : 'info'), 'cron_finish', [
        'started_at' => $runStartedAt,
        'finished_at' => date('c'),
        'duration_ms' => (int) round((microtime(true) - $runStart) * 1000),
        'jobs_total' => count($jobs),
        'jobs_failed' => $summary['failed'],
        'jobs_degraded' => $summary['degraded'],
        'critical_jobs_failed' => $summary['critical_failures'],
    ]);

    if ($summary['critical_failures'] > 0) {
        if (PHP_SAPI !== 'cli') http_response_code(500);
        exit(1);
    }
    echo "OK\n";
    exit(0);
} catch (Throwable $e) {
    cron_log_event('error', 'runtime_failed', ['error' => CronService::sanitizeError($e->getMessage())]);
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit(2);
} finally {
    if ($dbLockAcquired) {
        cron_db_lock_release($conn, $dbLockName);
    }
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
}
