<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/helpers/coupon-functions.php';
require_once __DIR__ . '/includes/security/customer-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/checkout.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('/checkout.php');
}

// Clear any stale pending-payment session from a previous abandoned Razorpay attempt
unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id'], $_SESSION['pending_online_method']);

// One-time nonce to prevent duplicate order submission (double-click, back-and-submit)
$submittedNonce = (string) ($_POST['order_nonce'] ?? '');
if ($submittedNonce === '' || empty($_SESSION['order_nonce']) ||
    !hash_equals((string) $_SESSION['order_nonce'], $submittedNonce)) {
    flash('error', 'Your session has expired or you already submitted this order. Please review your cart and try again.');
    redirect('/checkout.php');
}
unset($_SESSION['order_nonce']);

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$checkoutInput = CheckoutInput::fromRequest($_POST, $customerId);
if (
    $customerId > 0
    && (int) $checkoutInput['shipping_address_id'] > 0
    && CustomerAddressService::tableReady($conn)
) {
    $checkoutInput = CheckoutInput::withSavedAddress(
        $checkoutInput,
        CustomerAddressService::get($conn, $customerId, (int) $checkoutInput['shipping_address_id'])
    );
}

$fullName = (string) $checkoutInput['full_name'];
$phone = (string) $checkoutInput['phone'];
$email = (string) $checkoutInput['email'];
$address = (string) $checkoutInput['address'];
$city = (string) $checkoutInput['city'];
$state = (string) $checkoutInput['state'];
$pincode = (string) $checkoutInput['pincode'];
$country = (string) $checkoutInput['country'];
$orderNotes = (string) $checkoutInput['order_notes'];
$paymentMethod = (string) $checkoutInput['payment_method'];
$codWhatsappConsent = (int) $checkoutInput['cod_whatsapp_consent'] === 1;
$onlineMethod = (string) $checkoutInput['online_method'];
$shippingAddressId = (int) $checkoutInput['shipping_address_id'];
$shippingQuoteToken = (string) $checkoutInput['shipping_quote_token'];
$codFeeApply = (int) $checkoutInput['cod_fee_apply'];
$selectedCourierName = '';
$selectedCourierId = 0;
$shippingRateSource = 'manual';
$acceptedEstimate = [];
$wantsCreateAccount = (bool) $checkoutInput['wants_create_account'];
$sendAccountActivation = $wantsCreateAccount;

$_SESSION['checkout_old'] = CheckoutInput::sessionState($checkoutInput);

PaymentService::release_stale_pending_razorpay_orders_for_customer($conn, $customerId, 30);

$errors = CheckoutInput::validateContactDeliveryPayment($checkoutInput);
if(empty($errors)){log_ecommerce_event($conn,'add_payment_info',$customerId>0?$customerId:null,null,null,null,null,null,['session_type'=>$customerId>0?'customer':'guest','payment_method'=>$paymentMethod]);}
$errors = array_merge($errors, CheckoutInput::validateOrderNotes($checkoutInput));

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart']) || empty($_SESSION['cart'])) {
    $errors['_cart'] = 'Your cart is empty.';
}

if (!empty($errors)) {
    $_SESSION['checkout_errors'] = $errors;
    redirect('/checkout.php');
}


$cart = $_SESSION['cart'];
$cartSizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
$cartMeterMap = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];

// Re-hydrate cart from canonical shared logic so checkout and order placement stay consistent.
$hydrated = CartService::cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
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
    if ($customerId > 0) {
        CartService::cart_save_to_db($conn, $customerId, $_SESSION['cart'] ?? [], $_SESSION['cart_meter_length'] ?? []);
    }
    flash('error', 'Some unavailable items or quantities changed. Please review and place your order again.');
    redirect('/checkout.php');
}
$cart = $_SESSION['cart'] ?? [];
$cartSubtotal = CartService::cart_items_subtotal($hydrated['items']);
if (empty($cart) || $cartSubtotal <= 0) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

