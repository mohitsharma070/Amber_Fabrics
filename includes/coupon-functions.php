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

function validate_coupon_for_subtotal(array $coupon, float $subtotal, string $today): array
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
    if ($subtotal < $minOrder) {
        return ['valid' => false, 'message' => 'Minimum order amount for this coupon is Rs ' . number_format($minOrder, 2) . '.'];
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
        $discountAmount = ($subtotal * $discountValue) / 100;
    } else {
        $discountAmount = $discountValue;
    }

    $maxDiscount = (float) ($coupon['max_discount'] ?? 0);
    if ($maxDiscount > 0 && $discountAmount > $maxDiscount) {
        $discountAmount = $maxDiscount;
    }

    if ($discountAmount > $subtotal) {
        $discountAmount = $subtotal;
    }

    return [
        'valid' => true,
        'message' => 'Coupon applied successfully.',
        'discount' => round($discountAmount, 2),
        'code' => (string) ($coupon['code'] ?? ''),
        'coupon_id' => (int) ($coupon['id'] ?? 0),
    ];
}

function get_active_coupon_discount(mysqli $conn, ?string $couponCode, float $subtotal): array
{
    $code = normalize_coupon_code((string) $couponCode);
    if ($code === '' || $subtotal <= 0) {
        return ['valid' => false, 'discount' => 0.00, 'code' => '', 'message' => ''];
    }

    $coupon = get_coupon_by_code($conn, $code);
    if (!$coupon) {
        return ['valid' => false, 'discount' => 0.00, 'code' => '', 'message' => 'Invalid coupon code.'];
    }

    $today = date('Y-m-d');
    $validated = validate_coupon_for_subtotal($coupon, $subtotal, $today);

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
