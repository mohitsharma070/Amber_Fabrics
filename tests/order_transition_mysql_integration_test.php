<?php
if (PHP_SAPI !== 'cli') { exit(2); }
if (getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Order transition MySQL tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1.\n";
    exit(0);
}
if (getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('An explicitly configured loopback disposable _test/_e2e database is required.');
}
$root = dirname(__DIR__);
require $root . '/config/db.php';
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { $failures[] = $message; }
};
$processes = [];
$start = static function (int $id, string $target, string $expected = '') use ($root, &$processes, &$adminId, $argv): int {
    $command = [PHP_BINARY, $root . '/tests/helpers/order_transition_worker.php', (string) $id, $target, $expected, (string) $adminId];
    if (!empty($argv[1])) { $command[] = $argv[1]; }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Worker launch failed.'); }
    $threadId = (int) trim((string) fgets($pipes[1]));
    $processes[] = [$process, $pipes];
    if ($threadId <= 0) { throw new RuntimeException('Worker bootstrap failed.'); }
    return $threadId;
};
$wait = static function (int $threadId) use ($conn): void {
    $deadline = microtime(true) + 10;
    do {
        $rows = $conn->query('SHOW PROCESSLIST')->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            if ((int) ($row['Id'] ?? 0) !== $threadId) { continue; }
            $state = strtolower((string) ($row['State'] ?? ''));
            $query = strtolower((string) ($row['Info'] ?? ''));
            if (str_contains($state, 'lock') || str_contains($query, 'for update')) { return; }
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker did not reach a real InnoDB row-lock wait.');
};
$finish = static function () use (&$processes, $assert): array {
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode($stdout, true);
        $assert($exit === 0 && $stderr === '' && is_array($result) && $result['status'] !== 'unknown', 'Worker failed: ' . $stdout . $stderr);
        $results[] = $result ?? [];
        if (($result['status'] ?? '') === 'success') {
            $assert(($result['hook']['committed'] ?? false) === true, 'Status hook must observe committed order/activity from another connection.');
        } else {
            $assert(($result['hook'] ?? null) === null, 'Rejected transitions must not dispatch status hooks.');
        }
    }
    $processes = [];
    return $results;
};
$suffix = bin2hex(random_bytes(8));
$orderIds = [];
$adminId = $fabricId = $couponId = 0;
$makeOrder = static function (string $state, string $method = 'cod', string $payment = 'pending') use ($conn, $suffix, &$orderIds): int {
    $number = 'TRANS-' . $suffix . '-' . count($orderIds);
    $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_name, customer_phone, customer_email, order_status, payment_method, payment_status) VALUES (?, 'Test', '9876543210', 'transition@example.test', ?, ?, ?)");
    $stmt->bind_param('ssss', $number, $state, $method, $payment);
    $stmt->execute();
    $orderIds[] = $id = (int) $conn->insert_id;
    return $id;
};
$lock = static function (int $id) use ($conn): void {
    $conn->begin_transaction();
    $conn->query("SELECT id FROM orders WHERE id = $id FOR UPDATE");
};
$activity = static function (int $id) use ($conn): array {
    return array_column($conn->query("SELECT details FROM order_activity_logs WHERE order_id = $id AND action = 'admin_status_update' ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'details');
};
try {
    $conn->query("INSERT INTO admins (name, email, role, is_active) VALUES ('Transition Test', 'transition-$suffix@example.test', 'super_admin', 1)");
    $adminId = (int) $conn->insert_id;

    // Both admins contend with the same page revision; exactly one wins.
    $id = $makeOrder('pending');
    $lock($id);
    $wait($start($id, 'confirmed', 'pending'));
    $wait($start($id, 'cancelled', 'pending'));
    $conn->commit();
    $results = $finish();
    $statuses = array_column($results, 'status'); sort($statuses);
    $assert($statuses === ['error', 'success'], 'Conflicting admin requests must have exactly one winner.');
    $state = $conn->query("SELECT order_status FROM orders WHERE id = $id")->fetch_assoc()['order_status'];
    $assert($activity($id) === ['Order: pending -> ' . $state], 'Only the winning transition may record activity.');

    // A courier/status writer commits a newer status after the admin starts.
    $id = $makeOrder('confirmed');
    $lock($id);
    $wait($start($id, 'cancelled'));
    $conn->query("UPDATE orders SET order_status = 'shipped', status = 'shipped' WHERE id = $id");
    $conn->commit();
    $result = $finish()[0];
    $assert(($result['status'] ?? '') === 'error', 'Admin must reject cancellation after the competing writer ships.');
    $assert($conn->query("SELECT order_status FROM orders WHERE id = $id")->fetch_assoc()['order_status'] === 'shipped' && $activity($id) === [], 'Admin must preserve courier state without false activity.');

    // Legacy callers omit expected_status: validate/log the authoritative row.
    $id = $makeOrder('pending');
    $lock($id);
    $wait($start($id, 'packed'));
    $conn->query("UPDATE orders SET order_status = 'confirmed', status = 'confirmed' WHERE id = $id");
    $conn->commit();
    $result = $finish()[0];
    $assert(($result['status'] ?? '') === 'success' && $activity($id) === ['Order: confirmed -> packed'], 'Activity must record locked confirmed state, not stale pending state.');
    $assert(($result['hook']['previous'] ?? '') === 'confirmed', 'Hook must receive the locked previous state.');

    // Both payment status and payment method must come from the locking read.
    foreach ([['razorpay', 'paid', 'razorpay', 'failed'], ['cod', 'pending', 'upi', 'pending']] as [$method, $payment, $newMethod, $newPayment]) {
        $id = $makeOrder('confirmed', $method, $payment);
        $lock($id);
        $wait($start($id, 'shipped'));
        $conn->query("UPDATE orders SET payment_method = '$newMethod', payment_status = '$newPayment' WHERE id = $id");
        $conn->commit();
        $result = $finish()[0];
        $assert(($result['status'] ?? '') === 'error', 'Shipping must apply the locked online-payment restriction.');
        $row = $conn->query("SELECT order_status, payment_method, payment_status FROM orders WHERE id = $id")->fetch_assoc();
        $assert($row === ['order_status' => 'confirmed', 'payment_method' => $newMethod, 'payment_status' => $newPayment], 'Payment writer state must survive rejection.');
        $assert($activity($id) === [] && (int) $conn->query("SELECT COUNT(*) FROM shipments WHERE order_id = $id")->fetch_row()[0] === 0, 'Rejected shipping must not create shipment/activity records.');
    }

    // Same-state transitions remain legal, but cancellation restores only once.
    $id = $makeOrder('confirmed');
    $conn->query("INSERT INTO fabrics (name, slug, unit_type, stock) VALUES ('Transition fixture', 'transition-$suffix', 'piece', 7)");
    $fabricId = (int) $conn->insert_id;
    $conn->query("INSERT INTO order_items (order_id, product_name, fabric_id, unit_type, quantity_meters) VALUES ($id, 'Transition fixture', $fabricId, 'piece', 3)");
    $conn->query("UPDATE orders SET inventory_reserved_at = NOW() WHERE id = $id");
    $conn->query("INSERT INTO coupons (code, discount_type, discount_value, used_count) VALUES ('T$suffix', 'flat', 10, 2)");
    $couponId = (int) $conn->insert_id;
    $conn->query("INSERT INTO coupon_usages (coupon_id, order_id, guest_identity_hash) VALUES ($couponId, $id, SHA2('transition-$suffix', 256))");
    $conn->query("INSERT INTO cod_confirmations (order_id, status) VALUES ($id, 'pending')");
    $lock($id);
    $wait($start($id, 'cancelled'));
    $wait($start($id, 'cancelled'));
    $conn->commit();
    $results = $finish();
    $assert(array_column($results, 'status') === ['success', 'success'], 'Existing same-status transition semantics must remain valid.');
    $assert((int) $conn->query("SELECT stock FROM fabrics WHERE id = $fabricId")->fetch_row()[0] === 10, 'Cancellation must restore exactly three units once.');
    $assert((int) $conn->query("SELECT COUNT(*) FROM stock_ledger WHERE order_id = $id AND movement = 'release'")->fetch_row()[0] === 1, 'Cancellation must create one inventory release ledger entry.');
    $assert((int) $conn->query("SELECT used_count FROM coupons WHERE id = $couponId")->fetch_row()[0] === 1, 'Cancellation must decrement coupon capacity exactly once.');
    $assert((int) $conn->query("SELECT COUNT(*) FROM coupon_usages WHERE order_id = $id")->fetch_row()[0] === 0, 'Cancellation must release the coupon reservation.');
    $assert($conn->query("SELECT inventory_restored_at FROM orders WHERE id = $id")->fetch_row()[0] !== null, 'Cancellation must mark inventory restored.');
    $assert($conn->query("SELECT status FROM cod_confirmations WHERE order_id = $id")->fetch_row()[0] === 'cancelled', 'COD confirmation must be cancelled in the transaction.');
    $assert($activity($id) === ['Order: confirmed -> cancelled', 'Order: cancelled -> cancelled'], 'Repeated cancellation activity must reflect the locked previous state.');
} finally {
    $conn->rollback();
    foreach ($processes as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { fclose($pipe); }
        proc_close($process);
    }
    foreach ($orderIds as $id) {
        $conn->query("DELETE FROM stock_ledger WHERE order_id = $id");
        $conn->query("DELETE FROM orders WHERE id = $id");
    }
    if ($fabricId) { $conn->query("DELETE FROM fabrics WHERE id = $fabricId"); }
    if ($couponId) { $conn->query("DELETE FROM coupons WHERE id = $couponId"); }
    if ($adminId) { $conn->query("DELETE FROM admins WHERE id = $adminId"); }
}
if ($failures) {
    fwrite(STDERR, "Order transition failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Order transition MySQL concurrency tests passed.\n";
