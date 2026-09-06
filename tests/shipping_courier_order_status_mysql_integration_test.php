<?php

if (PHP_SAPI !== 'cli') { exit(2); }
if ((string) getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Courier order-status MySQL tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1.\n";
    exit(0);
}
if (getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('An explicitly configured loopback disposable _test/_e2e database is required.');
}

define('AMBER_TESTING', true);
$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/plugin-loader.php';
require_once $root . '/plugins/shipping-courier/plugin.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};
$suffix = bin2hex(random_bytes(6));
$orderIds = [];
$process = null;
$pipes = [];

$makeOrder = static function (string $status) use ($conn, $suffix, &$orderIds): int {
    $number = 'COURIER-STATUS-' . $suffix . '-' . count($orderIds);
    $legacyStatus = OrderFieldCompatibilityService::legacyStatus($status);
    $stmt = $conn->prepare(
        "INSERT INTO orders
            (order_number, customer_name, customer_phone, customer_email, address, city, state, pincode, country,
             subtotal, total_amount, total, payment_method, payment_status, order_status, status)
         VALUES (?, 'Courier Status Test', '9876543210', 'courier-status@example.test', '1 Test Road',
                 'Test City', 'Test State', '100001', 'India', 100, 100, 100, 'cod', 'paid', ?, ?)"
    );
    $stmt->bind_param('sss', $number, $status, $legacyStatus);
    $stmt->execute();
    $orderIds[] = $id = (int) $conn->insert_id;
    return $id;
};
$setStatus = static function (int $orderId, string $status) use ($conn): void {
    $legacyStatus = OrderFieldCompatibilityService::legacyStatus($status);
    $stmt = $conn->prepare('UPDATE orders SET order_status = ?, status = ? WHERE id = ?');
    $stmt->bind_param('ssi', $status, $legacyStatus, $orderId);
    $stmt->execute();
};
$readStatus = static function (int $orderId) use ($conn): array {
    return $conn->query("SELECT order_status, status FROM orders WHERE id = $orderId")->fetch_assoc() ?: [];
};

