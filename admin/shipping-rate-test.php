<?php
/**
 * Admin-only live courier quote checker.
 * This intentionally does not persist a checkout shipping quote.
 */
require_once __DIR__ . '/../includes/init.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!verify_csrf()) {
    api_json(['ok' => false, 'message' => 'Invalid session token.'], 403);
}

$pincode = trim((string) ($_POST['pincode'] ?? ''));
$subtotal = max(0.0, (float) ($_POST['subtotal'] ?? 0));
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'razorpay')));
if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    api_json(['ok' => false, 'message' => 'Enter a valid 6-digit delivery pincode.'], 422);
}
if ($subtotal <= 0) {
    api_json(['ok' => false, 'message' => 'Enter an order subtotal greater than zero.'], 422);
}
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    api_json(['ok' => false, 'message' => 'Choose COD or prepaid.'], 422);
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
    'admin_debug' => true,
]);

$source = trim((string) ($quote['source'] ?? 'manual'));
api_json([
    'ok' => true,
    'live' => $source !== '' && strtolower($source) !== 'manual',
    'source' => $source !== '' ? $source : 'manual',
    'courier_name' => trim((string) ($quote['courier_name'] ?? '')),
    'courier_id' => max(0, (int) ($quote['courier_id'] ?? 0)),
    'base_shipping' => max(0.0, round((float) ($quote['base_shipping'] ?? 0), 2)),
    'cod_fee' => max(0.0, round((float) ($quote['cod_fee'] ?? 0), 2)),
    'shipping_total' => max(0.0, round((float) ($quote['shipping_total'] ?? 0), 2)),
    'debug_reason' => trim((string) ($quote['debug_reason'] ?? '')),
    'debug_message' => trim((string) ($quote['debug_message'] ?? '')),
]);
