<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$placeOrderSource = (string) file_get_contents(__DIR__ . '/../place-order.php');
$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');

// A. Generic pre-commit order exception creates a named checkout error.
// E. Exception details are not exposed.
$assert(
    str_contains($placeOrderSource, "\$_SESSION['checkout_errors'] = ['_checkout' => 'Unable to place order right now. Please try again.'];") &&
    !str_contains($placeOrderSource, "getMessage()']"),
    'place-order.php must set _checkout generic error without exposing raw exception details'
);

// B. checkout.php renders it.
$assert(
    str_contains($checkoutSource, "<?php if (!empty(\$errors['_checkout'])): ?>") &&
    str_contains($checkoutSource, "role=\"alert\">") &&
    str_contains($checkoutSource, "echo e(\$errors['_checkout']);"),
    'checkout.php must render _checkout error with role=alert'
);

// C. Field-specific errors still render.
// Ensure form_error is used for various fields.
$assert(
    str_contains($checkoutSource, "form_error(\$errors, 'full_name')") &&
    str_contains($checkoutSource, "form_error(\$errors, 'phone')") &&
    str_contains($checkoutSource, "form_error(\$errors, 'email')") &&
    str_contains($checkoutSource, "form_error(\$errors, 'pincode')") &&
    str_contains($checkoutSource, "form_error(\$errors, 'cod_whatsapp_consent')"),
    'checkout.php must preserve field-specific error rendering'
);

// D. `_cart` still renders.
$assert(
    str_contains($checkoutSource, "<?php if (!empty(\$errors['_cart'])): ?>") &&
    str_contains($checkoutSource, "echo e(\$errors['_cart']);"),
    'checkout.php must preserve _cart error rendering'
);

// F. Successful order flow is unchanged.
$assert(
    str_contains($placeOrderSource, "\$conn->commit();") &&
    str_contains($placeOrderSource, "redirect('/order-success.php?order=' . urlencode(\$orderNumber));") &&
    str_contains($placeOrderSource, "\$businessCommitted = true;"),
    'place-order.php successful flow must remain intact'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Checkout error presentation contract tests passed.\n";

