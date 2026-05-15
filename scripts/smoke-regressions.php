<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/coupon-functions.php';

$results = [];

function smoke_case(string $name, callable $fn, array &$results, mysqli $conn): void
{
    try {
        $conn->begin_transaction();
        $fn($conn);
        $conn->rollback();
        $results[] = ['name' => $name, 'status' => 'PASS', 'detail' => 'ok'];
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        $results[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $e->getMessage()];
    }
}

function must(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

$stamp = 'SMK' . date('YmdHis') . rand(1000, 9999);

smoke_case('COD cancel coupon release', function (mysqli $conn) use ($stamp): void {
    $email = strtolower($stamp) . '_cod@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke COD', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $code = $stamp . 'C1';
    $coupon = $conn->prepare("INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, usage_limit, used_count, status) VALUES (?, 'flat', 100, 0, 10, 1, 'active')");
    $coupon->bind_param('s', $code);
    $coupon->execute();
    $couponId = (int) $conn->insert_id;

    $orderNo = $stamp . 'COD1';
    $ord = $conn->prepare(
        "INSERT INTO orders (order_number, customer_name, customer_phone, customer_email, subtotal, shipping_amount, discount_amount, total_amount, payment_method, payment_status, order_status, customer_id, shipping_cost, total, status)
         VALUES (?, 'Smoke COD', '9999999999', ?, 1000, 0, 100, 900, 'cod', 'pending', 'pending', ?, 0, 900, 'pending')"
    );
    $ord->bind_param('ssi', $orderNo, $email, $customerId);
    $ord->execute();
    $orderId = (int) $conn->insert_id;

    $usage = $conn->prepare("INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (?, ?, ?)");
    $usage->bind_param('iii', $couponId, $customerId, $orderId);
    $usage->execute();

    $released = release_coupon_usage_for_order($conn, $orderId);
    must($released === true, 'release_coupon_usage_for_order returned false');

    $chk1 = $conn->prepare("SELECT COUNT(*) c FROM coupon_usages WHERE order_id = ?");
    $chk1->bind_param('i', $orderId);
    $chk1->execute();
    $leftUsage = (int) (($chk1->get_result()->fetch_assoc()['c'] ?? 0));
    must($leftUsage === 0, 'coupon_usages row still exists after release');

    $chk2 = $conn->prepare("SELECT used_count FROM coupons WHERE id = ?");
    $chk2->bind_param('i', $couponId);
    $chk2->execute();
    $usedCount = (int) (($chk2->get_result()->fetch_assoc()['used_count'] ?? -1));
    must($usedCount === 0, 'coupon used_count not decremented to 0');
}, $results, $conn);

smoke_case('Razorpay stale cleanup (global cron path)', function (mysqli $conn) use ($stamp): void {
    $email = strtolower($stamp) . '_rzp@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke RZP', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $fabricSku = $stamp . 'FAB';
    $fabric = $conn->prepare(
        "INSERT INTO fabrics (name, sku, category, unit_type, price, stock, stock_meters, min_order_meters, status, is_available)
         VALUES ('Smoke Fabric', ?, 'fabric-by-meter', 'meter', 100, 0, 20, 1, 'active', 1)"
    );
    $fabric->bind_param('s', $fabricSku);
    $fabric->execute();
    $fabricId = (int) $conn->insert_id;

    $orderNo = $stamp . 'RZP1';
    $ord = $conn->prepare(
        "INSERT INTO orders (order_number, customer_name, customer_phone, customer_email, subtotal, shipping_amount, discount_amount, total_amount, payment_method, payment_status, order_status, customer_id, shipping_cost, total, status)
         VALUES (?, 'Smoke RZP', '9999999999', ?, 200, 0, 0, 200, 'razorpay', 'pending', 'pending', ?, 0, 200, 'pending')"
    );
    $ord->bind_param('ssi', $orderNo, $email, $customerId);
    $ord->execute();
    $orderId = (int) $conn->insert_id;

    $item = $conn->prepare(
        "INSERT INTO order_items (order_id, product_id, product_name, unit_type, quantity, price, total, fabric_id, fabric_name_snapshot, quantity_meters, price_per_meter, line_total)
         VALUES (?, ?, 'Smoke Fabric', 'meter', 2, 100, 200, ?, 'Smoke Fabric', 2, 100, 200)"
    );
    $item->bind_param('iii', $orderId, $fabricId, $fabricId);
    $item->execute();

    $pay = $conn->prepare("INSERT INTO payments (order_id, payment_method, payment_status, amount) VALUES (?, 'razorpay', 'pending', 200)");
    $pay->bind_param('i', $orderId);
    $pay->execute();

    reserve_order_inventory($conn, $orderId);

    $setOld = $conn->prepare("UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 40 MINUTE) WHERE id = ?");
    $setOld->bind_param('i', $orderId);
    $setOld->execute();

    $released = release_stale_pending_razorpay_orders_global($conn, 30, 50);
    must($released >= 1, 'global stale cleanup did not cancel stale order');

    $ordChk = $conn->prepare("SELECT order_status, payment_status FROM orders WHERE id = ?");
    $ordChk->bind_param('i', $orderId);
    $ordChk->execute();
    $or = $ordChk->get_result()->fetch_assoc() ?: [];
    must(($or['order_status'] ?? '') === 'cancelled', 'order_status not cancelled');

    $payChk = $conn->prepare("SELECT payment_status FROM payments WHERE order_id = ? AND payment_method = 'razorpay' LIMIT 1");
    $payChk->bind_param('i', $orderId);
    $payChk->execute();
    $pr = $payChk->get_result()->fetch_assoc() ?: [];
    must(($pr['payment_status'] ?? '') === 'failed', 'payment_status not moved to failed');

    $stockChk = $conn->prepare("SELECT stock_meters FROM fabrics WHERE id = ?");
    $stockChk->bind_param('i', $fabricId);
    $stockChk->execute();
    $stock = (float) (($stockChk->get_result()->fetch_assoc()['stock_meters'] ?? -1));
    must(abs($stock - 20.0) < 0.0001, 'inventory not restored after stale cleanup');
}, $results, $conn);

smoke_case('Delivered return window logic', function (mysqli $conn) use ($stamp): void {
    $email = strtolower($stamp) . '_ret@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke RET', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $orderNo = $stamp . 'RET1';
    $ord = $conn->prepare(
        "INSERT INTO orders (order_number, customer_name, customer_phone, customer_email, subtotal, shipping_amount, discount_amount, total_amount, payment_method, payment_status, order_status, customer_id, shipping_cost, total, status)
         VALUES (?, 'Smoke RET', '9999999999', ?, 500, 0, 0, 500, 'cod', 'pending', 'delivered', ?, 0, 500, 'delivered')"
    );
    $ord->bind_param('ssi', $orderNo, $email, $customerId);
    $ord->execute();
    $orderId = (int) $conn->insert_id;

    $ship = $conn->prepare("INSERT INTO shipments (order_id, delivered_at) VALUES (?, DATE_SUB(NOW(), INTERVAL 3 DAY))");
    $ship->bind_param('i', $orderId);
    $ship->execute();

    $q = $conn->prepare(
        "SELECT o.order_status, s.delivered_at
         FROM orders o LEFT JOIN shipments s ON s.order_id = o.id
         WHERE o.id = ? AND o.customer_id = ?"
    );
    $q->bind_param('ii', $orderId, $customerId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc() ?: [];

    $statusDelivered = strtolower((string) ($row['order_status'] ?? '')) === 'delivered';
    $deliveredAt = trim((string) ($row['delivered_at'] ?? ''));
    $within7 = ($deliveredAt !== '') && strtotime($deliveredAt) >= strtotime('-7 days');
    must($statusDelivered && $within7, 'delivered return window check failed for recent delivery');
}, $results, $conn);

smoke_case('Coupon min-order uses subtotal only', function (mysqli $conn) use ($stamp): void {
    $email = strtolower($stamp) . '_cpn@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke CPN', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $code = $stamp . 'MIN1000';
    $coupon = $conn->prepare(
        "INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, usage_limit, used_count, status)
         VALUES (?, 'flat', 100, 1000, 0, 0, 'active')"
    );
    $coupon->bind_param('s', $code);
    $coupon->execute();

    $resBelow = get_active_coupon_discount_for_customer($conn, $code, 900.0, $customerId);
    must(empty($resBelow['valid']), 'coupon incorrectly valid below subtotal minimum');

    $resAt = get_active_coupon_discount_for_customer($conn, $code, 1000.0, $customerId);
    must(!empty($resAt['valid']), 'coupon should be valid at subtotal minimum');
}, $results, $conn);

smoke_case('Single-size quick-add detection', function (mysqli $conn): void {
    $single = parse_size_options('M');
    must(count($single) === 1 && $single[0] === 'M', 'parse_size_options did not detect single size');
    $hasSizeOptions = !empty(parse_size_options('M'));
    must($hasSizeOptions === true, 'single size should force select-size route, not quick-add');
}, $results, $conn);

smoke_case('Persistent wishlist DB roundtrip', function (mysqli $conn) use ($stamp): void {
    must(wishlist_table_ready($conn), 'wishlist_items table is missing');
    $email = strtolower($stamp) . '_wish@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke WIS', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $sku = $stamp . 'WSH';
    $fabric = $conn->prepare(
        "INSERT INTO fabrics (name, sku, category, unit_type, price, stock, stock_meters, min_order_meters, status, is_available)
         VALUES ('Smoke Wish Fabric', ?, 'fabric-by-meter', 'meter', 100, 0, 10, 1, 'active', 1)"
    );
    $fabric->bind_param('s', $sku);
    $fabric->execute();
    $fabricId = (int) $conn->insert_id;
    $key = $fabricId . '::' . rawurlencode('M');

    wishlist_save_to_db($conn, $customerId, [$key => 4.0], [$key => 2.0], [$key => 'M']);
    $loaded = wishlist_load_from_db_bundle($conn, $customerId);

    must(isset($loaded['wishlist'][$key]), 'wishlist key not loaded back from DB');
    must(abs((float) $loaded['wishlist'][$key] - 4.0) < 0.0001, 'wishlist quantity mismatch');
    must(isset($loaded['meter_map'][$key]) && abs((float) $loaded['meter_map'][$key] - 2.0) < 0.0001, 'wishlist meter_length mismatch');
    must((string) ($loaded['size_map'][$key] ?? '') === 'M', 'wishlist size mismatch');
}, $results, $conn);

smoke_case('Customer address book default + fetch', function (mysqli $conn) use ($stamp): void {
    must(customer_addresses_table_ready($conn), 'customer_addresses table is missing');
    $email = strtolower($stamp) . '_addr@example.com';
    $cust = $conn->prepare("INSERT INTO customers (name, email, password_hash, is_active) VALUES ('Smoke ADR', ?, 'x', 1)");
    $cust->bind_param('s', $email);
    $cust->execute();
    $customerId = (int) $conn->insert_id;

    $ins1 = $conn->prepare(
        "INSERT INTO customer_addresses (customer_id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping)
         VALUES (?, 'Home', 'User A', '9999999999', 'Street 1', 'Surat', 'GJ', '395001', 'India', 1)"
    );
    $ins1->bind_param('i', $customerId);
    $ins1->execute();
    $id1 = (int) $conn->insert_id;

    $ins2 = $conn->prepare(
        "INSERT INTO customer_addresses (customer_id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping)
         VALUES (?, 'Office', 'User B', '8888888888', 'Street 2', 'Ahmedabad', 'GJ', '380001', 'India', 0)"
    );
    $ins2->bind_param('i', $customerId);
    $ins2->execute();
    $id2 = (int) $conn->insert_id;

    $list = customer_addresses_list($conn, $customerId);
    must(count($list) === 2, 'address count mismatch');
    must((int) ($list[0]['id'] ?? 0) === $id1, 'default address should be first');

    $addr2 = customer_address_get($conn, $customerId, $id2);
    must(is_array($addr2), 'customer_address_get did not return row');
    must((string) ($addr2['city'] ?? '') === 'Ahmedabad', 'address fetch mismatch');
}, $results, $conn);

$fail = 0;
foreach ($results as $r) {
    echo '[' . $r['status'] . '] ' . $r['name'] . ' - ' . $r['detail'] . PHP_EOL;
    if ($r['status'] !== 'PASS') {
        $fail++;
    }
}
echo 'Summary: ' . (count($results) - $fail) . '/' . count($results) . ' passed' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
