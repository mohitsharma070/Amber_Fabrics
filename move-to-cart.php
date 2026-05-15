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
    $parts = explode('::', $cartKey, 2);
    $productId = (int) ($parts[0] ?? 0);
}
$productId = $productId > 0 ? $productId : (int) ($_POST['product_id'] ?? 0);
$cartKey = $cartKey !== '' ? $cartKey : ($productId > 0 ? ($productId . '::_') : '');
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

if (isset($_SESSION['wishlist'][$cartKey])) {
    $_SESSION['cart'][$cartKey] = $_SESSION['wishlist'][$cartKey];
    unset($_SESSION['wishlist'][$cartKey]);

    if (isset($_SESSION['wishlist_size'][$cartKey])) {
        $_SESSION['cart_size'][$cartKey] = $_SESSION['wishlist_size'][$cartKey];
        unset($_SESSION['wishlist_size'][$cartKey]);
    }
    if (isset($_SESSION['wishlist_meter_length'][$cartKey])) {
        $_SESSION['cart_meter_length'][$cartKey] = $_SESSION['wishlist_meter_length'][$cartKey];
        unset($_SESSION['wishlist_meter_length'][$cartKey]);
    }

    if (!empty($_SESSION['customer_id'])) {
        $cid = (int) $_SESSION['customer_id'];
        cart_save_to_db($conn, $cid, $_SESSION['cart']);
        wishlist_save_to_db(
            $conn,
            $cid,
            $_SESSION['wishlist'],
            $_SESSION['wishlist_meter_length'] ?? [],
            $_SESSION['wishlist_size'] ?? []
        );
        $_SESSION['wishlist_loaded_for'] = $cid;
    }

    flash('success', 'Item moved to cart.');
} else {
    flash('error', 'Item not found in wishlist.');
}

redirect('/cart.php');
