<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$passes = [];
$warnings = [];
$failures = [];

$markPass = static function (string $message) use (&$passes): void {
    $passes[] = $message;
    fwrite(STDOUT, '[PASS] ' . $message . PHP_EOL);
};
$markWarn = static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
    fwrite(STDOUT, '[WARN] ' . $message . PHP_EOL);
};
$markFail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
};

function token_looks_placeholder(string $value): bool
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return true;
    }
    foreach (['replace-with', 'changeme', 'your-', 'your_', 'example', 'sample'] as $needle) {
        if (str_contains($v, $needle)) {
            return true;
        }
    }
    return false;
}

try {
    require __DIR__ . '/../config/db.php';
    require __DIR__ . '/../includes/functions.php';
    $markPass('Bootstrap loaded.');
} catch (Throwable $e) {
    $markFail('Bootstrap failed: ' . $e->getMessage());
    exit(1);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $markFail('Database connection object not available.');
    exit(1);
}

try {
    $conn->query('SELECT 1');
    $markPass('Database connectivity check passed.');
} catch (Throwable $e) {
    $markFail('Database connectivity failed: ' . $e->getMessage());
}

$mode = strtolower((string) ($GLOBALS['_app_mode'] ?? ''));
$appDebugRaw = strtolower(trim((string) _cfg('APP_DEBUG', '0')));
$debugEnabled = in_array($appDebugRaw, ['1', 'true', 'yes', 'on'], true);
$isProduction = $mode === 'production';

if ($isProduction && $debugEnabled) {
    $markFail('APP_DEBUG is enabled in production mode.');
} elseif ($isProduction) {
    $markPass('Production mode with debug disabled.');
} else {
    $markPass('Local mode detected.');
}

$cronToken = trim((string) _cfg('CRON_RUN_TOKEN', ''));
if (token_looks_placeholder($cronToken)) {
    $markWarn('CRON_RUN_TOKEN is missing or placeholder-like.');
} elseif (strlen($cronToken) < 24) {
    $markWarn('CRON_RUN_TOKEN is short (recommended >= 24 chars).');
} else {
    $markPass('CRON_RUN_TOKEN quality looks acceptable.');
}

try {
    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'cron_last_run_at' LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $lastRun = trim((string) ($row['setting_value'] ?? ''));
    if ($lastRun === '') {
        $isProduction ? $markFail('Cron heartbeat missing (cron_last_run_at empty).') : $markWarn('Cron heartbeat missing in local DB.');
    } else {
        $staleSeconds = time() - (int) strtotime($lastRun);
        if ($staleSeconds > 900) {
            $isProduction
                ? $markFail('Cron heartbeat stale (>15m): ' . $lastRun)
                : $markWarn('Cron heartbeat stale in local mode: ' . $lastRun);
        } else {
            $markPass('Cron heartbeat is fresh: ' . $lastRun);
        }
    }
} catch (Throwable $e) {
    $markFail('Cron heartbeat check failed: ' . $e->getMessage());
}

try {
    $staleSql = "SELECT COUNT(*) AS total
                 FROM orders
                 WHERE order_status = 'pending'
                   AND payment_status = 'pending'
                   AND payment_method IN ('razorpay', 'upi')
                   AND created_at < (NOW() - INTERVAL 30 MINUTE)";
    $stale = (int) (($conn->query($staleSql)->fetch_assoc()['total'] ?? 0));
    if ($stale > 0) {
        $markWarn("Stale pending online orders found: {$stale} (cron cleanup should handle).");
    } else {
        $markPass('No stale pending online orders found.');
    }
} catch (Throwable $e) {
    $markFail('Stale-order check failed: ' . $e->getMessage());
}

try {
    $refundSql = "SELECT COUNT(*) AS total
                  FROM orders
                  WHERE order_status = 'cancelled'
                    AND payment_status = 'paid'";
    $refundQueue = (int) (($conn->query($refundSql)->fetch_assoc()['total'] ?? 0));
    if ($refundQueue > 0) {
        $markWarn("Refund queue items (cancelled+paid): {$refundQueue}");
    } else {
        $markPass('Refund queue is clear.');
    }
} catch (Throwable $e) {
    $markFail('Refund-queue check failed: ' . $e->getMessage());
}

try {
    $pieceThreshold = max(0.0, (float) _cfg('INVENTORY_ALERT_PIECE_THRESHOLD', '5'));
    $meterThreshold = max(0.0, (float) _cfg('INVENTORY_ALERT_METER_THRESHOLD', '10'));

    $pieceSql = "SELECT COUNT(*) AS total
                 FROM fabrics
                 WHERE status = 'active'
                   AND is_available = 1
                   AND unit_type IN ('piece', 'set')
                   AND COALESCE(stock, 0) > 0
                   AND COALESCE(stock, 0) <= ?";
    $pieceStmt = $conn->prepare($pieceSql);
    $pieceStmt->bind_param('d', $pieceThreshold);
    $pieceStmt->execute();
    $pieceLow = (int) (($pieceStmt->get_result()->fetch_assoc()['total'] ?? 0));

    $meterSql = "SELECT COUNT(*) AS total
                 FROM fabrics
                 WHERE status = 'active'
                   AND is_available = 1
                   AND unit_type = 'meter'
                   AND COALESCE(stock_meters, 0) > 0
                   AND COALESCE(stock_meters, 0) <= ?";
    $meterStmt = $conn->prepare($meterSql);
    $meterStmt->bind_param('d', $meterThreshold);
    $meterStmt->execute();
    $meterLow = (int) (($meterStmt->get_result()->fetch_assoc()['total'] ?? 0));

    if ($pieceLow + $meterLow > 0) {
        $markWarn("Low-stock products: piece/set={$pieceLow}, meter={$meterLow}");
    } else {
        $markPass('No low-stock products under configured thresholds.');
    }
} catch (Throwable $e) {
    $markFail('Low-stock check failed: ' . $e->getMessage());
}

fwrite(STDOUT, PHP_EOL . 'Ops Health Summary' . PHP_EOL);
fwrite(STDOUT, 'Passed: ' . count($passes) . PHP_EOL);
fwrite(STDOUT, 'Warnings: ' . count($warnings) . PHP_EOL);
fwrite(STDOUT, 'Failed: ' . count($failures) . PHP_EOL);

if (!empty($failures)) {
    fwrite(STDERR, 'Result: FAIL' . PHP_EOL);
    exit(1);
}

if (!empty($warnings)) {
    fwrite(STDOUT, 'Result: WARN' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, 'Result: PASS' . PHP_EOL);
exit(0);