$cartReferences = OrderItemSnapshotService::cartReferences($cart);
$ids = $cartReferences['product_ids'];
$variantIds = $cartReferences['variant_ids'];

if (empty($ids)) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

$businessCommitted = false;
try {
    $conn->begin_transaction();

    $productMap = OrderItemSnapshotService::lockProducts($conn, $ids);
    $variantMap = $variantIds !== [] ? InventoryService::get_variants_by_ids($conn, $variantIds) : [];
    $taxContext = CheckoutPricingService::taxContext(SiteSettingsService::get(), $state, $country);
    $gstRateSnapshot = (float) $taxContext['default_gst_rate'];
    $hsnCodeSnapshot = (string) $taxContext['default_hsn_code'];
    $orderTaxType = (string) $taxContext['tax_type'];

    $itemSnapshot = OrderItemSnapshotService::build(
        $cart,
        $cartSizes,
        $cartMeterMap,
        $productMap,
        $variantMap,
        $gstRateSnapshot,
        $hsnCodeSnapshot
    );
    $orderItems = $itemSnapshot['items'];
    $subtotal = (float) $itemSnapshot['subtotal'];

    $shipping = PaymentService::checkout_shipping_for_order((float) $subtotal, $country, $pincode, $paymentMethod);
    $baseShippingAmount = (float) $shipping['base_shipping'];
    $codFeeAmount = (float) $shipping['cod_fee'];
    $shippingAmount = (float) $shipping['shipping_total'];

    $couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
    $discountAmount = 0.00;
    $couponId = 0;
    $couponCodeNormalized = '';

    if ($couponCode !== '') {
        $couponDiscount = CouponService::lockedDiscountForOrder(
            $conn,
            $couponCode,
            (float) $subtotal,
            $customerId,
            date('Y-m-d')
        );
        if ($couponDiscount['valid']) {
            $discountAmount = (float) $couponDiscount['discount'];
            $couponId = (int) $couponDiscount['coupon_id'];
            $couponCodeNormalized = (string) $couponDiscount['code'];
        } else {
            unset($_SESSION['applied_coupon_code']);
        }
    }

    $discountAmount     = min($discountAmount, $subtotal); // discount applies to product subtotal only — shipping is never discounted
    $quotedInvoiceValue = round(max(0.0, $subtotal - $discountAmount), 2);

    if (strcasecmp($country, 'india') === 0) {
        $quote = InventoryService::shipping_quote_get($shippingQuoteToken);
        if (!$quote) {
            throw new RuntimeException('Shipping quote expired. Please review checkout and place order again.');
        }
        $quoteSubtotal = round((float) ($quote['subtotal'] ?? -1), 2);
        $quotePincode = trim((string) ($quote['pincode'] ?? ''));
        $quoteCountry = strtolower(trim((string) ($quote['country'] ?? '')));
        $quotePayment = strtolower(trim((string) ($quote['payment_method'] ?? '')));
        if (
            abs($quoteSubtotal - $quotedInvoiceValue) > 0.001 ||
            strtolower(trim((string) $country)) !== $quoteCountry ||
            trim((string) $pincode) !== $quotePincode ||
            strtolower((string) $paymentMethod) !== $quotePayment
        ) {
            throw new RuntimeException('Shipping quote changed. Please review checkout totals and try again.');
        }
        $baseShippingAmount = round((float) ($quote['base_shipping'] ?? $baseShippingAmount), 2);
        $codFeeAmount = round((float) ($quote['cod_fee'] ?? $codFeeAmount), 2);
        $shippingAmount = round((float) ($quote['shipping_total'] ?? $shippingAmount), 2);
        $selectedCourierName = trim((string) ($quote['courier_name'] ?? ''));
        $selectedCourierId = (int) ($quote['courier_id'] ?? 0);
        $acceptedEstimate = $quote;
        $shippingRateSource = trim((string) ($quote['source'] ?? '')) ?: 'manual';
    }

    $orderItems = CheckoutPricingService::allocateIncludedTax(
        $orderItems,
        (float) $subtotal,
        (float) $discountAmount,
        $orderTaxType,
        $gstRateSnapshot,
        $hsnCodeSnapshot
    );

    $taxableAmountOrder = max(0.0, $subtotal - $discountAmount);
    // Tax-inclusive pricing: GST is already in product prices. Total = taxable + shipping only.
    $totalAmount        = round($taxableAmountOrder + $shippingAmount, 2);
    $isZeroAmountOrder  = $totalAmount <= 0.0;

    $codWhatsappConsentRequired = false;
    if (
        $paymentMethod === 'cod'
        && function_exists('cod_guard_whatsapp_configured')
        && function_exists('cod_guard_plan_for_amount')
        && cod_guard_whatsapp_configured(cod_guard_settings())
    ) {
        $codPlan = cod_guard_plan_for_amount($totalAmount);
        $codWhatsappConsentRequired = in_array((string) ($codPlan['channel'] ?? ''), ['whatsapp', 'call'], true);
    }
    if ($codWhatsappConsentRequired && !$codWhatsappConsent) {
        $conn->rollback();
        $_SESSION['checkout_errors'] = [
            'cod_whatsapp_consent' => 'Agree to receive transactional WhatsApp messages for COD confirmation, or choose online payment.',
        ];
        redirect('/checkout.php');
    }

    $orderNumber = 'VT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

    $orderNotesWithCoupon = $orderNotes;
    if ($couponCode !== '' && $discountAmount > 0) {
        $couponNote = "Coupon Applied: " . normalize_coupon_code($couponCode);
        $orderNotesWithCoupon = trim($orderNotesWithCoupon . "\n" . $couponNote);
    }
    $shippingNote = "Shipping: " . money($baseShippingAmount) . " | COD Fee: " . money($codFeeAmount);
    if ($selectedCourierName !== '') {
        $shippingNote .= " | Courier: " . $selectedCourierName;
    }
    $orderNotesWithCoupon = trim($orderNotesWithCoupon . "\n" . $shippingNote);
    $shippingAddressJson = json_encode([
        'address_id' => $shippingAddressId,
        'name' => $fullName,
        'address' => $address,
        'city' => $city,
        'state' => $state,
        'pincode' => $pincode,
        'country' => $country,
        'phone' => $phone,
        'email' => $email,
    ], JSON_UNESCAPED_UNICODE);
    if (!is_string($shippingAddressJson) || $shippingAddressJson === '') {
        $shippingAddressJson = null;
    }

    // A real guest order has no customer owner. Keep customer_id NULL rather
    // than using 0; payment ownership is held by the server-side checkout
    // session until payment completes.
    $orderCustomerId = $customerId > 0 ? $customerId : null;

    $orderId = OrderPersistenceService::insertOrder($conn, [
        'order_number' => $orderNumber,
        'customer_name' => $fullName,
        'customer_phone' => $phone,
        'customer_email' => $email,
        'address' => $address,
        'city' => $city,
        'state' => $state,
        'pincode' => $pincode,
        'country' => $country,
        'subtotal' => $subtotal,
        'shipping_amount' => $shippingAmount,
        'discount_amount' => $discountAmount,
        'total_amount' => $totalAmount,
        'payment_method' => $paymentMethod,
        'order_notes' => $orderNotesWithCoupon,
        'shipping_address_json' => $shippingAddressJson,
        'customer_id' => $orderCustomerId,
        'coupon_id' => $couponId,
        'coupon_code' => $couponCodeNormalized,
        'shipping_quote_token' => $shippingQuoteToken,
        'shipping_source' => $shippingRateSource,
        'courier_id' => $selectedCourierId,
        'courier_name' => $selectedCourierName,
        'cod_fee' => $codFeeAmount,
        'base_shipping' => $baseShippingAmount,
    ]);

    // Keep the accepted delivery promise inside the still-open order transaction.
    OrderPersistenceService::saveDeliveryEstimate($conn, $orderId, $acceptedEstimate);

    if ($sendAccountActivation && (int) plugin_setting('conversion-mvp', 'account_activation_enabled', 1) === 1) {
        EmailService::mark_account_activation_requested($conn, $orderId);
    }

    OrderPersistenceService::insertItems($conn, $orderId, $orderItems);

    InventoryService::reserve_order_inventory($conn, $orderId);

    OrderPersistenceService::insertPendingPayment($conn, $orderId, $paymentMethod, (float) $totalAmount);
    if ($isZeroAmountOrder) {
        OrderPersistenceService::markZeroAmountPaid($conn, $orderId, $paymentMethod);
    }
    OrderPersistenceService::upsertQuotedShipment(
        $conn,
        $orderId,
        $selectedCourierName,
        (float) $baseShippingAmount
    );

    $orderActorType = $customerId > 0 ? 'customer' : 'guest';
    log_order_activity(
        $conn,
        $orderId,
        'order_placed',
        $orderActorType,
        $customerId,
        $fullName,
        'Payment: ' . $paymentMethod . ' | Total: ' . number_format($totalAmount, 2, '.', '')
    );
    if ($couponId > 0) {
        log_order_activity(
            $conn,
            $orderId,
            'coupon_applied',
            'system',
            0,
            'system',
            'Coupon code: ' . normalize_coupon_code($couponCode)
        );
    }
    if ($paymentMethod === 'razorpay') {
        log_order_activity($conn, $orderId, 'inventory_reserved', 'system', 0, 'system', 'Stock reserved before online payment.');
    }
    if ($selectedCourierName !== '') {
        log_order_activity(
            $conn,
            $orderId,
            'shipping_quote_locked',
            'system',
            0,
            'system',
            'Courier: ' . $selectedCourierName . ($selectedCourierId > 0 ? (' (#' . $selectedCourierId . ')') : '')
        );
    }
    if ($isZeroAmountOrder) {
        log_order_activity($conn, $orderId, 'payment_zero_amount_auto_confirmed', 'system', 0, 'system', 'Order auto-confirmed because total payable amount is zero.');
    } elseif ($paymentMethod === 'cod') {
        log_order_activity($conn, $orderId, 'payment_pending_cod', 'system', 0, 'system', 'COD order created.');
    } else {
        log_order_activity($conn, $orderId, 'payment_pending_online', 'system', 0, 'system', 'Awaiting Razorpay payment.');
    }

    $orderHookContext = [
        'conn' => $conn,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'customer_id' => $customerId,
        'customer_name' => $fullName,
        'customer_phone' => $phone,
        'payment_method' => $paymentMethod,
        'whatsapp_transactional_consent' => $codWhatsappConsent,
        'whatsapp_transactional_consent_at' => $codWhatsappConsent ? date('Y-m-d H:i:s') : null,
        'payment_status' => $isZeroAmountOrder ? 'paid' : 'pending',
        'order_status' => $isZeroAmountOrder ? 'confirmed' : 'pending',
        'marketing_consent' => marketing_consent_status(),
        'subtotal' => $subtotal,
        'shipping_amount' => $shippingAmount,
        'discount_amount' => $discountAmount,
        'total_amount' => $totalAmount,
    ];
    do_action('order.after_create', $orderHookContext);

    // Coupon capacity is part of the order reservation and is committed atomically
    // with inventory for both COD and Razorpay orders.
    if ($couponId > 0) {
        $guestIdentityHash = $customerId > 0 ? null : CouponService::guestIdentityHash($email, $phone);
        CouponService::reserveForOrder($conn, $couponId, $customerId, $orderId, $guestIdentityHash);
        log_order_activity($conn, $orderId, 'coupon_reserved', 'system', 0, 'system', 'Coupon capacity reserved with order creation.');
    }

    OutboxService::enqueueOrderAfterCommit($conn, $orderId, $orderHookContext);
    if ($paymentMethod === 'cod') {
        OutboxService::enqueueOrderConfirmation($conn, $orderId);
        OutboxService::enqueueAccountActivation($conn, $orderId);
    } elseif ($isZeroAmountOrder) {
        OutboxService::enqueuePaidOrderSideEffects($conn, $orderId, [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
        ]);
    }

    $conn->commit();
    $businessCommitted = true;
    OutboxService::safeDrainForOrder($conn, $orderId);
    foreach ($orderItems as $item) {
        log_ecommerce_event(
            $conn,
            'order_item_placed',
            $customerId > 0 ? $customerId : null,
            $orderId,
            (int) ($item['product_id'] ?? 0),
            (string) ($item['unit_type'] ?? 'meter'),
            isset($item['quantity']) ? (float) $item['quantity'] : null,
            isset($item['total']) ? (float) $item['total'] : null,
            [
                'order_number' => $orderNumber,
                'variant_id' => (int) ($item['variant_id'] ?? 0),
                'meter_length' => $item['meter_length'] ?? null,
                'bundle_quantity' => $item['bundle_quantity'] ?? null,
                'payment_method' => $paymentMethod,
            ]
        );
    }
    log_ecommerce_event(
        $conn,
        'order_placed',
        $customerId > 0 ? $customerId : null,
        $orderId,
        null,
        null,
        null,
        (float) $totalAmount,
        [
            'order_number' => $orderNumber,
            'payment_method' => $paymentMethod,
            'subtotal' => (float) $subtotal,
            'shipping_amount' => (float) $shippingAmount,
            'discount_amount' => (float) $discountAmount,
            'item_count' => count($orderItems),
        ]
    );

    $_SESSION['last_order'] = [
        'id' => $orderId,
        'order_number' => $orderNumber,
        'customer_name' => $fullName,
        'total_amount' => $totalAmount,
    ];

    if ($paymentMethod === 'razorpay') {
        if (!$isZeroAmountOrder) {
            $_SESSION['pending_order_id'] = $orderId;
            $_SESSION['pending_order_number'] = $orderNumber;
            $_SESSION['pending_coupon_id'] = $couponId;
            $_SESSION['pending_online_method'] = $onlineMethod;
            redirect('/payment/razorpay-create.php');
        }
    }

    CartService::checkout_session_clear_after_order($conn, $customerId);
    redirect('/order-success.php?order=' . urlencode($orderNumber));
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback failure
    }

    if ($businessCommitted) {
        error_log('[app] place-order post-commit work failed: ' . CronService::sanitizeError($e->getMessage()));
        $_SESSION['last_order'] = [
            'id' => (int) ($orderId ?? 0),
            'order_number' => (string) ($orderNumber ?? ''),
            'customer_name' => (string) ($fullName ?? ''),
            'total_amount' => (float) ($totalAmount ?? 0),
        ];
        if (($paymentMethod ?? '') === 'razorpay' && empty($isZeroAmountOrder)) {
            $_SESSION['pending_order_id'] = (int) ($orderId ?? 0);
            $_SESSION['pending_order_number'] = (string) ($orderNumber ?? '');
            $_SESSION['pending_coupon_id'] = (int) ($couponId ?? 0);
            $_SESSION['pending_online_method'] = (string) ($onlineMethod ?? '');
            redirect('/payment/razorpay-create.php');
        }
        CartService::checkout_session_clear_after_order($conn, (int) ($customerId ?? 0));
        redirect('/order-success.php?order=' . urlencode((string) ($orderNumber ?? '')));
    }

    error_log('[app] place-order failed: ' . $e->getMessage());
    $_SESSION['checkout_errors'] = ['_checkout' => 'Unable to place order right now. Please try again.'];
    redirect('/checkout.php');
}
