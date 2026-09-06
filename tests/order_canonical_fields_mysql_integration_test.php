<?php
if (PHP_SAPI !== 'cli') { exit(2); }
if (getenv('AMBER_RUN_DISPOSABLE_MYSQL_TESTS') !== '1') {
    echo "Canonical order field MySQL tests skipped; set AMBER_RUN_DISPOSABLE_MYSQL_TESTS=1.\n";
    exit(0);
}
if (getenv('APP_MODE') !== 'local' || !preg_match('/_(test|e2e)$/', (string) getenv('DB_NAME'))
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('An explicitly configured loopback disposable _test/_e2e database is required.');
}

$root = dirname(__DIR__);
require $root . '/config/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/helpers/coupon-functions.php';
require_once $root . '/plugins/shipping-courier/modules/bigship-payloads.php';
require_once $root . '/plugins/shipping-courier/modules/shipment-lifecycle.php';
require_once $root . '/plugins/cod-guard/plugin.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};
$suffix = bin2hex(random_bytes(6));
$orderIds = [];
$customerId = 0;

$makeOrder = static function (string $number, string $method = 'razorpay') use ($conn, &$orderIds, &$customerId): int {
    $id = OrderPersistenceService::insertOrder($conn, [
        'order_number' => $number,
        'customer_name' => 'Canonical Test',
        'customer_phone' => '9876543210',
        'customer_email' => 'canonical@example.test',
        'address' => '1 Test Road',
        'city' => 'Test City',
        'state' => 'Test State',
        'pincode' => '100001',
        'country' => 'India',
        'subtotal' => 100.00,
        'shipping_amount' => 12.50,
        'discount_amount' => 7.50,
        'total_amount' => 105.00,
        'payment_method' => $method,
        'order_notes' => 'Canonical customer note',
        'shipping_address_json' => null,
        'customer_id' => $customerId,
    ]);
    $orderIds[] = $id;
    OrderPersistenceService::insertPendingPayment($conn, $id, $method, 105.00);
    return $id;
};

