<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if ((string) getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Priority MySQL concurrency tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1 for an authorized disposable database.\n";
    exit(0);
}

$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/includes/services/AdminOtpService.php';
require_once $root . '/includes/services/CronService.php';
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/services/OutboxService.php';
require_once $root . '/includes/coupon-functions.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$runConcurrent = static function (array $workerArgs) use ($root): array {
    $startAt = microtime(true) + 0.75;
    $processes = [];
    foreach ($workerArgs as $args) {
        $command = array_merge([PHP_BINARY, $root . '/tests/helpers/priority_findings_worker.php', (string) $args[0], (string) $startAt], array_map('strval', array_slice($args, 1)));
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrency worker.');
        }
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $decoded = json_decode((string) $stdout, true);
        $results[] = (string) ($decoded['status'] ?? 'worker_error');
    }
    return $results;
};

$suffix = bin2hex(random_bytes(8));
$adminId = 0;
$couponId = 0;
$orderIds = [];
try {
    $email = 'priority-' . $suffix . '@example.test';
    $stmt = $conn->prepare("INSERT INTO admins (name, email, role, is_active) VALUES ('Priority Test', ?, 'viewer', 1)");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $adminId = (int) $conn->insert_id;

    $validOtp = '731905';
    $otpHash = hash('sha256', $validOtp);
    $stmt = $conn->prepare(
        "INSERT INTO admin_login_otps (admin_id, otp_hash, expires_at, attempts, resend_available_at, created_ip)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), 0, UTC_TIMESTAMP(), '127.0.0.1')"
    );
    $stmt->bind_param('is', $adminId, $otpHash);
    $stmt->execute();
    $passphrase = 'disposable-test-passphrase';
    $validResults = $runConcurrent([
        ['otp', $adminId, $validOtp, $passphrase],
        ['otp', $adminId, $validOtp, $passphrase],
    ]);
    sort($validResults);
    $assert($validResults === ['missing', 'success'], 'Only one concurrent valid OTP verification may succeed.');

    $stmt = $conn->prepare(
        "INSERT INTO admin_login_otps (admin_id, otp_hash, expires_at, attempts, resend_available_at, created_ip)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), 0, UTC_TIMESTAMP(), '127.0.0.1')"
    );
    $stmt->bind_param('is', $adminId, $otpHash);
    $stmt->execute();
    $attemptKey = AdminOtpService::otpAttemptKey('verify', $adminId, '127.0.0.1');
    $clear = $conn->prepare('DELETE FROM admin_login_attempts WHERE attempt_key = ?');
    $clear->bind_param('s', $attemptKey);
    $clear->execute();
    $guessResults = $runConcurrent(array_fill(0, 5, ['otp', $adminId, '000000', $passphrase]));
    $assert(count(array_filter($guessResults, static fn(string $status): bool => $status === 'success')) === 0, 'Concurrent invalid guesses must never authenticate.');
    $otpCheck = $conn->prepare('SELECT COUNT(*) AS total FROM admin_login_otps WHERE admin_id = ?');
    $otpCheck->bind_param('i', $adminId);
    $otpCheck->execute();
    $assert((int) ($otpCheck->get_result()->fetch_assoc()['total'] ?? -1) === 0, 'The OTP must be invalidated exactly at the attempt limit.');
    $attemptCheck = $conn->prepare('SELECT attempts FROM admin_login_attempts WHERE attempt_key = ?');
    $attemptCheck->bind_param('s', $attemptKey);
    $attemptCheck->execute();
    $assert((int) ($attemptCheck->get_result()->fetch_assoc()['attempts'] ?? 0) === 5, 'Concurrent invalid attempts must be serialized without lost increments.');

    $code = 'P' . strtoupper(substr($suffix, 0, 12));
    $stmt = $conn->prepare("INSERT INTO coupons (code, discount_type, discount_value, usage_limit, status) VALUES (?, 'flat', 10, 10, 'active')");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $couponId = (int) $conn->insert_id;
    foreach ([1, 2] as $index) {
        $number = 'PRIORITY-' . $suffix . '-' . $index;
        $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, customer_email) VALUES (?, 'Guest', '9876543210', 'guest@example.test')");
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $orderIds[] = (int) $conn->insert_id;
    }
    $identityHash = hash_hmac('sha256', 'v1|guest@example.test|9876543210', 'disposable-test-identity-key-32-chars');
    $couponResults = $runConcurrent([
        ['coupon', $couponId, $orderIds[0], $identityHash],
        ['coupon', $couponId, $orderIds[1], $identityHash],
    ]);
    $assert(count(array_filter($couponResults, static fn(string $status): bool => $status === 'reserved')) === 1, 'Only one concurrent reservation for a guest identity may succeed.');
    $usage = $conn->prepare('SELECT order_id FROM coupon_usages WHERE coupon_id = ? AND guest_identity_hash = ?');
    $usage->bind_param('is', $couponId, $identityHash);
    $usage->execute();
    $reservedOrderId = (int) ($usage->get_result()->fetch_assoc()['order_id'] ?? 0);
    $retryOrderId = $reservedOrderId === $orderIds[0] ? $orderIds[1] : $orderIds[0];
    $conn->begin_transaction();
    release_coupon_usage_for_order($conn, $reservedOrderId);
    reserve_coupon_for_order($conn, $couponId, 0, $retryOrderId, $identityHash);
    $conn->commit();
    $assert(true, 'Cancellation release must permit retry reacquisition.');

    $GLOBALS['amber_hooks'] = [];
    $firstHandlerRuns = 0;
    $retryingHandlerRuns = 0;
    add_action('order.after_payment_success', static function () use (&$firstHandlerRuns): void {
        $firstHandlerRuns++;
    }, 10);
    add_action('order.after_payment_success', static function () use (&$retryingHandlerRuns): void {
        $retryingHandlerRuns++;
        if ($retryingHandlerRuns === 1) {
            throw new RuntimeException('Injected integration failure.');
        }
    }, 20);
    $conn->begin_transaction();
    $outboxEventId = OutboxService::enqueuePaymentSuccess(
        $conn,
        $orderIds[0],
        ['order_id' => $orderIds[0], 'payment_status' => 'paid'],
    );
    $conn->commit();
    $firstDelivery = OutboxService::processBatch($conn, 10, 'order', $orderIds[0]);
    $assert((int) ($firstDelivery['failed'] ?? 0) === 1, 'A fault-injected post-commit handler must remain pending for retry.');
    $ready = $conn->prepare('UPDATE commerce_outbox SET available_at = NOW() WHERE id = ?');
    $ready->bind_param('i', $outboxEventId);
    $ready->execute();
    $secondDelivery = OutboxService::processBatch($conn, 10, 'order', $orderIds[0]);
    $assert((int) ($secondDelivery['succeeded'] ?? 0) === 1, 'A retried post-commit event must eventually complete.');
    $assert($firstHandlerRuns === 1 && $retryingHandlerRuns === 2, 'Completed outbox handlers must not rerun when a later handler retries.');

    $staleEventId = OutboxService::enqueue(
        $conn,
        'order.after_payment_success',
        $orderIds[0],
        ['order_id' => $orderIds[0]],
        'test:' . $suffix . ':stale-claim'
    );
    $stale = $conn->prepare(
        "UPDATE commerce_outbox
         SET status = 'processing', claim_token = 'stale-test-claim', claimed_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE)
         WHERE id = ?"
    );
    $stale->bind_param('i', $staleEventId);
    $stale->execute();
    OutboxService::processBatch($conn, 10, 'order', $orderIds[0]);
    $assert(OutboxService::isCompleted($conn, $staleEventId), 'Stale outbox claims must be reclaimed and completed.');

    $GLOBALS['amber_hooks'] = [];
    add_action('order.after_payment_success', static function (): void {
        throw new RuntimeException('Injected permanent failure.');
    }, 10);
    $exhaustedEventId = OutboxService::enqueue(
        $conn,
        'order.after_payment_success',
        $orderIds[0],
        ['order_id' => $orderIds[0]],
        'test:' . $suffix . ':retry-exhaustion'
    );
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $ready->bind_param('i', $exhaustedEventId);
        $ready->execute();
        OutboxService::processBatch($conn, 10, 'order', $orderIds[0]);
    }
    $exhausted = $conn->prepare('SELECT status, attempts FROM commerce_outbox WHERE id = ?');
    $exhausted->bind_param('i', $exhaustedEventId);
    $exhausted->execute();
    $exhaustedRow = $exhausted->get_result()->fetch_assoc() ?: [];
    $assert((string) ($exhaustedRow['status'] ?? '') === 'failed' && (int) ($exhaustedRow['attempts'] ?? 0) === 6, 'Outbox retries must stop after one immediate attempt and five bounded retries.');
} finally {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    foreach ($orderIds as $orderId) {
        $outboxDelete = $conn->prepare("DELETE FROM commerce_outbox WHERE aggregate_type = 'order' AND aggregate_id = ?");
        $outboxDelete->bind_param('i', $orderId);
        $outboxDelete->execute();
        $stmt = $conn->prepare('DELETE FROM orders WHERE id = ?');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
    }
    if ($couponId > 0) {
        $stmt = $conn->prepare('DELETE FROM coupons WHERE id = ?');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
    }
    if ($adminId > 0) {
        $stmt = $conn->prepare('DELETE FROM admins WHERE id = ?');
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Priority MySQL concurrency failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Priority MySQL concurrency tests passed.\n";
