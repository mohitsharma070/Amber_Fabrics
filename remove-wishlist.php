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

if (!isset($_SESSION['wishlist']) || !is_array($_SESSION['wishlist'])) {
    $_SESSION['wishlist'] = [];
}
if (!isset($_SESSION['wishlist_size']) || !is_array($_SESSION['wishlist_size'])) {
    $_SESSION['wishlist_size'] = [];
}
if (!isset($_SESSION['wishlist_meter_length']) || !is_array($_SESSION['wishlist_meter_length'])) {
    $_SESSION['wishlist_meter_length'] = [];
}

unset($_SESSION['wishlist'][$productId], $_SESSION['wishlist_size'][$productId], $_SESSION['wishlist_meter_length'][$productId]);
flash('success', 'Item removed from wishlist.');
redirect('/cart.php');
