<?php
/**
 * Checkout coupon regression contract. It is deliberately database-free so it
 * can run in release checks: php tests/checkout_coupon_state_test.php
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$checkout = $read('checkout.php');
$apply = $read('apply-coupon.php');
$remove = $read('remove-coupon.php');
$coupon = $read('includes/coupon-functions.php');
$placeOrder = $read('place-order.php');

$checks = [
    'guest checkout fields survive apply/remove fallback through a sanitized draft' =>
        str_contains($coupon, 'coupon_store_checkout_draft') && str_contains($checkout, "\$_SESSION['checkout_draft']") && str_contains($apply, 'coupon_store_checkout_draft($_POST)') && str_contains($remove, 'coupon_store_checkout_draft($_POST)'),
    'logged-in edited address and saved-address selection are submitted with coupon actions' =>
        str_contains($checkout, 'form="checkout_form"') && str_contains($coupon, "'shipping_address_id'") && str_contains($checkout, 'saved_address_select'),
    'payment method and online method survive coupon operations' =>
        str_contains($coupon, "'payment_method'") && str_contains($coupon, "'online_method'") && str_contains($placeOrder, "'online_method' => \$onlineMethod"),
    'invalid and expired and usage-limited coupons retain server validation' =>
        str_contains($coupon, 'validate_coupon_for_amount') && str_contains($coupon, 'This coupon has expired.') && str_contains($coupon, 'This coupon usage limit is reached.') && str_contains($apply, '!' . '$info' . "['valid']"),
    'repeated clicks are coalesced and controls are disabled while running' =>
        str_contains($checkout, 'couponRequestInFlight') && str_contains($checkout, 'setCouponBusy(true)') && str_contains($checkout, 'disabled = busy'),
    'network failure reports accessibly without clearing checkout fields' =>
        str_contains($checkout, 'Network error. Your coupon was not changed') && str_contains($checkout, 'aria-live="polite"'),
    'JSON responses contain only server-authoritative financial values and a quote token' =>
        str_contains($apply, "'discount_amount'") && str_contains($apply, "'final_total'") && str_contains($remove, "'shipping_quote_token'") && str_contains($coupon, 'coupon_checkout_totals'),
];
$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
