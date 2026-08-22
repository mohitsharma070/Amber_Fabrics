<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cart = (string) file_get_contents($root . '/cart.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(!str_contains($cart, '$estimatedShipping'), 'Cart must not preload a hard-coded shipping charge.');
$assert(!str_contains($cart, 'You unlocked free shipping.'), 'Cart must not promise free shipping before a destination quote.');
$assert(!str_contains($cart, '<span>Shipping</span>') && !str_contains($cart, 'Calculated at checkout'), 'Cart summary must not display shipping information.');
$assert(!str_contains($cart, 'Final shipping, COD charges, delivery estimate'), 'Cart summary must not display checkout-charge explanatory copy.');
$assert(!str_contains($cart, 'cart_delivery_form') && !str_contains($cart, "fetch('/shipping-rate.php'"), 'Cart must not duplicate the checkout shipping calculator.');

if ($failures !== []) {
    fwrite(STDERR, "Cart shipping presentation failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "cart_shipping_presentation_contract_test: OK\n";
