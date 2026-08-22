<?php

require_once dirname(__DIR__) . '/services/CouponService.php';

function normalize_coupon_code(string $code): string
{
    return CouponService::normalizeCode($code);
}

function normalize_coupon_guest_email(string $email): string
{
    return CouponService::normalizeGuestEmail($email);
}

function normalize_coupon_guest_phone(string $phone): string
{
    return CouponService::normalizeGuestPhone($phone);
}

function coupon_guest_identity_hash(string $email, string $phone): string
{
    return CouponService::guestIdentityHash($email, $phone);
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
    $state['cod_whatsapp_consent'] = (int) ($_POST['cod_whatsapp_consent'] ?? 0) === 1 ? 1 : 0;
    $_SESSION['checkout_old'] = $state;
}

function get_coupon_by_code(mysqli $conn, string $code): ?array
{
    return CouponService::getByCode($conn, $code);
}

function has_customer_used_coupon(mysqli $conn, int $couponId, int $customerId): bool
{
    return CouponService::hasCustomerUsed($conn, $couponId, $customerId);
}

/** Reserve one global coupon slot for an order inside the caller transaction. */
function reserve_coupon_for_order(
    mysqli $conn,
    int $couponId,
    int $customerId,
    int $orderId,
    ?string $guestIdentityHash = null
): bool
{
    return CouponService::reserveForOrder($conn, $couponId, $customerId, $orderId, $guestIdentityHash);
}

function release_coupon_usage_for_order(mysqli $conn, int $orderId): bool
{
    return CouponService::releaseForOrder($conn, $orderId);
}

function validate_coupon_for_amount(array $coupon, float $amount, string $today): array
{
    return CouponService::validateForAmount($coupon, $amount, $today);
}

function get_active_coupon_discount(mysqli $conn, ?string $couponCode, float $amount): array
{
    return CouponService::activeDiscount($conn, $couponCode, $amount);
}

function get_active_coupon_discount_for_customer(mysqli $conn, ?string $couponCode, float $amount, int $customerId): array
{
    return CouponService::activeDiscountForCustomer($conn, $couponCode, $amount, $customerId);
}