try {
    $email = 'canonical-' . $suffix . '@example.test';
    $customer = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Canonical Test', ?, 'test', 1)");
    $customer->bind_param('s', $email);
    $customer->execute();
    $customerId = (int) $conn->insert_id;

    $orderId = $makeOrder('CANON-' . $suffix . '-A');
    $row = $conn->query("SELECT total_amount, total, shipping_amount, shipping_cost, discount_amount, payment_status, order_status, status, order_notes, notes FROM orders WHERE id = $orderId")->fetch_assoc();
    $assert($row['total_amount'] === $row['total'], 'Creation must synchronize total from canonical total_amount.');
    $assert($row['shipping_amount'] === $row['shipping_cost'], 'Creation must synchronize shipping_cost from canonical shipping_amount.');
    $assert($row['order_status'] === $row['status'], 'Creation must synchronize legacy status.');
    $assert($row['order_notes'] === $row['notes'], 'Creation must synchronize legacy notes.');
    $assert($row['discount_amount'] === '7.50', 'Canonical discount_amount must persist.');

    $zeroId = $makeOrder('CANON-' . $suffix . '-Z', 'cod');
    OrderPersistenceService::markZeroAmountPaid($conn, $zeroId, 'cod');
    $zero = $conn->query("SELECT payment_status, order_status, status, order_notes, notes FROM orders WHERE id = $zeroId")->fetch_assoc();
    $assert($zero['payment_status'] === 'paid' && $zero['order_status'] === 'confirmed' && $zero['status'] === 'confirmed', 'Zero-amount confirmation must synchronize payment and order status.');
    $assert($zero['order_notes'] === $zero['notes'] && str_contains((string) $zero['order_notes'], 'Zero-amount order auto-confirmed'), 'Zero-amount confirmation must append canonical and compatibility notes together.');

    $conn->query("UPDATE orders SET total = 999.00, shipping_cost = 888.00, status = 'cancelled', notes = 'stale legacy note' WHERE id = $orderId");
    $list = OrderReadService::customerOrders($conn, $customerId);
    $listed = $list[0] ?? [];
    $assert(($listed['total_amount'] ?? null) === '105.00', 'Customer read model must use canonical total_amount when legacy total diverges.');
    $assert(($listed['order_status'] ?? null) === 'pending', 'Customer read model must use canonical order_status when legacy status diverges.');
    $assert(($listed['order_notes'] ?? null) === 'Canonical customer note', 'Customer read model must use canonical order_notes when legacy notes diverge.');
    $detail = OrderReadService::customerOrder($conn, $orderId, $customerId);
    $guestRead = OrderReadService::orderById($conn, $orderId);
    foreach ([$detail, $guestRead] as $canonicalRead) {
        $assert(($canonicalRead['total_amount'] ?? null) === '105.00' && ($canonicalRead['order_status'] ?? null) === 'pending', 'Detail read models must preserve canonical authority when compatibility values diverge.');
        $assert(!array_key_exists('total', $canonicalRead ?? []) && !array_key_exists('status', $canonicalRead ?? []) && !array_key_exists('notes', $canonicalRead ?? []), 'Detail read models must not expose legacy order fields.');
    }
    $adminRead = OrderReadService::adminOrder($conn, $orderId);
    $assert(($adminRead['total_amount'] ?? null) === '105.00' && ($adminRead['order_notes'] ?? null) === 'Canonical customer note', 'Admin read model must expose canonical financial and note values.');
    $assert(!array_key_exists('notes', $adminRead ?? []), 'Admin read model must not expose legacy notes.');

    PaymentService::razorpay_mark_order_failed($conn, $orderId, 'pending', 'Razorpay payment failed: test');
    $row = $conn->query("SELECT order_notes, notes FROM orders WHERE id = $orderId")->fetch_assoc();
    $assert($row['order_notes'] === $row['notes'], 'Payment failure must synchronize compatibility notes from canonical order_notes.');
    $assert(str_contains((string) $row['order_notes'], 'Canonical customer note') && str_contains((string) $row['order_notes'], 'Razorpay payment failed'), 'Payment failure must append to canonical notes without losing canonical content.');

    InventoryService::customer_cancel_order($conn, $orderId, $customerId);
    $row = $conn->query("SELECT order_status, status, order_notes, notes FROM orders WHERE id = $orderId")->fetch_assoc();
    $assert($row['order_status'] === 'cancelled' && $row['status'] === 'cancelled', 'Customer cancellation must synchronize canonical and legacy statuses.');
    $assert($row['order_notes'] === $row['notes'], 'Customer cancellation must keep notes synchronized.');

    $courierId = $makeOrder('CANON-' . $suffix . '-B', 'cod');
    $conn->query("UPDATE orders SET order_status = 'shipped', status = 'shipped' WHERE id = $courierId");
    shipping_courier_apply_bigship_order_status($conn, $courierId, 'returned');
    $row = $conn->query("SELECT order_status, status FROM orders WHERE id = $courierId")->fetch_assoc();
    $assert($row['order_status'] === 'returned', 'Courier return must persist canonical returned status.');
    $assert($row['status'] === OrderFieldCompatibilityService::legacyStatus('returned'), 'Courier return must use the centralized legacy status mapping.');

    $refundId = $makeOrder('CANON-' . $suffix . '-R', 'cod');
    $conn->query("UPDATE orders SET payment_status = 'paid', order_status = 'cancelled', status = 'cancelled' WHERE id = $refundId");
    $conn->query("UPDATE payments SET payment_status = 'paid' WHERE order_id = $refundId");
    $refundResult = PaymentService::admin_mark_order_refunded($conn, $refundId);
    $row = $conn->query("SELECT payment_status, order_status, status FROM orders WHERE id = $refundId")->fetch_assoc();
    $assert(($refundResult['ok'] ?? false) === true, 'Eligible non-gateway refund must complete.');
    $assert($row['payment_status'] === 'refunded' && $row['order_status'] === 'refunded', 'Refund must update canonical payment and order statuses.');
    $assert($row['status'] === OrderFieldCompatibilityService::legacyStatus('refunded'), 'Refund must use the centralized legacy status mapping.');

    $codId = $makeOrder('CANON-' . $suffix . '-C', 'cod');
    $conn->query("INSERT INTO cod_confirmations (order_id, status) VALUES ($codId, 'pending')");
    cod_guard_cancel_order($conn, $codId, 'COD test cancellation');
    $row = $conn->query("SELECT order_status, status, order_notes, notes FROM orders WHERE id = $codId")->fetch_assoc();
    $assert($row['order_status'] === 'cancelled' && $row['status'] === 'cancelled', 'COD cancellation must synchronize statuses.');
    $assert($row['order_notes'] === $row['notes'] && str_contains((string) $row['order_notes'], 'COD test cancellation'), 'COD cancellation must append canonical and compatibility notes together.');

    // CASE 1: Partial return completion
    $partId = $makeOrder('CANON-' . $suffix . '-P1', 'cod');
    $conn->query("UPDATE orders SET order_status = 'delivered', status = 'delivered', payment_status = 'paid' WHERE id = $partId");
    $conn->query("UPDATE payments SET payment_status = 'paid' WHERE order_id = $partId");
    $conn->query("INSERT INTO returns (order_id, customer_id, return_number, status, reason, refund_amount, requested_at) VALUES ($partId, $customerId, 'RET-P1', 'refund_initiated', 'defective', 0, NOW())");
    $retPartId = (int) $conn->insert_id;
    $conn->query("INSERT INTO return_items (return_id, order_item_id, product_name, quantity, line_total, refund_amount) VALUES ($retPartId, 0, 'Item 1', 1, 50.00, 0)");
    
    // Process real return completion via worker
    $conn->commit();
    exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tests/helpers/admin_returns_worker.php') . ' ' . $retPartId . ' refund_completed 20.00');
    $conn->begin_transaction();
    
    $row = $conn->query("SELECT payment_status, order_status, status FROM orders WHERE id = $partId")->fetch_assoc();
    $assert($row['order_status'] === 'returned', 'Partial return must map canonical order_status to returned.');
    $assert($row['status'] === OrderFieldCompatibilityService::legacyStatus('returned'), 'Partial return must sync legacy status.');
    $assert($row['payment_status'] === 'paid', 'Partial return must keep payment_status unchanged.');

    // CASE 2: Full refund completion
    $fullId = $makeOrder('CANON-' . $suffix . '-F1', 'cod');
    $conn->query("UPDATE orders SET order_status = 'delivered', status = 'delivered', payment_status = 'paid' WHERE id = $fullId");
    $conn->query("UPDATE payments SET payment_status = 'paid' WHERE order_id = $fullId");
    $conn->query("INSERT INTO returns (order_id, customer_id, return_number, status, reason, refund_amount, requested_at) VALUES ($fullId, $customerId, 'RET-F1', 'refund_initiated', 'defective', 0, NOW())");
    $retFullId = (int) $conn->insert_id;
    $conn->query("INSERT INTO return_items (return_id, order_item_id, product_name, quantity, line_total, refund_amount) VALUES ($retFullId, 0, 'Item 1', 1, 105.00, 0)");
    
    // Process real full refund via worker
    $conn->commit();
    exec(PHP_BINARY . ' ' . escapeshellarg($root . '/tests/helpers/admin_returns_worker.php') . ' ' . $retFullId . ' refund_completed 105.00');
    $conn->begin_transaction();
    
    $row = $conn->query("SELECT payment_status, order_status, status FROM orders WHERE id = $fullId")->fetch_assoc();
    $assert($row['order_status'] === 'refunded', 'Full return must map canonical order_status to refunded.');
    $assert($row['status'] === OrderFieldCompatibilityService::legacyStatus('refunded'), 'Full return must sync legacy status.');
    $assert($row['payment_status'] === 'refunded', 'Full return must update payment_status to refunded.');
} finally {
    $conn->rollback();
    foreach ($orderIds as $id) {
        $conn->query("DELETE FROM orders WHERE id = $id");
    }
    if ($customerId > 0) { $conn->query("DELETE FROM customers WHERE id = $customerId"); }
}

if ($failures) {
    fwrite(STDERR, "Canonical order field MySQL failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Canonical order field MySQL tests passed.\n";
