<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/cart.php');
}

$productId = (int) ($_POST['product_id'] ?? 0);
if ($productId <= 0) {
    flash('error', 'Invalid cart item.');
    redirect('/cart.php');
}

if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    unset($_SESSION['cart'][$productId]);
}
if (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) {
    unset($_SESSION['cart_size'][$productId]);
}

if (!empty($_SESSION['customer_id'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'] ?? []);
}

flash('success', 'Item removed from cart.');
redirect('/cart.php');

