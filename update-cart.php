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
$quantityInput = $_POST['quantity'] ?? 1;

if ($productId <= 0) {
    flash('error', 'Invalid cart item.');
    redirect('/cart.php');
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
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

if ($quantity < 1) {
    flash('error', 'Quantity must be at least 1 ' . (($unitType === 'piece' || $unitType === 'set') ? rtrim($unitType) : 'meter') . '.');
    redirect('/cart.php');
}

if ($product) {
    $stock = ($unitType === 'piece' || $unitType === 'set')
        ? (float) ($product['stock'] ?? 0)
        : (float) ($product['stock_meters'] ?? 0);
    if ($stock > 0 && $quantity > $stock) {
        $quantity = normalize_quantity_by_unit($stock, $unitType, (float) $minOrder);
    }
}

$_SESSION['cart'][$productId] = normalize_quantity_by_unit($quantity, $unitType, (float) $minOrder);

if (!empty($_SESSION['customer_id'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart']);
}

flash('success', 'Cart updated.');
redirect('/cart.php');

