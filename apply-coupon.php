<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

function coupon_redirect_target(string $fallback = '/cart.php'): string
{
    $target = (string) ($_POST['redirect_to'] ?? '');
    if ($target === 'checkout') {
        return '/checkout.php';
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
$ids = array_map('intval', array_keys($cart));
$ids = array_values(array_filter($ids, static fn($v) => $v > 0));
$subtotal = 0.00;

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, unit_type, price, sale_price, price_inr FROM fabrics WHERE status = 'active' AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $row['unit_type']
            : 'meter';
        $qty = normalize_quantity_by_unit($cart[$pid] ?? 1, $unitType);
        $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
        $sale = (float) ($row['sale_price'] ?? 0);
        $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        $subtotal += ($unitPrice * $qty);
    }
}

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

$baseShippingAmount = 0.00;
$codFeeAmount = 0.00;
if ($isIndia) {
    $baseShippingAmount = ($selectedPayment === 'razorpay' && $subtotal >= 999) ? 0.00 : 70.00;
    $codFeeAmount = ($selectedPayment === 'cod' && $codFeeApply === 1) ? 50.00 : 0.00;
}
$preDiscountTotal = round($subtotal + $baseShippingAmount + $codFeeAmount, 2);

$customerIdForCoupon = (int) ($_SESSION['customer_id'] ?? 0);
$discountInfo = get_active_coupon_discount_for_customer($conn, $code, $preDiscountTotal, $customerIdForCoupon);

if (!$discountInfo['valid']) {
    unset($_SESSION['applied_coupon_code']);
    flash('error', $discountInfo['message'] ?: 'Coupon is not valid.');
    redirect(coupon_redirect_target('/cart.php'));
}

$_SESSION['applied_coupon_code'] = $discountInfo['code'];
flash('success', 'Coupon applied: ' . $discountInfo['code']);
redirect(coupon_redirect_target('/cart.php'));
