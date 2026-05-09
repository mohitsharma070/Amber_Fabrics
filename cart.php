<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$items = [];
$subtotal = 0.00;

if (!empty($cart)) {
    $ids = array_map('intval', array_keys($cart));
    $ids = array_values(array_filter($ids, static fn($v) => $v > 0));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT id, name, image, unit_type, price, sale_price, price_inr, stock, stock_meters, is_available
                FROM fabrics
                WHERE status = 'active' AND id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            $pid = (int) $row['id'];
            $unitType = in_array((string) ($row['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $row['unit_type']
                : 'meter';
            $qty = normalize_quantity_by_unit($cart[$pid] ?? 1, $unitType);
            $regular = (float) (($row['price'] !== null && $row['price'] !== '') ? $row['price'] : ($row['price_inr'] ?? 0));
            $sale = (float) ($row['sale_price'] ?? 0);
            $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal = round($subtotal + $lineTotal, 2);
            $unitLabel = 'meter';
            if ($unitType === 'piece') {
                $unitLabel = ((float) $qty === 1.0) ? 'piece' : 'pieces';
            } elseif ($unitType === 'set') {
                $unitLabel = ((float) $qty === 1.0) ? 'set' : 'sets';
            }

            $displayStock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($row['stock'] ?? 0)
                : (float) ($row['stock_meters'] ?? 0);
            $inStock = !empty($row['is_available']) && $displayStock > 0;

            $items[] = [
                'id' => $pid,
                'name' => (string) $row['name'],
                'image' => (string) ($row['image'] ?? ''),
                'quantity' => $qty,
                'quantity_text' => format_quantity_by_unit($qty, $unitType),
                'quantity_unit_label' => $unitLabel,
                'unit_type' => $unitType,
                'selected_size' => (string) ($cartSizes[$pid] ?? ''),
                'regular_price' => $regular,
                'sale_price' => $sale,
                'unit_price' => $unitPrice,
                'subtotal' => $lineTotal,
                'stock' => $displayStock,
                'in_stock' => $inStock,
            ];
        }
    }
}

$couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
$couponInfo = get_active_coupon_discount($conn, $couponCode, $subtotal);
if (!$couponInfo['valid'] && $couponCode !== '') {
    unset($_SESSION['applied_coupon_code']);
}
$discountAmount = $couponInfo['valid'] ? (float) $couponInfo['discount'] : 0.00;
$total = max(0, $subtotal - $discountAmount);

$metaTitle = 'Your Cart | Amber Fabrics';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Your Cart</h1>
        <p class="mb-0"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?> in your cart</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <?php if (empty($items)): ?>
            <div class="text-center py-5">
                <p class="text-muted fs-5">Your cart is empty.</p>
                <a href="/catalog.php" class="btn btn-primary">Shop Collection</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-8">
                    <?php foreach ($items as $item): ?>
                    <div class="surface-panel p-3 mb-3">
                        <div class="d-flex gap-3 align-items-start">
                            <?php if ($item['image'] !== ''): ?>
                                <a href="/fabric.php?id=<?php echo $item['id']; ?>">
                                    <img src="/images/fabrics/<?php echo e($item['image']); ?>"
                                         alt="<?php echo e($item['name']); ?>"
                                         class="rounded" style="width:80px;height:80px;object-fit:cover;">
                                </a>
                            <?php else: ?>
                                <div class="rounded" style="width:80px;height:80px;background:#eee;flex-shrink:0;"></div>
                            <?php endif; ?>

                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <a href="/fabric.php?id=<?php echo $item['id']; ?>" class="fw-semibold text-decoration-none d-block">
                                            <?php echo e($item['name']); ?>
                                        </a>
                                        <span class="text-muted small"><?php echo e($item['name']); ?> — <?php echo e($item['quantity_text']); ?> <?php echo e($item['quantity_unit_label']); ?></span>
                                    </div>
                                    <span class="fw-semibold">Rs <?php echo number_format($item['subtotal'], 2); ?></span>
                                </div>

                                <div class="text-muted small mb-2">
                                    <?php if ($item['selected_size'] !== ''): ?>
                                        <span class="me-2">Size: <strong><?php echo e($item['selected_size']); ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ($item['sale_price'] > 0 && $item['sale_price'] < $item['regular_price']): ?>
                                        <span class="fw-semibold">Rs <?php echo number_format($item['sale_price'], 2); ?></span>
                                        <span class="ms-1"><del>Rs <?php echo number_format($item['regular_price'], 2); ?></del></span>
                                    <?php else: ?>
                                        <span>Rs <?php echo number_format($item['unit_price'], 2); ?></span>
                                    <?php endif; ?>
                                    <span> / <?php echo e($item['quantity_unit_label'] === 'pieces' ? 'piece' : ($item['quantity_unit_label'] === 'sets' ? 'set' : $item['quantity_unit_label'])); ?></span>
                                </div>

                                <div class="d-flex gap-2 mt-2 align-items-center">
                                    <form method="POST" action="/update-cart.php" class="d-flex gap-1 align-items-center">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                        <input type="number"
                                               name="quantity"
                                               class="form-control form-control-sm"
                                               style="width:90px"
                                               value="<?php echo e($item['quantity_text']); ?>"
                                               min="1"
                                               step="<?php echo ($item['unit_type'] === 'piece' || $item['unit_type'] === 'set') ? '1' : '0.01'; ?>"
                                               <?php echo $item['stock'] > 0 ? 'max="' . $item['stock'] . '"' : ''; ?>>
                                        <button class="btn btn-sm btn-outline-secondary">Update</button>
                                    </form>

                                    <form method="POST" action="/remove-cart.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-lg-4">
                    <div class="surface-panel p-4">
                        <h5 class="mb-3">Cart Summary</h5>

                        <form method="POST" action="/apply-coupon.php" class="mb-3">
                            <?php echo csrf_field(); ?>
                            <label class="form-label">Coupon Code</label>
                            <div class="d-flex gap-2">
                                <input type="text" name="coupon_code" class="form-control" placeholder="Enter code" value="<?php echo e($couponCode); ?>">
                                <button class="btn btn-outline-dark" type="submit">Apply</button>
                            </div>
                        </form>

                        <?php if ($couponInfo['valid']): ?>
                            <form method="POST" action="/remove-coupon.php" class="mb-3">
                                <?php echo csrf_field(); ?>
                                <div class="d-flex justify-content-between small">
                                    <span>Coupon: <strong><?php echo e($couponInfo['code']); ?></strong></span>
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-danger">Remove</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span class="fw-semibold">Rs <?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Discount</span>
                            <span class="fw-semibold text-success">- Rs <?php echo number_format($discountAmount, 2); ?></span>
                        </div>
                        <?php $estimatedShipping = ($subtotal - $discountAmount) < 999 ? 70.00 : 0.00; ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Shipping <small class="text-muted">(est.)</small></span>
                            <?php if ($estimatedShipping > 0): ?>
                                <span class="fw-semibold">Rs <?php echo number_format($estimatedShipping, 2); ?></span>
                            <?php else: ?>
                                <span class="fw-semibold text-success">Free</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Total</span>
                            <span class="fw-semibold">Rs <?php echo number_format(max(0, $total + $estimatedShipping), 2); ?></span>
                        </div>
                        <p class="text-muted small mb-2">Free shipping on India orders over Rs 999. Final shipping confirmed at checkout.</p>
                        <hr>
                        <a class="btn btn-primary w-100 btn-lg" href="/checkout.php">Proceed to Checkout</a>
                        <a href="/catalog.php" class="btn btn-outline-secondary w-100 mt-2">Continue Shopping</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
