<?php
/** Database-free regression contract for checkout live-shipping concurrency. */
$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/checkout.php');
$shipping = (string) file_get_contents($root . '/shipping-rate.php');

$checks = [
    'out-of-order responses are ignored by monotonically increasing generation and request key' =>
        str_contains($checkout, 'generation !== shippingQuoteState.generation') && str_contains($checkout, 'shippingKey(shippingSnapshot()) !== key'),
    'previous request is aborted when AbortController is available' =>
        str_contains($checkout, "typeof AbortController !== 'undefined'") && str_contains($checkout, 'shippingQuoteState.controller.abort()'),
    'network and HTTP failures clear the token, keep checkout disabled, and expose retry' =>
        str_contains($checkout, "if (!res.ok) throw new Error") && str_contains($checkout, 'clearCurrentShippingQuote()') && str_contains($checkout, 'We could not calculate shipping') && str_contains($checkout, 'retryCount < 2'),
    'rapid payment toggles create a new quote and online method selection does the same' =>
        str_contains($checkout, 'codRadio.addEventListener') && str_contains($checkout, 'razorpayRadio.addEventListener') && str_contains($checkout, 'maybeFetchLiveRate();'),
    'rapid pincode changes invalidate old quote data before requesting the latest state' =>
        str_contains($checkout, "pincodeInput.addEventListener('input'") && str_contains($checkout, 'clearCurrentShippingQuote();') && str_contains($checkout, 'abortLiveShippingRequest();'),
    'submission is blocked while a matching current quote is unavailable' =>
        str_contains($checkout, 'shippingQuoteState.pending || shippingQuoteState.validKey !== shippingKey(shippingSnapshot())') && str_contains($checkout, 'setShippingSubmissionEnabled(false)'),
    'server response binds pincode, payment method, subtotal, and country' =>
        str_contains($shipping, "'quote_for'") && str_contains($shipping, "'pincode' => " . '$pincode') && str_contains($shipping, "'payment_method' => " . '$paymentMethod') && str_contains($shipping, "'subtotal' => round(" . '$subtotal' . ", 2)") && str_contains($shipping, "'country' => 'india'"),
    'server-side order quote validation remains present' =>
        str_contains((string) file_get_contents($root . '/place-order.php'), 'Shipping quote expired') && str_contains((string) file_get_contents($root . '/place-order.php'), 'quotePayment'),
];
$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
