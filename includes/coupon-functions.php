<?php

function normalize_coupon_code(string $code): string
{
    return strtoupper(trim($code));
}

function coupon_redirect_target(string $fallback = '/cart.php'): string
{
    $target = (string) ($_POST['redirect_to'] ?? '');
    $addressId = (int) ($_POST['shipping_address_id'] ?? 0);
    if ($target === 'checkout') {
        return $addressId > 0 ? ('/checkout.php?address_id=' . $addressId) : '/checkout.php';
    }
    if ($target === 'cart') {
        return '/cart.php';
    }
    return $fallback;
}

/** Preserve the in-progress checkout form when applying/removing a coupon. */
function preserve_checkout_state_from_coupon_request(): void
{
    if ((string) ($_POST['redirect_to'] ?? '') !== 'checkout') {
        return;
    }

    $state = is_array($_SESSION['checkout_old'] ?? null) ? $_SESSION['checkout_old'] : [];
    foreach (['full_name', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'order_notes'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $state[$key] = substr(trim((string) $_POST[$key]), 0, $key === 'order_notes' ? 2000 : 255);
        }
    }
    $state['country'] = 'India';
    $paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
    $state['payment_method'] = in_array($paymentMethod, ['cod', 'razorpay'], true) ? $paymentMethod : 'cod';
    $onlineMethod = strtolower(trim((string) ($_POST['online_method'] ?? '')));
    $state['online_method'] = in_array($onlineMethod, ['upi', 'card', 'netbanking', 'wallet', 'emi'], true)
        ? $onlineMethod
        : '';
    $state['shipping_address_id'] = max(0, (int) ($_POST['shipping_address_id'] ?? 0));
    $_SESSION['checkout_old'] = $state;
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

/** Reserve one global coupon slot for an order inside the caller transaction. */
function reserve_coupon_for_order(mysqli $conn, int $couponId, int $customerId, int $orderId): bool
{
    if ($couponId <= 0 || $orderId <= 0) {
        return false;
    }

    $existingStmt = $conn->prepare(
        "SELECT coupon_id FROM coupon_usages WHERE order_id = ? LIMIT 1 FOR UPDATE"
    );
    $existingStmt->bind_param('i', $orderId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    if ($existing) {
        if ((int) ($existing['coupon_id'] ?? 0) !== $couponId) {
            throw new RuntimeException('Order coupon reservation does not match the order.');
        }
        return true;
    }

    $couponStmt = $conn->prepare(
        "SELECT id, usage_limit, used_count FROM coupons WHERE id = ? LIMIT 1 FOR UPDATE"
    );
    $couponStmt->bind_param('i', $couponId);
    $couponStmt->execute();
    if (!$couponStmt->get_result()->fetch_assoc()) {
        throw new RuntimeException('Coupon is no longer available.');
    }
    if ($customerId > 0 && has_customer_used_coupon($conn, $couponId, $customerId)) {
        throw new RuntimeException('You have already used this coupon.');
    }

    $reserve = $conn->prepare(
        "UPDATE coupons
         SET used_count = used_count + 1
         WHERE id = ? AND (usage_limit = 0 OR used_count < usage_limit)"
    );
    $reserve->bind_param('i', $couponId);
    $reserve->execute();
    if ($reserve->affected_rows <= 0) {
        throw new RuntimeException('Coupon usage limit reached.');
    }

    $reservationCustomerId = $customerId > 0 ? $customerId : null;
    $insert = $conn->prepare(
        "INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (?, ?, ?)"
    );
    $insert->bind_param('iii', $couponId, $reservationCustomerId, $orderId);
    $insert->execute();
    return true;
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
         LIMIT 1
         FOR UPDATE"
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
    if ($del->affected_rows <= 0) {
        return false;
    }

    $upd = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?");
    $upd->bind_param('i', $couponId);
    $upd->execute();

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
