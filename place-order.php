<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

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

$fullName = trim($_POST['full_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$pincode = trim($_POST['pincode'] ?? '');
$country = trim($_POST['country'] ?? '');
$orderNotes = trim($_POST['order_notes'] ?? '');
$paymentMethod = strtolower(trim($_POST['payment_method'] ?? ''));
$onlineMethod = sanitize_online_payment_method((string) ($_POST['online_method'] ?? ''));
$shippingAddressId = (int) ($_POST['shipping_address_id'] ?? 0);
$shippingQuoteToken = trim((string) ($_POST['shipping_quote_token'] ?? ''));
$codFeeApply = ($paymentMethod === 'cod') ? 1 : 0;
$selectedCourierName = '';
$selectedCourierId = 0;
$shippingRateSource = 'manual';
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
release_stale_pending_razorpay_orders_for_customer($conn, $customerId, 30);

if ($customerId > 0 && $shippingAddressId > 0 && customer_addresses_table_ready($conn)) {
    $savedAddress = customer_address_get($conn, $customerId, $shippingAddressId);
    if ($savedAddress) {
        $fullName = trim((string) ($savedAddress['full_name'] ?? $fullName));
        $phone = trim((string) ($savedAddress['phone'] ?? $phone));
        $address = trim((string) ($savedAddress['address_line'] ?? $address));
        $city = trim((string) ($savedAddress['city'] ?? $city));
        $state = trim((string) ($savedAddress['state'] ?? $state));
        $pincode = trim((string) ($savedAddress['pincode'] ?? $pincode));
        $country = trim((string) ($savedAddress['country'] ?? $country));
    } else {
        $shippingAddressId = 0;
    }
}

$_SESSION['checkout_old'] = [
    'full_name' => $fullName,
    'phone' => $phone,
    'email' => $email,
    'address' => $address,
    'city' => $city,
    'state' => $state,
    'pincode' => $pincode,
    'country' => $country,
    'order_notes' => $orderNotes,
    'payment_method' => $paymentMethod,
    'cod_fee_apply' => $codFeeApply,
    'shipping_address_id' => $shippingAddressId,
];

$errors = [];
if ($fullName === '') { $errors['full_name'] = 'Full name is required.'; }
if ($phone === '') {
    $errors['phone'] = 'Phone is required.';
} elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
    $errors['phone'] = 'Enter a valid phone number.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Valid email is required.'; }
if ($address === '') { $errors['address'] = 'Address is required.'; }
elseif (strlen($address) > 500) { $errors['address'] = 'Address must be 500 characters or fewer.'; }
if ($city === '') { $errors['city'] = 'City is required.'; }
if ($state === '') { $errors['state'] = 'State is required.'; }
if ($pincode === '') {
    $errors['pincode'] = 'Pincode is required.';
} elseif (strcasecmp($country, 'india') === 0 && !preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
    $errors['pincode'] = 'Enter a valid 6-digit Indian pincode.';
}
if ($country === '') { $errors['country'] = 'Country is required.'; }
if ($country !== '' && strcasecmp($country, 'india') !== 0) {
    $errors['country'] = 'International checkout is inquiry-only for now. Please use Request International Quote.';
}
if (!in_array($paymentMethod, ['cod', 'razorpay'], true)) { $errors['payment_method'] = 'Invalid payment method.'; }
if (strlen($orderNotes) > 500) { $errors['order_notes'] = 'Notes must be 500 characters or fewer.'; }

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
$hydrated = cart_hydrate_items($conn, $cart, $cartSizes, $cartMeterMap);
if (!empty($hydrated['removed_keys'])) {
    foreach ($hydrated['removed_keys'] as $cartKey) {
        unset($_SESSION['cart'][$cartKey], $_SESSION['cart_size'][$cartKey], $_SESSION['cart_meter_length'][$cartKey]);
    }
    if ($customerId > 0) {
        cart_save_to_db($conn, $customerId, $_SESSION['cart'] ?? [], $_SESSION['cart_meter_length'] ?? []);
    }
    flash('error', 'Some unavailable items were removed from your cart. Please review and place your order again.');
    redirect('/checkout.php');
}
$cart = $_SESSION['cart'] ?? [];
$cartSubtotal = cart_items_subtotal($hydrated['items']);
if (empty($cart) || $cartSubtotal <= 0) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

$ids        = [];
$variantIds = [];
foreach (array_keys($cart) as $key) {
    [$pid, $variantId] = cart_parse_key((string) $key);
    if ($pid > 0) {
        $ids[] = $pid;
    }
    if ($variantId > 0) {
        $variantIds[] = $variantId;
    }
}
$ids        = array_values(array_unique($ids));
$variantIds = array_values(array_unique($variantIds));

if (empty($ids)) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

try {
    $conn->begin_transaction();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, sku, unit_type, min_order_meters, stock, stock_meters, is_available, status, price, sale_price, price_inr, cost_price, size, color
            FROM fabrics
            WHERE id IN ($placeholders)
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $productMap = [];
    foreach ($rows as $row) {
        $productMap[(int) $row['id']] = $row;
    }

    // Batch-load variants for stock / price / color / size
    $variantMap = !empty($variantIds) ? get_variants_by_ids($conn, $variantIds) : [];
    $siteSettings = get_site_settings();
    $gstRateSnapshot = max(0.0, (float) ($siteSettings['gst_rate'] ?? 18));
    $hsnCodeSnapshot = trim((string) ($siteSettings['hsn_code'] ?? '5208'));
    $companyState = strtolower(trim((string) ($siteSettings['company_state'] ?? '')));
    $buyerState = strtolower(trim((string) $state));
    $isIndiaOrder = strcasecmp(trim((string) $country), 'india') === 0;
    if (!$isIndiaOrder || $gstRateSnapshot <= 0) {
        $orderTaxType = 'none';
    } elseif ($companyState !== '' && $buyerState !== '' && $companyState !== $buyerState) {
        $orderTaxType = 'igst';
    } else {
        $orderTaxType = 'cgst_sgst';
    }

    $orderItems = [];
    $subtotal = 0.00;

    foreach ($cart as $cartKey => $qtyRaw) {
        [$productId, $variantId] = cart_parse_key((string) $cartKey);

        if (!isset($productMap[$productId])) {
            throw new RuntimeException('One of the products is no longer available.');
        }

        $product = $productMap[$productId];
        $variant = ($variantId > 0 && isset($variantMap[$variantId])) ? $variantMap[$variantId] : null;
        if ($variantId > 0 && (!$variant || (int) ($variant['fabric_id'] ?? 0) !== $productId || (int) ($variant['is_active'] ?? 0) !== 1)) {
            throw new RuntimeException('Selected variant is unavailable for ' . ($product['name'] ?? 'product'));
        }
        $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $product['unit_type']
            : 'meter';
        $meterMin = $unitType === 'meter' ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0) : 1.0;
        $qty = normalize_quantity_by_unit($qtyRaw, $unitType, (float) $meterMin);
        if (($product['status'] ?? '') !== 'active' || empty($product['is_available'])) {
            throw new RuntimeException('Product unavailable: ' . ($product['name'] ?? 'Unknown'));
        }

        // Color + size: prefer variant data; fall back to fabric fields / legacy session size.
        if ($variant) {
            $selectedColor = (string) ($variant['color'] ?? '');
            $selectedSize  = variant_size_display($variant, $unitType);
        } else {
            $selectedColor = (string) ($product['color'] ?? '');
            $selectedSize  = trim((string) ($cartSizes[$cartKey] ?? ''));
        }
        $unitsPerSet = ($variant && $unitType === 'set') ? normalize_units_per_set($variant['units_per_set'] ?? 1) : null;
        $packLabel = ($variant && $unitType === 'set')
            ? trim((string) (($variant['pack_label'] ?? '') ?: format_pack_label((int) $unitsPerSet)))
            : null;

        // Stock check: prefer variant-level; fall back to fabric-level.
        if ($variant) {
            $availableStock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($variant['stock'] ?? 0)
                : (float) ($variant['stock_meters'] ?? 0);
        } else {
            $availableStock = ($unitType === 'piece' || $unitType === 'set')
                ? (float) ($product['stock'] ?? 0)
                : (float) ($product['stock_meters'] ?? 0);
        }
        if ($availableStock < $qty) {
            throw new RuntimeException('Insufficient stock for ' . ($product['name'] ?? 'product'));
        }

        $regular = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
        $sale    = (float) ($product['sale_price'] ?? 0);
        if ($variant && $variant['price_override'] !== null && (float) $variant['price_override'] > 0) {
            $unitPrice = (float) $variant['price_override'];
        } else {
            $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        }
        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal  = round($subtotal + $lineTotal, 2);

        // Preserve bundle display info for invoice (e.g. "1 × 5m")
        $bundleMeterLength = null;
        $bundleQtyVal      = null;
        if ($unitType === 'meter' && isset($cartMeterMap[$cartKey]) && is_numeric($cartMeterMap[$cartKey]) && (float) $cartMeterMap[$cartKey] > 0) {
            $bundleMeterLength = round((float) $cartMeterMap[$cartKey], 2);
            $bundleQtyVal      = max(1, (int) round($qty / $bundleMeterLength));
        }

        $orderItems[] = [
            'product_id'     => $productId,
            'product_name'   => (string) ($product['name'] ?? ''),
            'size'           => $selectedSize,
            'color'          => $selectedColor,
            'unit_type'      => $unitType,
            'quantity'       => $qty,
            'price'          => $unitPrice,
            'total'          => $lineTotal,
            'sku'            => (string) ($product['sku'] ?? ''),
            'variant_id'     => $variantId,
            'bundle_quantity' => $bundleQtyVal,
            'meter_length'    => $bundleMeterLength,
            'pack_label'      => $packLabel,
            'units_per_set'   => $unitsPerSet,
            'cost_price_snapshot' => max(0.0, (float) ($product['cost_price'] ?? 0.0)),
        ];
    }

    $shipping = checkout_shipping_for_order((float) $subtotal, $country, $pincode, $paymentMethod);
    $baseShippingAmount = (float) $shipping['base_shipping'];
    $codFeeAmount = (float) $shipping['cod_fee'];
    $shippingAmount = (float) $shipping['shipping_total'];

    if (strcasecmp($country, 'india') === 0) {
        $quote = shipping_quote_get($shippingQuoteToken);
        if (!$quote) {
            throw new RuntimeException('Shipping quote expired. Please review checkout and place order again.');
        }
        $quoteSubtotal = round((float) ($quote['subtotal'] ?? -1), 2);
        $quotePincode = trim((string) ($quote['pincode'] ?? ''));
        $quoteCountry = strtolower(trim((string) ($quote['country'] ?? '')));
        $quotePayment = strtolower(trim((string) ($quote['payment_method'] ?? '')));
        if (
            abs($quoteSubtotal - round((float) $subtotal, 2)) > 0.001 ||
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
        $shippingRateSource = trim((string) ($quote['source'] ?? '')) ?: 'manual';
    }

    $couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
    $discountAmount = 0.00;
    $couponId = 0;
    $couponCodeNormalized = '';

    if ($couponCode !== '') {
        $couponStmt = $conn->prepare(
            "SELECT id, code, discount_type, discount_value, min_order_amount, max_discount,
                    start_date, end_date, usage_limit, used_count, status
             FROM coupons
             WHERE code = ?
             FOR UPDATE"
        );
        $normalizedCode = normalize_coupon_code($couponCode);
        $couponStmt->bind_param('s', $normalizedCode);
        $couponStmt->execute();
        $coupon = $couponStmt->get_result()->fetch_assoc();

        if ($coupon) {
            $validated = validate_coupon_for_amount($coupon, (float) $subtotal, date('Y-m-d'));
            if ($validated['valid']) {
                if (has_customer_used_coupon($conn, (int) $coupon['id'], $customerId)) {
                    throw new RuntimeException('You have already used this coupon.');
                }
                $discountAmount = (float) $validated['discount'];
                $couponId = (int) $coupon['id'];
                $couponCodeNormalized = (string) $normalizedCode;
            } else {
                unset($_SESSION['applied_coupon_code']);
            }
        } else {
            unset($_SESSION['applied_coupon_code']);
        }
    }

    $discountAmount     = min($discountAmount, $subtotal); // discount applies to product subtotal only — shipping is never discounted
    $remainingDiscount  = $discountAmount;
    $itemsCount         = count($orderItems);
    foreach ($orderItems as $idx => &$item) {
        $lineTotal = (float) ($item['total'] ?? 0.0);
        if ($itemsCount === 1 || $idx === ($itemsCount - 1)) {
            $itemDiscount = round($remainingDiscount, 2);
        } else {
            $itemDiscount = ($subtotal > 0 && $discountAmount > 0)
                ? round(($lineTotal / $subtotal) * $discountAmount, 2)
                : 0.0;
            $itemDiscount = min($itemDiscount, $remainingDiscount);
        }
        $itemDiscount = min($itemDiscount, $lineTotal);
        $remainingDiscount = round(max(0.0, $remainingDiscount - $itemDiscount), 2);

        $taxableAmount = round(max(0.0, $lineTotal - $itemDiscount), 2);
        $itemGstRate = ($orderTaxType === 'none') ? 0.0 : $gstRateSnapshot;
        $gstAmount = ($itemGstRate > 0 && $taxableAmount > 0)
            ? round($taxableAmount * $itemGstRate / (100 + $itemGstRate), 2)
            : 0.0;
        $cgstAmount = 0.0;
        $sgstAmount = 0.0;
        $igstAmount = 0.0;
        if ($orderTaxType === 'cgst_sgst' && $gstAmount > 0) {
            $cgstAmount = round($gstAmount / 2, 2);
            $sgstAmount = round($gstAmount - $cgstAmount, 2);
        } elseif ($orderTaxType === 'igst' && $gstAmount > 0) {
            $igstAmount = $gstAmount;
        }

        $item['discount_amount'] = $itemDiscount;
        $item['taxable_amount'] = $taxableAmount;
        $item['gst_rate_snapshot'] = round($itemGstRate, 3);
        $item['gst_amount'] = $gstAmount;
        $item['cgst_amount'] = $cgstAmount;
        $item['sgst_amount'] = $sgstAmount;
        $item['igst_amount'] = $igstAmount;
        $item['tax_type'] = $orderTaxType;
        $item['hsn_code_snapshot'] = $hsnCodeSnapshot;
    }
    unset($item);

    $taxableAmountOrder = max(0.0, $subtotal - $discountAmount);
    // Tax-inclusive pricing: GST is already in product prices. Total = taxable + shipping only.
    $totalAmount        = round($taxableAmountOrder + $shippingAmount, 2);

    $orderNumber = 'VT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

    $orderNotesWithCoupon = $orderNotes;
    if ($couponCode !== '' && $discountAmount > 0) {
        $couponNote = "Coupon Applied: " . normalize_coupon_code($couponCode);
        $orderNotesWithCoupon = trim($orderNotesWithCoupon . "\n" . $couponNote);
    }
    $shippingNote = "Shipping: Rs " . number_format($baseShippingAmount, 2) . " | COD Fee: Rs " . number_format($codFeeAmount, 2);
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

    if (orders_structured_financial_columns_ready($conn)) {
        $insertOrder = $conn->prepare(
            "INSERT INTO orders (
                order_number, customer_name, customer_phone, customer_email,
                address, city, state, pincode, country,
                subtotal, shipping_amount, discount_amount, total_amount,
                payment_method, payment_status, order_status, order_notes, shipping_address,
                customer_id, currency, shipping_cost, total, status, notes,
                coupon_id, coupon_code, coupon_discount,
                shipping_quote_token, shipping_source, courier_id, courier_name, cod_fee, base_shipping
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, 'INR', ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertOrder->bind_param(
            'sssssssssddddsssiddsisdssisdd',
            $orderNumber,
            $fullName,
            $phone,
            $email,
            $address,
            $city,
            $state,
            $pincode,
            $country,
            $subtotal,
            $shippingAmount,
            $discountAmount,
            $totalAmount,
            $paymentMethod,
            $orderNotesWithCoupon,
            $shippingAddressJson,
            $customerId,
            $shippingAmount,
            $totalAmount,
            $orderNotesWithCoupon,
            $couponId,
            $couponCodeNormalized,
            $discountAmount,
            $shippingQuoteToken,
            $shippingRateSource,
            $selectedCourierId,
            $selectedCourierName,
            $codFeeAmount,
            $baseShippingAmount
        );
    } else {
        $insertOrder = $conn->prepare(
            "INSERT INTO orders (
                order_number, customer_name, customer_phone, customer_email,
                address, city, state, pincode, country,
                subtotal, shipping_amount, discount_amount, total_amount,
                payment_method, payment_status, order_status, order_notes, shipping_address,
                customer_id, currency, shipping_cost, total, status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, 'INR', ?, ?, 'pending', ?)"
        );
        $insertOrder->bind_param(
            'sssssssssddddsssidds',
            $orderNumber,
            $fullName,
            $phone,
            $email,
            $address,
            $city,
            $state,
            $pincode,
            $country,
            $subtotal,
            $shippingAmount,
            $discountAmount,
            $totalAmount,
            $paymentMethod,
            $orderNotesWithCoupon,
            $shippingAddressJson,
            $customerId,
            $shippingAmount,
            $totalAmount,
            $orderNotesWithCoupon
        );
    }
    $insertOrder->execute();
    $orderId = (int) $conn->insert_id;

    $supportsVariantCol = order_items_supports_variant($conn);
    $supportsTaxSnapshot = order_items_supports_tax_snapshot($conn);
    $supportsCostSnapshot = order_items_supports_cost_snapshot($conn);
    if ($supportsVariantCol && $supportsTaxSnapshot && $supportsCostSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                bundle_quantity, meter_length, pack_label, units_per_set, variant_id,
                taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsVariantCol && $supportsTaxSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                bundle_quantity, meter_length, pack_label, units_per_set, variant_id,
                taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsVariantCol && $supportsCostSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                bundle_quantity, meter_length, pack_label, units_per_set, variant_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsVariantCol) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                bundle_quantity, meter_length, pack_label, units_per_set, variant_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsTaxSnapshot && $supportsCostSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                bundle_quantity, meter_length, pack_label, units_per_set,
                taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsTaxSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                bundle_quantity, meter_length, pack_label, units_per_set,
                taxable_amount, discount_amount, gst_rate_snapshot, gst_amount, cgst_amount, sgst_amount, igst_amount, tax_type, hsn_code_snapshot
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } elseif ($supportsCostSnapshot) {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total, cost_price_snapshot,
                bundle_quantity, meter_length, pack_label, units_per_set
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    } else {
        $insertOrderItem = $conn->prepare(
            "INSERT INTO order_items (
                order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
                fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total,
                bundle_quantity, meter_length, pack_label, units_per_set
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
    }

    foreach ($orderItems as $item) {
        $pid    = (int)   $item['product_id'];
        $pname  =         $item['product_name'];
        $psize  =         $item['size'];
        $pcolor =         $item['color'];
        $punit  =         $item['unit_type'];
        $qty    = (float) $item['quantity'];
        $price  = (float) $item['price'];
        $total  = (float) $item['total'];
        $sku    =         $item['sku'];
        $bQty   = isset($item['bundle_quantity']) ? (int)   $item['bundle_quantity'] : null;
        $bMeter = isset($item['meter_length'])    ? (float) $item['meter_length']    : null;
        $pLabel = isset($item['pack_label'])      ? (string) $item['pack_label']     : null;
        $uSet   = isset($item['units_per_set'])   ? (int) $item['units_per_set']     : null;
        $vId    = ($supportsVariantCol && ($item['variant_id'] ?? 0) > 0) ? (int) $item['variant_id'] : null;
        $costSnapshot = (float) ($item['cost_price_snapshot'] ?? 0.0);
        $taxableAmount = (float) ($item['taxable_amount'] ?? $total);
        $itemDiscount = (float) ($item['discount_amount'] ?? 0.0);
        $itemGstRate = (float) ($item['gst_rate_snapshot'] ?? 0.0);
        $itemGstAmount = (float) ($item['gst_amount'] ?? 0.0);
        $itemCgstAmount = (float) ($item['cgst_amount'] ?? 0.0);
        $itemSgstAmount = (float) ($item['sgst_amount'] ?? 0.0);
        $itemIgstAmount = (float) ($item['igst_amount'] ?? 0.0);
        $itemTaxType = (string) ($item['tax_type'] ?? 'none');
        $itemHsnCode = (string) ($item['hsn_code_snapshot'] ?? '');

        if ($supportsVariantCol && $supportsTaxSnapshot && $supportsCostSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissddddidsiidddddddss',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total, $costSnapshot,
                $bQty, $bMeter, $pLabel, $uSet, $vId,
                $taxableAmount, $itemDiscount, $itemGstRate, $itemGstAmount, $itemCgstAmount, $itemSgstAmount, $itemIgstAmount, $itemTaxType, $itemHsnCode
            );
        } elseif ($supportsVariantCol && $supportsTaxSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissdddidsiidddddddss',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total,
                $bQty, $bMeter, $pLabel, $uSet, $vId,
                $taxableAmount, $itemDiscount, $itemGstRate, $itemGstAmount, $itemCgstAmount, $itemSgstAmount, $itemIgstAmount, $itemTaxType, $itemHsnCode
            );
        } elseif ($supportsVariantCol && $supportsCostSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissddddidsii',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total, $costSnapshot,
                $bQty, $bMeter, $pLabel, $uSet, $vId
            );
        } elseif ($supportsVariantCol) {
            $insertOrderItem->bind_param(
                'iissssdddissdddidsii',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total,
                $bQty, $bMeter, $pLabel, $uSet, $vId
            );
        } elseif ($supportsTaxSnapshot && $supportsCostSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissddddidsidddddddss',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total, $costSnapshot,
                $bQty, $bMeter, $pLabel, $uSet,
                $taxableAmount, $itemDiscount, $itemGstRate, $itemGstAmount, $itemCgstAmount, $itemSgstAmount, $itemIgstAmount, $itemTaxType, $itemHsnCode
            );
        } elseif ($supportsTaxSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissdddidsidddddddss',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total,
                $bQty, $bMeter, $pLabel, $uSet,
                $taxableAmount, $itemDiscount, $itemGstRate, $itemGstAmount, $itemCgstAmount, $itemSgstAmount, $itemIgstAmount, $itemTaxType, $itemHsnCode
            );
        } elseif ($supportsCostSnapshot) {
            $insertOrderItem->bind_param(
                'iissssdddissddddidsi',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total, $costSnapshot,
                $bQty, $bMeter, $pLabel, $uSet
            );
        } else {
            $insertOrderItem->bind_param(
                'iissssdddissdddidsi',
                $orderId, $pid, $pname, $psize, $pcolor, $punit,
                $qty, $price, $total,
                $pid, $pname, $sku,
                $qty, $price, $total,
                $bQty, $bMeter, $pLabel, $uSet
            );
        }
        $insertOrderItem->execute();
    }
    reserve_order_inventory($conn, $orderId);

    $insertPayment = $conn->prepare(
        "INSERT INTO payments (order_id, payment_method, payment_status, transaction_id, amount)
         VALUES (?, ?, 'pending', NULL, ?)"
    );
    $insertPayment->bind_param('isd', $orderId, $paymentMethod, $totalAmount);
    $insertPayment->execute();
    if ($selectedCourierName !== '') {
        $insShipment = $conn->prepare(
            "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
             VALUES (?, ?, '', '', ?, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                courier_name = VALUES(courier_name),
                shipping_cost = VALUES(shipping_cost)"
        );
        $insShipment->bind_param('isd', $orderId, $selectedCourierName, $baseShippingAmount);
        $insShipment->execute();
    }

    log_order_activity(
        $conn,
        $orderId,
        'order_placed',
        'customer',
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
    if ($paymentMethod === 'cod') {
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
        'payment_status' => 'pending',
        'order_status' => 'pending',
        'subtotal' => $subtotal,
        'shipping_amount' => $shippingAmount,
        'discount_amount' => $discountAmount,
        'total_amount' => $totalAmount,
    ];
    do_action('order.after_create', $orderHookContext);

    // For COD: increment coupon usage only after the order is committed (payment confirmed
    // on delivery is outside our system's control, but the order is real and the discount
    // is locked in — this is the earliest safe point for COD).
    // For Razorpay: coupon increment happens in razorpay-verify.php after payment confirmation.
    if ($couponId > 0 && $paymentMethod === 'cod') {
        $couponUsedStmt = $conn->prepare(
            "UPDATE coupons
             SET used_count = used_count + 1
             WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)"
        );
        $couponUsedStmt->bind_param('i', $couponId);
        $couponUsedStmt->execute();
        if ($conn->affected_rows <= 0) {
            throw new RuntimeException('Coupon usage limit reached.');
        }
        if (!mark_coupon_used_once($conn, $couponId, $customerId, $orderId)) {
            throw new RuntimeException('Unable to mark coupon usage for this order.');
        }
    }

    $conn->commit();
    do_action('order.after_commit', $orderHookContext);

    $_SESSION['last_order'] = [
        'id' => $orderId,
        'order_number' => $orderNumber,
        'customer_name' => $fullName,
        'total_amount' => $totalAmount,
    ];

    if ($paymentMethod === 'razorpay') {
        $_SESSION['pending_order_id'] = $orderId;
        $_SESSION['pending_order_number'] = $orderNumber;
        $_SESSION['pending_coupon_id'] = $couponId;
        $_SESSION['pending_online_method'] = $onlineMethod;
        redirect('/payment/razorpay-create.php');
    }

    unset($_SESSION['cart'], $_SESSION['cart_size'], $_SESSION['cart_meter_length'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
    if ($customerId > 0) {
        cart_clear_db($conn, $customerId);
    }
    send_order_confirmation_email($conn, $orderId);
    redirect('/order-success.php?order=' . urlencode($orderNumber));
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback failure
    }

    error_log('[amberfabrics] place-order failed: ' . $e->getMessage());
    $_SESSION['checkout_errors'] = ['Unable to place order right now. Please try again.'];
    redirect('/checkout.php');
}
