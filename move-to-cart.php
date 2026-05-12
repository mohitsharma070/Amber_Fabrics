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
    flash('error', 'Invalid item.');
    redirect('/cart.php');
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
    $_SESSION['wishlist'] = [];
}
if (!isset($_SESSION['cart_size']) || !is_array($_SESSION['cart_size'])) {
    $_SESSION['cart_size'] = [];
}
if (!isset($_SESSION['wishlist_size']) || !is_array($_SESSION['wishlist_size'])) {
    $_SESSION['wishlist_size'] = [];
}
if (!isset($_SESSION['cart_meter_length']) || !is_array($_SESSION['cart_meter_length'])) {
    $_SESSION['cart_meter_length'] = [];
}
if (!isset($_SESSION['wishlist_meter_length']) || !is_array($_SESSION['wishlist_meter_length'])) {
    $_SESSION['wishlist_meter_length'] = [];
}

if (isset($_SESSION['wishlist'][$productId])) {
    $_SESSION['cart'][$productId] = $_SESSION['wishlist'][$productId];
    unset($_SESSION['wishlist'][$productId]);

    if (isset($_SESSION['wishlist_size'][$productId])) {
        $_SESSION['cart_size'][$productId] = $_SESSION['wishlist_size'][$productId];
        unset($_SESSION['wishlist_size'][$productId]);
    }
    if (isset($_SESSION['wishlist_meter_length'][$productId])) {
        $_SESSION['cart_meter_length'][$productId] = $_SESSION['wishlist_meter_length'][$productId];
        unset($_SESSION['wishlist_meter_length'][$productId]);
    }

    if (!empty($_SESSION['customer_id'])) {
        cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart']);
    }

    flash('success', 'Item moved to cart.');
} else {
    flash('error', 'Item not found in wishlist.');
}

redirect('/cart.php');
