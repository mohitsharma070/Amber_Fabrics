<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');
$checkoutJsSource = (string) file_get_contents(__DIR__ . '/../js/checkout.js');
$checkoutInputSource = (string) file_get_contents(__DIR__ . '/../includes/domain/CheckoutInput.php');
$hooksSource = (string) file_get_contents(__DIR__ . '/../includes/hooks.php');
$footerSource = (string) file_get_contents(__DIR__ . '/../includes/views/layouts/footer.php');
$consentEndpointSource = (string) file_get_contents(__DIR__ . '/../cookie-consent.php');
require_once __DIR__ . '/../includes/hooks.php';

// 1. checkout.php POST handler with CSRF
$assert(
    str_contains($checkoutSource, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['action'] ?? '') === 'refresh_quote') {") &&
    str_contains($checkoutSource, "if (!verify_csrf()) {") &&
    str_contains($checkoutSource, "redirect('/checkout.php');"),
    'checkout.php must intercept refresh_quote POST action and verify CSRF before PRG'
);

// 2. Normalized state instead of raw $_POST
$assert(
    !str_contains($checkoutSource, "\$_SESSION['checkout_old'] = \$_POST;"),
    'checkout.php must not store raw $_POST into checkout_old'
);
$assert(
    str_contains($checkoutSource, "\$normalized = CheckoutInput::fromRequest(\$_POST") &&
    str_contains($checkoutSource, "\$_SESSION['checkout_old'] = CheckoutInput::sessionState(\$normalized);") &&
    !str_contains($checkoutSource, "unset(\$_SESSION['checkout_old']);"),
    'checkout.php must normalize and retain valid submitted state until order completion'
);

// 3. CheckoutInput persists online_method
$assert(
    str_contains($checkoutInputSource, "'online_method'") &&
    str_contains($checkoutInputSource, "'payment_method'"),
    'CheckoutInput::sessionState() must persist both payment_method and online_method'
);

$quoteHookPosition = strpos($checkoutSource, "apply_filters('shipping.quote'");
$quoteTryPosition = $quoteHookPosition === false
    ? false
    : strrpos(substr($checkoutSource, 0, $quoteHookPosition), 'try {');
$quoteCatchPosition = $quoteHookPosition === false
    ? false
    : strpos($checkoutSource, 'catch (Throwable $e)', $quoteHookPosition);
$assert(
    $quoteHookPosition !== false && $quoteTryPosition !== false && $quoteCatchPosition !== false,
    'checkout.php must contain courier hook failures and retain the manual no-JavaScript quote fallback'
);
$assert(
    str_contains($checkoutSource, "        ], true);") &&
    str_contains($hooksSource, 'bool $throwOnFailure = false') &&
    str_contains($hooksSource, 'if ($throwOnFailure) {'),
    'checkout.php must request strict filter failure propagation so its fallback boundary is effective'
);
add_filter('test.filter.failure', static function (): never {
    throw new RuntimeException('provider-only detail');
});
$assert(
    apply_filters('test.filter.failure', 'manual') === 'manual',
    'Default filter isolation must retain the fallback even when observability helpers are not loaded'
);
$strictFailurePropagated = false;
try {
    apply_filters('test.filter.failure', 'manual', [], true);
} catch (RuntimeException $e) {
    $strictFailurePropagated = true;
}
$assert($strictFailurePropagated, 'Strict filter calls must propagate failures to their endpoint fallback boundary');

// 4. Button is type=submit with formaction
$assert(
    str_contains($checkoutSource, '<button type="submit" name="action" value="refresh_quote" formaction="/checkout.php"') &&
    str_contains($checkoutSource, 'id="checkout_continue_payment">Continue to Payment</button>'),
    'Continue to Payment button must be a submit button targeting checkout.php'
);

// 5. JS prevents default
$assert(
    str_contains($checkoutJsSource, "continuePaymentBtn.addEventListener('click', async function (ev) {") &&
    str_contains($checkoutJsSource, "ev.preventDefault();"),
    'checkout.js must prevent default form submission on the Continue button'
);

// 6. Unknown consent must remain explicit but non-blocking without JavaScript.
$assert(
    str_contains($footerSource, '<noscript>')
        && str_contains($footerSource, '#cookieConsentBanner { position: static !important; }')
        && str_contains($footerSource, 'data-consent-noscript-actions')
        && str_contains($footerSource, 'method="post" action="/cookie-consent.php"')
        && str_contains($footerSource, 'name="return_to"'),
    'The no-JavaScript consent banner must be non-blocking and expose CSRF-protected server choices'
);
$assert(
    str_contains($consentEndpointSource, "marketing_consent_set(\$status)")
        && str_contains($consentEndpointSource, 'cookie_consent_safe_return_path(')
        && str_contains($consentEndpointSource, "header('Location: ' . \$returnTo, true, 303);")
        && str_contains($consentEndpointSource, 'if ($isAjax) {'),
    'The consent endpoint must preserve AJAX JSON while supporting a safe no-JavaScript PRG response'
);

// 7. The no-JavaScript online method control must not remain inside the CSS-hidden Razorpay panel.
$razorpayPanelStart = strpos($checkoutSource, 'id="razorpay-panel"');
$razorpayPanelEnd = $razorpayPanelStart === false ? false : strpos($checkoutSource, 'data-noscript-online-method', $razorpayPanelStart);
$assert(
    $razorpayPanelStart !== false
        && $razorpayPanelEnd !== false
        && str_contains(substr($checkoutSource, $razorpayPanelStart, $razorpayPanelEnd - $razorpayPanelStart), '</div>'),
    'The no-JavaScript online payment select must render outside the hidden Razorpay detail panel'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Checkout noscript contract tests passed.\n";
