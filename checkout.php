<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/helpers/coupon-functions.php';
require_once __DIR__ . '/includes/security/customer-auth.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$cartMeterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];
$hydrated = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
$items = $hydrated['items'];
$subtotal = CartService::cart_items_subtotal($items);

$cartAdjusted = false;
if (!empty($hydrated['quantity_updates'])) {
    foreach ($hydrated['quantity_updates'] as $cartKey => $quantity) {
        $_SESSION['cart'][$cartKey] = $quantity;
    }
    $cartAdjusted = true;
}
if (!empty($hydrated['removed_keys'])) {
    foreach ($hydrated['removed_keys'] as $cartKey) {
        unset($_SESSION['cart'][$cartKey], $_SESSION['cart_size'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
    }
    $cartAdjusted = true;
}
if ($cartAdjusted) {
    if (!empty($_SESSION['customer_id'])) {
        CartService::cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'] ?? [], $_SESSION['cart_meter_length'] ?? []);
    }
    if (!empty($hydrated['invalid_variant_found'])) {
        flash('error', 'Some unavailable variants were removed from your cart. Please review before checkout.');
    } elseif (!empty($hydrated['quantity_updates'])) {
        flash('info', 'Some cart quantities were adjusted to available stock.');
    }
}

if (empty($items)) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

$errors = [];
$old = CheckoutInput::defaults();
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$old = array_merge($old, CheckoutReadService::customerPrefill($conn, $customerId));

if (!empty($_SESSION['checkout_old']) && is_array($_SESSION['checkout_old'])) {
    $old = array_merge($old, $_SESSION['checkout_old']);
    unset($_SESSION['checkout_old']);
}

if (!empty($_SESSION['checkout_errors']) && is_array($_SESSION['checkout_errors'])) {
    $errors = $_SESSION['checkout_errors'];
    unset($_SESSION['checkout_errors']);
}
// India-only checkout path: keep country fixed for consistent pricing/shipping rules.
$old['country'] = 'India';

$addressState = CheckoutReadService::savedAddressState(
    $conn,
    $customerId,
    $old,
    (int) ($_GET['address_id'] ?? 0)
);
$old = $addressState['form'];
$savedAddresses = $addressState['addresses'];
$selectedAddressId = (int) $addressState['selected_address_id'];

$selectedPayment = in_array((string) ($old['payment_method'] ?? 'cod'), ['cod', 'razorpay'], true)
    ? (string) $old['payment_method']
    : 'cod';
$selectedOnlineMethod = InventoryService::sanitize_online_payment_method((string) ($old['online_method'] ?? 'upi'));
if ($selectedOnlineMethod === '') {
    $selectedOnlineMethod = 'upi';
}
$codFeeApply = ($selectedPayment === 'cod') ? 1 : 0;
$codGuardSettings = function_exists('cod_guard_settings') ? cod_guard_settings() : [];
$codWhatsappConsentAvailable = function_exists('cod_guard_whatsapp_configured')
    && cod_guard_whatsapp_configured($codGuardSettings);
$codWhatsappThreshold = max(0.0, (float) ($codGuardSettings['whatsapp_threshold'] ?? PHP_FLOAT_MAX));
$codWhatsappConsentChecked = (int) ($old['cod_whatsapp_consent'] ?? 0) === 1;
$countryForCalc = trim((string) ($old['country'] ?? ''));
$couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
$couponInfo = get_active_coupon_discount_for_customer($conn, $couponCode, (float) $subtotal, $customerId);
if (!$couponInfo['valid'] && $couponCode !== '') {
    unset($_SESSION['applied_coupon_code']);
}
$discountAmount = $couponInfo['valid'] ? (float) $couponInfo['discount'] : 0.00;
$discountAmount = min($discountAmount, $subtotal); // discount applies to product subtotal only - shipping is never discounted
$taxableAmount = max(0.0, $subtotal - $discountAmount);
$hasCompleteDelivery = CheckoutInput::hasCompleteDelivery($old);

$shipping = CartService::checkout_shipping_breakdown((float) $subtotal, $countryForCalc, $selectedPayment, $codFeeApply === 1);
$isIndia = (bool) $shipping['is_india'];
$shippingQuote = [
    'base_shipping' => (float) $shipping['base_shipping'],
    'cod_fee' => (float) $shipping['cod_fee'],
    'shipping_total' => (float) $shipping['shipping_total'],
    'source' => 'manual',
    'courier_name' => '',
    'courier_id' => 0,
];
if ($hasCompleteDelivery) {
    $shippingQuote = apply_filters('shipping.quote', $shippingQuote, [
        'conn' => $conn,
        'subtotal' => (float) $subtotal,
        'invoice_value' => (float) $taxableAmount,
        'country' => $countryForCalc,
        'pincode' => (string) ($old['pincode'] ?? ''),
        'payment_method' => $selectedPayment,
        'items' => $items,
    ]);
}
$baseShippingAmount = max(0.0, round((float) ($shippingQuote['base_shipping'] ?? $shipping['base_shipping']), 2));
$codFeeAmount = max(0.0, round((float) ($shippingQuote['cod_fee'] ?? $shipping['cod_fee']), 2));
$shippingAmount = round($baseShippingAmount + $codFeeAmount, 2);
$shippingRateSource = trim((string) ($shippingQuote['source'] ?? 'manual')) ?: 'manual';
$selectedCourierName = trim((string) ($shippingQuote['courier_name'] ?? ''));
$selectedCourierId = max(0, (int) ($shippingQuote['courier_id'] ?? 0));
$deliveryEstimate = $hasCompleteDelivery ? DeliveryEstimateService::calculate($items, $shippingRateSource) : [];
$shippingQuoteToken = $hasCompleteDelivery
    ? InventoryService::shipping_quote_store(
        (float) $taxableAmount,
        (string) $countryForCalc,
        (string) ($old['pincode'] ?? ''),
        (string) $selectedPayment,
        (float) $baseShippingAmount,
        (float) $codFeeAmount,
        (float) $shippingAmount,
        (string) $shippingRateSource,
        $selectedCourierName,
        $selectedCourierId,
        $deliveryEstimate
    )
    : '';
// Tax-inclusive pricing: GST is already embedded in product prices.
// Total = (subtotal - discount) + shipping. No extra GST added.
$totalAmount    = round($taxableAmount + ($hasCompleteDelivery ? $shippingAmount : 0), 2);
$codWhatsappConsentRequiredInitially = $codWhatsappConsentAvailable
    && $selectedPayment === 'cod'
    && $totalAmount >= $codWhatsappThreshold;
// Back-calculate GST included in price (for display info only)
$gstRate        = (float) configured_gst_rate();
$gstInclAmount  = ($isIndia && $gstRate > 0) ? round($taxableAmount * $gstRate / (100 + $gstRate), 2) : 0.0;
$internationalQuoteUrl = '/international-buyers.php';
if (!$isIndia) {
    $internationalQuoteUrl .= '?' . http_build_query([
        'name' => (string) ($old['full_name'] ?? ''),
        'email' => (string) ($old['email'] ?? ''),
        'phone' => (string) ($old['phone'] ?? ''),
        'country' => (string) ($old['country'] ?? ''),
        'notes' => (string) ($old['order_notes'] ?? ''),
    ]);
}

$metaTitle = SiteContext::title('Checkout');
do_action('checkout.view', [
    'conn' => $conn,
    'customer_id' => $customerId,
    'email' => (string) ($old['email'] ?? ''),
    'phone' => (string) ($old['phone'] ?? ''),
    'content_ids' => array_values(array_map(static fn($item) => (string) ($item['id'] ?? ''), $items)),
    'num_items' => count($items),
]);

// One-time order nonce - consumed on first successful place-order.php submission
// so that double-click / back-and-resubmit creates only one order.
$_SESSION['order_nonce'] = bin2hex(random_bytes(16));

include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Checkout</h1>
        <p class="mb-0">Cash on Delivery and Razorpay online payment available</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <?php if (!empty($errors['_cart'])): ?>
            <div class="alert alert-danger"><?php echo e($errors['_cart']); ?></div>
        <?php endif; ?>
        <?php if (!$isIndia): ?>
            <div class="alert alert-warning">
                International checkout is inquiry-only for now. Please use
                <a href="/international-buyers.php" class="alert-link">Request International Quote</a>.
            </div>
        <?php endif; ?>

        <div class="row g-4 checkout-layout">
            <div class="col-lg-7 order-0 checkout-form-column">
                <form id="checkout_form" method="POST" action="/place-order.php" novalidate data-confirm-modal data-confirm-context="checkout">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_nonce" value="<?php echo e($_SESSION['order_nonce']); ?>">
                    <input type="hidden" name="online_method" id="online_method" value="<?php echo e($selectedOnlineMethod); ?>">
                    <input type="hidden" name="shipping_address_id" id="shipping_address_id" value="<?php echo (int) ($old['shipping_address_id'] ?? 0); ?>">
                    <input type="hidden" name="shipping_quote_token" id="shipping_quote_token" value="<?php echo e($shippingQuoteToken); ?>">

                    <div class="surface-panel p-4 mb-4 checkout-section" id="checkout_section_address">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="small text-muted">Step 1 of 3: Delivery</div>
                                <h5 class="mb-0">Delivery Details</h5>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="checkout_edit_address">Edit</button>
                        </div>
                        <div class="checkout-section-summary d-none" id="checkout_address_summary"></div>
                        <div class="checkout-section-body" id="checkout_address_body">
                        <?php if ($customerId <= 0): ?>
                            <div class="alert alert-light border py-2 px-3 small mb-3">
                                Have an account? <a href="/customer/login?return=%2Fcheckout">Log in</a> for faster checkout.
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($savedAddresses)): ?>
                            <div class="mb-3">
                                <label class="form-label">Use Saved Address</label>
                                <select class="form-select" id="saved_address_select">
                                    <option value="">Select saved address</option>
                                    <?php foreach ($savedAddresses as $addr): ?>
                                        <?php
                                            $addrId = (int) ($addr['id'] ?? 0);
                                            $addrLine = trim((string) ($addr['address_line'] ?? ''));
                                            $addrLabel = trim((string) ($addr['label'] ?? ''));
                                            if ($addrLabel === '') {
                                                $addrLabel = 'Address #' . $addrId;
                                            }
                                        ?>
                                        <option
                                            value="<?php echo $addrId; ?>"
                                            data-full-name="<?php echo e((string) ($addr['full_name'] ?? '')); ?>"
                                            data-phone="<?php echo e((string) ($addr['phone'] ?? '')); ?>"
                                            data-address="<?php echo e($addrLine); ?>"
                                            data-city="<?php echo e((string) ($addr['city'] ?? '')); ?>"
                                            data-state="<?php echo e((string) ($addr['state'] ?? '')); ?>"
                                            data-pincode="<?php echo e((string) ($addr['pincode'] ?? '')); ?>"
                                            data-country="<?php echo e((string) ($addr['country'] ?? '')); ?>"
                                            <?php echo ((int) ($old['shipping_address_id'] ?? 0) === $addrId) ? 'selected' : ''; ?>
                                        >
                                            <?php echo e($addrLabel . ' - ' . (strlen($addrLine) > 44 ? substr($addrLine, 0, 41) . '...' : $addrLine)); ?>
                                            <?php if ((int) ($addr['is_default_shipping'] ?? 0) === 1): ?> (Default)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" id="checkout_full_name" name="full_name" class="<?php echo form_class($errors, 'full_name'); ?>" required value="<?php echo e($old['full_name']); ?>">
                                <?php echo form_error($errors, 'full_name'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Phone *</label>
                                <input type="text" id="checkout_phone" name="phone" class="<?php echo form_class($errors, 'phone'); ?>" required value="<?php echo e($old['phone']); ?>">
                                <?php echo form_error($errors, 'phone'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email *</label>
                                <input type="email" id="checkout_email" name="email" class="<?php echo form_class($errors, 'email'); ?>" required value="<?php echo e($old['email']); ?>">
                                <?php echo form_error($errors, 'email'); ?>
                            </div>
                            <?php if ($customerId <= 0): ?>
                                <div class="col-12">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="1" id="create_account" name="create_account" <?php echo !empty($old['create_account']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="create_account">
                                            Email me an account activation link after ordering
                                        </label>
                                    </div>
                                    <div class="small text-muted">You will choose a password from a secure email link after checkout.</div>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label">Address *</label>
                                <textarea id="checkout_address" name="address" class="<?php echo form_class($errors, 'address'); ?>" rows="2" maxlength="500" required><?php echo e($old['address']); ?></textarea>
                                <?php echo form_error($errors, 'address'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">City *</label>
                                <input type="text" id="checkout_city" name="city" class="<?php echo form_class($errors, 'city'); ?>" required value="<?php echo e($old['city']); ?>">
                                <?php echo form_error($errors, 'city'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">State *</label>
                                <input type="text" id="checkout_state" name="state" class="<?php echo form_class($errors, 'state'); ?>" required value="<?php echo e($old['state']); ?>">
                                <?php echo form_error($errors, 'state'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Pincode *</label>
                                <input type="text" id="checkout_pincode" name="pincode" class="<?php echo form_class($errors, 'pincode'); ?>" required value="<?php echo e($old['pincode']); ?>">
                                <?php echo form_error($errors, 'pincode'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Country *</label>
                                <select id="checkout_country" name="country" class="<?php echo form_class($errors, 'country'); ?>" required>
                                    <option value="India" selected>India</option>
                                </select>
                                <?php echo form_error($errors, 'country'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Order Notes</label>
                                <textarea name="order_notes" class="form-control" rows="2" maxlength="500"><?php echo e($old['order_notes']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="button" class="btn btn-primary w-100" id="checkout_continue_payment">Continue to Payment</button>
                                <div class="small mt-2" id="checkout_delivery_status" aria-live="polite">
                                    <?php echo $hasCompleteDelivery ? 'Delivery details verified. You can continue to payment.' : 'Complete your delivery address to calculate shipping.'; ?>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>

                    <div class="surface-panel p-4 mb-4 checkout-section<?php echo $hasCompleteDelivery ? '' : ' d-none'; ?>" id="checkout_section_payment" aria-hidden="<?php echo $hasCompleteDelivery ? 'false' : 'true'; ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="small text-muted">Step 2 of 3: Payment</div>
                                <h5 class="mb-0">Payment Method</h5>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="checkout_edit_payment">Edit</button>
                        </div>
                        <div class="checkout-section-summary d-none" id="checkout_payment_summary"></div>
                        <div class="checkout-section-body" id="checkout_payment_body">
                        <div class="checkout-payment-options">
                            <label class="checkout-pay-option" for="payment_cod" data-pay-option="cod">
                                <span class="checkout-pay-main">
                                    <input class="form-check-input mt-0" type="radio" name="payment_method" id="payment_cod" value="cod" <?php echo ($old['payment_method'] ?? 'cod') === 'cod' ? 'checked' : ''; ?>>
                                    <span>
                                        <strong>Cash on Delivery (COD)</strong>
                                        <small class="d-block text-muted">Pay in cash when your order is delivered.</small>
                                    </span>
                                </span>
                            </label>
                            <div class="checkout-pay-panel" id="cod-panel">
                                <div class="small text-muted">
                                    COD handling fee of Rs 50 is applied for India orders.
                                </div>
                                <?php if ($codWhatsappConsentAvailable): ?>
                                    <div class="form-check mt-3 checkout-whatsapp-consent<?php echo !$codWhatsappConsentRequiredInitially ? ' d-none' : ''; ?><?php echo isset($errors['cod_whatsapp_consent']) ? ' is-invalid' : ''; ?>" id="cod_whatsapp_consent_wrap">
                                        <input class="form-check-input<?php echo isset($errors['cod_whatsapp_consent']) ? ' is-invalid' : ''; ?>" type="checkbox" name="cod_whatsapp_consent" id="cod_whatsapp_consent" value="1" <?php echo $codWhatsappConsentChecked ? 'checked' : ''; ?> <?php echo $codWhatsappConsentRequiredInitially ? 'required aria-required="true"' : 'aria-required="false"'; ?>>
                                        <label class="form-check-label small" for="cod_whatsapp_consent">
                                            I agree to receive transactional WhatsApp messages for COD confirmation and updates about this order. This does not include marketing messages.
                                        </label>
                                        <?php echo form_error($errors, 'cod_whatsapp_consent'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <label class="checkout-pay-option" for="payment_razorpay" data-pay-option="razorpay">
                                <span class="checkout-pay-main">
                                    <input class="form-check-input mt-0" type="radio" name="payment_method" id="payment_razorpay" value="razorpay" <?php echo ($old['payment_method'] ?? '') === 'razorpay' ? 'checked' : ''; ?>>
                                    <span>
                                        <strong>Pay Online (Razorpay)</strong>
                                        <small class="d-block text-muted">
                                            Choose UPI, Card, Netbanking or EMI in secure checkout.
                                        </small>
                                    </span>
                                </span>
                            </label>
                            <div class="checkout-pay-panel" id="razorpay-panel">
                                <div class="checkout-online-methods">
                                    <button type="button" class="checkout-online-method is-active" data-online-method="upi" aria-pressed="true">UPI</button>
                                    <button type="button" class="checkout-online-method" data-online-method="card" aria-pressed="false">Card</button>
                                    <button type="button" class="checkout-online-method" data-online-method="emi" aria-pressed="false">EMI</button>
                                </div>
                                <noscript>
                                    <div class="mb-3">
                                        <label class="form-label mb-1" for="online_method_noscript">Online payment type</label>
                                        <select class="form-select" id="online_method_noscript" name="online_method">
                                            <option value="upi" <?php echo $selectedOnlineMethod === 'upi' ? 'selected' : ''; ?>>UPI</option>
                                            <option value="card" <?php echo $selectedOnlineMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                                            <option value="emi" <?php echo $selectedOnlineMethod === 'emi' ? 'selected' : ''; ?>>EMI</option>
                                        </select>
                                    </div>
                                </noscript>
                                <div class="checkout-online-panels">
                                    <div class="checkout-online-panel is-active" data-online-panel="upi">
                                        <div class="small text-muted mb-2">Pay instantly with any UPI app in secure Razorpay checkout.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">Google Pay</span>
                                            <span class="checkout-brand-chip">PhonePe</span>
                                            <span class="checkout-brand-chip">Paytm</span>
                                            <span class="checkout-brand-chip">BHIM</span>
                                        </div>
                                    </div>
                                    <div class="checkout-online-panel" data-online-panel="card">
                                        <div class="small text-muted mb-2">Domestic and international cards are supported.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">Visa</span>
                                            <span class="checkout-brand-chip">Mastercard</span>
                                            <span class="checkout-brand-chip">RuPay</span>
                                            <span class="checkout-brand-chip">Amex</span>
                                        </div>
                                    </div>
                                    <div class="checkout-online-panel" data-online-panel="emi">
                                        <div class="small text-muted mb-2">No-cost/standard EMI options shown based on card and bank eligibility.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">HDFC</span>
                                            <span class="checkout-brand-chip">ICICI</span>
                                            <span class="checkout-brand-chip">SBI</span>
                                            <span class="checkout-brand-chip">Axis</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>

                    <?php if ($isIndia): ?>
                        <div id="checkout_review_section" class="<?php echo $hasCompleteDelivery ? '' : 'd-none'; ?>" aria-hidden="<?php echo $hasCompleteDelivery ? 'false' : 'true'; ?>">
                        <div class="small text-muted mb-2">Step 3 of 3: Review</div><button type="submit" id="checkout_submit" class="btn btn-primary btn-lg w-100"><?php echo $selectedPayment === 'cod' ? 'Place COD Order — ' : 'Pay Securely — '; ?><?php echo e(money($totalAmount, 'INR', true)); ?></button>
                        <div class="trust-badge-block mt-3 mb-2" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo e($internationalQuoteUrl); ?>" class="btn btn-primary btn-lg w-100">Request International Quote</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="col-lg-5 checkout-summary-column">
                <div class="surface-panel p-4 checkout-summary-sticky">
                    <h5 class="mb-3">Order Summary</h5>
                    <?php foreach ($items as $item): ?>
                        <div class="d-flex justify-content-between mb-2 small">
                            <div>
                                <span class="fw-semibold"><?php echo e($item['name']); ?></span>
                                <?php if ($item['unit_type'] === 'meter' && !empty($item['meter_length']) && !empty($item['bundle_quantity'])): ?>
                                    <span class="text-muted"> - <?php echo e((string) $item['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $item['meter_length'])); ?>m = <?php echo e($item['quantity_text']); ?>m</span>
                                <?php else: ?>
                                    <span class="text-muted"> - <?php echo e($item['quantity_text']); ?> <?php echo e($item['quantity_unit_label']); ?></span>
                                    <?php if ($item['unit_type'] === 'set' && (int) ($item['units_per_set'] ?? 0) > 0): ?>
                                        <span class="text-muted"> (<?php echo (int) $item['quantity']; ?> sets x <?php echo (int) $item['units_per_set']; ?> = <?php echo (int) $item['quantity'] * (int) $item['units_per_set']; ?> pieces)</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($item['selected_size'] !== ''): ?>
                                    <span class="text-muted"> (<?php echo e($item['selected_size']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            <span><?php echo e(money($item['subtotal'])); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="/apply-coupon.php" class="mb-3" data-preserve-checkout-state>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="redirect_to" value="checkout">
                        <input type="hidden" name="shipping_address_id" value="<?php echo (int) ($old['shipping_address_id'] ?? 0); ?>">
                        <label class="form-label">Coupon Code</label>
                        <div class="d-flex gap-2">
                            <input type="text" name="coupon_code" class="form-control" placeholder="Enter code" value="<?php echo e((string) ($couponInfo['code'] ?? '')); ?>">
                            <button class="btn btn-outline-dark" type="submit">Apply</button>
                        </div>
                    </form>

                    <?php if ($couponInfo['valid']): ?>
                    <form method="POST" action="/remove-coupon.php" class="mb-2" data-preserve-checkout-state>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="redirect_to" value="checkout">
                        <input type="hidden" name="shipping_address_id" value="<?php echo (int) ($old['shipping_address_id'] ?? 0); ?>">
                        <div class="d-flex justify-content-between small">
                            <span>Coupon: <strong><?php echo e($couponInfo['code']); ?></strong></span>
                            <button type="submit" class="btn btn-link btn-sm p-0 text-danger">Remove</button>
                        </div>
                    </form>
                    <?php endif; ?>

                    <hr>
                    <?php if ($couponInfo['valid']): ?>
                    <div class="d-flex justify-content-between mb-1 small">
                        <span>Coupon (<?php echo e($couponInfo['code']); ?>)</span>
                        <span class="text-success">Applied</span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-1">
                        <span>Subtotal</span>
                        <span id="summary_subtotal"><?php echo e(money($subtotal)); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>Discount</span>
                        <span class="text-success" id="summary_discount">- <?php echo e(money($discountAmount)); ?></span>
                    </div>

                    <div class="d-flex justify-content-between mb-1">
                        <span>Shipping</span>
                        <span id="summary_shipping"><?php echo $hasCompleteDelivery ? e(money($baseShippingAmount)) : '—'; ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>COD Fee</span>
                        <span id="summary_cod_fee"><?php echo $hasCompleteDelivery ? e(money($codFeeAmount)) : '—'; ?></span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top">
                        <span>Total</span>
                        <span id="summary_total"><?php echo e(money($totalAmount)); ?></span>
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0 checkout-summary-note" id="summary_shipping_note">
                        <?php if (!$hasCompleteDelivery): ?>
                            Enter your delivery address and pincode to calculate shipping.
                        <?php elseif (strtolower((string) $shippingRateSource) !== 'manual'): ?>
                            Live courier rate active<?php echo $selectedCourierName !== '' ? ': ' . e($selectedCourierName) : '.'; ?>
                        <?php else: ?>
                            Manual shipping active. Free shipping above Rs 999; otherwise Rs 70. COD adds Rs 50 handling fee.
                        <?php endif; ?>
                        </div>
                        <div id="checkout_delivery_estimate" class="small text-muted mb-2"><?php if ($hasCompleteDelivery): ?>Estimated delivery: <?php echo e(DeliveryEstimateService::formatRange($deliveryEstimate['estimated_delivery_start'] ?? null,$deliveryEstimate['estimated_delivery_end'] ?? null)); ?><?php endif; ?></div>
                        <?php if ($isIndia): ?>
                        <div id="checkout_mobile_review_section" class="checkout-mobile-review d-lg-none mt-3<?php echo $hasCompleteDelivery ? '' : ' d-none'; ?>" aria-hidden="<?php echo $hasCompleteDelivery ? 'false' : 'true'; ?>">
                            <div class="small text-muted mb-2">Step 3 of 3: Review</div>
                            <button type="submit" form="checkout_form" id="mobile_place_order_btn" class="btn btn-primary btn-lg w-100">
                                <span id="mobile_place_order_label"><?php echo $selectedPayment === 'cod' ? 'Place COD Order — ' : 'Pay Securely — '; ?></span><span id="mobile_summary_total"><?php echo e(money($totalAmount)); ?></span>
                            </button>
                        </div>
                        <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var csrfToken = <?php echo json_encode(csrf_token()); ?>;
    var codRadio = document.getElementById('payment_cod');
    var razorpayRadio = document.getElementById('payment_razorpay');
    var countryInput = document.querySelector('[name="country"]');
    var savedAddressSelect = document.getElementById('saved_address_select');
    var shippingAddressIdInput = document.getElementById('shipping_address_id');
    var fullNameInput = document.getElementById('checkout_full_name');
    var phoneInput = document.getElementById('checkout_phone');
    var addressInput = document.getElementById('checkout_address');
    var cityInput = document.getElementById('checkout_city');
    var stateInput = document.getElementById('checkout_state');
    var pincodeInput = document.getElementById('checkout_pincode');
    var countryFieldInput = document.getElementById('checkout_country');
    var subtotal = <?php echo json_encode((float) $subtotal); ?>;
    var discount = <?php echo json_encode((float) $discountAmount); ?>;
    var gstRate  = <?php echo json_encode((float) $gstRate); ?>;
    var codWhatsappThreshold = <?php echo json_encode((float) $codWhatsappThreshold); ?>;
    var checkoutCurrentTotal = <?php echo json_encode((float) $totalAmount); ?>;

    var shippingEl = document.getElementById('summary_shipping');
    var codFeeEl = document.getElementById('summary_cod_fee');
    var totalEl = document.getElementById('summary_total');
    var shippingNoteEl = document.getElementById('summary_shipping_note');
    var shippingSource = <?php echo json_encode((string) $shippingRateSource); ?>;
    var shippingCourierName = <?php echo json_encode((string) $selectedCourierName); ?>;
    var shippingDebugReason = <?php echo json_encode((string) ($shippingQuote['debug_reason'] ?? '')); ?>;
    var shippingDebugMessage = <?php echo json_encode((($GLOBALS['_app_mode'] ?? '') === 'local') ? (string) ($shippingQuote['debug_message'] ?? '') : ''); ?>;
    var shippingRateTimer = null;
    var shippingRateRequestId = 0;
    var shippingRateAbortController = null;

    var payOptionCards = document.querySelectorAll('[data-pay-option]');
    var codPanel = document.getElementById('cod-panel');
    var codWhatsappConsentWrap = document.getElementById('cod_whatsapp_consent_wrap');
    var codWhatsappConsent = document.getElementById('cod_whatsapp_consent');
    var razorpayPanel = document.getElementById('razorpay-panel');
    var onlineMethodButtons = document.querySelectorAll('.checkout-online-method');
    var onlinePanels = document.querySelectorAll('.checkout-online-panel');
    var onlineMethodInput = document.getElementById('online_method');
    var shippingQuoteTokenInput = document.getElementById('shipping_quote_token');
    var mobileTotalEl = document.getElementById('mobile_summary_total');
    var mobileSubmitBtn = document.getElementById('mobile_place_order_btn');
    var mobileSubmitLabel = document.getElementById('mobile_place_order_label');
    var checkoutForm = document.getElementById('checkout_form');
    var sectionAddress = document.getElementById('checkout_section_address');
    var sectionPayment = document.getElementById('checkout_section_payment');
    var sectionAddressBody = document.getElementById('checkout_address_body');
    var sectionPaymentBody = document.getElementById('checkout_payment_body');
    var sectionAddressSummary = document.getElementById('checkout_address_summary');
    var sectionPaymentSummary = document.getElementById('checkout_payment_summary');
    var editAddressBtn = document.getElementById('checkout_edit_address');
    var editPaymentBtn = document.getElementById('checkout_edit_payment');
    var createAccountCheckbox = document.getElementById('create_account');
    var createAccountFields = document.getElementById('create_account_fields');
    var checkoutSubmit = document.getElementById('checkout_submit');
    var checkoutDeliveryEstimate = document.getElementById('checkout_delivery_estimate');
    var createAccountPassword = document.getElementById('create_account_password');
    var createAccountConfirmPassword = document.getElementById('create_account_confirm_password');
    var couponStateForms = document.querySelectorAll('[data-preserve-checkout-state]');
    var continuePaymentBtn = document.getElementById('checkout_continue_payment');
    var deliveryStatusEl = document.getElementById('checkout_delivery_status');
    var checkoutReviewSection = document.getElementById('checkout_review_section');
    var mobileReviewSection = document.getElementById('checkout_mobile_review_section');
    var deliveryUnlocked = <?php echo ($hasCompleteDelivery && $shippingQuoteToken !== '') ? 'true' : 'false'; ?>;
    var deliveryRequestPending = false;

    if (!codRadio || !razorpayRadio || !shippingEl || !codFeeEl || !totalEl || !countryInput) {
        return;
    }

    function applySavedAddressOption(optionEl) {
        if (!optionEl) return;
        var selectedId = String(optionEl.value || '');
        if (shippingAddressIdInput) {
            shippingAddressIdInput.value = selectedId;
        }
        if (selectedId === '') {
            return;
        }
        if (fullNameInput) fullNameInput.value = optionEl.getAttribute('data-full-name') || '';
        if (phoneInput) phoneInput.value = optionEl.getAttribute('data-phone') || '';
        if (addressInput) addressInput.value = optionEl.getAttribute('data-address') || '';
        if (cityInput) cityInput.value = optionEl.getAttribute('data-city') || '';
        if (stateInput) stateInput.value = optionEl.getAttribute('data-state') || '';
        if (pincodeInput) pincodeInput.value = optionEl.getAttribute('data-pincode') || '';
        if (countryFieldInput) countryFieldInput.value = 'India';
    }

    function toMoney(v) {
        return 'Rs ' + Number(v).toFixed(2);
    }

    function codRequiresWhatsappConsent() {
        return !!(codWhatsappConsent && codRadio.checked && checkoutCurrentTotal >= codWhatsappThreshold);
    }

    function syncWhatsappConsentRequirement() {
        var required = codRequiresWhatsappConsent();
        if (codWhatsappConsentWrap) codWhatsappConsentWrap.classList.toggle('d-none', !required);
        if (!codWhatsappConsent) return;
        codWhatsappConsent.required = required;
        codWhatsappConsent.setAttribute('aria-required', required ? 'true' : 'false');
        if (!required) codWhatsappConsent.classList.remove('is-invalid');
    }

    function setCouponStateField(form, name, value) {
        var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        input.value = String(value == null ? '' : value);
    }

    function preserveCheckoutState(form) {
        var emailInput = document.getElementById('checkout_email');
        var notesInput = document.querySelector('[name="order_notes"]');
        var checkedPayment = document.querySelector('[name="payment_method"]:checked');
        var state = {
            full_name: fullNameInput ? fullNameInput.value : '',
            phone: phoneInput ? phoneInput.value : '',
            email: emailInput ? emailInput.value : '',
            address: addressInput ? addressInput.value : '',
            city: cityInput ? cityInput.value : '',
            state: stateInput ? stateInput.value : '',
            pincode: pincodeInput ? pincodeInput.value : '',
            country: 'India',
            order_notes: notesInput ? notesInput.value : '',
            payment_method: checkedPayment ? checkedPayment.value : 'cod',
            online_method: onlineMethodInput ? onlineMethodInput.value : '',
            shipping_address_id: shippingAddressIdInput ? shippingAddressIdInput.value : '0'
        };
        state.cod_whatsapp_consent = codWhatsappConsent && codWhatsappConsent.checked ? '1' : '0';
        Object.keys(state).forEach(function (name) {
            setCouponStateField(form, name, state[name]);
        });
    }

    function setShippingNote(source, courierName, debugReason, debugMessage) {
        if (!shippingNoteEl) {
            return;
        }
        var src = String(source || '').trim().toLowerCase();
        var courier = String(courierName || '').trim();
        var reason = String(debugReason || '').trim();
        var message = String(debugMessage || '').trim();
        if (src !== '' && src !== 'manual') {
            shippingNoteEl.textContent = courier !== ''
                ? ('Live courier rate active: ' + courier + '.')
                : 'Live courier rate active.';
            return;
        }
        if (reason !== '') {
            var fallbackMessages = {
                shipping_quote_refreshing: 'Updating live shipping rate...',
                shipping_courier_disabled: 'Manual shipping is active because live courier rates are disabled.',
                shipping_courier_not_configured: 'Manual shipping is active because the courier service is not configured.',
                shipping_quote_context_invalid: 'Enter a valid delivery pincode to calculate live shipping.',
                bigship_origin_or_parcel_invalid: 'Manual shipping is active because parcel details need attention.',
                bigship_rate_api_failed: 'Live courier pricing is temporarily unavailable; manual shipping is being used.',
                bigship_rate_unavailable: 'No live courier rate is available for this order; manual shipping is being used.'
            };
            shippingNoteEl.textContent = fallbackMessages[reason]
                || ('Manual shipping fallback' + (message !== '' ? (': ' + message) : '.'));
            return;
        }
        shippingNoteEl.textContent = 'Manual shipping active. Free shipping above Rs 999; otherwise Rs 70. COD adds Rs 50 handling fee.';
    }

    function syncSummary() {
        var paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
        var taxable = Math.max(0, subtotal - discount);
        if (!deliveryUnlocked) {
            checkoutCurrentTotal = taxable;
            shippingEl.textContent = '—';
            codFeeEl.textContent = '—';
            totalEl.textContent = toMoney(taxable);
            if (mobileTotalEl) mobileTotalEl.textContent = toMoney(taxable);
            if (checkoutDeliveryEstimate) checkoutDeliveryEstimate.textContent = '';
            if (shippingNoteEl) shippingNoteEl.textContent = 'Enter your delivery address and pincode to calculate shipping.';
            syncWhatsappConsentRequirement();
            return;
        }
        var currentTotal = Number(String(totalEl.textContent || '').replace(/[^0-9.]/g, '')) || taxable;
        checkoutCurrentTotal = currentTotal;
        syncWhatsappConsentRequirement();
        if (checkoutSubmit) checkoutSubmit.textContent = (paymentMethod === 'cod' ? 'Place COD Order — ' : 'Pay Securely — ') + toMoney(currentTotal);
        if (mobileSubmitLabel) mobileSubmitLabel.textContent = paymentMethod === 'cod' ? 'Place COD Order — ' : 'Pay Securely — ';
    }

    function setCheckoutUnlocked(unlocked) {
        deliveryUnlocked = !!unlocked;
        if (sectionPayment) {
            sectionPayment.classList.toggle('d-none', !deliveryUnlocked);
            sectionPayment.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
        }
        if (checkoutReviewSection) {
            checkoutReviewSection.classList.toggle('d-none', !deliveryUnlocked);
            checkoutReviewSection.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
        }
        if (mobileReviewSection) {
            mobileReviewSection.classList.toggle('d-none', !deliveryUnlocked);
            mobileReviewSection.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
        }
        if (!deliveryUnlocked && shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
        syncSummary();
    }

    function setDeliveryRequestPending(pending) {
        deliveryRequestPending = !!pending;
        if (continuePaymentBtn) {
            continuePaymentBtn.disabled = deliveryRequestPending;
            continuePaymentBtn.classList.toggle('is-loading', deliveryRequestPending);
        }
        if (checkoutSubmit) checkoutSubmit.disabled = deliveryRequestPending;
        if (mobileSubmitBtn) mobileSubmitBtn.disabled = deliveryRequestPending;
    }

    function invalidateDeliveryQuote() {
        if (shippingRateTimer) {
            window.clearTimeout(shippingRateTimer);
            shippingRateTimer = null;
        }
        shippingRateRequestId++;
        if (shippingRateAbortController) {
            shippingRateAbortController.abort();
            shippingRateAbortController = null;
        }
        setDeliveryRequestPending(false);
        setCheckoutUnlocked(false);
        if (deliveryStatusEl) deliveryStatusEl.textContent = 'Delivery details changed. Continue again to refresh shipping.';
    }

    function syncCreateAccountFields() {
        if (!createAccountCheckbox || !createAccountFields) return;
        var enabled = !!createAccountCheckbox.checked;
        createAccountFields.style.display = enabled ? '' : 'none';
        if (createAccountPassword) createAccountPassword.required = enabled;
        if (createAccountConfirmPassword) createAccountConfirmPassword.required = enabled;
    }

    function isValidEmail(val) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(val || '').trim());
    }

    function setFieldError(input, hasError) {
        if (!input) return;
        input.classList.toggle('is-invalid', !!hasError);
    }

    function validateAddressSection() {
        var hasError = false;
        var fv = String(fullNameInput ? fullNameInput.value : '').trim();
        var ph = String(phoneInput ? phoneInput.value : '').trim();
        var em = String(document.getElementById('checkout_email') ? document.getElementById('checkout_email').value : '').trim();
        var ad = String(addressInput ? addressInput.value : '').trim();
        var ct = String(cityInput ? cityInput.value : '').trim();
        var st = String(stateInput ? stateInput.value : '').trim();
        var pc = String(pincodeInput ? pincodeInput.value : '').trim();
        setFieldError(fullNameInput, fv === '');
        setFieldError(phoneInput, !/^[0-9+\-\s()]{7,20}$/.test(ph));
        setFieldError(document.getElementById('checkout_email'), !isValidEmail(em));
        setFieldError(addressInput, ad === '');
        setFieldError(cityInput, ct === '');
        setFieldError(stateInput, st === '');
        setFieldError(pincodeInput, !/^[1-9][0-9]{5}$/.test(pc));
        hasError = [fullNameInput, phoneInput, document.getElementById('checkout_email'), addressInput, cityInput, stateInput, pincodeInput]
            .some(function (el) { return !!(el && el.classList.contains('is-invalid')); });
        return !hasError;
    }

    function updateSectionSummaries() {
        if (sectionAddressSummary) {
            var nm = String(fullNameInput ? fullNameInput.value : '').trim();
            var ph = String(phoneInput ? phoneInput.value : '').trim();
            var ct = String(cityInput ? cityInput.value : '').trim();
            var pc = String(pincodeInput ? pincodeInput.value : '').trim();
            sectionAddressSummary.textContent = [nm, ph, [ct, pc].filter(Boolean).join(' - ')].filter(Boolean).join(' | ');
        }
        if (sectionPaymentSummary) {
            sectionPaymentSummary.textContent = codRadio.checked ? 'Cash on Delivery' : 'Online Payment (Razorpay)';
        }
    }

    function setSectionCollapsed(sectionEl, bodyEl, summaryEl, editBtn, collapsed) {
        if (!sectionEl || !bodyEl || !summaryEl || !editBtn) return;
        sectionEl.classList.toggle('checkout-section-collapsed', !!collapsed);
        bodyEl.classList.toggle('d-none', !!collapsed);
        summaryEl.classList.toggle('d-none', !collapsed);
        editBtn.classList.toggle('d-none', !collapsed);
    }

    function focusFirstError() {
        if (!checkoutForm) return false;
        var firstError = checkoutForm.querySelector('.is-invalid');
        if (!firstError) return false;
        firstError.focus({ preventScroll: true });
        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return true;
    }

    async function maybeFetchLiveRate() {
        var country = String(countryInput.value || '').trim().toLowerCase();
        var pincode = pincodeInput ? String(pincodeInput.value || '').trim() : '';
        if (country !== 'india' || !/^[1-9][0-9]{5}$/.test(pincode)) {
            if (shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
            shippingDebugReason = 'shipping_quote_context_invalid';
            setShippingNote('manual', '', shippingDebugReason, '');
            return false;
        }
        if (shippingRateAbortController) shippingRateAbortController.abort();
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        shippingRateAbortController = controller;
        var requestId = ++shippingRateRequestId;
        if (shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
        var paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
        var requestContext = country + '|' + pincode + '|' + paymentMethod;
        var body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('pincode', pincode);
        body.set('payment_method', paymentMethod);
        setDeliveryRequestPending(true);
        if (shippingNoteEl) shippingNoteEl.textContent = 'Checking delivery service and shipping…';
        try {
            var res = await fetch('/shipping-rate.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString(),
                signal: controller ? controller.signal : undefined
            });
            var data = null;
            try {
                data = await res.json();
            } catch (jsonError) {
                data = null;
            }
            if (data && data.code === 'cart_changed' && data.reload === true) {
                window.location.reload();
                return false;
            }
            var currentContext = String(countryInput.value || '').trim().toLowerCase()
                + '|' + String(pincodeInput ? pincodeInput.value : '').trim()
                + '|' + (codRadio.checked ? 'cod' : 'razorpay');
            if (requestId !== shippingRateRequestId || requestContext !== currentContext) {
                return false;
            }
            if (!res.ok || !data || !data.ok) {
                shippingDebugReason = 'bigship_rate_api_failed';
                setShippingNote('manual', '', shippingDebugReason, '');
                return false;
            }
            var liveShipping = Number(data.base_shipping || 0);
            var liveCodFee = Number(data.cod_fee || 0);
            if (shippingQuoteTokenInput && data.quote_token) {
                shippingQuoteTokenInput.value = String(data.quote_token);
            }
            shippingSource = String(data.source || 'manual');
            shippingCourierName = String(data.courier_name || '');
            shippingDebugReason = String(data.debug_reason || '');
            shippingDebugMessage = String(data.debug_message || '');
            var taxable = Math.max(0, subtotal - discount);
            var total = taxable + liveShipping + liveCodFee;
            checkoutCurrentTotal = total;
            shippingEl.textContent = toMoney(liveShipping);
            codFeeEl.textContent = toMoney(liveCodFee);
            totalEl.textContent = toMoney(total);
            if (mobileTotalEl) {
                mobileTotalEl.textContent = toMoney(total);
            }
            if(checkoutSubmit){checkoutSubmit.textContent=(paymentMethod==='cod'?'Place COD Order — ':'Pay Securely — ')+toMoney(total);}
            if(mobileSubmitLabel){mobileSubmitLabel.textContent=paymentMethod==='cod'?'Place COD Order — ':'Pay Securely — ';}
            if(checkoutDeliveryEstimate&&data.estimated_delivery_label){checkoutDeliveryEstimate.textContent='Estimated delivery: '+String(data.estimated_delivery_label);}
            if(typeof window.gtag==='function'){window.gtag('event','add_shipping_info',{currency:'INR',value:total,shipping_tier:shippingSource});}
            setShippingNote(shippingSource, shippingCourierName, shippingDebugReason, shippingDebugMessage);
            syncWhatsappConsentRequirement();
            setCheckoutUnlocked(true);
            if (deliveryStatusEl) deliveryStatusEl.textContent = data.serviceability_status === 'live'
                ? 'Delivery address verified with a live courier rate.'
                : 'Delivery address verified with an estimated shipping rate.';
            return true;
        } catch (error) {
            if (error && error.name === 'AbortError') return false;
            if (requestId === shippingRateRequestId) {
                shippingDebugReason = 'bigship_rate_api_failed';
                setShippingNote('manual', '', shippingDebugReason, '');
            }
            return false;
        } finally {
            if (requestId === shippingRateRequestId) {
                shippingRateAbortController = null;
                setDeliveryRequestPending(false);
            }
        }
    }

    function scheduleLiveRate(delay) {
        if (shippingRateTimer) {
            window.clearTimeout(shippingRateTimer);
        }
        shippingRateTimer = window.setTimeout(function () { maybeFetchLiveRate(); }, Number(delay || 0));
    }

    function syncPaymentPanels() {
        var selected = codRadio.checked ? 'cod' : 'razorpay';
        payOptionCards.forEach(function (card) {
            card.classList.toggle('is-active', card.getAttribute('data-pay-option') === selected);
        });
        if (codPanel) {
            codPanel.classList.toggle('is-open', selected === 'cod');
        }
        syncWhatsappConsentRequirement();
        if (razorpayPanel) {
            razorpayPanel.classList.toggle('is-open', selected === 'razorpay');
        }
        if (onlineMethodInput && selected === 'cod') {
            onlineMethodInput.value = '';
        }
    }

    function activateOnlineMethod(method) {
        onlineMethodButtons.forEach(function (btn) {
            var selected = btn.getAttribute('data-online-method') === method;
            btn.classList.toggle('is-active', selected);
            btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        onlinePanels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-online-panel') === method);
        });
        if (onlineMethodInput) {
            onlineMethodInput.value = method || 'upi';
        }
    }

    codRadio.addEventListener('change', syncSummary);
    razorpayRadio.addEventListener('change', syncSummary);
    [codRadio,razorpayRadio].forEach(function(radio){radio.addEventListener('change',function(){if(this.checked&&typeof window.gtag==='function'){window.gtag('event','add_payment_info',{currency:'INR',value:Number(totalEl.textContent.replace(/[^0-9.]/g,''))||0,payment_type:this.value});}});});
    codRadio.addEventListener('change', syncPaymentPanels);
    razorpayRadio.addEventListener('change', syncPaymentPanels);
    countryInput.addEventListener('input', syncSummary);
    if (pincodeInput) {
        pincodeInput.addEventListener('input', function () {
            invalidateDeliveryQuote();
        });
    }
    countryInput.addEventListener('change', invalidateDeliveryQuote);
    function refreshQuoteForPaymentChange() {
        invalidateDeliveryQuote();
        if (validateAddressSection()) scheduleLiveRate(0);
    }
    codRadio.addEventListener('change', refreshQuoteForPaymentChange);
    razorpayRadio.addEventListener('change', refreshQuoteForPaymentChange);
    onlineMethodButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateOnlineMethod(btn.getAttribute('data-online-method'));
            razorpayRadio.checked = true;
            syncPaymentPanels();
            syncSummary();
            refreshQuoteForPaymentChange();
        });
    });
    if (savedAddressSelect) {
        savedAddressSelect.addEventListener('change', function () {
            applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
            invalidateDeliveryQuote();
        });
    }
    [fullNameInput, phoneInput, addressInput, cityInput, stateInput, pincodeInput, countryFieldInput].forEach(function (field) {
        if (!field) return;
        field.addEventListener('input', function () {
            if (shippingAddressIdInput && shippingAddressIdInput.value !== '') {
                shippingAddressIdInput.value = '';
            }
            if (savedAddressSelect && savedAddressSelect.value !== '') {
                savedAddressSelect.value = '';
            }
            if (field !== pincodeInput) invalidateDeliveryQuote();
        });
    });
    if (savedAddressSelect && savedAddressSelect.value !== '') {
        applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
    }
    setShippingNote(shippingSource, shippingCourierName, shippingDebugReason, shippingDebugMessage);
    setCheckoutUnlocked(deliveryUnlocked);
    if (continuePaymentBtn) {
        continuePaymentBtn.addEventListener('click', async function () {
            if (!validateAddressSection()) {
                setCheckoutUnlocked(false);
                if (deliveryStatusEl) deliveryStatusEl.textContent = 'Please complete the highlighted delivery fields.';
                focusFirstError();
                return;
            }
            var quoted = await maybeFetchLiveRate();
            if (!quoted) {
                setCheckoutUnlocked(false);
                if (deliveryStatusEl) deliveryStatusEl.textContent = 'We could not calculate shipping. Please check the pincode and try again.';
                return;
            }
            updateSectionSummaries();
            setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
            if (sectionPayment) sectionPayment.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
    }
    if (createAccountCheckbox) {
        createAccountCheckbox.addEventListener('change', syncCreateAccountFields);
        syncCreateAccountFields();
    }
    couponStateForms.forEach(function (form) {
        form.addEventListener('submit', function () {
            preserveCheckoutState(form);
        });
    });
    if (editAddressBtn) {
        editAddressBtn.addEventListener('click', function () {
            setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, false);
            if (fullNameInput) fullNameInput.focus();
        });
    }
    if (editPaymentBtn) {
        editPaymentBtn.addEventListener('click', function () {
            setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, false);
            if (codRadio) codRadio.focus();
        });
    }
    if (sectionPayment) {
        sectionPayment.addEventListener('click', function () {
            updateSectionSummaries();
            if (validateAddressSection()) {
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
            }
        });
    }
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function (ev) {
            updateSectionSummaries();
            var okAddress = validateAddressSection();
            var okWhatsappConsent = !codRequiresWhatsappConsent() || codWhatsappConsent.checked;
            if (codWhatsappConsent) codWhatsappConsent.classList.toggle('is-invalid', !okWhatsappConsent);
            if (!okAddress || !okWhatsappConsent || !deliveryUnlocked || !shippingQuoteTokenInput || shippingQuoteTokenInput.value === '') {
                ev.preventDefault();
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, false);
                setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, false);
                if (!okAddress) {
                    focusFirstError();
                } else if (!okWhatsappConsent && codWhatsappConsent) {
                    codWhatsappConsent.focus();
                } else if (deliveryStatusEl) {
                    deliveryStatusEl.textContent = 'Continue to payment again so we can confirm shipping.';
                }
                return;
            }
            setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
            setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, true);
        });
    }
    updateSectionSummaries();
    if (codWhatsappConsent) {
        codWhatsappConsent.addEventListener('change', function () {
            codWhatsappConsent.classList.toggle('is-invalid', !codWhatsappConsent.checked && codRequiresWhatsappConsent());
        });
    }
    focusFirstError();
    activateOnlineMethod(onlineMethodInput && onlineMethodInput.value ? onlineMethodInput.value : 'upi');
    syncPaymentPanels();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

