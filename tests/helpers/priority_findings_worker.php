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
    if ($action === 'order_transition') {
        $orderId = (int) ($argv[3] ?? 0);
        $targetStatus = (string) ($argv[4] ?? '');
        $expectedStatus = (string) ($argv[5] ?? '');
        $adminId = (int) ($argv[6] ?? 1);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/admin/order-view.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'test-agent';

        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_name'] = 'Worker Admin';
        $_SESSION['admin_role'] = 'super_admin';
        $_SESSION['admin_session_started_at'] = time();
        $_SESSION['admin_last_seen_at'] = time();
        require_once dirname(__DIR__, 2) . '/includes/helpers/admin.php';
        $_SESSION['admin_session_fingerprint'] = admin_session_fingerprint();
        $_SESSION['csrf_token'] = 'test-csrf';

        $_POST = [
            'action' => 'workflow_transition',
            'target_status' => $targetStatus,
            'expected_status' => $expectedStatus,
            'csrf_token' => 'test-csrf',
        ];

        $_GET['id'] = $orderId;

        register_shutdown_function(static function () {
            $flash = $_SESSION['flash_messages'] ?? [];
            $error = $flash['error'][0] ?? null;
            $success = $flash['success'][0] ?? null;
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            if ($success) {
                echo json_encode(['status' => 'success']);
            } else if ($error) {
                echo json_encode(['status' => 'error', 'message' => $error]);
            } else {
                echo json_encode(['status' => 'unknown']);
            }
        });

        ob_start();
        try {
            require dirname(__DIR__, 2) . '/admin/order-view.php';
        } catch (Throwable $e) {
            // caught
        }
        exit;
    }

    throw new RuntimeException('Unknown worker action.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    echo json_encode(['status' => 'failed', 'error' => $e->getMessage()]);
    exit(1);
}
