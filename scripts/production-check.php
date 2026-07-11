<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$passes = [];
$failures = [];
$bootstrapOk = false;

$markPass = static function (string $message) use (&$passes): void {
    $passes[] = $message;
    fwrite(STDOUT, '[PASS] ' . $message . PHP_EOL);
};
$markFail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
};

try {
    require __DIR__ . '/../config/db.php';
    $markPass('Bootstrap loaded.');
    $bootstrapOk = true;
} catch (Throwable $e) {
    $markFail('Bootstrap/config/database initialization failed: ' . $e->getMessage());
}

if ($bootstrapOk) {
    require __DIR__ . '/../includes/functions.php';
    $markPass('Functions loaded.');
}

function value_looks_like_placeholder(string $value): bool
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return true;
    }
    $placeholders = [
        'changeme', 'change-me', 'replace-me', 'replace_this',
        'your-token', 'your_token', 'token-here', 'example', 'sample',
    ];
    if (in_array($v, $placeholders, true)) {
        return true;
    }
    return str_contains($v, 'your_') || str_contains($v, 'your-');
}

$requiredExtensions = ['curl', 'fileinfo', 'json', 'mbstring', 'mysqli', 'openssl'];
foreach ($requiredExtensions as $extension) {
    if (!extension_loaded($extension)) {
        $markFail('Missing PHP extension: ' . $extension);
    } else {
        $markPass('Extension loaded: ' . $extension);
    }
}

if (PHP_VERSION_ID < 80200) {
    $markFail('PHP 8.2+ is required.');
} else {
    $markPass('PHP version is 8.2+.');
}

$appMode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
if ($bootstrapOk) {
    if ($appMode !== 'production') {
        $markFail('APP_MODE must be production for this check. Current mode: ' . ($appMode === '' ? '(unknown)' : $appMode));
    } else {
        $markPass('APP_MODE is production.');
    }

    $appDebugRaw = strtolower(trim((string) _cfg('APP_DEBUG', '0')));
    $appDebugEnabled = in_array($appDebugRaw, ['1', 'true', 'yes', 'on'], true);
    if ($appDebugEnabled) {
        $markFail('APP_DEBUG must be disabled in production.');
    } else {
        $markPass('APP_DEBUG is disabled for production mode.');
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $markFail('Database connection object is not available.');
    } else {
        try {
            $conn->query('SELECT 1');
            $markPass('Database connectivity check passed (SELECT 1).');
        } catch (Throwable $e) {
            $markFail('Database check failed: ' . $e->getMessage());
        }
    }

    $cronToken = trim((string) _cfg('CRON_RUN_TOKEN', ''));
    if (value_looks_like_placeholder($cronToken)) {
        $markFail('CRON_RUN_TOKEN is missing or still a placeholder.');
    } elseif (strlen($cronToken) < 24) {
        $markFail('CRON_RUN_TOKEN is too short (minimum 24 characters).');
    } else {
        $markPass('CRON_RUN_TOKEN is set with acceptable length.');
    }

    $criticalCronFns = [
        'release_stale_pending_razorpay_orders_global',
        'save_site_settings_to_db',
    ];
    foreach ($criticalCronFns as $fn) {
        if (!function_exists($fn)) {
            $markFail('Cron dependency function missing: ' . $fn);
        } else {
            $markPass('Cron dependency function available: ' . $fn);
        }
    }
} else {
    $markFail('Skipping APP_MODE and database checks because bootstrap failed.');
}

if ($bootstrapOk && isset($conn) && ($conn instanceof mysqli) && $appMode === 'production') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'cron_last_run_at' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $lastRun = trim((string) ($row['setting_value'] ?? ''));
        if ($lastRun === '') {
            $markFail('Cron heartbeat missing: cron_last_run_at is empty.');
        } elseif (strtotime($lastRun) < strtotime('-15 minutes')) {
            $markFail('Cron heartbeat stale: ' . $lastRun);
        } else {
            $markPass('Cron heartbeat is fresh: ' . $lastRun);
        }
    } catch (Throwable $e) {
        $markFail('Cron heartbeat check failed: ' . $e->getMessage());
    }
} elseif ($bootstrapOk && $appMode !== 'production') {
    $markPass('Cron heartbeat freshness check skipped in local mode.');
} elseif (!$bootstrapOk) {
    $markFail('Skipping cron heartbeat check because bootstrap failed.');
}

fwrite(STDOUT, PHP_EOL . 'Production Check Summary' . PHP_EOL);
fwrite(STDOUT, 'Passed: ' . count($passes) . PHP_EOL);
fwrite(STDOUT, 'Failed: ' . count($failures) . PHP_EOL);

if (!empty($failures)) {
    fwrite(STDERR, 'Result: FAIL' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Result: PASS' . PHP_EOL);
exit(0);
