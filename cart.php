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

$metaTitle = SiteContext::title('Your Cart');
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1>Your Cart</h1>
        <p class="u-mb-0"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?> in your cart</p>
    </div>
</section>

<section class="section-block">
    <div class="l-container">
        <?php if (empty($items)): ?>
            <div class="l-grid l-grid--12 u-gap-4">
                <div class="l-col-lg-eight">
                    <div class="u-text-center u-py-5">
                        <p class="u-text-muted u-text-large">Your cart is empty.</p>
                        <a href="/catalog.php" class="ui-button ui-button--primary">Shop Collection</a>
                    </div>

                    <div class="surface-panel u-p-3 u-mt-4">
                        <h5 class="u-mb-3">Saved for Later</h5>
                        <?php if (empty($wishlistItems)): ?>
                            <p class="u-text-muted u-text-small u-mb-0">No products saved yet. Use "Move to Wishlist" on any cart item.</p>
                        <?php else: ?>
                            <?php foreach ($wishlistItems as $w): ?>
                                <div class="saved-cart-item u-flex u-justify-between u-items-center u-py-2 u-border-bottom">
                                    <div class="saved-cart-item__details">
                                        <a href="/fabric.php?id=<?php echo $w['id']; ?>" class="u-font-semibold u-no-underline"><?php echo e($w['name']); ?></a>
                                        <div class="u-text-small u-text-muted"><?php echo e(money($w['unit_price'])); ?> / <?php echo e($w['quantity_unit_label'] === 'pieces' ? 'piece' : ($w['quantity_unit_label'] === 'sets' ? 'set' : $w['quantity_unit_label'])); ?></div>
                                        <div class="u-text-small u-text-muted">
                                            <?php if ($w['unit_type'] === 'meter' && !empty($w['meter_length']) && !empty($w['bundle_quantity'])): ?>
                                                Qty: <?php echo e((string) $w['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $w['meter_length'])); ?>m = <?php echo e($w['quantity_text']); ?>m
                                            <?php else: ?>
                                                Qty: <?php echo e($w['quantity_text']); ?> <?php echo e($w['quantity_unit_label']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="saved-cart-item__actions u-flex u-gap-2">
                                        <form method="POST" action="/move-to-cart.php" class="u-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="ui-button ui-button--small ui-button--primary">Move to Cart</button>
                                        </form>
                                        <form method="POST" action="/remove-wishlist.php" class="u-inline" data-confirm-modal data-confirm-title="Remove Saved Item?" data-confirm-message="Remove this product from your saved items?" data-confirm-ok="Remove" data-confirm-cancel="Keep Item" data-confirm-variant="danger">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="ui-button ui-button--small ui-button--danger-outline">Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="l-col-lg-third">
                    <div class="surface-panel u-p-4 cart-summary-card">
                        <h5 class="u-mb-3">Cart Summary</h5>
                        <div class="u-flex u-justify-between u-mb-2"><span>Subtotal</span><span class="u-font-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="u-flex u-justify-between u-mb-2"><span>Shipping <small class="u-text-muted">(est.)</small></span><span class="u-font-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="u-flex u-justify-between u-mb-2"><span>Total</span><span class="u-font-semibold"><?php echo e(money(0)); ?></span></div>
                        <div class="u-text-small u-text-muted u-mb-3">Coupon can be applied at checkout.</div>
                        <hr>
                        <button type="button" class="ui-button ui-button--primary u-w-full ui-button--large" disabled aria-disabled="true">Proceed to Checkout</button>
                        <div class="trust-badge-block u-mt-3" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        <a href="/catalog.php" class="ui-button ui-button--secondary u-w-full u-mt-2">Continue Shopping</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="l-grid l-grid--12 u-gap-4">
                <div class="l-col-lg-eight">
                    <?php foreach ($items as $item): ?>
                    <?php $cartImageAsset = ($item['image'] !== '') ? fabric_image_asset_data((string) $item['image']) : null; ?>
                    <div class="surface-panel u-p-3 u-mb-3">
                        <div class="u-flex u-gap-3 u-items-start cart-line-item">
                            <?php if ($item['image'] !== ''): ?>
                                <a href="/fabric.php?id=<?php echo $item['id']; ?>">
                                    <img src="<?php echo e((string) ($cartImageAsset['thumb_src'] ?? '')); ?>" alt="<?php echo e($item['name']); ?>" class="u-rounded cart-item-img" loading="lazy">
                                </a>
                            <?php else: ?>
                                <div class="u-rounded cart-item-img ui-surface-soft"></div>
                            <?php endif; ?>

                            <div class="u-grow">
                                <div class="u-flex u-justify-between u-items-start">
                                    <div>
                                        <a href="/fabric.php?id=<?php echo $item['id']; ?>" class="u-font-semibold u-no-underline u-block"><?php echo e($item['name']); ?></a>
                                        <div class="u-text-muted u-text-small">
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
                                        <div class="u-text-muted u-text-small u-mt-1">
                                            <?php if ($item['sale_price'] > 0 && $item['sale_price'] < $item['regular_price']): ?>
                                                <span class="u-font-semibold u-text-ink"><?php echo e(money($item['sale_price'])); ?></span>
                                                <span class="u-ms-1"><del><?php echo e(money($item['regular_price'])); ?></del></span>
                                            <?php else: ?>
                                                <span class="u-font-semibold u-text-ink"><?php echo e(money($item['unit_price'])); ?></span>
                                            <?php endif; ?>
                                            <span> / <?php echo e($item['quantity_unit_label'] === 'pieces' ? 'piece' : ($item['quantity_unit_label'] === 'sets' ? 'set' : $item['quantity_unit_label'])); ?></span>
                                        </div>
                                        <div class="u-text-small u-mt-2 u-text-muted">
                                            Delivery estimate is calculated from your pincode at checkout.
                                        </div>
                                    </div>
                                    <div class="u-text-end">
                                        <div class="u-text-small u-text-muted">Line Total</div>
                                        <div class="u-font-semibold"><?php echo e(money($item['subtotal'])); ?></div>
                                    </div>
                                </div>

                                <div class="u-flex u-gap-2 u-mt-3 u-items-center u-wrap cart-line-actions">
                                    <form method="POST" action="/update-cart.php" class="u-flex u-gap-1 u-items-center cart-qty-form" data-ui-cart-quantity>
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <?php if ($item['unit_type'] === 'meter' && !empty($item['meter_length'])): ?>
                                            <input type="hidden" name="meter_length" value="<?php echo e(format_meter_quantity((float) $item['meter_length'])); ?>">
                                        <?php endif; ?>
                                        <button type="button" class="ui-button ui-button--small ui-button--secondary qty-dec" aria-label="Decrease quantity">-</button>
                                        <input type="number" name="<?php echo ($item['unit_type'] === 'meter') ? 'bundle_quantity' : 'quantity'; ?>" class="ui-input ui-input--small cart-qty-input"
                                               aria-label="Quantity for <?php echo e($item['name']); ?>"
                                               value="<?php echo e(($item['unit_type'] === 'meter') ? (string) max(1, (int) ($item['bundle_quantity'] ?? 1)) : $item['quantity_text']); ?>" min="1"
                                               step="<?php echo ($item['unit_type'] === 'piece' || $item['unit_type'] === 'set' || $item['unit_type'] === 'meter') ? '1' : '0.01'; ?>"
                                               <?php echo ($item['unit_type'] === 'meter' && !empty($item['max_bundle_qty'])) ? 'max="' . (int) $item['max_bundle_qty'] . '"' : ($item['stock'] > 0 ? 'max="' . $item['stock'] . '"' : ''); ?>>
                                        <button type="button" class="ui-button ui-button--small ui-button--secondary qty-inc" aria-label="Increase quantity">+</button>
                                    </form>

                                    <form method="POST" action="/move-to-wishlist.php" class="u-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <button class="ui-button ui-button--small ui-button--outline">Move to Wishlist</button>
                                    </form>

                                    <form method="POST" action="/remove-cart.php" class="u-inline" data-confirm-modal data-confirm-title="Remove Item?" data-confirm-message="Remove this product from your cart?" data-confirm-ok="Remove" data-confirm-cancel="Keep Item" data-confirm-variant="danger">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="cart_key" value="<?php echo e($item['cart_key']); ?>">
                                        <button class="ui-button ui-button--small ui-button--danger-outline">Remove</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="surface-panel u-p-3 u-mt-4">
                        <h5 class="u-mb-3">Saved for Later</h5>
                        <?php if (empty($wishlistItems)): ?>
                            <p class="u-text-muted u-text-small u-mb-0">No products saved yet. Use "Move to Wishlist" on any cart item.</p>
                        <?php else: ?>
                            <?php foreach ($wishlistItems as $w): ?>
                                <div class="saved-cart-item u-flex u-justify-between u-items-center u-py-2 u-border-bottom">
                                    <div class="saved-cart-item__details">
                                        <a href="/fabric.php?id=<?php echo $w['id']; ?>" class="u-font-semibold u-no-underline"><?php echo e($w['name']); ?></a>
                                        <div class="u-text-small u-text-muted"><?php echo e(money($w['unit_price'])); ?> / <?php echo e($w['quantity_unit_label'] === 'pieces' ? 'piece' : ($w['quantity_unit_label'] === 'sets' ? 'set' : $w['quantity_unit_label'])); ?></div>
                                        <div class="u-text-small u-text-muted">
                                            <?php if ($w['unit_type'] === 'meter' && !empty($w['meter_length']) && !empty($w['bundle_quantity'])): ?>
                                                Qty: <?php echo e((string) $w['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $w['meter_length'])); ?>m = <?php echo e($w['quantity_text']); ?>m
                                            <?php else: ?>
                                                Qty: <?php echo e($w['quantity_text']); ?> <?php echo e($w['quantity_unit_label']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="saved-cart-item__actions u-flex u-gap-2">
                                        <form method="POST" action="/move-to-cart.php" class="u-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="ui-button ui-button--small ui-button--primary">Move to Cart</button>
                                        </form>
                                        <form method="POST" action="/remove-wishlist.php" class="u-inline" data-confirm-modal data-confirm-title="Remove Saved Item?" data-confirm-message="Remove this product from your saved items?" data-confirm-ok="Remove" data-confirm-cancel="Keep Item" data-confirm-variant="danger">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cart_key" value="<?php echo e($w['cart_key']); ?>">
                                            <button class="ui-button ui-button--small ui-button--danger-outline">Remove</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="l-col-lg-third">
                    <div class="surface-panel u-p-4 cart-summary-card">
                        <h5 class="u-mb-3">Cart Summary</h5>

                        <div class="u-flex u-justify-between u-mb-2"><span>Subtotal</span><span class="u-font-semibold"><?php echo e(money($subtotal)); ?></span></div>
                        <hr>
                        <a class="ui-button ui-button--primary u-w-full ui-button--large" href="/checkout.php">Proceed to Checkout</a>
                        <div class="trust-badge-block u-mt-3" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        <a href="/catalog.php" class="ui-button ui-button--secondary u-w-full u-mt-2">Continue Shopping</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
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
