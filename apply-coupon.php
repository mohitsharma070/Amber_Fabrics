<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

function coupon_redirect_target(string $fallback = '/cart.php'): string
{
    $target = (string) ($_POST['redirect_to'] ?? '');
    $addressId = (int) ($_POST['shipping_address_id'] ?? 0);
    if ($target === 'checkout') {
        return $addressId > 0 ? ('/checkout.php?address_id=' . $addressId) : '/checkout.php';
    }
    if ($target === 'cart') {
        return '/cart.php';
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect(coupon_redirect_target('/cart.php'));
}
if (!public_form_rate_limit_allow('coupon_apply', 15, 300)) {
    flash('error', 'Too many coupon attempts. Please wait a few minutes and try again.');
    redirect(coupon_redirect_target('/cart.php'));
}

$code = normalize_coupon_code((string) ($_POST['coupon_code'] ?? ''));

if ($code === '') {
    flash('error', 'Please enter a coupon code.');
    redirect(coupon_redirect_target('/cart.php'));
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart']) || empty($_SESSION['cart'])) {
    flash('error', 'Add items to cart before applying a coupon.');
    redirect(coupon_redirect_target('/cart.php'));
}

$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$cartMeterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];

$hydrated = cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
if (!empty($hydrated['removed_keys'])) {
    foreach ($hydrated['removed_keys'] as $badKey) {
        unset($cart[$badKey], $cartSizes[$badKey], $cartMeterMap[$badKey]);
    }
    $_SESSION['cart'] = $cart;
    $_SESSION['cart_size'] = $cartSizes;
    $_SESSION['cart_meter_length'] = $cartMeterMap;

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($customerId > 0) {
        cart_save_to_db($conn, $customerId, $cart, $cartMeterMap);
    }
}
if (empty($hydrated['items'])) {
    unset($_SESSION['applied_coupon_code']);
    flash('error', 'Your cart is empty after removing unavailable items.');
    redirect(coupon_redirect_target('/cart.php'));
}
$subtotal = cart_items_subtotal($hydrated['items']);

$selectedPayment = in_array((string) ($_SESSION['checkout_old']['payment_method'] ?? 'cod'), ['cod', 'razorpay'], true)
    ? (string) $_SESSION['checkout_old']['payment_method']
    : 'cod';
$codFeeApply = ($selectedPayment === 'cod') ? 1 : 0;

$countryForCalc = trim((string) ($_SESSION['checkout_old']['country'] ?? ''));
if ($countryForCalc === '' && !empty($_SESSION['customer_id'])) {
    $customerId = (int) $_SESSION['customer_id'];
    if ($customerId > 0) {
        $countryStmt = $conn->prepare("SELECT country FROM customers WHERE id = ? LIMIT 1");
        $countryStmt->bind_param('i', $customerId);
        $countryStmt->execute();
        $countryRow = $countryStmt->get_result()->fetch_assoc();
        $countryForCalc = trim((string) ($countryRow['country'] ?? ''));
    }
}
$isIndia = strcasecmp($countryForCalc, 'india') === 0;

$shipping = checkout_shipping_breakdown((float) $subtotal, $countryForCalc, $selectedPayment, $codFeeApply === 1);

$customerIdForCoupon = (int) ($_SESSION['customer_id'] ?? 0);
$discountInfo = get_active_coupon_discount_for_customer($conn, $code, (float) $subtotal, $customerIdForCoupon);

if (!$discountInfo['valid']) {
    unset($_SESSION['applied_coupon_code']);
    flash('error', $discountInfo['message'] ?: 'Coupon is not valid.');
    redirect(coupon_redirect_target('/cart.php'));
}

$_SESSION['applied_coupon_code'] = $discountInfo['code'];
flash('success', 'Coupon applied: ' . $discountInfo['code']);
redirect(coupon_redirect_target('/cart.php'));
