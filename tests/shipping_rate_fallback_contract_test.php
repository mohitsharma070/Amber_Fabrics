<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$shippingRateSource = (string) file_get_contents(__DIR__ . '/../shipping-rate.php');
$checkoutJsSource = (string) file_get_contents(__DIR__ . '/../js/checkout.js');

// 1. shipping-rate.php understands fallback_only
$assert(
    str_contains($shippingRateSource, "\$fallbackOnly = (int) (\$_POST['fallback_only'] ?? 0) === 1;") &&
    str_contains($shippingRateSource, "if (!\$fallbackOnly) {") &&
    str_contains($shippingRateSource, "try {") &&
    str_contains($shippingRateSource, "apply_filters('shipping.quote'"),
    'shipping-rate.php must support fallback_only flag and bypass apply_filters'
);

$assert(
    str_contains($shippingRateSource, "} catch (Throwable \$e) {") &&
    str_contains($shippingRateSource, "\$quote['debug_reason'] = 'bigship_rate_api_failed';"),
    'shipping-rate.php must catch exceptions inside apply_filters'
);

// 2. checkout.js retries with fallback_only=1 on failure
$assert(
    str_contains($checkoutJsSource, "if (isFallback) body.set('fallback_only', '1');") &&
    str_contains($checkoutJsSource, "return await maybeFetchLiveRate(true);"),
    'checkout.js must retry with fallback_only=1 when initial fetch fails or times out'
);

// 3. invalid pincode is still blocked
$assert(
    str_contains($checkoutJsSource, "if (country !== 'india' || !/^[1-9][0-9]{5}$/.test(pincode)) {") &&
    str_contains($checkoutJsSource, "return false;"),
    'checkout.js must still reject invalid pincodes immediately'
);

// 4. shipping-rate timeout uses fallback
$assert(
    str_contains($checkoutJsSource, "if (timedOut && requestId === shippingRateRequestId && !isFallback)") &&
    str_contains($checkoutJsSource, "return await maybeFetchLiveRate(true);"),
    'checkout.js must fallback on timeout'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Shipping rate fallback contract tests passed.\n";

