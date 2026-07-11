<?php

function normalize_coupon_code(string $code): string
{
    return strtoupper(trim($code));
}

function get_coupon_by_code(mysqli $conn, string $code): ?array
{
    $normalized = normalize_coupon_code($code);
    if ($normalized === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, code, discount_type, discount_value, min_order_amount, max_discount,
                start_date, end_date, usage_limit, used_count, status
         FROM coupons
         WHERE code = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $coupon = $stmt->get_result()->fetch_assoc();

    return $coupon ?: null;
}

/** Coupon requests from checkout carry a small, non-sensitive draft so a fallback
 * redirect can rebuild the form exactly as the customer left it. */
function coupon_store_checkout_draft(array $input): void
{
    $fields = [
        'full_name' => 120, 'phone' => 30, 'email' => 190, 'address' => 500,
        'city' => 120, 'state' => 120, 'pincode' => 12, 'order_notes' => 500,
    ];
    $draft = [];
    foreach ($fields as $name => $length) {
        $draft[$name] = substr(trim((string) ($input[$name] ?? '')), 0, $length);
    }
    $draft['country'] = 'India';
    $draft['payment_method'] = in_array((string) ($input['payment_method'] ?? ''), ['cod', 'razorpay'], true)
        ? (string) $input['payment_method'] : 'cod';
    $draft['online_method'] = InventoryService::sanitize_online_payment_method((string) ($input['online_method'] ?? 'upi')) ?: 'upi';
    $draft['cod_fee_apply'] = $draft['payment_method'] === 'cod' ? 1 : 0;
    $draft['shipping_address_id'] = max(0, (int) ($input['shipping_address_id'] ?? 0));
    $draft['create_account'] = !empty($input['create_account']) ? 1 : 0;
    $_SESSION['checkout_draft'] = $draft;
}

function coupon_wants_json(): bool
{
    return (string) ($_POST['ajax'] ?? '') === '1'
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
}

/** Server-authoritative checkout amounts for the coupon JSON contract. */
function coupon_checkout_totals(mysqli $conn, string $couponCode = ''): array
{
    $cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
    $sizes = (isset($_SESSION['cart_size']) && is_array($_SESSION['cart_size'])) ? $_SESSION['cart_size'] : [];
    $meters = (isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])) ? $_SESSION['cart_meter_length'] : [];
    $hydrated = CartService::cart_hydrate_items($conn, $cart, $sizes, $meters);
    if (!empty($hydrated['removed_keys'])) {
        foreach ($hydrated['removed_keys'] as $key) { unset($cart[$key], $sizes[$key], $meters[$key]); }
        $_SESSION['cart'] = $cart; $_SESSION['cart_size'] = $sizes; $_SESSION['cart_meter_length'] = $meters;
        $customerId = (int) ($_SESSION['customer_id'] ?? 0);
        if ($customerId > 0) {
            CartService::cart_save_to_db($conn, $customerId, $cart, $meters);
        }
    }
    $subtotal = round((float) CartService::cart_items_subtotal($hydrated['items']), 2);
    $draft = (isset($_SESSION['checkout_draft']) && is_array($_SESSION['checkout_draft'])) ? $_SESSION['checkout_draft'] : [];
    $payment = in_array((string) ($draft['payment_method'] ?? ''), ['cod', 'razorpay'], true) ? $draft['payment_method'] : 'cod';
    $pincode = trim((string) ($draft['pincode'] ?? ''));
    $manual = CartService::checkout_shipping_breakdown($subtotal, 'India', $payment, $payment === 'cod');
    $quote = apply_filters('shipping.quote', [
        'base_shipping' => (float) $manual['base_shipping'], 'cod_fee' => (float) $manual['cod_fee'],
        'shipping_total' => (float) $manual['shipping_total'], 'source' => 'manual', 'courier_name' => '', 'courier_id' => 0,
    ], ['conn' => $conn, 'subtotal' => $subtotal, 'country' => 'India', 'pincode' => $pincode, 'payment_method' => $payment]);
    $shipping = max(0.0, round((float) ($quote['base_shipping'] ?? $manual['base_shipping']), 2));
    $codFee = max(0.0, round((float) ($quote['cod_fee'] ?? $manual['cod_fee']), 2));
    $token = InventoryService::shipping_quote_store($subtotal, 'India', $pincode, $payment, $shipping, $codFee, $shipping + $codFee,
        substr(trim((string) ($quote['source'] ?? 'manual')), 0, 32) ?: 'manual', trim((string) ($quote['courier_name'] ?? '')), max(0, (int) ($quote['courier_id'] ?? 0)));
    $code = normalize_coupon_code($couponCode);
    $discountInfo = get_active_coupon_discount_for_customer($conn, $code, $subtotal, (int) ($_SESSION['customer_id'] ?? 0));
    $discount = $discountInfo['valid'] ? min((float) $discountInfo['discount'], $subtotal) : 0.0;
    return [
        'coupon_code' => $discountInfo['valid'] ? (string) $discountInfo['code'] : '',
        'discount_amount' => round($discount, 2), 'subtotal' => $subtotal, 'shipping' => $shipping,
        'cod_fee' => $codFee, 'final_total' => round(max(0, $subtotal - $discount) + $shipping + $codFee, 2),
        'shipping_quote_token' => $token, 'coupon_info' => $discountInfo, 'cart_empty' => empty($hydrated['items']),
        'quote_for' => ['pincode' => $pincode, 'payment_method' => $payment, 'subtotal' => $subtotal, 'country' => 'india'],
    ];
}

