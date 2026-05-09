<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/cart.php');
}
if (!public_form_rate_limit_allow('coupon_apply', 15, 300)) {
    flash('error', 'Too many coupon attempts. Please wait a few minutes and try again.');
    redirect('/cart.php');
}

$code = normalize_coupon_code((string) ($_POST['coupon_code'] ?? ''));

if ($code === '') {
    flash('error', 'Please enter a coupon code.');
    redirect('/cart.php');
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart']) || empty($_SESSION['cart'])) {
    flash('error', 'Add items to cart before applying a coupon.');
    redirect('/cart.php');
}

$cart = $_SESSION['cart'];
$ids = array_map('intval', array_keys($cart));
$ids = array_values(array_filter($ids, static fn($v) => $v > 0));
$subtotal = 0.00;

if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, price, sale_price, price_inr FROM fabrics WHERE status = 'active' AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $qty = max(1, (int) ($cart[$pid] ?? 1));
        $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
        $sale = (float) ($row['sale_price'] ?? 0);
        $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        $subtotal += ($unitPrice * $qty);
    }
}

$discountInfo = get_active_coupon_discount($conn, $code, $subtotal);

if (!$discountInfo['valid']) {
    unset($_SESSION['applied_coupon_code']);
    flash('error', $discountInfo['message'] ?: 'Coupon is not valid.');
    redirect('/cart.php');
}

$_SESSION['applied_coupon_code'] = $discountInfo['code'];
flash('success', 'Coupon applied: ' . $discountInfo['code']);
redirect('/cart.php');
