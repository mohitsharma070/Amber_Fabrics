<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$shippingRateSource = (string) file_get_contents(__DIR__ . '/../shipping-rate.php');
$checkoutJsSource = (string) file_get_contents(__DIR__ . '/../js/checkout.js');
$hooksSource = (string) file_get_contents(__DIR__ . '/../includes/hooks.php');

// 1. shipping-rate.php must NOT accept fallback_only from the client
$assert(
    !str_contains($shippingRateSource, 'fallback_only'),
    'shipping-rate.php must not read or use fallback_only from the client request'
);

// 2. shipping-rate.php always attempts apply_filters unconditionally
$assert(
    str_contains($shippingRateSource, "apply_filters('shipping.quote'"),
    'shipping-rate.php must call apply_filters for the shipping.quote hook'
);
$assert(
    !str_contains($shippingRateSource, 'if (!$fallbackOnly)'),
    'shipping-rate.php must not conditionally bypass apply_filters based on client input'
);

// 3. shipping-rate.php catches exceptions from the live quote provider
$assert(
    str_contains($shippingRateSource, "} catch (Throwable \$e) {") &&
    str_contains($shippingRateSource, "\$quote['debug_reason'] = 'bigship_rate_api_failed';") &&
    str_contains($shippingRateSource, "    ], true);") &&
    str_contains($shippingRateSource, "app_log('error', 'shipping_quote_failed'") &&
    !str_contains($shippingRateSource, '$e->getMessage()') &&
    str_contains($hooksSource, 'if ($throwOnFailure) {'),
    'shipping-rate.php must catch Throwable around apply_filters and set debug_reason on failure'
);

// 4. Server-side pincode validation before quote generation
$pincodeValidationPos = strpos($shippingRateSource, "preg_match('/^[1-9][0-9]{5}$/'");
$quoteStorePos = strpos($shippingRateSource, 'InventoryService::shipping_quote_store');
$applyFiltersPos = strpos($shippingRateSource, "apply_filters('shipping.quote'");
$assert(
    $pincodeValidationPos !== false &&
    $quoteStorePos !== false &&
    $applyFiltersPos !== false &&
    $pincodeValidationPos < $applyFiltersPos &&
    $pincodeValidationPos < $quoteStorePos,
    'shipping-rate.php must validate pincode before calling the courier filter or generating a quote token'
);
$assert(
    str_contains($shippingRateSource, "api_json(['ok' => false, 'message' => 'Please enter a valid 6-digit Indian pincode.'], 422)"),
    'shipping-rate.php must return HTTP 422 for invalid pincodes'
);

// 5. checkout.js must NOT send fallback_only
$assert(
    !str_contains($checkoutJsSource, 'fallback_only'),
    'checkout.js must not send fallback_only to the server'
);

// 6. checkout.js must not use isFallback recursive retry
$assert(
    !str_contains($checkoutJsSource, 'isFallback'),
    'checkout.js must not use an isFallback parameter or recursive retry'
);

// 7. checkout.js client-side pincode guard still works
$assert(
    str_contains($checkoutJsSource, "if (country !== 'india' || !/^[1-9][0-9]{5}$/.test(pincode)) {") &&
    str_contains($checkoutJsSource, "return false;"),
    'checkout.js must still reject invalid pincodes client-side as a UX guard'
);

// 8. Timeout handling preserves retryability without auto-unlock
$assert(
    str_contains($checkoutJsSource, "if (timedOut && requestId === shippingRateRequestId)") &&
    str_contains($checkoutJsSource, "shippingQuoteTokenInput.value = ''") &&
    str_contains($checkoutJsSource, 'setCheckoutUnlocked(false)') &&
    str_contains($checkoutJsSource, 'Shipping calculation timed out. Please try again.'),
    'checkout.js must clear token and keep checkout locked on timeout without creating an insecure unlock path'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Shipping rate fallback contract tests passed.\n";
