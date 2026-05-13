<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$cartMeterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];
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
            $meterLength = null;
            $bundleQty = null;
            if ($unitType === 'meter' && isset($cartMeterMap[$pid]) && is_numeric($cartMeterMap[$pid]) && (float) $cartMeterMap[$pid] > 0) {
                $meterLength = (float) $cartMeterMap[$pid];
                $bundleQty = max(1, (int) round($qty / $meterLength));
            }
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
                'unit_price' => $unitPrice,
                'subtotal' => $lineTotal,
                'stock' => $displayStock,
                'in_stock' => $inStock,
                'meter_length' => $meterLength,
                'bundle_quantity' => $bundleQty,
            ];
        }
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
    unset($_SESSION['checkout_old']);
}

if (!empty($_SESSION['checkout_errors']) && is_array($_SESSION['checkout_errors'])) {
    $errors = $_SESSION['checkout_errors'];
    unset($_SESSION['checkout_errors']);
}

$selectedPayment = in_array((string) ($old['payment_method'] ?? 'cod'), ['cod', 'razorpay'], true)
    ? (string) $old['payment_method']
    : 'cod';
$selectedOnlineMethod = in_array((string) ($old['online_method'] ?? 'upi'), ['upi', 'card', 'paypal', 'emi'], true)
    ? (string) ($old['online_method'] ?? 'upi')
    : 'upi';
$codFeeApply = ($selectedPayment === 'cod') ? 1 : 0;
$countryForCalc = trim((string) ($old['country'] ?? ''));
$isIndia = strcasecmp($countryForCalc, 'india') === 0;
$baseShippingAmount = 0.00;
$codFeeAmount = 0.00;
if ($isIndia) {
    $baseShippingAmount = ($subtotal >= 999) ? 0.00 : 70.00;
    $codFeeAmount = ($selectedPayment === 'cod' && $codFeeApply === 1) ? 50.00 : 0.00;
}
$shippingAmount = $baseShippingAmount + $codFeeAmount;
$preDiscountTotal = $subtotal + $shippingAmount;
$couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
$couponInfo = get_active_coupon_discount($conn, $couponCode, $preDiscountTotal);
if (!$couponInfo['valid'] && $couponCode !== '') {
    unset($_SESSION['applied_coupon_code']);
}
$discountAmount = $couponInfo['valid'] ? (float) $couponInfo['discount'] : 0.00;
$discountAmount = min($discountAmount, $subtotal); // discount applies to product subtotal only — shipping is never discounted
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

$metaTitle = 'Checkout | Amber Fabrics';

