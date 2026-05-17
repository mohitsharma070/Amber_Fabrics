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
    [$productId] = cart_parse_key($cartKey);
}
$productId = $productId > 0 ? $productId : (int) ($_POST['product_id'] ?? 0);
$cartKey = $cartKey !== '' ? $cartKey : ($productId > 0 ? ($productId . '::0') : '');
if ($productId <= 0) {
    flash('error', 'Invalid item.');
    redirect('/cart.php');
}

if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
    $_SESSION['wishlist'] = [];
}
if (!isset($_SESSION['wishlist_size']) || !is_array($_SESSION['wishlist_size'])) {
    $_SESSION['wishlist_size'] = [];
}
if (!isset($_SESSION['wishlist_meter_length']) || !is_array($_SESSION['wishlist_meter_length'])) {
    $_SESSION['wishlist_meter_length'] = [];
}

unset($_SESSION['wishlist'][$cartKey], $_SESSION['wishlist_size'][$cartKey], $_SESSION['wishlist_meter_length'][$cartKey]);
if (!empty($_SESSION['customer_id'])) {
    $cid = (int) $_SESSION['customer_id'];
    wishlist_save_to_db(
        $conn,
        $cid,
        $_SESSION['wishlist'],
        $_SESSION['wishlist_meter_length'] ?? [],
        $_SESSION['wishlist_size'] ?? []
    );
    $_SESSION['wishlist_loaded_for'] = $cid;
}
flash('success', 'Item removed from wishlist.');
redirect('/cart.php');
