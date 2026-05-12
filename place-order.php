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
unset($_SESSION['pending_order_id'], $_SESSION['pending_order_number'], $_SESSION['pending_coupon_id']);

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
$codFeeApply = ($paymentMethod === 'cod') ? 1 : 0;
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

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
$ids = array_map('intval', array_keys($cart));
$ids = array_values(array_filter($ids, static fn($v) => $v > 0));

if (empty($ids)) {
    flash('error', 'Your cart is empty.');
    redirect('/cart.php');
}

try {
    $conn->begin_transaction();

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, sku, unit_type, min_order_meters, stock, stock_meters, is_available, status, price, sale_price, price_inr, size, color
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

    $orderItems = [];
    $subtotal = 0.00;

    foreach ($cart as $productIdRaw => $qtyRaw) {
        $productId = (int) $productIdRaw;

        if (!isset($productMap[$productId])) {
            throw new RuntimeException('One of the products is no longer available.');
        }

        $product = $productMap[$productId];
        $unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
            ? (string) $product['unit_type']
            : 'meter';
        $meterMin = $unitType === 'meter' ? normalize_meter_quantity($product['min_order_meters'] ?? 1, 1.0) : 1.0;
        $qty = normalize_quantity_by_unit($qtyRaw, $unitType, (float) $meterMin);
        if (($product['status'] ?? '') !== 'active' || empty($product['is_available'])) {
            throw new RuntimeException('Product unavailable: ' . ($product['name'] ?? 'Unknown'));
        }

        $selectedSize = trim((string) ($cartSizes[$productId] ?? ''));
        if (!empty($product['size'])) {
            $options = preg_split('/[,\|\/]+/', (string) $product['size']);
            $validSizes = [];
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $clean = trim((string) $opt);
                    if ($clean !== '') {
                        $validSizes[] = $clean;
                    }
                }
            }
            $validSizes = array_values(array_unique($validSizes));
            if (!empty($validSizes) && !in_array($selectedSize, $validSizes, true)) {
                throw new RuntimeException('Please reselect product size before placing order.');
            }
        }

        $availableStock = ($unitType === 'piece' || $unitType === 'set')
            ? (float) ($product['stock'] ?? 0)
            : (float) ($product['stock_meters'] ?? 0);
        if ($availableStock < $qty) {
            throw new RuntimeException('Insufficient stock for ' . ($product['name'] ?? 'product'));
        }

        $regular = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
        $sale = (float) ($product['sale_price'] ?? 0);
        $unitPrice = ($sale > 0 && $sale < $regular) ? $sale : $regular;
        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal = round($subtotal + $lineTotal, 2);

        $orderItems[] = [
            'product_id' => $productId,
            'product_name' => (string) ($product['name'] ?? ''),
            'size' => $selectedSize,
            'color' => (string) ($product['color'] ?? ''),
            'unit_type' => $unitType,
            'quantity' => $qty,
            'price' => $unitPrice,
            'total' => $lineTotal,
            'sku' => (string) ($product['sku'] ?? ''),
        ];
    }

    $baseShippingAmount = ($paymentMethod === 'razorpay' && $subtotal >= 999) ? 0.00 : 70.00;
    $codFeeAmount = ($paymentMethod === 'cod' && $codFeeApply === 1) ? 50.00 : 0.00;
    $shippingAmount = $baseShippingAmount + $codFeeAmount;
    $preDiscountTotal = $subtotal + $shippingAmount;

    $couponCode = (string) ($_SESSION['applied_coupon_code'] ?? '');
    $discountAmount = 0.00;
    $couponId = 0;

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
            $validated = validate_coupon_for_amount($coupon, $preDiscountTotal, date('Y-m-d'));
            if ($validated['valid']) {
                $discountAmount = (float) $validated['discount'];
                $couponId = (int) $coupon['id'];
            } else {
                unset($_SESSION['applied_coupon_code']);
            }
        } else {
            unset($_SESSION['applied_coupon_code']);
        }
    }

    $totalAmount = max(0, $preDiscountTotal - $discountAmount);

    $orderNumber = 'VT' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

    $orderNotesWithCoupon = $orderNotes;
    if ($couponCode !== '' && $discountAmount > 0) {
        $couponNote = "Coupon Applied: " . normalize_coupon_code($couponCode);
        $orderNotesWithCoupon = trim($orderNotesWithCoupon . "\n" . $couponNote);
    }
    $shippingNote = "Shipping: Rs " . number_format($baseShippingAmount, 2) . " | COD Fee: Rs " . number_format($codFeeAmount, 2);
    $orderNotesWithCoupon = trim($orderNotesWithCoupon . "\n" . $shippingNote);

    $insertOrder = $conn->prepare(
        "INSERT INTO orders (
            order_number, customer_name, customer_phone, customer_email,
            address, city, state, pincode, country,
            subtotal, shipping_amount, discount_amount, total_amount,
            payment_method, payment_status, order_status, order_notes,
            customer_id, currency, shipping_cost, total, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, 'INR', ?, ?, 'pending', ?)"
    );
    $insertOrder->bind_param(
        'sssssssssddddssidds',
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
        $customerId,
        $shippingAmount,
        $totalAmount,
        $orderNotesWithCoupon
    );
    $insertOrder->execute();
    $orderId = (int) $conn->insert_id;

    $insertOrderItem = $conn->prepare(
        "INSERT INTO order_items (
            order_id, product_id, product_name, size, color, unit_type, quantity, price, total,
            fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters, price_per_meter, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($orderItems as $item) {
        $pid = (int) $item['product_id'];
        $pname = $item['product_name'];
        $psize = $item['size'];
        $pcolor = $item['color'];
        $punit = $item['unit_type'];
        $qty = (float) $item['quantity'];
        $price = (float) $item['price'];
        $total = (float) $item['total'];
        $sku = $item['sku'];

        $insertOrderItem->bind_param(
            'iissssdddissddd',
            $orderId,
            $pid,
            $pname,
            $psize,
            $pcolor,
            $punit,
            $qty,
            $price,
            $total,
            $pid,
            $pname,
            $sku,
            $qty,
            $price,
            $total
        );
        $insertOrderItem->execute();

        // Reduce stock immediately only for COD.
        // For online payments, stock is reduced after payment verification succeeds.
        if ($paymentMethod === 'cod') {
            $fabric = $productMap[$pid] ?? null;
            if (!$fabric) {
                throw new RuntimeException('Unable to lock product inventory for order placement.');
            }

            // Use only one stock source to avoid double-deduction.
            $useMeters = (($item['unit_type'] ?? 'meter') === 'meter');
            $availableStock = $useMeters ? (float) ($fabric['stock_meters'] ?? 0) : (float) $fabric['stock'];
            if ($availableStock < $qty) {
                throw new RuntimeException('Insufficient stock during order confirmation.');
            }

            if ($useMeters) {
                $updateStock = $conn->prepare(
                    "UPDATE fabrics SET stock_meters = stock_meters - ? WHERE id = ? AND stock_meters >= ?"
                );
                $updateStock->bind_param('did', $qty, $pid, $qty);
            } else {
                $updateStock = $conn->prepare(
                    "UPDATE fabrics SET stock = stock - ? WHERE id = ? AND stock >= ?"
                );
                $deductQty = round((float) $qty, 2);
                $updateStock->bind_param('did', $deductQty, $pid, $deductQty);
            }
            $updateStock->execute();
            if ($conn->affected_rows === 0) {
                throw new RuntimeException('Stock update conflict for product ' . $pid . '. Please try again.');
            }
        }
    }

    $insertPayment = $conn->prepare(
        "INSERT INTO payments (order_id, payment_method, payment_status, transaction_id, amount)
         VALUES (?, ?, 'pending', NULL, ?)"
    );
    $insertPayment->bind_param('isd', $orderId, $paymentMethod, $totalAmount);
    $insertPayment->execute();

    // For COD: increment coupon usage only after the order is committed (payment confirmed
    // on delivery is outside our system's control, but the order is real and the discount
    // is locked in — this is the earliest safe point for COD).
    // For Razorpay: coupon increment happens in razorpay-verify.php after payment confirmation.
    if ($couponId > 0 && $paymentMethod === 'cod') {
        $couponUsedStmt = $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?");
        $couponUsedStmt->bind_param('i', $couponId);
        $couponUsedStmt->execute();
    }

    $conn->commit();

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
        redirect('/payment/razorpay-create.php');
    }

    unset($_SESSION['cart'], $_SESSION['checkout_old'], $_SESSION['checkout_errors'], $_SESSION['applied_coupon_code']);
    unset($_SESSION['cart_size']);
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