// One-time order nonce — consumed on first successful place-order.php submission
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
                <form method="POST" action="/place-order.php" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_nonce" value="<?php echo e($_SESSION['order_nonce']); ?>">
                    <input type="hidden" name="online_method" id="online_method" value="<?php echo e($selectedOnlineMethod); ?>">

                    <div class="surface-panel p-4 mb-4">
                        <h5 class="mb-3">Delivery Details</h5>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="<?php echo form_class($errors, 'full_name'); ?>" required value="<?php echo e($old['full_name']); ?>">
                                <?php echo form_error($errors, 'full_name'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Phone *</label>
                                <input type="text" name="phone" class="<?php echo form_class($errors, 'phone'); ?>" required value="<?php echo e($old['phone']); ?>">
                                <?php echo form_error($errors, 'phone'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" class="<?php echo form_class($errors, 'email'); ?>" required value="<?php echo e($old['email']); ?>">
                                <?php echo form_error($errors, 'email'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address *</label>
                                <textarea name="address" class="<?php echo form_class($errors, 'address'); ?>" rows="2" maxlength="500" required><?php echo e($old['address']); ?></textarea>
                                <?php echo form_error($errors, 'address'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">City *</label>
                                <input type="text" name="city" class="<?php echo form_class($errors, 'city'); ?>" required value="<?php echo e($old['city']); ?>">
                                <?php echo form_error($errors, 'city'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">State *</label>
                                <input type="text" name="state" class="<?php echo form_class($errors, 'state'); ?>" required value="<?php echo e($old['state']); ?>">
                                <?php echo form_error($errors, 'state'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Pincode *</label>
                                <input type="text" name="pincode" class="<?php echo form_class($errors, 'pincode'); ?>" required value="<?php echo e($old['pincode']); ?>">
                                <?php echo form_error($errors, 'pincode'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Country *</label>
                                <input type="text" name="country" class="<?php echo form_class($errors, 'country'); ?>" required value="<?php echo e($old['country']); ?>">
                                <?php echo form_error($errors, 'country'); ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Order Notes</label>
                                <textarea name="order_notes" class="form-control" rows="2" maxlength="500"><?php echo e($old['order_notes']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="surface-panel p-4 mb-4">
                        <h5 class="mb-3">Payment Method</h5>
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
                            </div>

                            <label class="checkout-pay-option" for="payment_razorpay" data-pay-option="razorpay">
                                <span class="checkout-pay-main">
                                    <input class="form-check-input mt-0" type="radio" name="payment_method" id="payment_razorpay" value="razorpay" <?php echo ($old['payment_method'] ?? '') === 'razorpay' ? 'checked' : ''; ?>>
                                    <span>
                                        <strong>Pay Online (Razorpay)</strong>
                                        <small class="d-block text-muted">Choose UPI, Card, Netbanking or EMI in secure checkout.</small>
                                    </span>
                                </span>
                            </label>
                            <div class="checkout-pay-panel" id="razorpay-panel">
                                <div class="checkout-online-methods">
                                    <button type="button" class="checkout-online-method is-active" data-online-method="upi">UPI</button>
                                    <button type="button" class="checkout-online-method" data-online-method="card">Card</button>
                                    <button type="button" class="checkout-online-method" data-online-method="paypal">PayPal</button>
                                    <button type="button" class="checkout-online-method" data-online-method="emi">EMI</button>
                                </div>
                                <noscript>
                                    <div class="mb-3">
                                        <label class="form-label mb-1" for="online_method_noscript">Online payment type</label>
                                        <select class="form-select" id="online_method_noscript" name="online_method">
                                            <option value="upi" <?php echo $selectedOnlineMethod === 'upi' ? 'selected' : ''; ?>>UPI</option>
                                            <option value="card" <?php echo $selectedOnlineMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                                            <option value="paypal" <?php echo $selectedOnlineMethod === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
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
                                    <div class="checkout-online-panel" data-online-panel="paypal">
                                        <div class="small text-muted mb-2">For international preference. Actual routing is handled in Razorpay flow.</div>
                                        <div class="checkout-brand-chips">
                                            <span class="checkout-brand-chip">PayPal</span>
                                            <span class="checkout-brand-chip">Buyer Protection</span>
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

                    <?php if ($isIndia): ?>
                        <button type="submit" class="btn btn-primary btn-lg w-100">Place Order</button>
                    <?php else: ?>
                        <a href="<?php echo e($internationalQuoteUrl); ?>" class="btn btn-primary btn-lg w-100">Request International Quote</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="col-lg-5">
                <div class="surface-panel p-4">
                    <h5 class="mb-3">Order Summary</h5>
                    <?php foreach ($items as $item): ?>
                        <div class="d-flex justify-content-between mb-2 small">
                            <div>
                                <span class="fw-semibold"><?php echo e($item['name']); ?></span>
                                <?php if ($item['unit_type'] === 'meter' && !empty($item['meter_length']) && !empty($item['bundle_quantity'])): ?>
                                    <span class="text-muted"> — <?php echo e((string) $item['bundle_quantity']); ?> x <?php echo e(format_meter_quantity((float) $item['meter_length'])); ?>m = <?php echo e($item['quantity_text']); ?>m</span>
                                <?php else: ?>
                                    <span class="text-muted"> — <?php echo e($item['quantity_text']); ?> <?php echo e($item['quantity_unit_label']); ?></span>
                                <?php endif; ?>
                                <?php if ($item['selected_size'] !== ''): ?>
                                    <span class="text-muted"> (<?php echo e($item['selected_size']); ?>)</span>
                                <?php endif; ?>
                            </div>
                            <span>Rs <?php echo number_format($item['subtotal'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <form method="POST" action="/apply-coupon.php" class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="redirect_to" value="checkout">
                        <label class="form-label">Coupon Code</label>
                        <div class="d-flex gap-2">
                            <input type="text" name="coupon_code" class="form-control" placeholder="Enter code" value="<?php echo e((string) ($couponInfo['code'] ?? '')); ?>">
                            <button class="btn btn-outline-dark" type="submit">Apply</button>
                        </div>
                    </form>

                    <?php if ($couponInfo['valid']): ?>
                    <form method="POST" action="/remove-coupon.php" class="mb-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="redirect_to" value="checkout">
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
                        <span id="summary_subtotal">Rs <?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>Discount</span>
                        <span class="text-success" id="summary_discount">- Rs <?php echo number_format($discountAmount, 2); ?></span>
                    </div>

                    <div class="d-flex justify-content-between mb-1">
                        <span>Shipping</span>
                        <span id="summary_shipping">Rs <?php echo number_format($baseShippingAmount, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>COD Fee</span>
                        <span id="summary_cod_fee">Rs <?php echo number_format($codFeeAmount, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top">
                        <span>Total</span>
                        <span id="summary_total">Rs <?php echo number_format($totalAmount, 2); ?></span>
                    </div>
                    <div class="text-muted small mt-2">
                        Free shipping on orders above Rs 999. Orders below Rs 999: Rs 70 shipping. COD adds Rs 50 handling fee.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var codRadio = document.getElementById('payment_cod');
    var razorpayRadio = document.getElementById('payment_razorpay');
    var countryInput = document.querySelector('input[name="country"]');
    var subtotal = <?php echo json_encode((float) $subtotal); ?>;
    var discount = <?php echo json_encode((float) $discountAmount); ?>;
    var gstRate  = <?php echo json_encode((float) $gstRate); ?>;

    var shippingEl = document.getElementById('summary_shipping');
    var codFeeEl = document.getElementById('summary_cod_fee');
    var totalEl = document.getElementById('summary_total');

    var stateInput = document.querySelector('input[name="state"]');
    var payOptionCards = document.querySelectorAll('[data-pay-option]');
    var codPanel = document.getElementById('cod-panel');
    var razorpayPanel = document.getElementById('razorpay-panel');
    var onlineMethodButtons = document.querySelectorAll('.checkout-online-method');
    var onlinePanels = document.querySelectorAll('.checkout-online-panel');
    var onlineMethodInput = document.getElementById('online_method');

    if (!codRadio || !razorpayRadio || !shippingEl || !codFeeEl || !totalEl || !countryInput) {
        return;
    }

    function toMoney(v) {
        return 'Rs ' + Number(v).toFixed(2);
    }

    function syncSummary() {
        var country = String(countryInput.value || '').trim().toLowerCase();
        var isIndia = country === 'india';
        var paymentMethod = codRadio.checked ? 'cod' : 'razorpay';

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
    }

    function syncPaymentPanels() {
        var selected = codRadio.checked ? 'cod' : 'razorpay';
        payOptionCards.forEach(function (card) {
            card.classList.toggle('is-active', card.getAttribute('data-pay-option') === selected);
        });
        if (codPanel) {
            codPanel.classList.toggle('is-open', selected === 'cod');
        }
        if (razorpayPanel) {
            razorpayPanel.classList.toggle('is-open', selected === 'razorpay');
        }
        if (onlineMethodInput && selected === 'cod') {
            onlineMethodInput.value = '';
        }
    }

    function activateOnlineMethod(method) {
        onlineMethodButtons.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-online-method') === method);
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
    codRadio.addEventListener('change', syncPaymentPanels);
    razorpayRadio.addEventListener('change', syncPaymentPanels);
    countryInput.addEventListener('input', syncSummary);
    onlineMethodButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateOnlineMethod(btn.getAttribute('data-online-method'));
            razorpayRadio.checked = true;
            syncPaymentPanels();
            syncSummary();
        });
    });
    syncSummary();
    activateOnlineMethod(onlineMethodInput && onlineMethodInput.value ? onlineMethodInput.value : 'upi');
    syncPaymentPanels();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
