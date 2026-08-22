<?php
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('database/migrations/2026-08-20-customer-backend-hardening.sql');
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$coupons = $read('includes/helpers/coupon-functions.php');
$couponService = $read('includes/services/CouponService.php');
$placeOrder = $read('place-order.php');
$failure = $read('payment/razorpay-failure.php');
$verify = $read('payment/razorpay-verify.php');
$webhook = $read('payment/razorpay-webhook.php');
$create = $read('payment/razorpay-create.php');
$paymentService = $read('includes/services/PaymentService.php');
$retry = $read('retry-payment.php');
$guestRetry = $read('guest/retry-payment.php');
$auth = $read('includes/security/customer-auth.php');
$init = $read('includes/init.php');
$login = $read('customer/login.php');
$reset = $read('customer/reset-password.php');
$profile = $read('customer/profile.php');
$register = $read('customer/register.php');
$cancelOrder = $read('customer/cancel-order.php');
$adminHelpers = $read('includes/helpers/admin.php');
$cron = $read('cron/run-plugins.php');
$cartService = $read('includes/services/CartService.php');
$addressService = $read('includes/services/CustomerAddressService.php');
$accountService = $read('includes/services/CustomerAccountService.php');

foreach ([$migration, $schema, $setup] as $source) {
    $assert(str_contains($source, 'auth_version'), 'Migration, schema, and setup must include customer auth_version.');
    $assert(str_contains($source, 'razorpay_create_claim_token'), 'Migration, schema, and setup must include the Razorpay create claim.');
}
$assert((bool) preg_match('/customer_id\s+INT\s+(?:DEFAULT\s+)?NULL/i', $migration), 'Migration must allow guest coupon reservations.');
$assert(str_contains($migration, 'UNIQUE uq_coupon_usages_order_id') || str_contains($migration, 'UNIQUE KEY uq_coupon_usages_order_id'), 'Migration must enforce one coupon reservation per order.');
$assert(str_contains($migration, 'DELETE duplicate_usage') && str_contains($migration, 'INSERT IGNORE INTO coupon_usages') && str_contains($migration, 'reservation_count'), 'Migration must deduplicate, backfill, and reconcile the coupon ledger.');
$assert((bool) preg_match('/customer_id\s+INT\s+DEFAULT\s+NULL/i', $schema), 'Fresh schema must allow guest coupon reservations.');
$assert(str_contains($schema, 'UNIQUE KEY uq_coupon_usages_order_id (order_id)'), 'Fresh schema must enforce one coupon reservation per order.');
$setupCouponStart = strpos($setup, 'CREATE TABLE IF NOT EXISTS coupon_usages');
$setupCoupon = $setupCouponStart === false ? '' : substr($setup, $setupCouponStart, 900);
$assert(str_contains($setupCoupon, 'customer_id INT DEFAULT NULL'), 'Fresh setup must allow guest coupon reservations.');

$assert(str_contains($coupons, 'function reserve_coupon_for_order') && str_contains($coupons, 'CouponService::reserveForOrder'), 'Legacy coupon reservation callers must retain a compatibility wrapper.');
$assert(str_contains($couponService, 'public static function reserveForOrder') && str_contains($couponService, 'FOR UPDATE'), 'Coupon reservation must lock capacity transactionally.');
$assert(str_contains($coupons, 'function release_coupon_usage_for_order') && str_contains($couponService, 'affected_rows'), 'Coupon release must remain idempotent behind its compatibility wrapper.');
$assert(str_contains($placeOrder, 'CouponService::reserveForOrder($conn, $couponId, $customerId, $orderId, $guestIdentityHash)'), 'Order creation must reserve coupon capacity for customer and guest orders.');
$assert(str_contains($retry, 'reserve_coupon_for_order') && str_contains($guestRetry, 'reserve_coupon_for_order'), 'Both retry paths must reacquire released coupon capacity.');
$assert(!str_contains($verify, 'consume_coupon_after_razorpay_capture'), 'Browser capture verification must not perform coupon bookkeeping.');
$assert(!str_contains($webhook, 'consume_coupon_after_razorpay_capture'), 'Webhook capture must not perform coupon bookkeeping.');

$assert(!str_contains($failure, 'restore_order_inventory'), 'Browser failure must not restore inventory.');
$assert(!str_contains($failure, 'release_coupon_usage_for_order'), 'Browser failure must not release coupon capacity.');
$assert(!str_contains($failure, 'razorpay_mark_order_failed'), 'Browser failure must not finalize payment failure.');
$assert(str_contains($failure, 'payment_browser_failed') && str_contains($failure, 'payment_browser_cancelled'), 'Browser failure must be recorded as informational activity.');
$assert(str_contains($webhook, 'InventoryService::restore_order_inventory') && str_contains($webhook, 'release_coupon_usage_for_order'), 'Signed failure webhook must restore inventory and release coupon capacity.');

$staleStart = strpos($paymentService, 'public static function cancel_stale_pending_razorpay_order');
$staleEnd = strpos($paymentService, 'public static function release_stale_pending_razorpay_orders', $staleStart === false ? 0 : $staleStart);
$stale = $staleStart === false ? '' : substr($paymentService, $staleStart, $staleEnd === false ? null : $staleEnd - $staleStart);
$assert(str_contains($stale, 'begin_transaction') && str_contains($stale, 'FOR UPDATE') && str_contains($stale, 'commit()'), 'Stale Razorpay cancellation must be transactional and lock the order.');
$assert(str_contains($stale, "payment_status = 'failed'"), 'Stale Razorpay cancellation must finalize local payment failure.');
$assert(str_contains($stale, 'restore_order_inventory') && str_contains($stale, 'release_coupon_usage_for_order') && str_contains($stale, 'log_order_activity'), 'Stale cancellation must restore inventory, release coupon capacity, and log activity.');
$assert(str_contains($create, 'razorpay_create_claim_token') && str_contains($create, 'FOR UPDATE') && str_contains($create, 'razorpay_order_id'), 'Razorpay initialization must claim and reuse its stored provider order.');

