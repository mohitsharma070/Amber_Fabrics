<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/helpers/coupon-functions.php';

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
if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    api_json(['ok' => false, 'message' => 'Please enter a valid 6-digit Indian pincode.'], 422);
}
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}

$cart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
$cartSizes = isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size']) ? $_SESSION['cart_size'] : [];
$cartMeterMap = isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length']) ? $_SESSION['cart_meter_length'] : [];
$hydratedCart = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
if (!empty($hydratedCart['quantity_updates']) || !empty($hydratedCart['removed_keys'])) {
    flash('info', 'Your cart changed because stock availability was updated. Checkout totals have been refreshed.');
    api_json([
        'ok' => false,
        'code' => 'cart_changed',
        'message' => 'Your cart changed. Reload checkout before requesting another shipping quote.',
        'reload' => true,
    ], 409);
}
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
$quote = [
    'base_shipping' => (float) $manual['base_shipping'],
    'cod_fee' => (float) $manual['cod_fee'],
    'shipping_total' => (float) $manual['shipping_total'],
    'source' => 'manual',
    'courier_name' => '',
    'courier_id' => 0,
];
try {
    $quote = apply_filters('shipping.quote', $quote, [
        'conn' => $conn,
        'subtotal' => $subtotal,
        'invoice_value' => $invoiceValue,
        'country' => 'India',
        'pincode' => $pincode,
        'payment_method' => $paymentMethod,
        'items' => $quoteItems,
        'storefront_rate_request' => true,
    ], true);
} catch (Throwable $e) {
    app_log('error', 'shipping_quote_failed', [
        'exception_type' => get_class($e),
        'payment_method' => $paymentMethod,
    ]);
    $quote['debug_reason'] = 'bigship_rate_api_failed';
}

$baseShipping = max(0.0, round((float) ($quote['base_shipping'] ?? $manual['base_shipping']), 2));
$codFee = max(0.0, round((float) ($quote['cod_fee'] ?? $manual['cod_fee']), 2));
$shippingTotal = round($baseShipping + $codFee, 2);
$source = trim((string) ($quote['source'] ?? 'manual'));
$source = $source !== '' ? substr($source, 0, 32) : 'manual';
$courierName = trim((string) ($quote['courier_name'] ?? ''));
$courierId = max(0, (int) ($quote['courier_id'] ?? 0));
$debugReason = trim((string) ($quote['debug_reason'] ?? ''));
$estimate = DeliveryEstimateService::calculate($quoteItems, $source);
log_ecommerce_event($conn,'add_shipping_info',$customerId>0?$customerId:null,null,null,null,null,$shippingTotal,['session_type'=>$customerId>0?'customer':'guest','source'=>$source,'payment_method'=>$paymentMethod]);

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
    $courierId,
    $estimate
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
    'serviceability_status' => $estimate['serviceability_status'],
    'estimated_dispatch_start' => $estimate['estimated_dispatch_start'],
    'estimated_dispatch_end' => $estimate['estimated_dispatch_end'],
    'estimated_delivery_start' => $estimate['estimated_delivery_start'],
    'estimated_delivery_end' => $estimate['estimated_delivery_end'],
    'estimated_delivery_label' => DeliveryEstimateService::formatRange($estimate['estimated_delivery_start'], $estimate['estimated_delivery_end']),
];

if ($debugReason !== '') {
    $response['debug_reason'] = $debugReason;
}

api_json($response);