function has_customer_used_coupon(mysqli $conn, int $couponId, int $customerId): bool
{
    if ($couponId <= 0 || $customerId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM coupon_usages
         WHERE coupon_id = ? AND customer_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $couponId, $customerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !empty($row);
}

function mark_coupon_used_once(mysqli $conn, int $couponId, int $customerId, int $orderId): bool
{
    if ($couponId <= 0 || $customerId <= 0 || $orderId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "INSERT INTO coupon_usages (coupon_id, customer_id, order_id)
         VALUES (?, ?, ?)"
    );
    $stmt->bind_param('iii', $couponId, $customerId, $orderId);
    return $stmt->execute();
}

function release_coupon_usage_for_order(mysqli $conn, int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT coupon_id
         FROM coupon_usages
         WHERE order_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $couponId = (int) ($row['coupon_id'] ?? 0);
    if ($couponId <= 0) {
        return false;
    }

    $del = $conn->prepare("DELETE FROM coupon_usages WHERE order_id = ?");
    $del->bind_param('i', $orderId);
    $del->execute();

    $upd = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?");
    $upd->bind_param('i', $couponId);
    $upd->execute();

    return true;
}

/** Reservations count against coupons.used_count, so capacity cannot be oversold while a gateway session is pending. */
function reserve_coupon_for_order(mysqli $conn, int $couponId, int $customerId, int $orderId): bool
{
    if ($couponId <= 0 || $customerId <= 0 || $orderId <= 0) {
        throw new RuntimeException('Invalid coupon reservation.');
    }
    $existing = $conn->prepare("SELECT coupon_id, customer_id, state FROM coupon_reservations WHERE order_id = ? FOR UPDATE");
    $existing->bind_param('i', $orderId);
    $existing->execute();
    $reservation = $existing->get_result()->fetch_assoc();
    if ($reservation && in_array((string) $reservation['state'], ['reserved', 'consumed'], true)) {
        if ((int) $reservation['coupon_id'] !== $couponId || (int) $reservation['customer_id'] !== $customerId) {
            throw new RuntimeException('Order has a different coupon reservation.');
        }
        return true;
    }

    $couponStmt = $conn->prepare("SELECT id FROM coupons WHERE id = ? FOR UPDATE");
    $couponStmt->bind_param('i', $couponId);
    $couponStmt->execute();
    if (!$couponStmt->get_result()->fetch_assoc()) {
        throw new RuntimeException('Coupon no longer exists.');
    }
    if (has_customer_used_coupon($conn, $couponId, $customerId)) {
        throw new RuntimeException('You have already used this coupon.');
    }
    $customerReservation = $conn->prepare("SELECT order_id FROM coupon_reservations WHERE coupon_id = ? AND customer_id = ? AND state IN ('reserved', 'consumed') AND order_id <> ? LIMIT 1 FOR UPDATE");
    $customerReservation->bind_param('iii', $couponId, $customerId, $orderId);
    $customerReservation->execute();
    if ($customerReservation->get_result()->fetch_assoc()) {
        throw new RuntimeException('You already have an active reservation for this coupon.');
    }
    $claim = $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)");
    $claim->bind_param('i', $couponId);
    $claim->execute();
    if ($conn->affected_rows !== 1) {
        throw new RuntimeException('Coupon usage limit reached.');
    }
    if ($reservation) {
        $restore = $conn->prepare("UPDATE coupon_reservations SET coupon_id = ?, customer_id = ?, state = 'reserved', reserved_at = NOW(), consumed_at = NULL, released_at = NULL, release_reason = NULL WHERE order_id = ?");
        $restore->bind_param('iii', $couponId, $customerId, $orderId);
        $restore->execute();
    } else {
        $insert = $conn->prepare("INSERT INTO coupon_reservations (coupon_id, customer_id, order_id, state) VALUES (?, ?, ?, 'reserved')");
        $insert->bind_param('iii', $couponId, $customerId, $orderId);
        $insert->execute();
    }
    return true;
}

function consume_coupon_reservation_for_order(mysqli $conn, int $orderId): bool
{
    $stmt = $conn->prepare("SELECT coupon_id, customer_id, state FROM coupon_reservations WHERE order_id = ? FOR UPDATE");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('Coupon reservation is missing.');
    }
    if ($row['state'] === 'consumed') return true;
    if ($row['state'] !== 'reserved') throw new RuntimeException('Coupon reservation was released.');
    $usage = $conn->prepare("INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (?, ?, ?)");
    $couponId = (int) $row['coupon_id']; $customerId = (int) $row['customer_id'];
    $usage->bind_param('iii', $couponId, $customerId, $orderId);
    if (!$usage->execute()) throw new RuntimeException('Unable to record coupon usage.');
    $consume = $conn->prepare("UPDATE coupon_reservations SET state = 'consumed', consumed_at = NOW() WHERE order_id = ? AND state = 'reserved'");
    $consume->bind_param('i', $orderId); $consume->execute();
    return true;
}

