<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';
require_once __DIR__ . '/includes/customer-auth.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$cartMeterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];
$hydrated = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
$items = $hydrated['items'];
$subtotal = CartService::cart_items_subtotal($items);

if (!empty($hydrated['removed_keys'])) {
    foreach ($hydrated['removed_keys'] as $cartKey) {
        unset($_SESSION['cart'][$cartKey], $_SESSION['cart_size'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
    }
    if (!empty($_SESSION['customer_id'])) {
        CartService::cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart'] ?? [], $_SESSION['cart_meter_length'] ?? []);
    }
    if (!empty($hydrated['invalid_variant_found'])) {
        flash('error', 'Some unavailable variants were removed from your cart. Please review before checkout.');
    }
}

if (empty($items)) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

$errors = [];
$old = [
    'full_name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'country' => 'India',
    'order_notes' => '',
    'payment_method' => 'cod',
    'cod_fee_apply' => 1,
    'shipping_address_id' => 0,
    'create_account' => 0,
];

// Prefill checkout form from customer profile + latest order address when available.
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
if ($customerId > 0) {
    try {
        $prefill = [
            'full_name' => '',
            'phone' => '',
            'email' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'pincode' => '',
            'country' => '',
        ];

        $cStmt = $conn->prepare("SELECT name, email, phone, country FROM customers WHERE id = ? LIMIT 1");
        $cStmt->bind_param('i', $customerId);
        $cStmt->execute();
        $customer = $cStmt->get_result()->fetch_assoc() ?: [];

        $prefill['full_name'] = (string) ($customer['name'] ?? '');
        $prefill['email'] = (string) ($customer['email'] ?? '');
        $prefill['phone'] = (string) ($customer['phone'] ?? '');
        $prefill['country'] = (string) ($customer['country'] ?? '');

        $oStmt = $conn->prepare(
            "SELECT customer_name, customer_phone, customer_email, address, city, state, pincode, country
             FROM orders
             WHERE customer_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $oStmt->bind_param('i', $customerId);
        $oStmt->execute();
        $lastOrder = $oStmt->get_result()->fetch_assoc() ?: [];

        foreach (['full_name' => 'customer_name', 'phone' => 'customer_phone', 'email' => 'customer_email', 'address' => 'address', 'city' => 'city', 'state' => 'state', 'pincode' => 'pincode', 'country' => 'country'] as $dst => $src) {
            if ($prefill[$dst] === '' && !empty($lastOrder[$src])) {
                $prefill[$dst] = (string) $lastOrder[$src];
            }
        }

        foreach ($prefill as $k => $v) {
            if ($v !== '') {
                $old[$k] = $v;
            }
        }
    } catch (Throwable $e) {
        error_log('[checkout] prefill load failed: ' . $e->getMessage());
    }
}

if (!empty($_SESSION['checkout_old']) && is_array($_SESSION['checkout_old'])) {
    $old = array_merge($old, $_SESSION['checkout_old']);
}
if (!empty($_SESSION['checkout_draft']) && is_array($_SESSION['checkout_draft'])) {
    $old = array_merge($old, $_SESSION['checkout_draft']);
}

if (!empty($_SESSION['checkout_errors']) && is_array($_SESSION['checkout_errors'])) {
    $errors = $_SESSION['checkout_errors'];
    unset($_SESSION['checkout_errors']);
}
$checkoutFieldError = static function (string $field) use ($errors): string {
    $message = (string) ($errors[$field] ?? '');
    return '<div id="checkout_' . e($field) . '_error" class="invalid-feedback' . ($message !== '' ? ' d-block' : '') . '" aria-live="polite">' . e($message) . '</div>';
};
$checkoutValidationMessages = [];
foreach ($errors as $field => $message) {
    if ($field !== '_cart' && (string) $message !== '') {
        $checkoutValidationMessages[] = (string) $message;
    }
}
// India-only checkout path: keep country fixed for consistent pricing/shipping rules.
$old['country'] = 'India';

$savedAddresses = [];
$selectedAddressId = (int) ($old['shipping_address_id'] ?? 0);
if ($customerId > 0 && customer_addresses_table_ready($conn)) {
    $savedAddresses = customer_addresses_list($conn, $customerId);
    $addressMap = [];
    foreach ($savedAddresses as $addr) {
        $aid = (int) ($addr['id'] ?? 0);
        if ($aid > 0) {
            $addressMap[$aid] = $addr;
        }
    }

    $applyAddress = static function (array $addr, array &$target): void {
        $target['full_name'] = (string) ($addr['full_name'] ?? $target['full_name']);
        $target['phone'] = (string) ($addr['phone'] ?? $target['phone']);
        $target['address'] = (string) ($addr['address_line'] ?? $target['address']);
        $target['city'] = (string) ($addr['city'] ?? $target['city']);
        $target['state'] = (string) ($addr['state'] ?? $target['state']);
        $target['pincode'] = (string) ($addr['pincode'] ?? $target['pincode']);
        $target['country'] = 'India';
    };

    $requestedAddressId = (int) ($_GET['address_id'] ?? 0);
    if ($requestedAddressId > 0 && isset($addressMap[$requestedAddressId])) {
        $selectedAddressId = $requestedAddressId;
        $applyAddress($addressMap[$requestedAddressId], $old);
    } elseif ($selectedAddressId > 0 && isset($addressMap[$selectedAddressId])) {
        $applyAddress($addressMap[$selectedAddressId], $old);
    } else {
        $hasAnyAddressInput = trim((string) ($old['address'] ?? '')) !== ''
            || trim((string) ($old['city'] ?? '')) !== ''
            || trim((string) ($old['pincode'] ?? '')) !== '';
        if (!$hasAnyAddressInput) {
            foreach ($savedAddresses as $addr) {
                if ((int) ($addr['is_default_shipping'] ?? 0) === 1) {
                    $selectedAddressId = (int) ($addr['id'] ?? 0);
                    $applyAddress($addr, $old);
                    break;
                }
            }
        }
    }
}
$old['shipping_address_id'] = $selectedAddressId;

$selectedPayment = in_array((string) ($old['payment_method'] ?? 'cod'), ['cod', 'razorpay'], true)
    ? (string) $old['payment_method']
    : 'cod';
$selectedOnlineMethod = InventoryService::sanitize_online_payment_method((string) ($old['online_method'] ?? 'upi'));
if ($selectedOnlineMethod === '') {
    $selectedOnlineMethod = 'upi';
}
$codFeeApply = ($selectedPayment === 'cod') ? 1 : 0;
$countryForCalc = trim((string) ($old['country'] ?? ''));
$shipping = CartService::checkout_shipping_breakdown((float) $subtotal, $countryForCalc, $selectedPayment, $codFeeApply === 1);
$isIndia = (bool) $shipping['is_india'];
$baseShippingAmount = (float) $shipping['base_shipping'];
$codFeeAmount = (float) $shipping['cod_fee'];
$shippingAmount = (float) $shipping['shipping_total'];
$shippingRateSource = 'manual';
$shippingQuoteToken = InventoryService::shipping_quote_store(
    (float) $subtotal,
    (string) $countryForCalc,
    (string) ($old['pincode'] ?? ''),
    (string) $selectedPayment,
    (float) $baseShippingAmount,
    (float) $codFeeAmount,
    (float) $shippingAmount,
    (string) $shippingRateSource,
    ''
);
$couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
$couponInfo = get_active_coupon_discount($conn, $couponCode, (float) $subtotal);
if (!$couponInfo['valid'] && $couponCode !== '') {
    unset($_SESSION['applied_coupon_code']);
}
$discountAmount = $couponInfo['valid'] ? (float) $couponInfo['discount'] : 0.00;
$discountAmount = min($discountAmount, $subtotal); // discount applies to product subtotal only - shipping is never discounted
$taxableAmount  = max(0.0, $subtotal - $discountAmount);
// Tax-inclusive pricing: GST is already embedded in product prices.
// Total = (subtotal - discount) + shipping. No extra GST added.
$totalAmount    = round($taxableAmount + $shippingAmount, 2);
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

        <div class="row g-4">
            <div class="col-lg-7">
                <form id="checkout_form" method="POST" action="/place-order.php" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="redirect_to" value="checkout">
                    <input type="hidden" name="order_nonce" value="<?php echo e($_SESSION['order_nonce']); ?>">
                    <input type="hidden" name="online_method" id="online_method" value="<?php echo e($selectedOnlineMethod); ?>">
                    <input type="hidden" name="shipping_address_id" id="shipping_address_id" value="<?php echo (int) ($old['shipping_address_id'] ?? 0); ?>">
                    <input type="hidden" name="shipping_quote_token" id="shipping_quote_token" value="<?php echo e($shippingQuoteToken); ?>">
                    <div id="checkout_validation_summary" class="alert alert-danger<?php echo empty($checkoutValidationMessages) ? ' d-none' : ''; ?>" role="alert" aria-live="assertive" tabindex="-1"><?php echo !empty($checkoutValidationMessages) ? e('Please correct the following: ' . implode(' ', $checkoutValidationMessages)) : ''; ?></div>

                    <div class="surface-panel p-4 mb-4 checkout-section" id="checkout_section_address">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="small text-muted">Step 1 of 4: Delivery Address</div>
                                <h5 class="mb-0">Delivery Details</h5>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="checkout_edit_address">Edit</button>
                        </div>
                        <div class="checkout-section-summary d-none" id="checkout_address_summary"></div>
                        <div class="checkout-section-body" id="checkout_address_body">
                        <?php if ($customerId <= 0): ?>
                            <div class="alert alert-light border py-2 px-3 small mb-3">
                                Have an account? <a href="/customer/login.php?return=%2Fcheckout.php">Log in</a> for faster checkout.
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
                                <label class="form-label" for="checkout_full_name">Full Name *</label>
                                <input type="text" id="checkout_full_name" name="full_name" class="<?php echo form_class($errors, 'full_name'); ?>" required maxlength="120" autocomplete="name" aria-invalid="<?php echo !empty($errors['full_name']) ? 'true' : 'false'; ?>" aria-describedby="checkout_full_name_error" value="<?php echo e($old['full_name']); ?>">
                                <?php echo $checkoutFieldError('full_name'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="checkout_phone">Phone *</label>
                                <input type="tel" id="checkout_phone" name="phone" class="<?php echo form_class($errors, 'phone'); ?>" required maxlength="20" inputmode="tel" autocomplete="tel" aria-invalid="<?php echo !empty($errors['phone']) ? 'true' : 'false'; ?>" aria-describedby="checkout_phone_error" value="<?php echo e($old['phone']); ?>">
                                <?php echo $checkoutFieldError('phone'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="checkout_email">Email *</label>
                                <input type="email" id="checkout_email" name="email" class="<?php echo form_class($errors, 'email'); ?>" required maxlength="190" inputmode="email" autocomplete="email" aria-invalid="<?php echo !empty($errors['email']) ? 'true' : 'false'; ?>" aria-describedby="checkout_email_error" value="<?php echo e($old['email']); ?>">
                                <?php echo $checkoutFieldError('email'); ?>
                            </div>
                            <?php if ($customerId <= 0): ?>
                                <div class="col-12">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" value="1" id="create_account" name="create_account" <?php echo !empty($old['create_account']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="create_account">
                                            Create account after order (track orders faster)
                                        </label>
                                    </div>
                                    <div id="create_account_fields" style="<?php echo !empty($old['create_account']) ? '' : 'display:none;'; ?>">
                                        <div class="row g-3">
                                            <div class="col-sm-6">
                                                <label class="form-label" for="create_account_password">Password</label>
                                                <input type="password" id="create_account_password" name="create_account_password" class="<?php echo form_class($errors, 'create_account_password'); ?>" minlength="10" autocomplete="new-password" aria-invalid="<?php echo !empty($errors['create_account_password']) ? 'true' : 'false'; ?>" aria-describedby="checkout_create_account_password_error">
                                                <?php echo $checkoutFieldError('create_account_password'); ?>
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label" for="create_account_confirm_password">Confirm Password</label>
                                                <input type="password" id="create_account_confirm_password" name="create_account_confirm_password" class="<?php echo form_class($errors, 'create_account_confirm_password'); ?>" minlength="10" autocomplete="new-password" aria-invalid="<?php echo !empty($errors['create_account_confirm_password']) ? 'true' : 'false'; ?>" aria-describedby="checkout_create_account_confirm_password_error">
                                                <?php echo $checkoutFieldError('create_account_confirm_password'); ?>
                                            </div>
                                        </div>
                                        <div class="small text-muted mt-2">Use at least 10 characters, including uppercase, lowercase and a number. We will send email verification before first login.</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label" for="checkout_address">Address *</label>
                                <textarea id="checkout_address" name="address" class="<?php echo form_class($errors, 'address'); ?>" rows="2" maxlength="500" required autocomplete="street-address" aria-invalid="<?php echo !empty($errors['address']) ? 'true' : 'false'; ?>" aria-describedby="checkout_address_error"><?php echo e($old['address']); ?></textarea>
                                <?php echo $checkoutFieldError('address'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="checkout_city">City *</label>
                                <input type="text" id="checkout_city" name="city" class="<?php echo form_class($errors, 'city'); ?>" required maxlength="120" autocomplete="address-level2" aria-invalid="<?php echo !empty($errors['city']) ? 'true' : 'false'; ?>" aria-describedby="checkout_city_error" value="<?php echo e($old['city']); ?>">
                                <?php echo $checkoutFieldError('city'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="checkout_state">State *</label>
                                <input type="text" id="checkout_state" name="state" class="<?php echo form_class($errors, 'state'); ?>" required maxlength="120" autocomplete="address-level1" aria-invalid="<?php echo !empty($errors['state']) ? 'true' : 'false'; ?>" aria-describedby="checkout_state_error" value="<?php echo e($old['state']); ?>">
                                <?php echo $checkoutFieldError('state'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="checkout_pincode">Pincode *</label>
                                <input type="text" id="checkout_pincode" name="pincode" class="<?php echo form_class($errors, 'pincode'); ?>" required maxlength="6" inputmode="numeric" autocomplete="postal-code" aria-invalid="<?php echo !empty($errors['pincode']) ? 'true' : 'false'; ?>" aria-describedby="checkout_pincode_error" value="<?php echo e($old['pincode']); ?>">
                                <?php echo $checkoutFieldError('pincode'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="checkout_country">Country *</label>
                                <select id="checkout_country" name="country" class="<?php echo form_class($errors, 'country'); ?>" required>
                                    <option value="India" selected>India</option>
                                </select>
                                <?php echo form_error($errors, 'country'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="checkout_order_notes">Order Notes</label>
                                <textarea id="checkout_order_notes" name="order_notes" class="form-control" rows="2" maxlength="500"><?php echo e($old['order_notes']); ?></textarea>
                            </div>
                        </div>
                        </div>
                    </div>

                    <div class="surface-panel p-4 mb-4 checkout-section" id="checkout_section_payment">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="small text-muted">Step 2 of 4: Payment</div>
                                <h5 class="mb-0">Payment Method</h5>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="checkout_edit_payment">Edit</button>
                        </div>
                        <div class="checkout-section-summary d-none" id="checkout_payment_summary"></div>
                        <div class="checkout-section-body" id="checkout_payment_body">
                        <div class="checkout-payment-options" role="radiogroup" aria-label="Payment method">
                            <label class="checkout-pay-option" for="payment_cod" data-pay-option="cod">
                                <span class="checkout-pay-main">
                                    <input class="form-check-input mt-0" type="radio" name="payment_method" id="payment_cod" value="cod" aria-controls="cod-panel" aria-expanded="false" <?php echo $selectedPayment === 'cod' ? 'checked' : ''; ?>>
                                    <span>
                                        <strong>Cash on Delivery (COD)</strong>
                                        <small class="d-block text-muted">Pay in cash when your order is delivered.</small>
                                    </span>
                                </span>
                            </label>
                            <div class="checkout-pay-panel" id="cod-panel" aria-hidden="true">
                                <div class="small text-muted">
                                    COD handling fee of Rs 50 is applied for India orders.
                                </div>
                            </div>

                            <label class="checkout-pay-option" for="payment_razorpay" data-pay-option="razorpay">
                                <span class="checkout-pay-main">
                                    <input class="form-check-input mt-0" type="radio" name="payment_method" id="payment_razorpay" value="razorpay" aria-controls="razorpay-panel" aria-expanded="false" <?php echo $selectedPayment === 'razorpay' ? 'checked' : ''; ?>>
                                    <span>
                                        <strong>Pay Online (Razorpay)</strong>
                                        <small class="d-block text-muted">Choose UPI, Card, Netbanking or EMI in secure checkout.</small>
                                    </span>
                                </span>
                            </label>
                            <div class="checkout-pay-panel" id="razorpay-panel" aria-hidden="true">
                                <div class="checkout-online-methods" role="tablist" aria-label="Online payment method">
                                    <button type="button" class="checkout-online-method" id="online_method_upi_tab" role="tab" aria-controls="online_method_upi_panel" aria-selected="false" tabindex="-1" data-online-method="upi">UPI</button>
                                    <button type="button" class="checkout-online-method" id="online_method_card_tab" role="tab" aria-controls="online_method_card_panel" aria-selected="false" tabindex="-1" data-online-method="card">Card</button>
                                    <button type="button" class="checkout-online-method" id="online_method_emi_tab" role="tab" aria-controls="online_method_emi_panel" aria-selected="false" tabindex="-1" data-online-method="emi">EMI</button>
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
                                    <div class="checkout-online-panel" id="online_method_upi_panel" role="tabpanel" aria-labelledby="online_method_upi_tab" data-online-panel="upi" hidden>
                                        <div class="small text-muted mb-2">Pay instantly with any UPI app in secure Razorpay checkout.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">Google Pay</span>
                                            <span class="checkout-brand-chip">PhonePe</span>
                                            <span class="checkout-brand-chip">Paytm</span>
                                            <span class="checkout-brand-chip">BHIM</span>
                                        </div>
                                    </div>
                                    <div class="checkout-online-panel" id="online_method_card_panel" role="tabpanel" aria-labelledby="online_method_card_tab" data-online-panel="card" hidden>
                                        <div class="small text-muted mb-2">Domestic and international cards are supported.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">Visa</span>
                                            <span class="checkout-brand-chip">Mastercard</span>
                                            <span class="checkout-brand-chip">RuPay</span>
                                            <span class="checkout-brand-chip">Amex</span>
                                        </div>
                                    </div>
                                    <div class="checkout-online-panel" id="online_method_emi_panel" role="tabpanel" aria-labelledby="online_method_emi_tab" data-online-panel="emi" hidden>
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
                        <button type="submit" id="place_order_btn" class="btn btn-primary btn-lg w-100 d-none d-lg-block">Place Order</button>
                        <div id="checkout_submit_status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
                        <div class="trust-badge-block mt-3 mb-2" aria-label="Checkout trust badges">
                            <span class="trust-badge-pill">COD Available</span>
                            <span class="trust-badge-pill">Secure Payment</span>
                            <span class="trust-badge-pill">Fast Dispatch</span>
                            <span class="trust-badge-pill">Easy Returns</span>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo e($internationalQuoteUrl); ?>" class="btn btn-primary btn-lg w-100">Request International Quote</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="col-lg-5">
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

                    <div class="mb-3" id="checkout_coupon_panel">
                        <label class="form-label">Coupon Code</label>
                        <div class="d-flex gap-2">
                            <input type="text" id="coupon_code" name="coupon_code" form="checkout_form" class="form-control" placeholder="Enter code" value="<?php echo e((string) ($couponInfo['code'] ?? '')); ?>" autocomplete="off">
                            <button id="coupon_apply_button" class="btn btn-outline-dark" type="submit" form="checkout_form" formaction="/apply-coupon.php" formmethod="post">Apply</button>
                        </div>
                    </div>
                    <div id="coupon_status" class="small mb-2" role="status" aria-live="polite" aria-atomic="true"></div>

                    <div class="d-flex justify-content-between small mb-2<?php echo $couponInfo['valid'] ? '' : ' d-none'; ?>" id="checkout_applied_coupon">
                            <span>Coupon: <strong><?php echo e($couponInfo['code']); ?></strong></span>
                            <button id="coupon_remove_button" type="submit" form="checkout_form" formaction="/remove-coupon.php" formmethod="post" class="btn btn-link btn-sm p-0 text-danger">Remove</button>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between mb-1 small<?php echo $couponInfo['valid'] ? '' : ' d-none'; ?>" id="summary_coupon_row">
                        <span>Coupon (<?php echo e($couponInfo['code']); ?>)</span>
                        <span class="text-success">Applied</span>
                    </div>
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
                        <span id="summary_shipping"><?php echo e(money($baseShippingAmount)); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>COD Fee</span>
                        <span id="summary_cod_fee"><?php echo e(money($codFeeAmount)); ?></span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top">
                        <span>Total</span>
                        <span id="summary_total"><?php echo e(money($totalAmount)); ?></span>
                    </div>
                    <div id="shipping_quote_status" class="small mt-2" role="status" aria-live="polite" aria-atomic="true"></div>
                    <button type="button" id="shipping_quote_retry" class="btn btn-link btn-sm p-0 d-none">Retry shipping calculation</button>
                    <div class="alert alert-light border small mt-3 mb-0 checkout-summary-note">
                        Manual shipping active. Free shipping above Rs 999; otherwise Rs 70. COD adds Rs 50 handling fee.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php if ($isIndia): ?>
<div id="checkout_mobile_submit_bar" class="checkout-mobile-submit-bar d-lg-none" aria-label="Checkout quick submit bar">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="small text-muted">Total</span>
        <strong id="mobile_summary_total"><?php echo e(money($totalAmount)); ?></strong>
    </div>
    <button type="button" id="mobile_place_order_btn" class="btn btn-primary w-100">Place Order</button>
</div>
<?php endif; ?>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var csrfToken = <?php echo json_encode(csrf_token()); ?>;
    var checkoutValidationRules = <?php echo json_encode(checkout_validation_constraints()); ?>;
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
    var emailInput = document.getElementById('checkout_email');
    var countryFieldInput = document.getElementById('checkout_country');
    var subtotal = <?php echo json_encode((float) $subtotal); ?>;
    var discount = <?php echo json_encode((float) $discountAmount); ?>;
    var gstRate  = <?php echo json_encode((float) $gstRate); ?>;

    var shippingEl = document.getElementById('summary_shipping');
    var codFeeEl = document.getElementById('summary_cod_fee');
    var totalEl = document.getElementById('summary_total');

    var payOptionCards = document.querySelectorAll('[data-pay-option]');
    var codPanel = document.getElementById('cod-panel');
    var razorpayPanel = document.getElementById('razorpay-panel');
    var onlineMethodButtons = document.querySelectorAll('.checkout-online-method');
    var onlinePanels = document.querySelectorAll('.checkout-online-panel');
    var onlineMethodInput = document.getElementById('online_method');
    var onlineMethods = ['upi', 'card', 'emi'];
    var paymentState = {
        paymentMethod: codRadio.checked ? 'cod' : 'razorpay',
        selectedOnlineMethod: onlineMethods.indexOf(String(onlineMethodInput && onlineMethodInput.value || '')) !== -1
            ? String(onlineMethodInput.value)
            : 'upi',
        lastValidOnlineMethod: 'upi'
    };
    paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;
    var shippingQuoteTokenInput = document.getElementById('shipping_quote_token');
    var mobileTotalEl = document.getElementById('mobile_summary_total');
    var mobileSubmitBtn = document.getElementById('mobile_place_order_btn');
    var mobileSubmitBar = document.getElementById('checkout_mobile_submit_bar');
    var cookieConsentBanner = document.getElementById('cookieConsentBanner');
    var placeOrderBtn = document.getElementById('place_order_btn');
    var shippingQuoteStatus = document.getElementById('shipping_quote_status');
    var shippingQuoteRetryBtn = document.getElementById('shipping_quote_retry');
    var couponInput = document.getElementById('coupon_code');
    var couponApplyButton = document.getElementById('coupon_apply_button');
    var couponRemoveButton = document.getElementById('coupon_remove_button');
    var couponStatus = document.getElementById('coupon_status');
    var couponApplied = document.getElementById('checkout_applied_coupon');
    var summaryCouponRow = document.getElementById('summary_coupon_row');
    var discountEl = document.getElementById('summary_discount');
    var submitStatusEl = document.getElementById('checkout_submit_status');
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
    var createAccountPassword = document.getElementById('create_account_password');
    var createAccountConfirmPassword = document.getElementById('create_account_confirm_password');
    var validationSummary = document.getElementById('checkout_validation_summary');

    if (!codRadio || !razorpayRadio || !shippingEl || !codFeeEl || !totalEl || !countryInput) {
        return;
    }

    function isElementVisible(el) {
        if (!el || el.classList.contains('d-none')) return false;
        return window.getComputedStyle(el).display !== 'none';
    }

    function measuredHeight(el) {
        if (!isElementVisible(el)) return 0;
        return Math.ceil(el.getBoundingClientRect().height || 0);
    }

    function syncMobileCheckoutLayout() {
        if (!mobileSubmitBar) return;
        var isMobileViewport = window.matchMedia('(max-width: 991.98px)').matches;
        var cookieVisible = isElementVisible(cookieConsentBanner);
        var cookieHeight = cookieVisible ? measuredHeight(cookieConsentBanner) : 0;
        var barHeight = (!cookieVisible && isMobileViewport) ? measuredHeight(mobileSubmitBar) : 0;

        document.documentElement.style.setProperty('--cookie-consent-height', cookieHeight + 'px');
        document.documentElement.style.setProperty('--checkout-mobile-bar-height', barHeight + 'px');
        document.body.classList.add('checkout-has-mobile-submit-bar');
        document.body.classList.toggle('checkout-mobile-bar-hidden', cookieVisible || !isMobileViewport);
        mobileSubmitBar.setAttribute('aria-hidden', (cookieVisible || !isMobileViewport) ? 'true' : 'false');
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

    function syncSummary() {
        var country = String(countryInput.value || '').trim().toLowerCase();
        var isIndia = country === 'india';
        var paymentMethod = paymentState.paymentMethod;

        // A local estimate must never replace a quote for a different checkout state.
        if (typeof shippingQuoteState !== 'undefined' && shippingQuoteState && shippingQuoteState.validKey !== shippingKey(shippingSnapshot())) {
            return;
        }

        var shipping = 0;
        var codFee = 0;
        if (isIndia) {
            shipping = (subtotal >= 999) ? 0 : 70;
            codFee = (paymentMethod === 'cod') ? 50 : 0;
        }

        // Tax-inclusive: total does NOT add extra GST
        var taxable = Math.max(0, subtotal - discount);
        var total = taxable + shipping + codFee;

        shippingEl.textContent = toMoney(shipping);
        codFeeEl.textContent = toMoney(codFee);
        totalEl.textContent = toMoney(total);
        if (mobileTotalEl) {
            mobileTotalEl.textContent = toMoney(total);
        }
    }

    function syncCreateAccountFields() {
        if (!createAccountCheckbox || !createAccountFields) return;
        var enabled = !!createAccountCheckbox.checked;
        createAccountFields.style.display = enabled ? '' : 'none';
        if (createAccountPassword) createAccountPassword.required = enabled;
        if (createAccountConfirmPassword) createAccountConfirmPassword.required = enabled;
        if (!enabled) {
            if (createAccountPassword) { createAccountPassword.value = ''; setFieldError(createAccountPassword, ''); }
            if (createAccountConfirmPassword) { createAccountConfirmPassword.value = ''; setFieldError(createAccountConfirmPassword, ''); }
            if (validationSummary && !validationSummary.classList.contains('d-none')) {
                validateAddressSection();
            }
        }
    }

    function isValidEmail(val) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(val || '').trim());
    }

    function setFieldError(input, message) {
        if (!input) return;
        var hasError = !!message;
        input.classList.toggle('is-invalid', hasError);
        input.setAttribute('aria-invalid', hasError ? 'true' : 'false');
        var errorId = input.getAttribute('aria-describedby');
        var errorEl = errorId ? document.getElementById(errorId) : null;
        if (errorEl) {
            errorEl.textContent = message || '';
            errorEl.classList.toggle('d-block', hasError);
        }
    }

    function validateAddressSection() {
        var invalidFields = [];
        var fv = String(fullNameInput ? fullNameInput.value : '').trim();
        var ph = String(phoneInput ? phoneInput.value : '').trim();
        var em = String(emailInput ? emailInput.value : '').trim();
        var ad = String(addressInput ? addressInput.value : '').trim();
        var ct = String(cityInput ? cityInput.value : '').trim();
        var st = String(stateInput ? stateInput.value : '').trim();
        var pc = String(pincodeInput ? pincodeInput.value : '').trim();
        var phonePattern = new RegExp(checkoutValidationRules.phone_pattern);
        var pincodePattern = new RegExp(checkoutValidationRules.pincode_pattern);
        function validate(input, message) {
            setFieldError(input, message);
            if (message && input) invalidFields.push({ input: input, message: message });
        }
        validate(fullNameInput, fv === '' ? 'Full name is required.' : (fv.length > 120 ? 'Full name must be 120 characters or fewer.' : ''));
        validate(phoneInput, ph === '' ? 'Phone is required.' : (!phonePattern.test(ph) ? 'Enter a valid phone number.' : ''));
        validate(emailInput, !isValidEmail(em) ? 'Valid email is required.' : (em.length > 190 ? 'Email must be 190 characters or fewer.' : ''));
        validate(addressInput, ad === '' ? 'Address is required.' : (ad.length > checkoutValidationRules.address_max_length ? 'Address must be 500 characters or fewer.' : ''));
        validate(cityInput, ct === '' ? 'City is required.' : (ct.length > 120 ? 'City must be 120 characters or fewer.' : ''));
        validate(stateInput, st === '' ? 'State is required.' : (st.length > 120 ? 'State must be 120 characters or fewer.' : ''));
        validate(pincodeInput, pc === '' ? 'Pincode is required.' : (!pincodePattern.test(pc) ? 'Enter a valid 6-digit Indian pincode.' : ''));
        if (createAccountCheckbox && createAccountCheckbox.checked) {
            var password = String(createAccountPassword ? createAccountPassword.value : '');
            var confirmation = String(createAccountConfirmPassword ? createAccountConfirmPassword.value : '');
            var passwordMessage = password.length < checkoutValidationRules.password_min_length
                ? 'Password must be at least ' + checkoutValidationRules.password_min_length + ' characters.'
                : (!new RegExp(checkoutValidationRules.password_uppercase_pattern).test(password)
                    ? 'Password must include at least one uppercase letter.'
                    : (!new RegExp(checkoutValidationRules.password_lowercase_pattern).test(password)
                        ? 'Password must include at least one lowercase letter.'
                        : (!new RegExp(checkoutValidationRules.password_number_pattern).test(password) ? 'Password must include at least one number.' : '')));
            validate(createAccountPassword, passwordMessage);
            validate(createAccountConfirmPassword, password !== confirmation ? 'Passwords do not match.' : '');
        }
        if (validationSummary) {
            validationSummary.classList.toggle('d-none', invalidFields.length === 0);
            validationSummary.textContent = invalidFields.length ? 'Please correct the following: ' + invalidFields.map(function (field) { return field.message; }).join(' ') : '';
        }
        return invalidFields;
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
            sectionPaymentSummary.textContent = paymentState.paymentMethod === 'cod' ? 'Cash on Delivery' : 'Online Payment (Razorpay · ' + paymentState.selectedOnlineMethod.toUpperCase() + ')';
        }
    }

    function syncOnlineMethodInput() {
        if (!onlineMethodInput) {
            return;
        }
        onlineMethodInput.value = paymentState.selectedOnlineMethod;
    }

    function ensureValidOnlineMethodForRazorpay() {
        if (onlineMethods.indexOf(paymentState.selectedOnlineMethod) === -1) {
            paymentState.selectedOnlineMethod = onlineMethods.indexOf(paymentState.lastValidOnlineMethod) !== -1
                ? paymentState.lastValidOnlineMethod
                : 'upi';
        }
        paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;
        syncOnlineMethodInput();
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

    var shippingQuoteState = { generation: 0, controller: null, pending: false, validKey: '', retryCount: 0 };
    function shippingSnapshot() {
        return {
            pincode: pincodeInput ? String(pincodeInput.value || '').trim() : '',
            payment_method: paymentState.paymentMethod,
            subtotal: Number(subtotal).toFixed(2),
            country: String(countryInput.value || '').trim().toLowerCase()
        };
    }
    function shippingKey(snapshot) {
        return [snapshot.pincode, snapshot.payment_method, snapshot.subtotal, snapshot.country].join('|');
    }
    function quoteSnapshotIsValid(snapshot) {
        return snapshot.country === 'india' && /^[1-9][0-9]{5}$/.test(snapshot.pincode);
    }
    function responseMatchesSnapshot(data, snapshot) {
        var bound = data && data.quote_for;
        return !!bound && String(bound.pincode || '') === snapshot.pincode &&
            String(bound.payment_method || '') === snapshot.payment_method &&
            Number(bound.subtotal || 0).toFixed(2) === snapshot.subtotal &&
            String(bound.country || '').toLowerCase() === snapshot.country;
    }
    var shippingSubmissionEnabled = true;
    var submissionState = { inProgress: false, restoreTimer: null };

    function setPlaceOrderButtonDisabled(button, disabled) {
        if (!button) return;
        button.disabled = !!disabled;
        button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    }

    function setPlaceOrderButtonLabel(button, label, isProcessing) {
        if (!button) return;
        if (!button.getAttribute('data-default-label')) {
            button.setAttribute('data-default-label', String(button.textContent || '').trim() || 'Place Order');
        }
        if (!isProcessing) {
            button.textContent = label;
            return;
        }
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
    }

    function updateSubmitAnnouncement(message) {
        if (!submitStatusEl) return;
        submitStatusEl.textContent = message || '';
    }

    function syncSubmitControls() {
        var disabled = submissionState.inProgress || !shippingSubmissionEnabled;
        setPlaceOrderButtonDisabled(placeOrderBtn, disabled);
        setPlaceOrderButtonDisabled(mobileSubmitBtn, disabled);
    }

    function clearSubmissionRestoreTimer() {
        if (!submissionState.restoreTimer) return;
        window.clearTimeout(submissionState.restoreTimer);
        submissionState.restoreTimer = null;
    }

    function exitSubmissionProcessing(reason) {
        if (!submissionState.inProgress) return;
        submissionState.inProgress = false;
        clearSubmissionRestoreTimer();
        if (checkoutForm) checkoutForm.removeAttribute('aria-busy');
        setPlaceOrderButtonLabel(placeOrderBtn, placeOrderBtn ? (placeOrderBtn.getAttribute('data-default-label') || 'Place Order') : 'Place Order', false);
        setPlaceOrderButtonLabel(mobileSubmitBtn, mobileSubmitBtn ? (mobileSubmitBtn.getAttribute('data-default-label') || 'Place Order') : 'Place Order', false);
        syncSubmitControls();
        updateSubmitAnnouncement(reason || '');
    }

    function enterSubmissionProcessing() {
        if (submissionState.inProgress) return false;
        submissionState.inProgress = true;
        if (checkoutForm) checkoutForm.setAttribute('aria-busy', 'true');
        setPlaceOrderButtonLabel(placeOrderBtn, 'Processing order…', true);
        setPlaceOrderButtonLabel(mobileSubmitBtn, 'Processing order…', true);
        syncSubmitControls();
        updateSubmitAnnouncement('Processing order…');
        clearSubmissionRestoreTimer();
        submissionState.restoreTimer = window.setTimeout(function () {
            exitSubmissionProcessing('Order processing timed out in this tab. Please review and try again.');
        }, 30000);
        return true;
    }

    function setShippingSubmissionEnabled(enabled) {
        shippingSubmissionEnabled = !!enabled;
        syncSubmitControls();
    }
    function setShippingStatus(message, kind, canRetry) {
        if (shippingQuoteStatus) {
            shippingQuoteStatus.className = 'small mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'pending' ? 'text-muted' : 'text-success'));
            shippingQuoteStatus.textContent = message;
        }
        if (shippingQuoteRetryBtn) shippingQuoteRetryBtn.classList.toggle('d-none', !canRetry);
    }
    function clearCurrentShippingQuote() {
        shippingQuoteState.validKey = '';
        if (shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
        setShippingSubmissionEnabled(false);
    }
    function abortLiveShippingRequest() {
        if (shippingQuoteState.controller && typeof shippingQuoteState.controller.abort === 'function') {
            shippingQuoteState.controller.abort();
        }
        shippingQuoteState.controller = null;
    }
    function maybeFetchLiveRate(options) {
        options = options || {};
        var snapshot = shippingSnapshot();
        var key = shippingKey(snapshot);
        if (!options.retry) shippingQuoteState.retryCount = 0;
        abortLiveShippingRequest();
        var generation = ++shippingQuoteState.generation;
        shippingQuoteState.pending = false;
        if (!quoteSnapshotIsValid(snapshot)) {
            clearCurrentShippingQuote();
            setShippingStatus('Enter a valid Indian pincode to calculate shipping.', 'error', false);
            return;
        }
        shippingQuoteState.pending = true;
        clearCurrentShippingQuote();
        setShippingStatus('Calculating shipping…', 'pending', false);
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        shippingQuoteState.controller = controller;
        var body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('pincode', snapshot.pincode);
        body.set('subtotal', snapshot.subtotal);
        body.set('payment_method', snapshot.payment_method);
        body.set('country', snapshot.country);
        var requestOptions = { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() };
        if (controller) requestOptions.signal = controller.signal;
        fetch('/shipping-rate.php', requestOptions).then(function (res) {
            if (!res.ok) throw new Error('Shipping quote request failed (' + res.status + ').');
            return res.json();
        }).then(function (data) {
            if (generation !== shippingQuoteState.generation || shippingKey(shippingSnapshot()) !== key) return;
            if (!data || !data.ok || !data.quote_token || !responseMatchesSnapshot(data, snapshot)) {
                throw new Error('Shipping quote did not match the current checkout details.');
            }
            shippingQuoteState.pending = false;
            shippingQuoteState.validKey = key;
            shippingQuoteState.retryCount = 0;
            shippingQuoteState.controller = null;
            var liveShipping = Number(data.base_shipping || 0);
            var liveCodFee = Number(data.cod_fee || 0);
            shippingQuoteTokenInput.value = String(data.quote_token);
            var total = Math.max(0, subtotal - discount) + liveShipping + liveCodFee;
            shippingEl.textContent = toMoney(liveShipping);
            codFeeEl.textContent = toMoney(liveCodFee);
            totalEl.textContent = toMoney(total);
            if (mobileTotalEl) mobileTotalEl.textContent = toMoney(total);
            setShippingSubmissionEnabled(true);
            setShippingStatus('Shipping calculated.', 'success', false);
        }).catch(function (error) {
            if (generation !== shippingQuoteState.generation || (error && error.name === 'AbortError')) return;
            shippingQuoteState.pending = false;
            shippingQuoteState.controller = null;
            clearCurrentShippingQuote();
            setShippingStatus('We could not calculate shipping. Check your connection and retry.', 'error', shippingQuoteState.retryCount < 2);
        });
    }

    function syncPaymentPanels() {
        var selected = paymentState.paymentMethod;
        payOptionCards.forEach(function (card) {
            card.classList.toggle('is-active', card.getAttribute('data-pay-option') === selected);
        });
        if (codPanel) {
            codPanel.classList.toggle('is-open', selected === 'cod');
            codPanel.hidden = selected !== 'cod';
            codPanel.classList.toggle('d-none', selected !== 'cod');
            codPanel.setAttribute('aria-hidden', selected === 'cod' ? 'false' : 'true');
        }
        if (razorpayPanel) {
            razorpayPanel.classList.toggle('is-open', selected === 'razorpay');
            razorpayPanel.hidden = selected !== 'razorpay';
            razorpayPanel.classList.toggle('d-none', selected !== 'razorpay');
            razorpayPanel.setAttribute('aria-hidden', selected === 'razorpay' ? 'false' : 'true');
        }
        if (codRadio) {
            codRadio.setAttribute('aria-expanded', selected === 'cod' ? 'true' : 'false');
        }
        if (razorpayRadio) {
            razorpayRadio.setAttribute('aria-expanded', selected === 'razorpay' ? 'true' : 'false');
        }
        if (selected === 'razorpay') {
            ensureValidOnlineMethodForRazorpay();
            activateOnlineMethod(paymentState.selectedOnlineMethod);
        }
    }

    function activateOnlineMethod(method, focusSelected) {
        paymentState.selectedOnlineMethod = onlineMethods.indexOf(method) !== -1 ? method : 'upi';
        paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;
        syncOnlineMethodInput();
        onlineMethodButtons.forEach(function (btn) {
            var active = btn.getAttribute('data-online-method') === paymentState.selectedOnlineMethod;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
            btn.tabIndex = active ? 0 : -1;
            if (active && focusSelected) btn.focus();
        });
        onlinePanels.forEach(function (panel) {
            var active = panel.getAttribute('data-online-panel') === paymentState.selectedOnlineMethod;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
            panel.classList.toggle('d-none', !active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });
        updateSectionSummaries();
    }

    function handlePaymentMethodChange() {
        paymentState.paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
        if (paymentState.paymentMethod === 'razorpay') {
            ensureValidOnlineMethodForRazorpay();
        } else {
            syncOnlineMethodInput();
        }
        syncPaymentPanels();
        syncSummary();
        updateSectionSummaries();
        maybeFetchLiveRate();
    }
    codRadio.addEventListener('change', handlePaymentMethodChange);
    razorpayRadio.addEventListener('change', handlePaymentMethodChange);
    countryInput.addEventListener('input', syncSummary);
    if (pincodeInput) {
        pincodeInput.addEventListener('input', function () {
            syncSummary();
            maybeFetchLiveRate();
        });
    }

    var couponRequestInFlight = false;
    function setCouponBusy(busy) {
        if (couponApplyButton) couponApplyButton.disabled = busy;
        if (couponRemoveButton) couponRemoveButton.disabled = busy;
        if (couponInput) couponInput.disabled = busy;
    }
    function updateCouponSummary(data) {
        discount = Number(data.discount_amount || 0);
        if (discountEl) discountEl.textContent = '- ' + toMoney(discount);
        // Coupon responses can race address/payment changes too; accept their quote only when it is bound to this exact state.
        if (data && data.shipping_quote_token && responseMatchesSnapshot(data, shippingSnapshot())) {
            abortLiveShippingRequest();
            shippingQuoteState.generation += 1;
            shippingQuoteState.pending = false;
            shippingQuoteState.validKey = shippingKey(shippingSnapshot());
            if (shippingEl) shippingEl.textContent = toMoney(Number(data.shipping || 0));
            if (codFeeEl) codFeeEl.textContent = toMoney(Number(data.cod_fee || 0));
            if (totalEl) totalEl.textContent = toMoney(Number(data.final_total || 0));
            if (mobileTotalEl) mobileTotalEl.textContent = toMoney(Number(data.final_total || 0));
            shippingQuoteTokenInput.value = String(data.shipping_quote_token);
            setShippingSubmissionEnabled(true);
            setShippingStatus('Shipping calculated.', 'success', false);
        } else if (shippingQuoteState.validKey !== shippingKey(shippingSnapshot())) {
            maybeFetchLiveRate();
        }
        var hasCoupon = String(data.coupon_code || '') !== '' && Number(data.discount_amount || 0) > 0;
        if (couponApplied) {
            couponApplied.classList.toggle('d-none', !hasCoupon);
            var appliedCode = couponApplied.querySelector('strong');
            if (appliedCode) appliedCode.textContent = String(data.coupon_code || '');
        }
        if (summaryCouponRow) {
            summaryCouponRow.classList.toggle('d-none', !hasCoupon);
            var label = summaryCouponRow.querySelector('span');
            if (label) label.textContent = hasCoupon ? 'Coupon (' + String(data.coupon_code) + ')' : '';
        }
        if (couponInput && hasCoupon) couponInput.value = String(data.coupon_code);
    }
    function couponRequest(url) {
        if (couponRequestInFlight || !checkoutForm) return;
        couponRequestInFlight = true;
        setCouponBusy(true);
        if (couponStatus) { couponStatus.className = 'small mb-2 text-muted'; couponStatus.textContent = 'Updating coupon…'; }
        var body = new URLSearchParams(new FormData(checkoutForm));
        body.set('ajax', '1');
        fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
            .then(function (res) { return res.json().catch(function () { return { ok: false, message: 'Unable to update coupon. Please try again.' }; }); })
            .then(function (data) {
                updateCouponSummary(data || {});
                if (couponStatus) { couponStatus.className = 'small mb-2 ' + (data && data.ok ? 'text-success' : 'text-danger'); couponStatus.textContent = (data && data.message) || 'Unable to update coupon.'; }
            }).catch(function () {
                if (couponStatus) { couponStatus.className = 'small mb-2 text-danger'; couponStatus.textContent = 'Network error. Your coupon was not changed; please try again.'; }
            }).finally(function () { couponRequestInFlight = false; setCouponBusy(false); });
    }
    countryInput.addEventListener('change', maybeFetchLiveRate);
    onlineMethodButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var method = btn.getAttribute('data-online-method');
            if (!razorpayRadio.checked) {
                razorpayRadio.checked = true;
                handlePaymentMethodChange();
            }
            activateOnlineMethod(method, true);
            syncOnlineMethodInput();
            maybeFetchLiveRate();
        });
        btn.addEventListener('keydown', function (ev) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(ev.key) === -1) return;
            ev.preventDefault();
            var buttons = Array.prototype.slice.call(onlineMethodButtons);
            var index = buttons.indexOf(btn);
            if (ev.key === 'ArrowRight') index = (index + 1) % buttons.length;
            if (ev.key === 'ArrowLeft') index = (index - 1 + buttons.length) % buttons.length;
            if (ev.key === 'ArrowDown') index = (index + 1) % buttons.length;
            if (ev.key === 'ArrowUp') index = (index - 1 + buttons.length) % buttons.length;
            if (ev.key === 'Home') index = 0;
            if (ev.key === 'End') index = buttons.length - 1;
            if (!razorpayRadio.checked) {
                razorpayRadio.checked = true;
                handlePaymentMethodChange();
            }
            activateOnlineMethod(buttons[index].getAttribute('data-online-method'), true);
            syncOnlineMethodInput();
            maybeFetchLiveRate();
        });
    });
    if (savedAddressSelect) {
        savedAddressSelect.addEventListener('change', function () {
            applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
            syncSummary();
            maybeFetchLiveRate();
        });
    }
    [fullNameInput, phoneInput, emailInput, addressInput, cityInput, stateInput, pincodeInput, countryFieldInput, createAccountPassword, createAccountConfirmPassword].forEach(function (field) {
        if (!field) return;
        field.addEventListener('input', function () {
            if (shippingAddressIdInput && shippingAddressIdInput.value !== '') {
                shippingAddressIdInput.value = '';
            }
            if (savedAddressSelect && savedAddressSelect.value !== '') {
                savedAddressSelect.value = '';
            }
            if (field.getAttribute('aria-invalid') === 'true') {
                validateAddressSection();
            }
        });
    });
    syncSummary();
    if (savedAddressSelect && savedAddressSelect.value !== '') {
        applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
        syncSummary();
    }
    maybeFetchLiveRate();
    if (mobileSubmitBtn && checkoutForm) {
        mobileSubmitBtn.addEventListener('click', function () {
            if (submissionState.inProgress) {
                return;
            }
            checkoutForm.requestSubmit();
        });
    }
    if (createAccountCheckbox) {
        createAccountCheckbox.addEventListener('change', syncCreateAccountFields);
        syncCreateAccountFields();
    }
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
            if (validateAddressSection().length === 0) {
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
            }
        });
    }
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function (ev) {
            if (submissionState.inProgress) {
                ev.preventDefault();
                return;
            }
            updateSectionSummaries();
            var invalidFields = validateAddressSection();
            if (invalidFields.length > 0) {
                ev.preventDefault();
                focusFirstError();
                return;
            }
            if (couponRequestInFlight) {
                ev.preventDefault();
                if (couponStatus) {
                    couponStatus.className = 'small mb-2 text-danger';
                    couponStatus.textContent = 'Please wait while coupon updates finish before placing your order.';
                }
                return;
            }
            if (shippingQuoteState.pending || shippingQuoteState.validKey !== shippingKey(shippingSnapshot()) || !shippingQuoteTokenInput.value) {
                ev.preventDefault();
                setShippingSubmissionEnabled(false);
                setShippingStatus(shippingQuoteState.pending ? 'Calculating shipping… Please wait before placing your order.' : 'Calculate shipping before placing your order.', 'error', !shippingQuoteState.pending);
                return;
            }
            if (!enterSubmissionProcessing()) {
                ev.preventDefault();
                return;
            }
        });
    }
    if (shippingQuoteRetryBtn) {
        shippingQuoteRetryBtn.addEventListener('click', function () {
            if (shippingQuoteState.pending || shippingQuoteState.retryCount >= 2) return;
            shippingQuoteState.retryCount += 1;
            maybeFetchLiveRate({ retry: true });
        });
    }
    if (couponApplyButton) couponApplyButton.addEventListener('click', function (ev) { ev.preventDefault(); couponRequest('/apply-coupon.php'); });
    if (couponRemoveButton) couponRemoveButton.addEventListener('click', function (ev) { ev.preventDefault(); couponRequest('/remove-coupon.php'); });
    updateSectionSummaries();
    focusFirstError();
    if (paymentState.paymentMethod === 'razorpay') {
        ensureValidOnlineMethodForRazorpay();
    }
    activateOnlineMethod(paymentState.selectedOnlineMethod);
    syncPaymentPanels();

    if (placeOrderBtn) {
        placeOrderBtn.setAttribute('data-default-label', String(placeOrderBtn.textContent || '').trim() || 'Place Order');
    }
    if (mobileSubmitBtn) {
        mobileSubmitBtn.setAttribute('data-default-label', String(mobileSubmitBtn.textContent || '').trim() || 'Place Order');
    }
    syncSubmitControls();
    if (mobileSubmitBar) {
        syncMobileCheckoutLayout();
        if (cookieConsentBanner && typeof MutationObserver !== 'undefined') {
            var consentObserver = new MutationObserver(function () {
                syncMobileCheckoutLayout();
            });
            consentObserver.observe(cookieConsentBanner, { attributes: true, attributeFilter: ['class', 'style', 'data-consent-status'] });
        }
        if (typeof ResizeObserver !== 'undefined') {
            var layoutObserver = new ResizeObserver(function () {
                syncMobileCheckoutLayout();
            });
            layoutObserver.observe(mobileSubmitBar);
            if (cookieConsentBanner) {
                layoutObserver.observe(cookieConsentBanner);
            }
        }
        window.addEventListener('resize', syncMobileCheckoutLayout);
        window.addEventListener('orientationchange', syncMobileCheckoutLayout);
        document.addEventListener('cookie-consent-visibility-change', syncMobileCheckoutLayout);
    }
    window.addEventListener('pageshow', function () {
        exitSubmissionProcessing('');
        if (mobileSubmitBar) {
            syncMobileCheckoutLayout();
        }
    });
    window.addEventListener('beforeunload', function () {
        clearSubmissionRestoreTimer();
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

