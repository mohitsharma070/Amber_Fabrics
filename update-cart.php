<?php
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/cart.php');
}

$cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
$identity = CartService::resolveExistingUpdateIdentity(
    $cart,
    (string) ($_POST['cart_key'] ?? ''),
    $_POST['product_id'] ?? null,
    $_POST['variant_id'] ?? null
);
if ($identity === null) {
    flash('error', 'Invalid cart item. Please refresh your cart and try again.');
    redirect('/cart.php');
}
$cartKey = $identity['cart_key'];
$productId = $identity['product_id'];
$variantId = $identity['variant_id'];
$quantityInput    = $_POST['quantity']       ?? 1;
$bundleQtyInput   = $_POST['bundle_quantity'] ?? null;
$meterLengthInput = $_POST['meter_length']    ?? null;

if (!isset($_SESSION['cart_meter_length']) || !is_array($_SESSION['cart_meter_length'])) {
    $_SESSION['cart_meter_length'] = [];
}

$stmt = $conn->prepare("SELECT id, product_type, unit_type, meter_options, min_order_meters, qty_step, stock, stock_meters, is_available, status FROM fabrics WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product || $product['status'] !== 'active' || empty($product['is_available'])) {
    unset($_SESSION['cart'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
    flash('error', 'This product is unavailable and was removed from your cart.');
    redirect('/cart.php');
}

$variantRow = $variantId > 0 ? InventoryService::get_variant_by_id($conn, $variantId) : null;
if (!CartService::isValidUpdateSelection($productId, $variantId, $product, $variantRow)) {
    unset($_SESSION['cart'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
    flash('error', 'This product selection is unavailable and was removed from your cart.');
    redirect('/cart.php');
}

$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$minOrder = $unitType === 'meter'
    ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
    : (float) max(1, (int) round((float) ($product['min_order_meters'] ?? 1)));
$quantityStep = is_numeric($product['qty_step'] ?? null) ? (float) $product['qty_step'] : 0.0;
$allowedMeterOptions = ($unitType === 'meter')
    ? CartService::parse_meter_options((string) ($product['meter_options'] ?? ''), (float) $minOrder)
    : [];
$quantity = normalize_quantity_by_unit($quantityInput, $unitType, (float) $minOrder);
if (($unitType === 'piece' || $unitType === 'set') && is_numeric($quantityInput)) {
    $rawWholeQty = (float) $quantityInput;
    if (abs($rawWholeQty - round($rawWholeQty)) > 0.0001) {
        flash('error', 'Quantity must be a whole number for this product.');
        redirect('/cart.php');
    }
    if (!CartService::quantityRespectsStep($rawWholeQty, $unitType, (float) $minOrder, $quantityStep)) {
        flash('error', 'Quantity does not match the allowed quantity step.');
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

    if ($meterLength === null || !CartService::meter_length_is_allowed($meterLength, $allowedMeterOptions)) {
        flash('error', 'Selected meter option is unavailable.');
        redirect('/cart.php');
    }

    if ($bundleQtyInput === null || !is_numeric($bundleQtyInput) || (float) $bundleQtyInput <= 0) {
        flash('error', 'Please select a valid quantity.');
        redirect('/cart.php');
    }
    $bundleQty = max(1, (int) round((float) $bundleQtyInput));
    $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
    if (!meter_qty_respects_step((float) $quantity, (float) $minOrder, $quantityStep)) {
        flash('error', 'Quantity does not match the allowed meter step.');
        redirect('/cart.php');
    }
    $_SESSION['cart_meter_length'][$cartKey] = round($meterLength, 2);
}

if ($quantity < 1) {
    flash('error', 'Quantity must be at least 1 ' . (($unitType === 'piece' || $unitType === 'set') ? rtrim($unitType) : 'meter') . '.');
    redirect('/cart.php');
}

if ($product) {
    // Variable lines use variant stock; simple lines use product stock.
    $stock = 0.0;
    if ($variantId > 0) {
        $stock = ($unitType === 'piece' || $unitType === 'set')
            ? (float) ($variantRow['stock'] ?? 0)
            : (float) ($variantRow['stock_meters'] ?? 0);
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
    $maximumSellableQuantity = CartService::maximumSellableQuantity(
        $stock,
        $unitType,
        (float) $minOrder,
        $quantityStep,
        $unitType === 'meter' ? $meterLength : null
    );
    if ($maximumSellableQuantity <= 0) {
        unset($_SESSION['cart'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
        flash('error', 'This product is not available in the minimum order quantity and was removed from your cart.');
        redirect('/cart.php');
    }
    if ($quantity > $maximumSellableQuantity) {
        $quantity = $maximumSellableQuantity;
    }
}

$_SESSION['cart'][$cartKey] = normalize_quantity_by_unit($quantity, $unitType, (float) $minOrder);

if (!empty($_SESSION['customer_id'])) {
    CartService::cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'], $_SESSION['cart_meter_length'] ?? []);
}

flash('success', 'Cart updated.');
redirect('/cart.php');
