<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Commerce probe is CLI-only.\n");
    exit(1);
}

$orderNumber = trim((string) ($argv[1] ?? ''));
$email = trim(strtolower((string) ($argv[2] ?? '')));
if ($orderNumber === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Order number and valid email are required.\n");
    exit(1);
}

$confirmation = (string) (getenv('E2E_FIXTURE_CONFIRM') ?: '');
$preflightErrors = e2e_fixture_policy_errors(
    (string) (getenv('APP_MODE') ?: ''),
    $confirmation,
    (string) (getenv('APP_ENV') ?: ''),
    'pending_e2e'
);
if ($preflightErrors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $preflightErrors) . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';
$databaseName = (string) (($conn->query('SELECT DATABASE() AS database_name')->fetch_assoc()['database_name'] ?? ''));
$policyErrors = e2e_fixture_policy_errors(
    (string) ($GLOBALS['_app_mode'] ?? ''),
    $confirmation,
    (string) ($GLOBALS['_app_config']['APP_ENV'] ?? ''),
    $databaseName
);
if ($policyErrors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $policyErrors) . PHP_EOL);
    exit(1);
}

if ($orderNumber === '-') {
    $orderStmt = $conn->prepare(
        "SELECT id, order_number, customer_email, payment_method, payment_status, order_status,
                coupon_code, coupon_discount, shipping_quote_token, shipping_source,
                inventory_reserved_at, subtotal, shipping_amount, total_amount
         FROM orders
         WHERE LOWER(customer_email) = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $orderStmt->execute([$email]);
} else {
    $orderStmt = $conn->prepare(
        "SELECT id, order_number, customer_email, payment_method, payment_status, order_status,
                coupon_code, coupon_discount, shipping_quote_token, shipping_source,
                inventory_reserved_at, subtotal, shipping_amount, total_amount
         FROM orders
         WHERE order_number = ? AND LOWER(customer_email) = ?
         LIMIT 1"
    );
    $orderStmt->execute([$orderNumber, $email]);
}
$order = $orderStmt->get_result()->fetch_assoc();
if (!$order) {
    fwrite(STDERR, "Expected E2E order was not found.\n");
    exit(1);
}

$orderId = (int) $order['id'];
$quoteStmt = $conn->prepare('SELECT subtotal, base_shipping, cod_fee, shipping_total, source, serviceability_status,
    estimated_delivery_start, estimated_delivery_end FROM shipping_quotes WHERE quote_token = ?');
$quoteStmt->execute([(string) $order['shipping_quote_token']]);
$quote = $quoteStmt->get_result()->fetch_assoc() ?: [];
$itemsStmt = $conn->prepare(
    "SELECT f.product_code, oi.variant_id, oi.unit_type, oi.quantity_meters,
            oi.bundle_quantity, oi.meter_length
     FROM order_items oi
     JOIN fabrics f ON f.id = oi.fabric_id
     WHERE oi.order_id = ?
     ORDER BY f.product_code"
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$paymentStmt = $conn->prepare('SELECT payment_method, payment_status, razorpay_order_id FROM payments WHERE order_id = ?');
$paymentStmt->execute([$orderId]);
$payment = $paymentStmt->get_result()->fetch_assoc() ?: [];

$couponStmt = $conn->prepare('SELECT COUNT(*) AS reservation_count FROM coupon_usages WHERE order_id = ?');
$couponStmt->execute([$orderId]);
$couponReservations = (int) (($couponStmt->get_result()->fetch_assoc()['reservation_count'] ?? 0));

$duplicateStmt = $conn->prepare('SELECT COUNT(*) AS order_count FROM orders WHERE LOWER(customer_email) = ?');
$duplicateStmt->execute([$email]);
$ordersForEmail = (int) (($duplicateStmt->get_result()->fetch_assoc()['order_count'] ?? 0));

echo json_encode([
    'order' => $order,
    'quote' => $quote,
    'items' => $items,
    'payment' => $payment,
    'coupon_reservations' => $couponReservations,
    'orders_for_email' => $ordersForEmail,
], JSON_THROW_ON_ERROR) . PHP_EOL;
