<?php
require_once __DIR__ . '/includes/init.php';

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

$cart = $_SESSION['cart'];
$cartSizes = $_SESSION['cart_size'];
$wishlist = $_SESSION['wishlist'];
$wishlistSizes = $_SESSION['wishlist_size'];
$cartMeterMap = $_SESSION['cart_meter_length'];
$wishlistMeterMap = $_SESSION['wishlist_meter_length'];

$cartHydrated = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
$wishlistHydrated = CartService::cart_hydrate_items($conn, $wishlist, $wishlistSizes, $wishlistMeterMap);

$sessionAdjusted = false;
if (!empty($cartHydrated['removed_keys'])) {
    foreach ($cartHydrated['removed_keys'] as $cartKey) {
        unset($cart[$cartKey], $cartSizes[$cartKey], $cartMeterMap[$cartKey]);
    }
    $sessionAdjusted = true;
}
if (!empty($wishlistHydrated['removed_keys'])) {
    foreach ($wishlistHydrated['removed_keys'] as $wishKey) {
        unset($wishlist[$wishKey], $wishlistSizes[$wishKey], $wishlistMeterMap[$wishKey]);
    }
    $sessionAdjusted = true;
}
if ($sessionAdjusted) {
    $_SESSION['cart'] = $cart;
    $_SESSION['cart_size'] = $cartSizes;
    $_SESSION['cart_meter_length'] = $cartMeterMap;
    $_SESSION['wishlist'] = $wishlist;
    $_SESSION['wishlist_size'] = $wishlistSizes;
    $_SESSION['wishlist_meter_length'] = $wishlistMeterMap;

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);
    if ($customerId > 0) {
        CartService::cart_save_to_db($conn, $customerId, $cart, $cartMeterMap);
        wishlist_save_to_db($conn, $customerId, $wishlist, $wishlistMeterMap, $wishlistSizes);
    }
}

$items = $cartHydrated['items'];
$wishlistItems = $wishlistHydrated['items'];
$subtotal = CartService::cart_items_subtotal($items);

$freeShippingThreshold = 999.00;
$shippingRemaining = max(0, $freeShippingThreshold - $subtotal);
$shippingProgress = $freeShippingThreshold > 0 ? min(100, (int) round(($subtotal / $freeShippingThreshold) * 100)) : 100;
$estimatedShipping = $subtotal < $freeShippingThreshold ? 70.00 : 0.00;
$total = max(0, $subtotal + $estimatedShipping);

