<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

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
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}

$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
$cartSizes = isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size']) ? $_SESSION['cart_size'] : [];
$cartMeterMap = isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']) ? $_SESSION['cart_meter_length'] : [];
$hydratedCart = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
$quoteItems = is_array($hydratedCart['items'] ?? null) ? $hydratedCart['items'] : [];
$subtotal = CartService::cart_items_subtotal($quoteItems);
if ($subtotal <= 0 || $quoteItems === []) {
    api_json(['ok' => false, 'message' => 'Your cart is empty.'], 422);
}
$couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$couponInfo = get_active_coupon_discount_for_customer($conn, $couponCode, $subtotal, $customerId);
if (!$couponInfo['valid'] && $couponCode !== '') {
    unset($_SESSION['applied_coupon_code']);
}
$discountAmount = $couponInfo['valid'] ? min((float) $couponInfo['discount'], $subtotal) : 0.0;
$invoiceValue = max(0.0, round($subtotal - $discountAmount, 2));

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
    'invoice_value' => $invoiceValue,
    'country' => 'India',
    'pincode' => $pincode,
    'payment_method' => $paymentMethod,
    'items' => $quoteItems,
]);

$baseShipping = max(0.0, round((float) ($quote['base_shipping'] ?? $manual['base_shipping']), 2));
$codFee = max(0.0, round((float) ($quote['cod_fee'] ?? $manual['cod_fee']), 2));
$shippingTotal = round($baseShipping + $codFee, 2);
$source = trim((string) ($quote['source'] ?? 'manual'));
$source = $source !== '' ? substr($source, 0, 32) : 'manual';
$courierName = trim((string) ($quote['courier_name'] ?? ''));
$courierId = max(0, (int) ($quote['courier_id'] ?? 0));
$debugReason = trim((string) ($quote['debug_reason'] ?? ''));
$debugMessage = trim((string) ($quote['debug_message'] ?? ''));

$token = InventoryService::shipping_quote_store(
    (float) $invoiceValue,
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

$response = [
    'ok' => true,
    'source' => $source,
    'quote_token' => $token,
    'courier_name' => $courierName,
    'courier_id' => $courierId,
    'base_shipping' => $baseShipping,
    'cod_fee' => $codFee,
    'shipping_total' => $shippingTotal,
];

if ($debugReason !== '') {
    $response['debug_reason'] = $debugReason;
    if (($GLOBALS['_app_mode'] ?? '') === 'local' && $debugMessage !== '') {
        $response['debug_message'] = $debugMessage;
    }
}

api_json($response);
