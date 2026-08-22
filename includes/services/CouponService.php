<?php

final class CouponService
{
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function normalizeGuestEmail(string $email): string
    {
        $email = trim($email);
        return function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
    }

    public static function normalizeGuestPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    public static function guestIdentityHash(string $email, string $phone): string
    {
        $key = function_exists('_cfg') ? trim((string) _cfg('APP_IDENTITY_HASH_KEY', '')) : '';
        if ($key === '') {
            $key = trim((string) (getenv('APP_IDENTITY_HASH_KEY') ?: ''));
        }
        if ($key === '' && strtolower((string) ($GLOBALS['_app_mode'] ?? 'local')) !== 'production') {
            // Stable only for local fixtures. Production boot validation requires an
            // environment-managed key and never reaches this fallback.
            $key = 'local-development-identity-key-not-for-production';
        }
        if (strlen($key) < 32) {
            throw new RuntimeException('Guest coupon identity configuration is unavailable.');
        }

        $normalizedEmail = self::normalizeGuestEmail($email);
        $normalizedPhone = self::normalizeGuestPhone($phone);
        if ($normalizedEmail === '' || $normalizedPhone === '') {
            throw new InvalidArgumentException('Guest email and phone are required for coupon reservation.');
        }
        return hash_hmac('sha256', 'v1|' . $normalizedEmail . '|' . $normalizedPhone, $key);
    }

    public static function getByCode(mysqli $conn, string $code): ?array
    {
        $normalized = self::normalizeCode($code);
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

    public static function lockedDiscountForOrder(
        mysqli $conn,
        string $couponCode,
        float $subtotal,
        int $customerId,
        string $today
    ): array {
        $normalized = self::normalizeCode($couponCode);
        if ($normalized === '') {
            return self::invalidDiscount();
        }

        $stmt = $conn->prepare(
            "SELECT id, code, discount_type, discount_value, min_order_amount, max_discount,
                    start_date, end_date, usage_limit, used_count, status
             FROM coupons
             WHERE code = ?
             FOR UPDATE"
        );
        $stmt->bind_param('s', $normalized);
        $stmt->execute();
        $coupon = $stmt->get_result()->fetch_assoc();
        if (!$coupon) {
            return self::invalidDiscount('Invalid coupon code.', $normalized);
        }

        $validated = self::validateForAmount($coupon, $subtotal, $today);
        if (!$validated['valid']) {
            return self::invalidDiscount((string) ($validated['message'] ?? ''), $normalized);
        }
        if (self::hasCustomerUsed($conn, (int) $coupon['id'], $customerId)) {
            throw new RuntimeException('You have already used this coupon.');
        }

        return [
            'valid' => true,
            'discount' => (float) $validated['discount'],
            'coupon_id' => (int) $coupon['id'],
            'code' => $normalized,
            'message' => (string) ($validated['message'] ?? ''),
        ];
    }

    public static function hasCustomerUsed(mysqli $conn, int $couponId, int $customerId): bool
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
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    /** Reserve one global coupon slot inside the transaction owned by the caller. */
    public static function reserveForOrder(
        mysqli $conn,
        int $couponId,
        int $customerId,
        int $orderId,
        ?string $guestIdentityHash = null
    ): bool {
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
        if ($customerId > 0 && self::hasCustomerUsed($conn, $couponId, $customerId)) {
            throw new RuntimeException('You have already used this coupon.');
        }
        if ($customerId <= 0) {
            $guestIdentityHash = strtolower(trim((string) $guestIdentityHash));
            if (!preg_match('/^[a-f0-9]{64}$/', $guestIdentityHash)) {
                throw new RuntimeException('Guest coupon identity is unavailable.');
            }
            $guestStmt = $conn->prepare(
                "SELECT 1 FROM coupon_usages
                 WHERE coupon_id = ? AND guest_identity_hash = ?
                 LIMIT 1"
            );
            $guestStmt->bind_param('is', $couponId, $guestIdentityHash);
            $guestStmt->execute();
            if ($guestStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('You have already used this coupon.');
            }
        } else {
            $guestIdentityHash = null;
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
            "INSERT INTO coupon_usages (coupon_id, customer_id, guest_identity_hash, order_id) VALUES (?, ?, ?, ?)"
        );
        $insert->bind_param('iisi', $couponId, $reservationCustomerId, $guestIdentityHash, $orderId);
        $insert->execute();
        return true;
    }

    public static function releaseForOrder(mysqli $conn, int $orderId): bool
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

        $delete = $conn->prepare("DELETE FROM coupon_usages WHERE order_id = ?");
        $delete->bind_param('i', $orderId);
        $delete->execute();
        if ($delete->affected_rows <= 0) {
            return false;
        }

        $update = $conn->prepare("UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?");
        $update->bind_param('i', $couponId);
        $update->execute();

        return true;
    }

    public static function validateForAmount(array $coupon, float $amount, string $today): array
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

        $discountAmount = $discountType === 'percent'
            ? ($amount * $discountValue) / 100
            : $discountValue;
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

    public static function activeDiscount(mysqli $conn, ?string $couponCode, float $amount): array
    {
        $code = self::normalizeCode((string) $couponCode);
        if ($code === '' || $amount <= 0) {
            return self::invalidDiscount();
        }

        $coupon = self::getByCode($conn, $code);
        if (!$coupon) {
            return self::invalidDiscount('Invalid coupon code.');
        }

        $validated = self::validateForAmount($coupon, $amount, date('Y-m-d'));
        if (!$validated['valid']) {
            return self::invalidDiscount((string) $validated['message'], $code);
        }

        return [
            'valid' => true,
            'discount' => (float) $validated['discount'],
            'code' => $code,
            'coupon_id' => (int) $validated['coupon_id'],
            'message' => (string) $validated['message'],
        ];
    }

    public static function activeDiscountForCustomer(
        mysqli $conn,
        ?string $couponCode,
        float $amount,
        int $customerId
    ): array {
        $base = self::activeDiscount($conn, $couponCode, $amount);
        if (!$base['valid']) {
            return $base;
        }

        $couponId = (int) ($base['coupon_id'] ?? 0);
        if ($customerId > 0 && $couponId > 0 && self::hasCustomerUsed($conn, $couponId, $customerId)) {
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

    private static function invalidDiscount(string $message = '', string $code = ''): array
    {
        return [
            'valid' => false,
            'discount' => 0.00,
            'code' => $code,
            'message' => $message,
        ];
    }
}
