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
$variantId = 0;
if ($cartKey !== '') {
    [$productId, $variantId] = cart_parse_key($cartKey);
}
$productId = $productId > 0 ? $productId : (int) ($_POST['product_id'] ?? 0);
$cartKey   = $cartKey !== '' ? $cartKey : ($productId > 0 ? ($productId . '::0') : '');
$quantityInput    = $_POST['quantity']       ?? 1;
$bundleQtyInput   = $_POST['bundle_quantity'] ?? null;
$meterLengthInput = $_POST['meter_length']    ?? null;

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

$stmt = $conn->prepare("SELECT unit_type, meter_options, min_order_meters, qty_step, stock, stock_meters FROM fabrics WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$minOrder = $unitType === 'meter'
    ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
    : (float) max(1, (int) round((float) ($product['min_order_meters'] ?? 1)));
$allowedMeterOptions = ($unitType === 'meter')
    ? parse_meter_options((string) ($product['meter_options'] ?? ''), (float) $minOrder)
    : [];
$quantity = normalize_quantity_by_unit($quantityInput, $unitType, (float) $minOrder);
if (($unitType === 'piece' || $unitType === 'set') && is_numeric($quantityInput)) {
    $rawWholeQty = (float) $quantityInput;
    if (abs($rawWholeQty - round($rawWholeQty)) > 0.0001) {
        flash('error', 'Quantity must be a whole number for this product.');
        redirect('/cart.php');
    }
}
if ($unitType === 'meter') {
    $meterLength = null;
    if ($meterLengthInput !== null && is_numeric($meterLengthInput) && (float) $meterLengthInput > 0) {
        $meterLength = round((float) $meterLengthInput, 2);
    } elseif (isset($_SESSION['cart_meter_length'][$cartKey]) && is_numeric($_SESSION['cart_meter_length'][$cartKey])) {
        $meterLength = round((float) $_SESSION['cart_meter_length'][$cartKey], 2);
    }

    if ($meterLength === null || !meter_length_is_allowed($meterLength, $allowedMeterOptions)) {
        flash('error', 'Selected meter option is unavailable.');
        redirect('/cart.php');
    }

    if ($bundleQtyInput === null || !is_numeric($bundleQtyInput) || (float) $bundleQtyInput <= 0) {
        flash('error', 'Please select a valid quantity.');
        redirect('/cart.php');
    }
    $bundleQty = max(1, (int) round((float) $bundleQtyInput));
    $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
    $_SESSION['cart_meter_length'][$cartKey] = round($meterLength, 2);
}

if ($quantity < 1) {
    flash('error', 'Quantity must be at least 1 ' . (($unitType === 'piece' || $unitType === 'set') ? rtrim($unitType) : 'meter') . '.');
    redirect('/cart.php');
}

if ($product) {
    // Use variant stock when available; fall back to fabric-level.
    $stock = 0.0;
    if ($variantId > 0) {
        $variantRow = get_variant_by_id($conn, $variantId);
        if (!$variantRow || (int) ($variantRow['fabric_id'] ?? 0) !== $productId || (int) ($variantRow['is_active'] ?? 0) !== 1) {
            unset($_SESSION['cart'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
            flash('error', 'Selected variant is unavailable and was removed from your cart.');
            redirect('/cart.php');
        }
        if ($variantRow) {
            $stock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($variantRow['stock'] ?? 0)
                : (float) ($variantRow['stock_meters'] ?? 0);
        }
    } else {
        $stock = ($unitType === 'piece' || $unitType === 'set')
            ? (float) ($product['stock'] ?? 0)
            : (float) ($product['stock_meters'] ?? 0);
    }
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