$assert(str_contains($init, 'customer_session_valid($conn, $bootCustomerId)'), 'Storefront bootstrap must validate existing customer sessions.');
$assert(str_contains($auth, 'SELECT is_active, auth_version FROM customers') && str_contains($auth, '$sessionAuthVersion !== $databaseAuthVersion'), 'Session validation must check account activity and auth version.');
$assert(str_contains($auth, 'customer_clear_auth_session') && !str_contains($auth, 'session_destroy()'), 'Invalid auth must clear only auth state and preserve the session cart.');
$assert(str_contains($login, "\$_SESSION['customer_auth_version']"), 'Login must store auth_version in the session.');
$assert(str_contains($reset, 'auth_version = auth_version + 1') && str_contains($reset, 'reset_token = ?') && str_contains($reset, 'affected_rows !== 1'), 'Password reset must atomically consume the token and revoke sessions.');
$assert(str_contains($accountService, 'auth_version = auth_version + 1') && str_contains($accountService, '$update->affected_rows !== 1') && str_contains($profile, "\$_SESSION['customer_auth_version']") && str_contains($profile, 'session_regenerate_id(true)'), 'Password change must preserve only the current browser session.');

$customerLimitStart = strpos($auth, 'function customer_check_rate_limit');
$customerLimitEnd = strpos($auth, 'function customer_record_attempt', $customerLimitStart === false ? 0 : $customerLimitStart);
$customerLimit = $customerLimitStart === false ? '' : substr($auth, $customerLimitStart, $customerLimitEnd === false ? null : $customerLimitEnd - $customerLimitStart);
$assert(str_contains($customerLimit, 'begin_transaction') && str_contains($customerLimit, 'FOR UPDATE'), 'Customer login limiting must serialize concurrent counters.');
$publicLimitStart = strpos($adminHelpers, 'function public_form_rate_limit_allow');
$publicLimitEnd = strpos($adminHelpers, 'function admin_notification_email', $publicLimitStart === false ? 0 : $publicLimitStart);
$publicLimit = $publicLimitStart === false ? '' : substr($adminHelpers, $publicLimitStart, $publicLimitEnd === false ? null : $publicLimitEnd - $publicLimitStart);
$assert(str_contains($publicLimit, 'begin_transaction') && str_contains($publicLimit, 'FOR UPDATE'), 'Public form limiting must serialize concurrent counters.');
$assert(!str_contains(strtoupper($publicLimit), 'CREATE TABLE') && !str_contains($publicLimit, 'DELETE FROM public_form_attempts'), 'Normal public requests must not perform rate-limit DDL or global cleanup.');
$assert(str_contains($cron, 'DELETE FROM public_form_attempts') && str_contains($cron, 'LIMIT 5000'), 'Stale public rate-limit cleanup must be bounded and cron-owned.');

$cartStart = strpos($cartService, 'public static function cart_save_to_db');
$cartEnd = strpos($cartService, 'public static function cart_load_from_db', $cartStart === false ? 0 : $cartStart);
$cartSave = $cartStart === false ? '' : substr($cartService, $cartStart, $cartEnd === false ? null : $cartEnd - $cartStart);
$assert(str_contains($cartSave, 'begin_transaction') && str_contains($cartSave, 'DELETE FROM cart_items') && str_contains($cartSave, 'rollback()'), 'Saved-cart replacement must roll back delete-and-reinsert failures.');

$defaultStart = strpos($addressService, 'public static function setDefault');
$defaultFlow = $defaultStart === false ? '' : substr($addressService, $defaultStart, 2500);
$ownershipPos = strpos($defaultFlow, 'LIMIT 1 FOR UPDATE');
$clearPos = strpos($defaultFlow, 'SET is_default_shipping = 0');
$assert($ownershipPos !== false && $clearPos !== false && $ownershipPos < $clearPos, 'Default-address ownership must be locked before clearing the old default.');
$assert(str_contains($defaultFlow, '$set->affected_rows !== 1'), 'Default-address update results must be checked.');
$assert(str_contains($profile, 'CustomerAddressService::save') && str_contains($profile, 'CustomerAddressService::delete') && str_contains($profile, 'CustomerAddressService::setDefault'), 'Profile address mutations must delegate to the focused service.');
$assert(str_contains($register, 'mb_strlen($name)') && str_contains($register, '$e->getCode() === 1062'), 'Registration must enforce DB lengths and handle duplicate-email races.');
$assert(str_contains($accountService, 'mb_strlen($name)') && str_contains($profile, 'Unable to update your profile right now.') && str_contains($profile, 'CustomerAccountService::updateProfile'), 'Profile updates must validate lengths, delegate persistence, and return generic database errors.');
$assert(str_contains($cancelOrder, '$e instanceof mysqli_sql_exception') && str_contains($cancelOrder, "flash('error', 'Unable to cancel order right now.')"), 'Customer cancellation must not expose database exceptions.');

if ($failures) {
    foreach ($failures as $failureMessage) {
        fwrite(STDERR, "FAIL: {$failureMessage}\n");
    }
    exit(1);
}

echo "customer_backend_hardening_contract_test: OK\n";