try {
    $orderId = $makeOrder('shipped');
    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'delivered') === 'delivered', 'shipped -> delivered must be applied.');
    $assert($readStatus($orderId) === ['order_status' => 'delivered', 'status' => 'delivered'], 'Successful delivery must synchronize canonical and legacy status.');

    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'rto') === 'returned', 'delivered -> returned must be applied when allowed by OrderLifecycle.');
    $assert($readStatus($orderId) === ['order_status' => 'returned', 'status' => 'processing'], 'Successful return must use the centralized legacy mapping.');

    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'in_transit') === '', 'returned -> shipped must be rejected instead of returning the proposal.');
    $assert($readStatus($orderId) === ['order_status' => 'returned', 'status' => 'processing'], 'Rejected returned -> shipped must not change either status field.');
    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'delivered') === '', 'returned -> delivered must be rejected.');

    $setStatus($orderId, 'delivered');
    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'in_transit') === '', 'delivered -> shipped must be rejected.');
    $assert($readStatus($orderId) === ['order_status' => 'delivered', 'status' => 'delivered'], 'Rejected delivered -> shipped must preserve the delivered state.');

    $setStatus($orderId, 'refunded');
    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'delivered') === '', 'A refunded order must reject courier status changes.');
    $assert($readStatus($orderId) === ['order_status' => 'refunded', 'status' => 'cancelled'], 'A rejected refunded transition must preserve compatibility status.');

    $setStatus($orderId, 'shipped');
    $conn->query("UPDATE orders SET status = 'processing' WHERE id = $orderId");
    $assert(shipping_courier_apply_bigship_order_status($conn, $orderId, 'in_transit') === 'shipped', 'A same-state courier update must retain its applied-status result.');
    $assert($readStatus($orderId) === ['order_status' => 'shipped', 'status' => 'shipped'], 'A same-state update must repair the legacy compatibility field.');

    $progressId = $makeOrder('confirmed');
    $assert(shipping_courier_apply_bigship_order_status($conn, $progressId, 'picked_up') === 'shipped', 'confirmed -> shipped must remain supported.');

    $outerTransactionId = $makeOrder('shipped');
    $conn->begin_transaction();
    $assert(shipping_courier_apply_bigship_order_status($conn, $outerTransactionId, 'delivered') === 'delivered', 'A caller-owned transaction must still apply an allowed transition.');
    $observer = new mysqli(
        (string) getenv('DB_HOST'),
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        (string) getenv('DB_NAME'),
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $outsideStatus = $observer->query("SELECT order_status FROM orders WHERE id = $outerTransactionId")->fetch_assoc()['order_status'] ?? '';
    $observer->close();
    $assert($outsideStatus === 'shipped', 'The courier helper must not commit a caller-owned transaction.');
    $conn->rollback();
    $assert($readStatus($outerTransactionId) === ['order_status' => 'shipped', 'status' => 'shipped'], 'Rolling back the caller-owned transaction must roll back the courier transition.');

    // Real race: an admin-style locked transition commits delivered while the real
    // tracking-sync writer waits to apply stale in_transit -> shipped information.
    $raceId = $makeOrder('shipped');
    $shipment = shipping_courier_upsert_shipment($conn, $raceId, ['tracking_id' => 'TRACK-' . $suffix]);
    $shipmentId = (int) ($shipment['id'] ?? 0);
    shipping_courier_upsert_metadata($conn, $raceId, $shipmentId, [
        'provider' => 'bigship',
        'provider_order_id' => 'BIG-STATUS-' . $suffix,
        'provider_shipment_id' => 'BIG-STATUS-SHIPMENT',
        'provider_status' => 'label_generated',
    ]);

    $conn->begin_transaction();
    $locked = $conn->query("SELECT order_status FROM orders WHERE id = $raceId FOR UPDATE")->fetch_assoc() ?: [];
    $assert(($locked['order_status'] ?? '') === 'shipped', 'Writer A must lock the authoritative shipped state.');
    $assert(OrderLifecycle::canTransition((string) ($locked['order_status'] ?? ''), 'delivered'), 'Writer A fixture must use the canonical lifecycle policy.');

    $command = [PHP_BINARY, $root . '/tests/helpers/shipping_courier_order_status_worker.php', (string) $raceId];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Courier status worker launch failed.'); }
    $threadId = (int) trim((string) fgets($pipes[1]));
    if ($threadId <= 0) { throw new RuntimeException('Courier status worker bootstrap failed.'); }

    $waiting = false;
    $deadline = microtime(true) + 10;
    do {
        foreach ($conn->query('SHOW PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $row) {
            if ((int) ($row['Id'] ?? 0) !== $threadId) { continue; }
            $state = strtolower((string) ($row['State'] ?? ''));
            $query = strtolower((string) ($row['Info'] ?? ''));
            if (str_contains($state, 'lock') || str_contains($query, 'for update')) {
                $waiting = true;
                break 2;
            }
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    $assert($waiting, 'The real courier writer must wait on Writer A\'s InnoDB order-row lock.');

    $setStatus($raceId, 'delivered');
    $conn->commit();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $process = null;
    $workerResult = json_decode(trim($stdout), true);
    $assert($exit === 0 && $stderr === '' && is_array($workerResult), 'The real courier tracking worker must complete after Writer A commits: ' . $stdout . $stderr);
    $assert(($workerResult['order_status'] ?? null) === '', 'Tracking sync must return an empty applied status when stale courier state is rejected.');
    $assert($readStatus($raceId) === ['order_status' => 'delivered', 'status' => 'delivered'], 'The stale courier writer must preserve Writer A\'s delivered state and legacy mirror.');

    $activities = $conn->query(
        "SELECT details FROM order_activity_logs
         WHERE order_id = $raceId AND action = 'shipping_courier_tracking_synced'
         ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    $details = implode("\n", array_column($activities, 'details'));
    $assert(!str_contains($details, 'Order status: shipped'), 'Tracking activity must not claim that rejected shipped status was applied.');
} finally {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_close($process);
    }
    foreach ($orderIds as $id) {
        $conn->query("DELETE FROM order_activity_logs WHERE order_id = $id");
        $conn->query("DELETE FROM orders WHERE id = $id");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Courier order-status MySQL failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Courier order-status MySQL concurrency tests passed.\n";
