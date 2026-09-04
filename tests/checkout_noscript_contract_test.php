<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');
$checkoutJsSource = (string) file_get_contents(__DIR__ . '/../js/checkout.js');
$checkoutInputSource = (string) file_get_contents(__DIR__ . '/../includes/domain/CheckoutInput.php');

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
    str_contains($checkoutSource, "\$_SESSION['checkout_old'] = CheckoutInput::sessionState(\$normalized);"),
    'checkout.php must normalize submitted checkout state using the CheckoutInput contract'
);

// 3. CheckoutInput persists online_method
$assert(
    str_contains($checkoutInputSource, "'online_method'") &&
    str_contains($checkoutInputSource, "'payment_method'"),
    'CheckoutInput::sessionState() must persist both payment_method and online_method'
);

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

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Checkout noscript contract tests passed.\n";

