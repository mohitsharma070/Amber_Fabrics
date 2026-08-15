<?php
require_once __DIR__ . '/includes/init.php';

if (!DeliveryEstimateService::enabled()) {
    api_json(['ok' => false, 'message' => 'Delivery estimates are unavailable.'], 503);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
if (!verify_csrf()) {
    api_json(['ok' => false, 'message' => 'Invalid session token.'], 403);
}
$rateKey = 'delivery_estimate_' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (!public_form_rate_limit_allow($rateKey, 30, 300)) {
    api_json(['ok' => false, 'message' => 'Too many requests.'], 429);
}

$productId = (int) ($_POST['product_id'] ?? 0);
$variantId = (int) ($_POST['variant_id'] ?? 0);
$quantityInput = $_POST['quantity'] ?? null;
$pincode = trim((string) ($_POST['pincode'] ?? ''));
$paymentInput = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
$payment = in_array($paymentInput, ['cod', 'razorpay'], true) ? $paymentInput : 'cod';
if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    api_json(['ok' => false, 'message' => 'Enter a valid 6-digit Indian pincode.'], 422);
}
if (!is_numeric($quantityInput) || (float) $quantityInput <= 0) {
    api_json(['ok' => false, 'message' => 'Enter a valid quantity.'], 422);
}

$q = $conn->prepare(
    "SELECT id, name, product_type, unit_type, price, sale_price, stock, stock_meters,
            min_order_meters, qty_step, is_available, dispatch_min_days, dispatch_max_days
     FROM fabrics WHERE id = ? AND status = 'active' LIMIT 1"
);
$q->bind_param('i', $productId);
$q->execute();
$product = $q->get_result()->fetch_assoc();
if (!$product || empty($product['is_available'])) {
    api_json(['ok' => false, 'message' => 'Product not found.'], 404);
}

$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type'] : 'meter';
$rawQuantity = (float) $quantityInput;
if (($unitType === 'piece' || $unitType === 'set') && abs($rawQuantity - round($rawQuantity)) > 0.0001) {
    api_json(['ok' => false, 'message' => 'Whole units are required for this product.'], 422);
}
$minimum = $unitType === 'meter'
    ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
    : (float) max(1, (int) round((float) ($product['min_order_meters'] ?? 1)));
$qty = normalize_quantity_by_unit($rawQuantity, $unitType, $minimum);
if ($rawQuantity + 0.0001 < $minimum) {
    api_json(['ok' => false, 'message' => 'Quantity is below the minimum order amount.'], 422);
}
if ($unitType === 'meter') {
    $step = is_numeric($product['qty_step'] ?? null) ? (float) $product['qty_step'] : 0.0;
    if (!meter_qty_respects_step($qty, $minimum, $step)) {
        api_json(['ok' => false, 'message' => 'Quantity does not match the allowed meter step.'], 422);
    }
}

$stock = $unitType === 'meter' ? (float) $product['stock_meters'] : (float) $product['stock'];
$requiresVariant = (($product['product_type'] ?? 'simple') === 'variable');
if (($requiresVariant && $variantId <= 0) || (!$requiresVariant && $variantId > 0)) {
    api_json(['ok' => false, 'message' => 'Select an available product variant and try again.'], 422);
}
$price = (float) (($product['sale_price'] ?? 0) > 0
    ? $product['sale_price']
    : ($product['price'] ?? 0));
if ($variantId > 0) {
    $q = $conn->prepare(
        "SELECT stock, stock_meters, price_override
         FROM fabric_variants WHERE id = ? AND fabric_id = ? AND is_active = 1 LIMIT 1"
    );
    $q->bind_param('ii', $variantId, $productId);
    $q->execute();
    $variant = $q->get_result()->fetch_assoc();
    if (!$variant) {
        api_json(['ok' => false, 'message' => 'Variant not found.'], 404);
    }
    $stock = $unitType === 'meter' ? (float) $variant['stock_meters'] : (float) $variant['stock'];
    if ((float) $variant['price_override'] > 0) {
        $price = (float) $variant['price_override'];
    }
}
if ($qty > $stock) {
    api_json(['ok' => false, 'message' => 'Requested quantity is not available.'], 422);
}

$subtotal = round($price * $qty, 2);
$manual = CartService::checkout_shipping_breakdown($subtotal, 'India', $payment, $payment === 'cod');
$quoteItem = $product;
$quoteItem['variant_id'] = $variantId;
$quoteItem['quantity'] = $qty;
if ($unitType === 'meter') {
    $quoteItem['quantity_meters'] = $qty;
}
$quote = apply_filters('shipping.quote', [
    'base_shipping' => $manual['base_shipping'],
    'cod_fee' => $manual['cod_fee'],
    'shipping_total' => $manual['shipping_total'],
    'source' => 'manual',
    'courier_name' => '',
    'courier_id' => 0,
], [
    'conn' => $conn,
    'subtotal' => $subtotal,
    'invoice_value' => $subtotal,
    'country' => 'India',
    'pincode' => $pincode,
    'payment_method' => $payment,
    'items' => [$quoteItem],
]);
$estimate = DeliveryEstimateService::calculate([$quoteItem], (string) ($quote['source'] ?? 'manual'));
$_SESSION['delivery_pincode'] = $pincode;

api_json(array_merge([
    'ok' => true,
    'payment_method' => $payment,
    'quantity' => $qty,
    'variant_id' => $variantId,
    'base_shipping' => (float) $quote['base_shipping'],
    'cod_fee' => (float) $quote['cod_fee'],
    'shipping_total' => (float) $quote['shipping_total'],
    'courier_name' => (string) ($quote['courier_name'] ?? ''),
    'cod_available' => true,
    'estimated_dispatch_label' => DeliveryEstimateService::formatRange($estimate['estimated_dispatch_start'], $estimate['estimated_dispatch_end']),
    'estimated_delivery_label' => DeliveryEstimateService::formatRange($estimate['estimated_delivery_start'], $estimate['estimated_delivery_end']),
], $estimate));
