<?php

if (PHP_SAPI !== 'cli' || (string) getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    exit(2);
}

require dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/services/AdminOtpService.php';
require_once dirname(__DIR__, 2) . '/includes/coupon-functions.php';

$action = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);
while ($startAt > microtime(true)) {
    usleep(1000);
}

try {
    if ($action === 'otp') {
        $adminId = (int) ($argv[3] ?? 0);
        $otp = (string) ($argv[4] ?? '');
        $passphrase = (string) ($argv[5] ?? '');
        $result = AdminOtpService::verify(
            $conn,
            $adminId,
            '127.0.0.1',
            $otp,
            $passphrase,
            $passphrase,
            true,
            5,
            8,
            900
        );
        echo json_encode(['status' => (string) ($result['status'] ?? 'unknown')]);
        exit;
    }
    if ($action === 'coupon') {
        $couponId = (int) ($argv[3] ?? 0);
        $orderId = (int) ($argv[4] ?? 0);
        $identityHash = (string) ($argv[5] ?? '');
        $conn->begin_transaction();
        reserve_coupon_for_order($conn, $couponId, 0, $orderId, $identityHash);
        $conn->commit();
        echo json_encode(['status' => 'reserved']);
        exit;
    }
    throw new RuntimeException('Unknown worker action.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    echo json_encode(['status' => 'failed']);
    exit(1);
}
