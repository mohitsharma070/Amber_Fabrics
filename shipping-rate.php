<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

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
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
$selectedCourierId = (int) ($_POST['courier_id'] ?? 0);
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) {
    $paymentMethod = 'cod';
}

$manual = checkout_shipping_breakdown($subtotal, 'India', $paymentMethod, $paymentMethod === 'cod');

if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    $token = shipping_quote_store(
        (float) $subtotal,
        'India',
        $pincode,
        $paymentMethod,
        (float) $manual['base_shipping'],
        (float) $manual['cod_fee'],
        (float) $manual['shipping_total'],
        'manual',
        ''
    );
    api_json([
        'ok' => true,
        'source' => 'manual',
        'quote_token' => $token,
        'base_shipping' => (float) $manual['base_shipping'],
        'cod_fee' => (float) $manual['cod_fee'],
        'shipping_total' => (float) $manual['shipping_total'],
    ]);
}

$forward = shiprocket_calculate_forward_rate(
    $subtotal,
    trim(_cfg('SHIPROCKET_PICKUP_PINCODE', '')),
    $pincode,
    $paymentMethod === 'cod'
);
if (!empty($forward['ok'])) {
    $options = is_array($forward['options'] ?? null) ? $forward['options'] : [];
    $selected = null;
    if ($selectedCourierId > 0) {
        foreach ($options as $opt) {
            if ((int) ($opt['courier_id'] ?? 0) === $selectedCourierId) {
                $selected = $opt;
                break;
            }
        }
    }
    if (!$selected) {
        $selected = [
            'courier_id' => (int) ($forward['courier_id'] ?? 0),
            'courier_name' => (string) ($forward['courier_name'] ?? ''),
            'rate' => (float) ($forward['rate'] ?? 0),
            'estimated_days' => 0,
        ];
    }
    $base = max(0.0, (float) ($selected['rate'] ?? 0));
    $codFee = $paymentMethod === 'cod' ? (float) $manual['cod_fee'] : 0.0;
    $shippingTotal = round($base + $codFee, 2);
    $token = shipping_quote_store(
        (float) $subtotal,
        'India',
        $pincode,
        $paymentMethod,
        (float) $base,
        (float) $codFee,
        (float) $shippingTotal,
        'live',
        (string) ($selected['courier_name'] ?? ''),
        (int) ($selected['courier_id'] ?? 0)
    );
    api_json([
        'ok' => true,
        'source' => 'live',
        'quote_token' => $token,
        'courier_name' => (string) ($selected['courier_name'] ?? ''),
        'courier_id' => (int) ($selected['courier_id'] ?? 0),
        'courier_options' => $options,
        'base_shipping' => $base,
        'cod_fee' => $codFee,
        'shipping_total' => $shippingTotal,
    ]);
}

 $token = shipping_quote_store(
    (float) $subtotal,
    'India',
    $pincode,
    $paymentMethod,
    (float) $manual['base_shipping'],
    (float) $manual['cod_fee'],
    (float) $manual['shipping_total'],
    'manual',
    ''
);

api_json([
    'ok' => true,
    'source' => 'manual',
    'quote_token' => $token,
    'reason' => (string) ($forward['reason'] ?? 'manual fallback'),
    'base_shipping' => (float) $manual['base_shipping'],
    'cod_fee' => (float) $manual['cod_fee'],
    'shipping_total' => (float) $manual['shipping_total'],
]);
