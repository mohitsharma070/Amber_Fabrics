<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$functions = $read('includes/functions.php');
$compatibilityWrapper = $read('includes/coupon-functions.php');
$compatibility = $read('includes/helpers/coupon-functions.php');
$service = $read('includes/services/CouponService.php');
$placeOrder = $read('place-order.php');

$assert(str_contains($functions, 'services/CouponService.php'), 'Shared bootstrap must load CouponService.');
$assert(str_contains($compatibilityWrapper, "require_once __DIR__ . '/helpers/coupon-functions.php'"), 'Legacy coupon include must delegate to the compatibility helper module.');
$assert(str_contains($compatibility, "dirname(__DIR__) . '/services/CouponService.php'"), 'Coupon compatibility helper must load CouponService.');
foreach ([
    'normalize_coupon_code' => 'normalizeCode',
    'normalize_coupon_guest_email' => 'normalizeGuestEmail',
    'normalize_coupon_guest_phone' => 'normalizeGuestPhone',
    'coupon_guest_identity_hash' => 'guestIdentityHash',
    'get_coupon_by_code' => 'getByCode',
    'has_customer_used_coupon' => 'hasCustomerUsed',
    'reserve_coupon_for_order' => 'reserveForOrder',
    'release_coupon_usage_for_order' => 'releaseForOrder',
    'validate_coupon_for_amount' => 'validateForAmount',
    'get_active_coupon_discount' => 'activeDiscount',
    'get_active_coupon_discount_for_customer' => 'activeDiscountForCustomer',
] as $function => $method) {
    $assert(str_contains($compatibility, 'function ' . $function . '('), 'Coupon compatibility function must remain: ' . $function);
    $assert(str_contains($compatibility, 'CouponService::' . $method), 'Coupon compatibility function must delegate to: ' . $method);
}

$assert(!str_contains($compatibility, '$conn->prepare'), 'Coupon compatibility include must not contain persistence statements.');
$assert(!str_contains($service, 'begin_transaction') && !str_contains($service, '->commit(') && !str_contains($service, '->rollback('), 'CouponService must remain transaction-neutral.');
$assert(str_contains($service, 'public static function lockedDiscountForOrder') && str_contains($service, 'FOR UPDATE'), 'Order coupon validation must retain a row lock.');
$assert(str_contains($service, 'used_count = used_count + 1') && str_contains($service, 'used_count < usage_limit'), 'Coupon global capacity must remain atomically reserved.');
$assert(str_contains($service, 'guest_identity_hash = ?') && str_contains($service, "preg_match('/^[a-f0-9]{64}$/"), 'Guest identity duplicate and format enforcement must remain in CouponService.');
$assert(str_contains($service, 'GREATEST(used_count - 1, 0)') && str_contains($service, '$delete->affected_rows'), 'Coupon release must remain bounded and idempotent.');

$assert(!str_contains($placeOrder, '$conn->prepare'), 'Order endpoint must no longer own SQL statements.');
$assert(str_contains($placeOrder, 'CouponService::lockedDiscountForOrder') && str_contains($placeOrder, 'CouponService::reserveForOrder'), 'Order endpoint must delegate locked validation and reservation.');
$transactionStart = strpos($placeOrder, '$conn->begin_transaction()');
$couponLock = strpos($placeOrder, 'CouponService::lockedDiscountForOrder');
$orderInsert = strpos($placeOrder, 'OrderPersistenceService::insertOrder');
$couponReservation = strpos($placeOrder, 'CouponService::reserveForOrder');
$outboxEnqueue = strpos($placeOrder, 'OutboxService::enqueueOrderAfterCommit');
$transactionCommit = strpos($placeOrder, '$conn->commit()');
$assert(
    $transactionStart !== false
        && $couponLock !== false
        && $orderInsert !== false
        && $couponReservation !== false
        && $outboxEnqueue !== false
        && $transactionCommit !== false
        && $transactionStart < $couponLock
        && $couponLock < $orderInsert
        && $orderInsert < $couponReservation
        && $couponReservation < $outboxEnqueue
        && $outboxEnqueue < $transactionCommit,
    'Locked coupon validation and reservation must remain inside the order transaction in their original order.'
);

require_once $root . '/includes/services/CouponService.php';
$assert(CouponService::normalizeCode(' amber10 ') === 'AMBER10', 'Coupon code normalization changed.');
$assert(CouponService::normalizeGuestEmail(' Buyer@Example.TEST ') === 'buyer@example.test', 'Guest email normalization changed.');
$assert(CouponService::normalizeGuestPhone('+91 (98765) 43210') === '9876543210', 'Guest phone normalization changed.');
$discount = CouponService::validateForAmount([
    'id' => 7,
    'code' => 'AMBER10',
    'status' => 'active',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'usage_limit' => 10,
    'used_count' => 2,
    'min_order_amount' => 0,
    'discount_type' => 'percent',
    'discount_value' => 10,
    'max_discount' => 30,
], 500.0, '2026-08-22');
$assert($discount['valid'] === true && (float) $discount['discount'] === 30.0, 'Coupon percentage and maximum-discount calculation changed.');

if ($failures !== []) {
    fwrite(STDERR, "Coupon service architecture contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Coupon service architecture contracts passed.\n";
