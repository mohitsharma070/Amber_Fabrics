<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!verify_csrf()) {
    api_json(['ok' => false, 'message' => 'Invalid session token.'], 403);
}
if (!public_form_rate_limit_allow('shipping_quote', 30, 300)) {
    api_json(['ok' => false, 'message' => 'Too many shipping quote requests.'], 429);
}

$pincode = trim((string) ($_POST['pincode'] ?? ''));
$subtotal = (float) ($_POST['subtotal'] ?? 0);
$country = strtolower(trim((string) ($_POST['country'] ?? 'india')));
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}
if ($country !== 'india') {
    api_json(['ok' => false, 'message' => 'Shipping quotes are currently available only for India.'], 422);
}
if (!preg_match('/^[1-9][0-9]{5}$/', $pincode) || $subtotal < 0) {
    api_json(['ok' => false, 'message' => 'Invalid shipping quote details.'], 422);
}

$manual = CartService::checkout_shipping_breakdown($subtotal, 'India', $paymentMethod, $paymentMethod === 'cod');
$quote = apply_filters('shipping.quote', [
    'base_shipping' => (float) $manual['base_shipping'],
    'cod_fee' => (float) $manual['cod_fee'],
    'shipping_total' => (float) $manual['shipping_total'],
    'source' => 'manual',
    'courier_name' => '',
    'courier_id' => 0,
], [
    'conn' => $conn,
    'subtotal' => $subtotal,
    'country' => 'India',
    'pincode' => $pincode,
    'payment_method' => $paymentMethod,
]);

$baseShipping = max(0.0, round((float) ($quote['base_shipping'] ?? $manual['base_shipping']), 2));
$codFee = max(0.0, round((float) ($quote['cod_fee'] ?? $manual['cod_fee']), 2));
$shippingTotal = round($baseShipping + $codFee, 2);
$source = trim((string) ($quote['source'] ?? 'manual'));
$source = $source !== '' ? substr($source, 0, 32) : 'manual';
$courierName = trim((string) ($quote['courier_name'] ?? ''));
$courierId = max(0, (int) ($quote['courier_id'] ?? 0));

$token = InventoryService::shipping_quote_store(
    (float) $subtotal,
    'India',
    $pincode,
    $paymentMethod,
    $baseShipping,
    $codFee,
    $shippingTotal,
    $source,
    $courierName,
    $courierId
);

api_json([
    'ok' => true,
    'source' => $source,
    'quote_token' => $token,
    'courier_name' => $courierName,
    'courier_id' => $courierId,
    'base_shipping' => $baseShipping,
    'cod_fee' => $codFee,
    'shipping_total' => $shippingTotal,
    'quote_for' => [
        'pincode' => $pincode,
        'payment_method' => $paymentMethod,
        'subtotal' => round($subtotal, 2),
        'country' => 'india',
    ],
]);
