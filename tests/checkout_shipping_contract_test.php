<?php

require_once __DIR__ . '/../includes/coupon-functions.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$_SESSION = [];
$_POST = [
    'redirect_to' => 'checkout',
    'full_name' => 'Coupon Customer',
    'phone' => '9876543210',
    'email' => 'customer@example.test',
    'address' => '12 Test Street',
    'city' => 'Jaipur',
    'state' => 'Rajasthan',
    'pincode' => '302001',
    'country' => 'Other',
    'order_notes' => 'Preserve this note',
    'payment_method' => 'razorpay',
    'online_method' => 'upi',
    'shipping_address_id' => '42',
];
preserve_checkout_state_from_coupon_request();
$state = (array) ($_SESSION['checkout_old'] ?? []);
$assert(($state['pincode'] ?? '') === '302001', 'Coupon redirect did not preserve the checkout pincode.');
$assert(($state['payment_method'] ?? '') === 'razorpay', 'Coupon redirect did not preserve the payment method.');
$assert(($state['shipping_address_id'] ?? 0) === 42, 'Coupon redirect did not preserve the saved address ID.');
$assert(($state['country'] ?? '') === 'India', 'Coupon redirect did not enforce the India checkout country.');

$rateSource = (string) file_get_contents(__DIR__ . '/../shipping-rate.php');
$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');
$checkoutScriptPath = __DIR__ . '/../js/checkout.js';
$checkoutScript = is_file($checkoutScriptPath) ? (string) file_get_contents($checkoutScriptPath) : '';
$pluginSource = (string) file_get_contents(__DIR__ . '/../plugins/shipping-courier/modules/reference-and-rates.php');
$emailServiceSource = (string) file_get_contents(__DIR__ . '/../includes/services/EmailService.php');
$placeOrderSource = (string) file_get_contents(__DIR__ . '/../place-order.php');
$applySource = (string) file_get_contents(__DIR__ . '/../apply-coupon.php');
$removeSource = (string) file_get_contents(__DIR__ . '/../remove-coupon.php');

$assert(!str_contains($rateSource, "\$_POST['subtotal']"), 'Shipping rate still trusts a browser-supplied subtotal.');
$assert(str_contains($rateSource, 'CartService::cart_items_subtotal'), 'Shipping rate does not derive subtotal from the server cart.');
$assert(str_contains($rateSource, "'invoice_value' => \$invoiceValue"), 'Shipping rate does not send the discounted invoice value.');
$cartChangedPosition = strpos($rateSource, "'code' => 'cart_changed'");
$quoteStorePosition = strpos($rateSource, 'InventoryService::shipping_quote_store');
$assert(
    $cartChangedPosition !== false && $quoteStorePosition !== false && $cartChangedPosition < $quoteStorePosition,
    'Shipping refresh must reject a reconciled cart before issuing a quote token.'
);
$assert(str_contains($rateSource, '], 409);'), 'Shipping refresh must return an HTTP conflict when the cart changed.');
$assert(str_contains($checkoutScript, 'data = await res.json();'), 'Checkout must read structured error responses from shipping refresh.');
$assert(str_contains($checkoutScript, "data.code === 'cart_changed'") && str_contains($checkoutScript, 'window.location.reload();'), 'Checkout must reload after server-side cart reconciliation.');
$assert(!str_contains($checkoutScript, 'res.ok ? await res.json() : null'), 'Checkout must not discard non-2xx cart-change responses.');
$assert(str_contains($checkoutSource, "'invoice_value' => (float) \$taxableAmount"), 'Initial checkout quote does not use the discounted invoice value.');
$assert((bool) preg_match('/shipping_quote_store\(\s*\(float\) \$taxableAmount/', $checkoutSource), 'Initial checkout token does not bind the discounted invoice value.');
$assert((bool) preg_match('/shipping_quote_store\(\s*\(float\) \$invoiceValue/', $rateSource), 'Refreshed quote token does not bind the discounted invoice value.');
$assert(str_contains($placeOrderSource, 'abs($quoteSubtotal - $quotedInvoiceValue)'), 'Order placement does not validate the coupon-adjusted quote value.');
$assert(substr_count($checkoutSource, 'data-preserve-checkout-state') >= 2, 'Coupon forms are not both preserving checkout state.');
$assert(str_contains($pluginSource, "\$context['invoice_value'] ?? \$subtotal"), 'Bigship rate payload ignores the discounted invoice value.');
$assert(str_contains($emailServiceSource, 'LEFT JOIN customers c ON c.id = o.customer_id'), 'Guest order confirmation still requires a customer account.');
$assert(str_contains($emailServiceSource, "\$order['customer_email']"), 'Guest order confirmation does not use the email stored on the order.');
$assert(str_contains($applySource, 'preserve_checkout_state_from_coupon_request();'), 'Coupon apply does not persist checkout state.');
$assert(str_contains($removeSource, 'preserve_checkout_state_from_coupon_request();'), 'Coupon remove does not persist checkout state.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Checkout shipping coupon contracts passed.\n";
