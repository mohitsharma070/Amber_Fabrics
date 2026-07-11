<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/cart.php');
}

$cartKey = trim((string) ($_POST['cart_key'] ?? ''));
$productId = 0;
if ($cartKey !== '') {
    [$productId] = CartService::cart_parse_key($cartKey);
}
$productId = $productId > 0 ? $productId : (int) ($_POST['product_id'] ?? 0);
$cartKey = $cartKey !== '' ? $cartKey : ($productId > 0 ? ($productId . '::0') : '');
if ($productId <= 0) {
    flash('error', 'Invalid cart item.');
    redirect('/cart.php');
}

if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    unset($_SESSION['cart'][$cartKey]);
}
if (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) {
    unset($_SESSION['cart_size'][$cartKey]);
}
if (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) {
    unset($_SESSION['cart_meter_length'][$cartKey]);
}

if (!empty($_SESSION['customer_id'])) {
    CartService::cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'] ?? []);
}

flash('success', 'Item removed from cart.');
redirect('/cart.php');

