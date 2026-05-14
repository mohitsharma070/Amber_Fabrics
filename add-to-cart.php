<?php
require_once __DIR__ . '/includes/init.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }
    redirect('/catalog.php');
}
if (!verify_csrf()) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid session token. Please try again.']); exit; }
    flash('error', 'Invalid session token. Please try again.');
    redirect('/catalog.php');
}

// Accept product_id (form POST) or fabric_id (AJAX)
$productId = (int) ($_POST['product_id'] ?? $_POST['fabric_id'] ?? 0);
$selectedSize = trim((string) ($_POST['selected_size'] ?? ''));
$redirectTo = (string) ($_POST['redirect_to'] ?? '');

if ($productId <= 0) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid product selected.']); exit; }
    flash('error', 'Invalid product selected.');
    redirect('/catalog.php');
}

$stmt = $conn->prepare("SELECT id, name, size, unit_type, min_order_meters, stock, stock_meters, is_available, status FROM fabrics WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product || $product['status'] !== 'active' || empty($product['is_available'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'This product is not available.']); exit; }
    flash('error', 'This product is not available.');
    redirect('/catalog.php');
}

$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$minOrder = $unitType === 'meter'
    ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0)
    : 1;
$quantity = normalize_quantity_by_unit($_POST['quantity'] ?? 1, $unitType, (float) $minOrder);
$selectedMeterLength = null;

// Meter products can be posted as: selected meter length + bundle quantity (pieces).
if ($unitType === 'meter') {
    $meterLengthRaw = $_POST['meter_length'] ?? null;
    $bundleQtyRaw = $_POST['bundle_quantity'] ?? null;
    if ($meterLengthRaw !== null && $bundleQtyRaw !== null && is_numeric($meterLengthRaw) && is_numeric($bundleQtyRaw)) {
        $meterLength = max(0.01, (float) $meterLengthRaw);
        $bundleQty = max(1, (int) round((float) $bundleQtyRaw));
        $selectedMeterLength = $meterLength;
        $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
    }
}

$sizeOptions = parse_size_options((string) ($product['size'] ?? ''));

if (!empty($sizeOptions)) {
    if ($selectedSize === '') {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Please select a size.']); exit; }
        flash('error', 'Please select a size.');
        redirect('/fabric.php?id=' . $productId);
    }
    if (!in_array($selectedSize, $sizeOptions, true)) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Selected size is invalid.']); exit; }
        flash('error', 'Selected size is invalid.');
        redirect('/fabric.php?id=' . $productId);
    }
}

$stock = ($unitType === 'piece' || $unitType === 'set')
    ? (float) ($product['stock'] ?? 0)
    : (float) ($product['stock_meters'] ?? 0);
$cappedByStock = false;
if ($stock > 0 && $quantity > $stock) {
    $quantity = normalize_quantity_by_unit($stock, $unitType, (float) $minOrder);
    $cappedByStock = true;
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
if (!isset($_SESSION['cart_meter_length']) || !is_array($_SESSION['cart_meter_length'])) {
    $_SESSION['cart_meter_length'] = [];
}

$existing = isset($_SESSION['cart'][$productId])
    ? normalize_quantity_by_unit($_SESSION['cart'][$productId], $unitType, (float) $minOrder)
    : 0;
$newQty = round($existing + $quantity, 2);
if ($stock > 0 && $newQty > $stock) {
    $newQty = normalize_quantity_by_unit($stock, $unitType, (float) $minOrder);
    $cappedByStock = true;
}
$_SESSION['cart'][$productId] = normalize_quantity_by_unit($newQty, $unitType, (float) $minOrder);
if ($unitType === 'meter' && $selectedMeterLength !== null) {
    $_SESSION['cart_meter_length'][$productId] = round((float) $selectedMeterLength, 2);
}
if (!isset($_SESSION['cart_size']) || !is_array($_SESSION['cart_size'])) {
    $_SESSION['cart_size'] = [];
}
if ($selectedSize !== '') {
    $_SESSION['cart_size'][$productId] = $selectedSize;
}

if (!empty($_SESSION['customer_id'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart']);
}

if ($isAjax) {
    $cartCount = count($_SESSION['cart']);
    $msg = $cappedByStock
        ? 'Added to cart (only ' . format_quantity_by_unit($stock, $unitType) . quantity_unit_suffix($unitType) . ' in stock - quantity adjusted).'
        : 'Added to cart: ' . ($product['name'] ?? 'Product');
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $msg, 'cart_count' => $cartCount, 'capped' => $cappedByStock]);
    exit;
}

$flashMsg = $cappedByStock
    ? 'Added to cart. Only ' . format_quantity_by_unit($stock, $unitType) . quantity_unit_suffix($unitType) . ' available - quantity has been adjusted.'
    : 'Added to cart: ' . ($product['name'] ?? 'Product');
flash('success', $flashMsg);
$target = ($redirectTo === 'checkout') ? '/checkout.php' : '/cart.php';
redirect($target);

