<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

header('Content-Type: application/json; charset=utf-8');

$pincode = trim((string) ($_GET['pincode'] ?? ''));
$subtotal = (float) ($_GET['subtotal'] ?? 0);
$paymentMethod = strtolower(trim((string) ($_GET['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}

$manual = checkout_shipping_breakdown($subtotal, 'India', $paymentMethod, $paymentMethod === 'cod');

if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    echo json_encode([
        'ok' => true,
        'source' => 'manual',
        'base_shipping' => (float) $manual['base_shipping'],
        'cod_fee' => (float) $manual['cod_fee'],
        'shipping_total' => (float) $manual['shipping_total'],
    ]);
    exit;
}

$forward = shiprocket_calculate_forward_rate(
    $subtotal,
    trim(_cfg('SHIPROCKET_PICKUP_PINCODE', '')),
    $pincode,
    $paymentMethod === 'cod'
);
if (!empty($forward['ok'])) {
    $base = max(0.0, (float) ($forward['rate'] ?? 0));
    $codFee = $paymentMethod === 'cod' ? (float) $manual['cod_fee'] : 0.0;
    echo json_encode([
        'ok' => true,
        'source' => 'live',
        'courier_name' => (string) ($forward['courier_name'] ?? ''),
        'base_shipping' => $base,
        'cod_fee' => $codFee,
        'shipping_total' => round($base + $codFee, 2),
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'source' => 'manual',
    'reason' => (string) ($forward['reason'] ?? 'manual fallback'),
    'base_shipping' => (float) $manual['base_shipping'],
    'cod_fee' => (float) $manual['cod_fee'],
    'shipping_total' => (float) $manual['shipping_total'],
]);
