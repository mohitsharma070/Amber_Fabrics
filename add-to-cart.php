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
$productId     = (int) ($_POST['product_id'] ?? $_POST['fabric_id'] ?? 0);
$selectedSize  = trim((string) ($_POST['selected_size'] ?? ''));
$selectedColor = trim((string) ($_POST['selected_color'] ?? ''));
$postedVariantId = (int) ($_POST['variant_id'] ?? 0);
$redirectTo    = (string) ($_POST['redirect_to'] ?? '');

if ($productId <= 0) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Invalid product selected.']); exit; }
    flash('error', 'Invalid product selected.');
    redirect('/catalog.php');
}

$stmt = $conn->prepare("SELECT id, name, size, color, unit_type, min_order_meters, stock, stock_meters, is_available, status, price, sale_price, price_inr FROM fabrics WHERE id = ? LIMIT 1");
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
    if ($meterLengthRaw !== null && is_numeric($meterLengthRaw) && $bundleQtyRaw !== null && is_numeric($bundleQtyRaw)) {
        $meterLength = max(0.01, (float) $meterLengthRaw);
        $bundleQty = max(1, (int) round((float) $bundleQtyRaw));
        $selectedMeterLength = $meterLength;
        $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
    } elseif ($meterLengthRaw !== null && is_numeric($meterLengthRaw) && isset($_POST['quantity']) && is_numeric($_POST['quantity'])) {
        $meterLength = max(0.01, (float) $meterLengthRaw);
        $qtyRaw = (float) $_POST['quantity'];
        if (abs($qtyRaw - round($qtyRaw)) < 0.0001) {
            $bundleQty = max(1, (int) round($qtyRaw));
            $selectedMeterLength = $meterLength;
            $quantity = normalize_meter_quantity($meterLength * $bundleQty, (float) $minOrder);
        } else {
            $selectedMeterLength = $meterLength;
            $quantity = normalize_meter_quantity($qtyRaw, (float) $minOrder);
        }
    }
}

$sizeOptions = parse_size_options((string) ($product['size'] ?? ''));

// ── Variant lookup ──────────────────────────────────────────────────────────
// Try explicit variant first (from product page), then color+size, then first active variant.
$variant = null;
if ($postedVariantId > 0) {
    $candidate = get_variant_by_id($conn, $postedVariantId);
    if ($candidate && (int) ($candidate['fabric_id'] ?? 0) === $productId && (int) ($candidate['is_active'] ?? 0) === 1) {
        $variant = $candidate;
    }
}
if (!$variant && ($selectedColor !== '' || $selectedSize !== '')) {
    $variant = find_variant($conn, $productId, $selectedColor, $selectedSize);
}
if (!$variant) {
    $variant = get_first_active_in_stock_variant($conn, $productId, $unitType);
}
if (!$variant && ($selectedColor !== '' || $selectedSize !== '')) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Selected colour/size combination is unavailable.']);
        exit;
    }
    flash('error', 'Selected colour/size combination is unavailable.');
    redirect('/fabric.php?id=' . $productId);
}
$variantId = $variant ? (int) ($variant['id'] ?? 0) : 0;
if ($variant) {
    $selectedColor = trim((string) ($variant['color'] ?? $selectedColor));
    $selectedSize = variant_size_display($variant, $unitType);
}

// Use variant stock; fall back to fabric-level stock for legacy items with no variant.
$stock = 0.0;
if ($variantId > 0) {
    $stock = ($unitType === 'piece' || $unitType === 'set')
        ? (float) ($variant['stock'] ?? 0)
        : (float) ($variant['stock_meters'] ?? 0);
} else {
    $stock = ($unitType === 'piece' || $unitType === 'set')
        ? (float) ($product['stock'] ?? 0)
        : (float) ($product['stock_meters'] ?? 0);
}
$stock = max(0.0, $stock);

// Determine unit price: variant override first, then sale price, then base price.
$regularPrice = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
$salePrice    = (float) ($product['sale_price'] ?? 0);
$overridePrice = ($variant && $variant['price_override'] !== null) ? (float) $variant['price_override'] : null;
if ($overridePrice !== null && $overridePrice > 0) {
    $unitPrice = $overridePrice;
} else {
    $unitPrice = ($salePrice > 0 && $salePrice < $regularPrice) ? $salePrice : $regularPrice;
}

$outOfStock = $stock <= 0;
if ($outOfStock) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'This product is out of stock.']); exit; }
    flash('error', 'This product is out of stock.');
    redirect('/fabric.php?id=' . $productId);
}
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

// New cart key format: "{fabricId}::{variantId}" (or ::0 for legacy fallback).
$cartKey = $productId . '::' . ($variantId > 0 ? $variantId : 0);

$existing = isset($_SESSION['cart'][$cartKey])
    ? normalize_quantity_by_unit($_SESSION['cart'][$cartKey], $unitType, (float) $minOrder)
    : 0;
$newQty = round($existing + $quantity, 2);
if ($stock > 0 && $newQty > $stock) {
    $newQty = normalize_quantity_by_unit($stock, $unitType, (float) $minOrder);
    $cappedByStock = true;
}
$_SESSION['cart'][$cartKey] = normalize_quantity_by_unit($newQty, $unitType, (float) $minOrder);
$addedQty = max(0.0, round($newQty - $existing, 2));
if ($unitType === 'meter' && $selectedMeterLength !== null) {
    $_SESSION['cart_meter_length'][$cartKey] = round((float) $selectedMeterLength, 2);
}
// cart_size is no longer the primary key, but keep for legacy fallback display.
if (!isset($_SESSION['cart_size']) || !is_array($_SESSION['cart_size'])) {
    $_SESSION['cart_size'] = [];
}
if ($selectedSize !== '') {
    $_SESSION['cart_size'][$cartKey] = $selectedSize;
}

if (!empty($_SESSION['customer_id'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'], $_SESSION['cart_meter_length'] ?? []);
}

do_action('cart.after_add', [
    'conn' => $conn,
    'product_id' => $productId,
    'product_name' => (string) ($product['name'] ?? ''),
    'quantity' => $addedQty,
    'unit_type' => $unitType,
    'is_ajax' => $isAjax,
]);

if ($isAjax) {
    $cartCount = count($_SESSION['cart']);
    $msg = $cappedByStock
        ? 'Added to cart (only ' . format_quantity_by_unit($stock, $unitType) . quantity_unit_suffix($unitType) . ' in stock - quantity adjusted).'
        : 'Added to cart: ' . ($product['name'] ?? 'Product');
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $msg,
        'cart_count' => $cartCount,
        'capped' => $cappedByStock,
        'meta_pixel_event' => $GLOBALS['meta_pixel_last_event'] ?? null,
    ]);
    exit;
}

$flashMsg = $cappedByStock
    ? 'Added to cart. Only ' . format_quantity_by_unit($stock, $unitType) . quantity_unit_suffix($unitType) . ' available - quantity has been adjusted.'
    : 'Added to cart: ' . ($product['name'] ?? 'Product');
flash('success', $flashMsg);
$target = ($redirectTo === 'checkout') ? '/checkout.php' : '/cart.php';
redirect($target);
