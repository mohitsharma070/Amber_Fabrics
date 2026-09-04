<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');
$checkoutJsSource = (string) file_get_contents(__DIR__ . '/../js/checkout.js');

// 1. checkout.php POST handler
$assert(
    str_contains($checkoutSource, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['action'] ?? '') === 'refresh_quote') {") &&
    str_contains($checkoutSource, "\$_SESSION['checkout_old'] = \$_POST;") &&
    str_contains($checkoutSource, "redirect('/checkout.php');"),
    'checkout.php must intercept refresh_quote POST action and PRG the state'
);

// 2. Button is type=submit with formaction
$assert(
    str_contains($checkoutSource, '<button type="submit" name="action" value="refresh_quote" formaction="/checkout.php"') &&
    str_contains($checkoutSource, 'id="checkout_continue_payment">Continue to Payment</button>'),
    'Continue to Payment button must be a submit button targeting checkout.php'
);

// 3. JS prevents default
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

