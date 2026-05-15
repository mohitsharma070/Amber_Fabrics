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
$cartKey = $cartKey !== '' ? $cartKey : ($productId > 0 ? ($productId . '::') : '');
$quantityInput = $_POST['quantity'] ?? 1;
$bundleQtyInput = $_POST['bundle_quantity'] ?? null;
$meterLengthInput = $_POST['meter_length'] ?? null;

if ($productId <= 0) {
    flash('error', 'Invalid cart item.');
    redirect('/cart.php');
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['cart_meter_length']) || !is_array($_SESSION['cart_meter_length'])) {
    $_SESSION['cart_meter_length'] = [];
}

$stmt = $conn->prepare("SELECT unit_type, min_order_meters, stock, stock_meters FROM fabrics WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$minOrder = $unitType === 'meter'
    ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
    : 1;
$quantity = normalize_quantity_by_unit($quantityInput, $unitType, (float) $minOrder);
if ($unitType === 'meter') {
    $meterLength = null;
    if ($meterLengthInput !== null && is_numeric($meterLengthInput) && (float) $meterLengthInput > 0) {
        $meterLength = (float) $meterLengthInput;
    } elseif (isset($_SESSION['cart_meter_length'][$cartKey]) && is_numeric($_SESSION['cart_meter_length'][$cartKey])) {
        $meterLength = (float) $_SESSION['cart_meter_length'][$cartKey];
    }

    if ($meterLength !== null && $bundleQtyInput !== null && is_numeric($bundleQtyInput)) {
        $bundleQty = max(1, (int) round((float) $bundleQtyInput));
        $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
        $_SESSION['cart_meter_length'][$cartKey] = round($meterLength, 2);
    }
}

if ($quantity < 1) {
    flash('error', 'Quantity must be at least 1 ' . (($unitType === 'piece' || $unitType === 'set') ? rtrim($unitType) : 'meter') . '.');
    redirect('/cart.php');
}

if ($product) {
    $stock = ($unitType === 'piece' || $unitType === 'set')
        ? (float) ($product['stock'] ?? 0)
        : (float) ($product['stock_meters'] ?? 0);
    if ($stock <= 0) {
        unset($_SESSION['cart'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
        flash('error', 'This product is out of stock and was removed from your cart.');
        redirect('/cart.php');
    }
    if ($stock > 0 && $quantity > $stock) {
        $quantity = normalize_quantity_by_unit($stock, $unitType, (float) $minOrder);
    }
}

$_SESSION['cart'][$cartKey] = normalize_quantity_by_unit($quantity, $unitType, (float) $minOrder);

if (!empty($_SESSION['customer_id'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'], $_SESSION['cart_meter_length'] ?? []);
}

flash('success', 'Cart updated.');
redirect('/cart.php');