$metaTitle = SiteContext::title('Your Cart');
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
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="text-center py-5">
                        <p class="text-muted fs-5">Your cart is empty.</p>
                        <a href="/catalog.php" class="btn btn-primary">Shop Collection</a>
                    </div>

                    <div class="surface-panel p-3 mt-4">
                        <h5 class="mb-3">Saved for Later</h5>
                        <?php if (empty($wishlistItems)): ?>
                            <p class="text-muted small mb-0">No products saved yet. Use "Move to Wishlist" on any cart item.</p>
                        <?php else: ?>
                            <?php foreach ($wishlistItems as $w): ?>
                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <div>
                                        <a href="/fabric.php?id=<?php echo $w['id']; ?>" class="fw-semibold text-decoration-none"><?php echo e($w['name']); ?></a>
                                        <div class="small text-muted"><?php echo e(money($w['unit_price'])); ?> / <?php echo e($w['quantity_unit_label'] === 'pieces' ? 'piece' : ($w['quantity_unit_label'] === 'sets' ? 'set' : $w['quantity_unit_label'])); ?></div>
                                        <div class="small text-muted">
                                            <?php if ($w['unit_type'] === 'meter' && !empty($w['meter_length']) && !empty($w['bundle_quantity'])): ?>
                                                Qty: <?php echo e((string) $w['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $w['meter_length'])); ?>m = <?php echo e($w['quantity_text']); ?>m
                                            <?php else: ?>
                                                Qty: <?php echo e($w['quantity_text']); ?> <?php echo e($w['quantity_unit_label']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <form method="POST" action="/move-to-cart.php" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="btn btn-sm btn-primary">Move to Cart</button>
                                        </form>
                                        <form method="POST" action="/remove-wishlist.php" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="surface-panel p-4 cart-summary-card">
                        <h5 class="mb-3">Cart Summary</h5>
                        <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><span class="fw-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="d-flex justify-content-between mb-2"><span>Shipping <small class="text-muted">(est.)</small></span><span class="fw-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="d-flex justify-content-between mb-2"><span>Total</span><span class="fw-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="small text-muted mb-3">Coupon can be applied at checkout.</div>
                        <hr>
                        <button type="button" class="btn btn-primary w-100 btn-lg" disabled aria-disabled="true">Proceed to Checkout</button>
                        <div class="trust-badge-block mt-3" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        <a href="/catalog.php" class="btn btn-outline-secondary w-100 mt-2">Continue Shopping</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-8">
                    <?php foreach ($items as $item): ?>
                    <?php $cartImageAsset = ($item['image'] !== '') ? fabric_image_asset_data((string) $item['image']) : null; ?>
                    <div class="surface-panel p-3 mb-3">
                        <div class="d-flex gap-3 align-items-start cart-line-item">
                            <?php if ($item['image'] !== ''): ?>
                                <a href="/fabric.php?id=<?php echo $item['id']; ?>">
                                    <img src="<?php echo e((string) ($cartImageAsset['thumb_src'] ?? '')); ?>" alt="<?php echo e($item['name']); ?>" class="rounded cart-item-img" loading="lazy">
                                </a>
                            <?php else: ?>
                                <div class="rounded cart-item-img bg-light"></div>
                            <?php endif; ?>

                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <a href="/fabric.php?id=<?php echo $item['id']; ?>" class="fw-semibold text-decoration-none d-block"><?php echo e($item['name']); ?></a>
                                        <div class="text-muted small">
                                            <?php if ($item['unit_type'] === 'meter' && !empty($item['meter_length']) && !empty($item['bundle_quantity'])): ?>
                                                Qty: <?php echo e((string) $item['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $item['meter_length'])); ?>m = <?php echo e($item['quantity_text']); ?>m
                                            <?php else: ?>
                                                Qty: <?php echo e($item['quantity_text']); ?> <?php echo e($item['quantity_unit_label']); ?>
                                                <?php if ($item['unit_type'] === 'set' && (int) ($item['units_per_set'] ?? 0) > 0): ?>
                                                    (<?php echo (int) $item['quantity']; ?> sets x <?php echo (int) $item['units_per_set']; ?> = <?php echo (int) $item['quantity'] * (int) $item['units_per_set']; ?> pieces)
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($item['selected_size'] !== ''): ?>
                                                | Size: <strong><?php echo e($item['selected_size']); ?></strong>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small mt-1">
                                            <?php if ($item['sale_price'] > 0 && $item['sale_price'] < $item['regular_price']): ?>
                                                <span class="fw-semibold text-dark"><?php echo e(money($item['sale_price'])); ?></span>
                                                <span class="ms-1"><del><?php echo e(money($item['regular_price'])); ?></del></span>
                                            <?php else: ?>
                                                <span class="fw-semibold text-dark"><?php echo e(money($item['unit_price'])); ?></span>
                                            <?php endif; ?>
                                            <span> / <?php echo e($item['quantity_unit_label'] === 'pieces' ? 'piece' : ($item['quantity_unit_label'] === 'sets' ? 'set' : $item['quantity_unit_label'])); ?></span>
                                        </div>
                                        <div class="small mt-2 text-muted">
                                            Delivery ETA: <?php echo e($item['dispatch_time'] !== '' ? $item['dispatch_time'] : '2-5 business days'); ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">Line Total</div>
                                        <div class="fw-semibold"><?php echo e(money($item['subtotal'])); ?></div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-3 align-items-center flex-wrap cart-line-actions">
                                    <form method="POST" action="/update-cart.php" class="d-flex gap-1 align-items-center cart-qty-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <?php if ($item['unit_type'] === 'meter' && !empty($item['meter_length'])): ?>
                                            <input type="hidden" name="meter_length" value="<?php echo e(format_meter_quantity((float) $item['meter_length'])); ?>">
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary qty-dec" aria-label="Decrease quantity">-</button>
                                        <input type="number" name="<?php echo ($item['unit_type'] === 'meter') ? 'bundle_quantity' : 'quantity'; ?>" class="form-control form-control-sm cart-qty-input"
                                               value="<?php echo e(($item['unit_type'] === 'meter') ? (string) max(1, (int) ($item['bundle_quantity'] ?? 1)) : $item['quantity_text']); ?>" min="1"
                                               step="<?php echo ($item['unit_type'] === 'piece' || $item['unit_type'] === 'set' || $item['unit_type'] === 'meter') ? '1' : '0.01'; ?>"
                                               <?php echo ($item['unit_type'] === 'meter' && !empty($item['max_bundle_qty'])) ? 'max="' . (int) $item['max_bundle_qty'] . '"' : ($item['stock'] > 0 ? 'max="' . $item['stock'] . '"' : ''); ?>>
                                        <button type="button" class="btn btn-sm btn-outline-secondary qty-inc" aria-label="Increase quantity">+</button>
                                    </form>

                                    <form method="POST" action="/move-to-wishlist.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <button class="btn btn-sm btn-outline-primary">Move to Wishlist</button>
                                    </form>

                                    <form method="POST" action="/remove-cart.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="surface-panel p-3 mt-4">
                        <h5 class="mb-3">Saved for Later</h5>
                        <?php if (empty($wishlistItems)): ?>
                            <p class="text-muted small mb-0">No products saved yet. Use "Move to Wishlist" on any cart item.</p>
                        <?php else: ?>
                            <?php foreach ($wishlistItems as $w): ?>
                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <div>
                                        <a href="/fabric.php?id=<?php echo $w['id']; ?>" class="fw-semibold text-decoration-none"><?php echo e($w['name']); ?></a>
                                        <div class="small text-muted"><?php echo e(money($w['unit_price'])); ?> / <?php echo e($w['quantity_unit_label'] === 'pieces' ? 'piece' : ($w['quantity_unit_label'] === 'sets' ? 'set' : $w['quantity_unit_label'])); ?></div>
                                        <div class="small text-muted">
                                            <?php if ($w['unit_type'] === 'meter' && !empty($w['meter_length']) && !empty($w['bundle_quantity'])): ?>
                                                Qty: <?php echo e((string) $w['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $w['meter_length'])); ?>m = <?php echo e($w['quantity_text']); ?>m
                                            <?php else: ?>
                                                Qty: <?php echo e($w['quantity_text']); ?> <?php echo e($w['quantity_unit_label']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <form method="POST" action="/move-to-cart.php" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="btn btn-sm btn-primary">Move to Cart</button>
                                        </form>
                                        <form method="POST" action="/remove-wishlist.php" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="surface-panel p-4 cart-summary-card">
                        <h5 class="mb-3">Cart Summary</h5>

                        <div class="mb-3">
                            <?php if ($shippingRemaining > 0): ?>
                                <div class="small text-muted mb-1">Add <?php echo e(money($shippingRemaining)); ?> more for free shipping.</div>
                            <?php else: ?>
                                <div class="small text-success mb-1">You unlocked free shipping.</div>
                            <?php endif; ?>
                            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $shippingProgress; ?>" style="height:8px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $shippingProgress; ?>%;"></div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><span class="fw-semibold"><?php echo e(money($subtotal)); ?></span></div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Shipping <small class="text-muted">(est.)</small></span>
                            <?php if ($estimatedShipping > 0): ?>
                                <span class="fw-semibold"><?php echo e(money($estimatedShipping)); ?></span>
                            <?php else: ?>
                                <span class="fw-semibold text-success">Free</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-between mb-2"><span>Total</span><span class="fw-semibold"><?php echo e(money($total)); ?></span></div>
                        <div class="small text-muted mb-3">Coupon can be applied at checkout.</div>
                        <hr>
                        <a class="btn btn-primary w-100 btn-lg" href="/checkout.php">Proceed to Checkout</a>
                        <div class="trust-badge-block mt-3" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        <a href="/catalog.php" class="btn btn-outline-secondary w-100 mt-2">Continue Shopping</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var forms = document.querySelectorAll('.cart-qty-form');
    if (!forms.length) return;

    forms.forEach(function (form) {
        var input = form.querySelector('input[name="quantity"], input[name="bundle_quantity"]');
        var dec = form.querySelector('.qty-dec');
        var inc = form.querySelector('.qty-inc');
        if (!input || !dec || !inc) return;

        function clamp(v) {
            var min = parseFloat(input.min || '1');
            var max = parseFloat(input.max || '');
            if (!Number.isFinite(v) || v < min) v = min;
            if (Number.isFinite(max) && v > max) v = max;
            return v;
        }

        function fmt(v) {
            var step = parseFloat(input.step || '1');
            if (step >= 1) return String(Math.round(v));
            var rounded = Math.round(v * 100) / 100;
            return rounded.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
        }

        function bump(dir) {
            var step = parseFloat(input.step || '1');
            if (!Number.isFinite(step) || step <= 0) step = 1;
            var current = parseFloat(input.value || input.min || '1');
            if (!Number.isFinite(current)) current = parseFloat(input.min || '1');
            var next = clamp(current + (dir * step));
            input.value = fmt(next);
            form.submit();
        }

        dec.addEventListener('click', function () { bump(-1); });
        inc.addEventListener('click', function () { bump(1); });
        input.addEventListener('change', function () {
            input.value = fmt(clamp(parseFloat(input.value || input.min || '1')));
            form.submit();
        });
    });
})();
</script>

<?php do_action('cart.after_items', [
    'conn' => $conn,
    'cart_items' => $items,
    'wishlist_items' => $wishlistItems,
    'subtotal' => $subtotal,
    'customer_id' => (int) ($_SESSION['customer_id'] ?? 0),
    'cart' => $cart,
    'wishlist' => $wishlist,
]); ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
