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

if (isset($_SESSION['cart'][$cartKey])) {
    $sourceHydrated = CartService::cart_hydrate_items(
        $conn,
        [$cartKey => $_SESSION['cart'][$cartKey]],
        $_SESSION['cart_size'],
        $_SESSION['cart_meter_length']
    );
    $sourceItem = $sourceHydrated['items'][0] ?? null;
    if (!$sourceItem || !CartService::cartLineAllowsMeterLength(
        $_SESSION['wishlist'],
        $_SESSION['wishlist_meter_length'],
        $cartKey,
        (string) ($sourceItem['unit_type'] ?? ''),
        $sourceItem['meter_length'] ?? null
    )) {
        flash('error', 'This product is already in your wishlist with a different or invalid meter length. Update or remove that line before moving it from your cart.');
        redirect('/cart.php');
    }

    $combinedQuantity = (float) $_SESSION['cart'][$cartKey] + (float) ($_SESSION['wishlist'][$cartKey] ?? 0);
    $combinedSizeMap = $_SESSION['wishlist_size'];
    if (!isset($combinedSizeMap[$cartKey]) && isset($_SESSION['cart_size'][$cartKey])) {
        $combinedSizeMap[$cartKey] = $_SESSION['cart_size'][$cartKey];
    }
    $combinedMeterMap = $_SESSION['wishlist_meter_length'];
    if (!isset($combinedMeterMap[$cartKey]) && isset($_SESSION['cart_meter_length'][$cartKey])) {
        $combinedMeterMap[$cartKey] = $_SESSION['cart_meter_length'][$cartKey];
    }
    $combinedHydrated = CartService::cart_hydrate_items(
        $conn,
        [$cartKey => $combinedQuantity],
        $combinedSizeMap,
        $combinedMeterMap
    );
    $combinedItem = $combinedHydrated['items'][0] ?? null;
    if (!$combinedItem) {
        flash('error', 'This item is not currently available in the minimum order quantity.');
        redirect('/cart.php');
    }

    $_SESSION['wishlist'][$cartKey] = (float) $combinedItem['quantity'];
    unset($_SESSION['cart'][$cartKey]);

    if (!isset($_SESSION['wishlist_size'][$cartKey]) && isset($_SESSION['cart_size'][$cartKey])) {
        $_SESSION['wishlist_size'][$cartKey] = $_SESSION['cart_size'][$cartKey];
    }
    unset($_SESSION['cart_size'][$cartKey]);
    if (!isset($_SESSION['wishlist_meter_length'][$cartKey]) && isset($_SESSION['cart_meter_length'][$cartKey])) {
        $_SESSION['wishlist_meter_length'][$cartKey] = $_SESSION['cart_meter_length'][$cartKey];
    }
    unset($_SESSION['cart_meter_length'][$cartKey]);

    if (!empty($_SESSION['customer_id'])) {
        $cid = (int) $_SESSION['customer_id'];
        CartService::cart_save_to_db(
            $conn,
            $cid,
            $_SESSION['cart'],
            $_SESSION['cart_meter_length'] ?? [],
            $_SESSION['cart_size'] ?? []
        );
        wishlist_save_to_db(
            $conn,
            $cid,
            $_SESSION['wishlist'],
            $_SESSION['wishlist_meter_length'] ?? [],
            $_SESSION['wishlist_size'] ?? []
        );
        $_SESSION['wishlist_loaded_for'] = $cid;
    }

    $quantityAdjusted = abs((float) ($_SESSION['wishlist'][$cartKey] ?? 0) - $combinedQuantity) > 0.0001;
    flash('success', $quantityAdjusted ? 'Item moved to wishlist with quantity adjusted to available stock.' : 'Item moved to wishlist.');
} else {
    flash('error', 'Item not found in cart.');
}

redirect('/cart.php');
