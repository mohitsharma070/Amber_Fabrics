<?php
/**
 * Application cron runner.
 *
 * Critical jobs:
 * - stale_razorpay_release
 *
 * Exit codes:
 * - 0 success (or safe overlap skip)
 * - 1 one or more critical jobs failed
 * - 2 bootstrap/runtime fatal
 *
 * Local smoke mode (CLI only):
 * - php cron/run-plugins.php --local-smoke
 * - Allows APP_MODE=local and downgrades critical jobs to non-critical.
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/init.php';
    $expectedToken = trim((string) _cfg('CRON_RUN_TOKEN', ''));
    $providedToken = trim((string) ($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '')));
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
} else {
    require_once __DIR__ . '/../includes/init.php';
}

function cron_log_event(string $level, string $event, array $fields = []): void
{
    $record = [
        'ts' => date('c'),
        'level' => strtolower($level),
        'event' => $event,
    ] + $fields;
    $line = '[cron] ' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        $stream = ($record['level'] === 'error' || $record['level'] === 'warning') ? STDERR : STDOUT;
        fwrite($stream, $line . PHP_EOL);
        return;
    }
    error_log($line);
}

function cron_run_job(string $name, bool $critical, callable $fn): array
{
    $startTs = microtime(true);
    $startedAt = date('c');
    cron_log_event('info', 'job_start', [
        'job' => $name,
        'critical' => $critical,
        'started_at' => $startedAt,
    ]);

    try {
        $result = $fn();
        $endedAt = date('c');
        $durationMs = (int) round((microtime(true) - $startTs) * 1000);
        cron_log_event('info', 'job_finish', [
            'job' => $name,
            'critical' => $critical,
            'status' => 'success',
            'started_at' => $startedAt,
            'finished_at' => $endedAt,
            'duration_ms' => $durationMs,
            'result' => $result,
        ]);
        return [
            'job' => $name,
            'critical' => $critical,
            'ok' => true,
            'started_at' => $startedAt,
            'finished_at' => $endedAt,
            'duration_ms' => $durationMs,
            'error' => '',
            'result' => $result,
        ];
    } catch (Throwable $e) {
        $endedAt = date('c');
        $durationMs = (int) round((microtime(true) - $startTs) * 1000);
        cron_log_event('error', 'job_finish', [
            'job' => $name,
            'critical' => $critical,
            'status' => 'failed',
            'started_at' => $startedAt,
            'finished_at' => $endedAt,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
        ]);
        return [
            'job' => $name,
            'critical' => $critical,
            'ok' => false,
            'started_at' => $startedAt,
            'finished_at' => $endedAt,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
            'result' => null,
        ];
    }
}

function cron_db_lock_acquire(mysqli $conn, string $lockName): bool
{
    $stmt = $conn->prepare("SELECT GET_LOCK(?, 0) AS got_lock");
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return ((int) ($row['got_lock'] ?? 0)) === 1;
}

function cron_db_lock_release(mysqli $conn, string $lockName): void
{
    try {
        $stmt = $conn->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
    } catch (Throwable $e) {
        cron_log_event('warning', 'db_lock_release_failed', ['error' => $e->getMessage()]);
    }
}

$mode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
$isCli = PHP_SAPI === 'cli';
$isLocalSmoke = $isCli && in_array('--local-smoke', $argv, true);
if ($mode !== 'production' && !$isLocalSmoke) {
    cron_log_event('error', 'bootstrap_mode_invalid', [
        'message' => 'APP_MODE must be production for cron runtime.',
        'current_mode' => $mode === '' ? '(unknown)' : $mode,
    ]);
    exit(2);
}
if ($isLocalSmoke) {
    cron_log_event('warning', 'local_smoke_enabled', [
        'message' => 'Local smoke mode enabled (critical jobs downgraded).',
        'current_mode' => $mode === '' ? '(unknown)' : $mode,
    ]);
}

$lockFile = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'amber-fabrics-cron.lock';
$lockFp = @fopen($lockFile, 'c+');
if (!$lockFp) {
    cron_log_event('error', 'lock_open_failed', ['lock_file' => $lockFile]);
    exit(2);
}
if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
    cron_log_event('warning', 'overlap_skipped', ['lock_file' => $lockFile]);
    @fclose($lockFp);
    exit(0);
}

$dbLockName = 'amber_fabrics:cron:run_plugins';
$dbLockAcquired = false;
if (isset($conn) && $conn instanceof mysqli) {
    try {
        $dbLockAcquired = cron_db_lock_acquire($conn, $dbLockName);
    } catch (Throwable $e) {
        cron_log_event('warning', 'db_lock_acquire_failed', ['error' => $e->getMessage()]);
    }
}
if (!$dbLockAcquired) {
    cron_log_event('warning', 'overlap_skipped_db_lock', ['db_lock' => $dbLockName]);
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    exit(0);
}

$runStartedAt = date('c');
$runStartTs = microtime(true);
cron_log_event('info', 'cron_start', [
    'started_at' => $runStartedAt,
    'pid' => function_exists('getmypid') ? getmypid() : 0,
    'mode' => $mode,
    'local_smoke' => $isLocalSmoke,
]);

$results = [];

$isCriticalJob = !$isLocalSmoke;

$results[] = cron_run_job('stale_razorpay_release', $isCriticalJob, static function () use ($conn): array {
    $released = PaymentService::release_stale_pending_razorpay_orders_global($conn, 30, 200);
    return ['released_count' => (int) $released, 'ttl_minutes' => 30, 'limit' => 200];
});

$results[] = cron_run_job('event_outbox', false, static function () use ($conn): array {
    return PaymentService::outbox_process($conn, 50);
});

$results[] = cron_run_job('plugin_tick', false, static function () use ($conn): array {
    $report = function_exists('do_action_report')
        ? do_action_report('cron.tick', [
            'conn' => $conn,
            'ran_at' => date('Y-m-d H:i:s'),
        ])
        : [];
    $total = count($report);
    $failed = 0;
    foreach ($report as $row) {
        if (empty($row['ok'])) {
            $failed++;
        }
    }
    return ['callbacks_total' => $total, 'callbacks_failed' => $failed];
});

$results[] = cron_run_job('cron_heartbeat_save', false, static function () use ($conn): array {
    if (!function_exists('save_site_settings_to_db')) {
        return ['saved' => false, 'reason' => 'save_site_settings_to_db unavailable'];
    }
    SiteSettingsService::saveToDb($conn, ['cron_last_run_at' => date('Y-m-d H:i:s')]);
    return ['saved' => true];
});

$criticalFailures = 0;
$allFailures = 0;
foreach ($results as $row) {
    if (empty($row['ok'])) {
        $allFailures++;
        if (!empty($row['critical'])) {
            $criticalFailures++;
        }
    }
}

$runFinishedAt = date('c');
cron_log_event($criticalFailures > 0 ? 'error' : 'info', 'cron_finish', [
    'started_at' => $runStartedAt,
    'finished_at' => $runFinishedAt,
    'duration_ms' => (int) round((microtime(true) - $runStartTs) * 1000),
    'jobs_total' => count($results),
    'jobs_failed' => $allFailures,
    'critical_jobs_failed' => $criticalFailures,
]);

cron_db_lock_release($conn, $dbLockName);
@flock($lockFp, LOCK_UN);
@fclose($lockFp);

if ($criticalFailures > 0) {
    exit(1);
}

echo "OK\n";
exit(0);
