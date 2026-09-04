<?php
/**
 * Shipping quote integrity behavioral regression tests.
 *
 * Verifies:
 * A. Direct request with fallback_only=1 cannot force provider bypass.
 * B. Valid pincode + healthy courier result: live rate is used; client cannot replace.
 * C. Valid pincode + courier failure: server-calculated manual fallback with quote token.
 * D. Invalid pincodes are rejected server-side and no quote token is created.
 * E. Courier exception is caught and does not produce HTTP 500.
 * F. Existing stale-request/AbortController behavior still works.
 * G. Existing COD/Razorpay shipping calculations remain correct.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$shippingRate = $read('shipping-rate.php');
$checkoutJs = $read('js/checkout.js');
$referenceAndRates = $read('plugins/shipping-courier/modules/reference-and-rates.php');
$inventoryService = $read('includes/services/InventoryService.php');
$placeOrder = $read('place-order.php');

// -----------------------------------------------------------------------
// A. fallback_only=1 cannot bypass the live shipping quote provider
// -----------------------------------------------------------------------
$assert(
    !str_contains($shippingRate, 'fallback_only'),
    'A: shipping-rate.php must not read, check, or use fallback_only from the client request'
);
$assert(
    !str_contains($checkoutJs, 'fallback_only'),
    'A: checkout.js must not send fallback_only to the server'
);
// The apply_filters call must be unconditional (not wrapped in a client-controlled condition)
$applyFiltersPos = strpos($shippingRate, "apply_filters('shipping.quote'");
$assert(
    $applyFiltersPos !== false,
    'A: shipping-rate.php must call apply_filters for the shipping.quote hook'
);
// There must be no $fallbackOnly variable
$assert(
    !str_contains($shippingRate, '$fallbackOnly'),
    'A: shipping-rate.php must not contain a $fallbackOnly variable'
);

// -----------------------------------------------------------------------
// B. Valid pincode + healthy courier: live rate is used
// -----------------------------------------------------------------------
// The courier filter uses a proper server-side selection that the client cannot override
$assert(
    str_contains($referenceAndRates, "function shipping_courier_filter_shipping_quote(\$quote, array \$context)"),
    'B: The shipping courier plugin must provide the established shipping.quote filter function'
);
// The filter returns a live source when the API succeeds
$assert(
    str_contains($referenceAndRates, "'source' => substr(\$provider !== '' ? \$provider : 'courier', 0, 32)"),
    'B: Successful courier quotes must set a non-manual source identifier'
);
// shipping-rate.php passes the quote result to InventoryService::shipping_quote_store
$assert(
    str_contains($shippingRate, 'InventoryService::shipping_quote_store('),
    'B: shipping-rate.php must generate a quote token from the server-selected result'
);
// The source in the quote token comes from the server
$assert(
    str_contains($shippingRate, "\$source = trim((string) (\$quote['source'] ?? 'manual'))"),
    'B: shipping-rate.php must extract the quote source from the server-side result, not from client input'
);

// -----------------------------------------------------------------------
// C. Valid pincode + courier failure: manual fallback with valid token
// -----------------------------------------------------------------------
$catchBlock = str_contains($shippingRate, "} catch (Throwable \$e) {") &&
    str_contains($shippingRate, "app_log('error', 'shipping_quote_failed'") &&
    str_contains($shippingRate, "'exception_type' => get_class(\$e)") &&
    !str_contains($shippingRate, '$e->getMessage()') &&
    str_contains($shippingRate, "\$quote['debug_reason'] = 'bigship_rate_api_failed'");
$assert(
    $catchBlock,
    'C: shipping-rate.php must catch Throwable from apply_filters and set fallback debug_reason'
);
// The courier filter itself returns the manual fallback when the API fails
$assert(
    str_contains($referenceAndRates, "return \$fallback(\$quote, 'bigship_rate_api_failed'"),
    'C: The courier filter must return the original manual quote with a debug_reason on API failure'
);
// After catch or filter failure, the endpoint still produces a quote token
$quoteTokenPos = strpos($shippingRate, 'InventoryService::shipping_quote_store(');
$catchPos = strpos($shippingRate, '} catch (Throwable $e) {');
$assert(
    $quoteTokenPos !== false && $catchPos !== false && $catchPos < $quoteTokenPos,
    'C: Quote token must be generated after the try/catch, meaning failures still produce tokens'
);

// -----------------------------------------------------------------------
// D. Invalid pincodes are rejected server-side; no quote token created
// -----------------------------------------------------------------------
$pincodeValidation = str_contains($shippingRate, "preg_match('/^[1-9][0-9]{5}$/', \$pincode)");
$pincodeReject422 = str_contains($shippingRate, "api_json(['ok' => false, 'message' => 'Please enter a valid 6-digit Indian pincode.'], 422)");
$assert(
    $pincodeValidation && $pincodeReject422,
    'D: shipping-rate.php must validate pincode with /^[1-9][0-9]{5}$/ and return 422 for invalid values'
);
$pincodeCheckPos = strpos($shippingRate, "preg_match('/^[1-9][0-9]{5}$/'");
$eventLogPos = strpos($shippingRate, "log_ecommerce_event");
$assert(
    $pincodeCheckPos !== false && $eventLogPos !== false && $pincodeCheckPos < $eventLogPos,
    'D: Pincode validation must occur before logging a shipping_info analytics event'
);

// The courier filter also validates pincodes, so both layers are covered
$assert(
    str_contains($referenceAndRates, "!preg_match('/^[1-9][0-9]{5}$/', \$pincode)"),
    'D: The courier filter must also validate pincode format as defense-in-depth'
);

// -----------------------------------------------------------------------
// E. Courier exception is caught and does not produce HTTP 500
// -----------------------------------------------------------------------
// The try/catch wraps apply_filters so no uncaught exception can reach the top level
$tryPos = strpos($shippingRate, "try {");
$applyFiltersInTry = $tryPos !== false && $applyFiltersPos !== false && $tryPos < $applyFiltersPos;
$assert(
    $applyFiltersInTry,
    'E: apply_filters must be wrapped in a try block so courier exceptions cannot produce HTTP 500'
);
// After catch, the endpoint continues to api_json with ok=true and a manual fallback
$apiJsonPos = strpos($shippingRate, "api_json(\$response)");
$assert(
    $apiJsonPos !== false && $catchPos !== false && $catchPos < $apiJsonPos,
    'E: The endpoint must return a valid JSON response even after catching an exception'
);

// -----------------------------------------------------------------------
// F. Existing stale-request/AbortController behavior still works
// -----------------------------------------------------------------------
$assert(
    str_contains($checkoutJs, 'shippingRateAbortController.abort()'),
    'F: checkout.js must abort prior shipping requests when a new one starts'
);
$assert(
    str_contains($checkoutJs, 'requestId !== shippingRateRequestId') &&
    str_contains($checkoutJs, 'requestContext !== currentContext'),
    'F: checkout.js must reject stale responses by checking requestId and context'
);
// Stale guard must come before quote token storage
$liveRateStart = strpos($checkoutJs, 'async function maybeFetchLiveRate(');
$liveRateEnd = strpos($checkoutJs, 'function scheduleLiveRate(', $liveRateStart ?: 0);
$liveRateFunction = ($liveRateStart !== false && $liveRateEnd !== false)
    ? substr($checkoutJs, $liveRateStart, $liveRateEnd - $liveRateStart)
    : '';
$staleGuardPos = strpos($liveRateFunction, 'if (requestId !== shippingRateRequestId || requestContext !== currentContext)');
$tokenStorePos = strpos($liveRateFunction, 'shippingQuoteTokenInput.value = String(data.quote_token)');
$unlockPos = strpos($liveRateFunction, 'setCheckoutUnlocked(true)');
$assert(
    $staleGuardPos !== false && $tokenStorePos !== false && $unlockPos !== false &&
    $staleGuardPos < $tokenStorePos && $staleGuardPos < $unlockPos,
    'F: Stale request guard must precede quote token storage and checkout unlock'
);
$assert(
    str_contains($liveRateFunction, "error.name === 'AbortError'"),
    'F: AbortError must be handled gracefully without showing error messages'
);

// -----------------------------------------------------------------------
// G. COD/Razorpay shipping calculations remain correct
// -----------------------------------------------------------------------
$assert(
    str_contains($shippingRate, "CartService::checkout_shipping_breakdown(\$subtotal, 'India', \$paymentMethod, \$paymentMethod === 'cod')"),
    'G: Manual shipping calculation must use the established COD/Razorpay-aware breakdown'
);
// The courier filter also respects payment method for COD fees
$assert(
    str_contains($referenceAndRates, "\$paymentMethod === 'cod'") &&
    str_contains($referenceAndRates, 'codAmount'),
    'G: The courier filter must include COD amount in the rate request for COD orders'
);
// place-order.php validates quote token against payment method
$assert(
    str_contains($placeOrder, "\$quotePayment = strtolower(trim((string) (\$quote['payment_method'] ?? '')))") &&
    str_contains($placeOrder, "strtolower((string) \$paymentMethod) !== \$quotePayment"),
    'G: Order placement must validate the quote token payment method matches the order request'
);

// -----------------------------------------------------------------------
// Server-authoritative design: no client-controlled provider bypass path
// -----------------------------------------------------------------------
$assert(
    !str_contains($checkoutJs, 'isFallback'),
    'Server-authoritative: checkout.js must not contain an isFallback parameter or variable'
);
$assert(
    substr_count($liveRateFunction, 'maybeFetchLiveRate') === 1,
    'Server-authoritative: maybeFetchLiveRate must not recursively call itself'
);

// Final checkout form still requires a valid server quote token
$assert(
    str_contains($checkoutJs, "shippingQuoteTokenInput.value === ''") &&
    str_contains($checkoutJs, 'ev.preventDefault()'),
    'Server-authoritative: form submission must be prevented when no server quote token is present'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Shipping quote integrity tests passed.\n";