function release_coupon_reservation_for_order(mysqli $conn, int $orderId, string $reason = ''): bool
{
    $stmt = $conn->prepare("SELECT coupon_id, state FROM coupon_reservations WHERE order_id = ? FOR UPDATE");
    $stmt->bind_param('i', $orderId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
    if (!$row || $row['state'] !== 'reserved') return false;
    $couponId = (int) $row['coupon_id'];
    $release = $conn->prepare("UPDATE coupon_reservations SET state = 'released', released_at = NOW(), release_reason = ? WHERE order_id = ? AND state = 'reserved'");
    $release->bind_param('si', $reason, $orderId); $release->execute();
    if ($release->affected_rows !== 1) return false;
    $decrement = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?");
    $decrement->bind_param('i', $couponId); $decrement->execute();
    return true;
}

function validate_coupon_for_amount(array $coupon, float $amount, string $today): array
{
    if (($coupon['status'] ?? '') !== 'active') {
        return ['valid' => false, 'message' => 'This coupon is not active.'];
    }

    $startDate = (string) ($coupon['start_date'] ?? '');
    $endDate = (string) ($coupon['end_date'] ?? '');

    if ($startDate !== '' && $today < $startDate) {
        return ['valid' => false, 'message' => 'This coupon is not started yet.'];
    }
    if ($endDate !== '' && $today > $endDate) {
        return ['valid' => false, 'message' => 'This coupon has expired.'];
    }

    $minOrder = (float) ($coupon['min_order_amount'] ?? 0);
    if ($amount < $minOrder) {
        return ['valid' => false, 'message' => 'Minimum order amount for this coupon is ' . money($minOrder) . '.'];
    }

    $usageLimit = (int) ($coupon['usage_limit'] ?? 0);
    $usedCount = (int) ($coupon['used_count'] ?? 0);
    if ($usageLimit > 0 && $usedCount >= $usageLimit) {
        return ['valid' => false, 'message' => 'This coupon usage limit is reached.'];
    }

    $discountType = (string) ($coupon['discount_type'] ?? 'flat');
    $discountValue = (float) ($coupon['discount_value'] ?? 0);
    if ($discountValue <= 0) {
        return ['valid' => false, 'message' => 'Invalid coupon discount value.'];
    }

    if ($discountType === 'percent') {
        $discountAmount = ($amount * $discountValue) / 100;
    } else {
        $discountAmount = $discountValue;
    }

    $maxDiscount = (float) ($coupon['max_discount'] ?? 0);
    if ($maxDiscount > 0 && $discountAmount > $maxDiscount) {
        $discountAmount = $maxDiscount;
    }

    if ($discountAmount > $amount) {
        $discountAmount = $amount;
    }

    $discountAmount = round($discountAmount, 0);
    if ($discountAmount > $amount) {
        $discountAmount = $amount;
    }

    return [
        'valid' => true,
        'message' => 'Coupon applied successfully.',
        'discount' => (float) $discountAmount,
        'code' => (string) ($coupon['code'] ?? ''),
        'coupon_id' => (int) ($coupon['id'] ?? 0),
    ];
}

function get_active_coupon_discount(mysqli $conn, ?string $couponCode, float $amount): array
{
    $code = normalize_coupon_code((string) $couponCode);
    if ($code === '' || $amount <= 0) {
        return ['valid' => false, 'discount' => 0.00, 'code' => '', 'message' => ''];
    }

    $coupon = get_coupon_by_code($conn, $code);
    if (!$coupon) {
        return ['valid' => false, 'discount' => 0.00, 'code' => '', 'message' => 'Invalid coupon code.'];
    }

    $today = date('Y-m-d');
    $validated = validate_coupon_for_amount($coupon, $amount, $today);

    if (!$validated['valid']) {
        return ['valid' => false, 'discount' => 0.00, 'code' => $code, 'message' => $validated['message']];
    }

    return [
        'valid' => true,
        'discount' => (float) $validated['discount'],
        'code' => $code,
        'coupon_id' => (int) $validated['coupon_id'],
        'message' => (string) $validated['message'],
    ];
}

function get_active_coupon_discount_for_customer(mysqli $conn, ?string $couponCode, float $amount, int $customerId): array
{
    $base = get_active_coupon_discount($conn, $couponCode, $amount);
    if (!$base['valid']) {
        return $base;
    }

    $couponId = (int) ($base['coupon_id'] ?? 0);
    if ($customerId > 0 && $couponId > 0 && has_customer_used_coupon($conn, $couponId, $customerId)) {
        return [
            'valid' => false,
            'discount' => 0.00,
            'code' => (string) ($base['code'] ?? ''),
            'coupon_id' => $couponId,
            'message' => 'You have already used this coupon.',
        ];
    }

    return $base;
}
