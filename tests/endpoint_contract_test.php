<?php

declare(strict_types=1);

/**
 * Endpoint contract checks for agentic-ready compliance.
 *
 * This test intentionally validates existing behavior guards only
 * (method restrictions, CSRF checks, webhook signature gates),
 * without executing app logic or requiring database connectivity.
 */

$root = dirname(__DIR__);

$failures = [];
$checks = 0;

function assert_contains(string $filePath, string $needle, string $label, array &$failures, int &$checks): void
{
    $checks++;
    if (!is_file($filePath)) {
        $failures[] = "[missing] {$label}: {$filePath}";
        return;
    }

    $content = (string) file_get_contents($filePath);
    if ($content === '' || strpos($content, $needle) === false) {
        $failures[] = "[failed] {$label}: expected to find [{$needle}] in {$filePath}";
    }
}

function assert_exists(string $filePath, string $label, array &$failures, int &$checks): void
{
    $checks++;
    if (!is_file($filePath)) {
        $failures[] = "[missing] {$label}: {$filePath}";
    }
}

function assert_before(string $filePath, string $firstNeedle, string $secondNeedle, string $label, array &$failures, int &$checks): void
{
    $checks++;
    if (!is_file($filePath)) {
        $failures[] = "[missing] {$label}: {$filePath}";
        return;
    }

    $content = (string) file_get_contents($filePath);
    $firstPosition = strpos($content, $firstNeedle);
    $secondPosition = strpos($content, $secondNeedle);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        $failures[] = "[failed] {$label}: expected [{$firstNeedle}] before [{$secondNeedle}] in {$filePath}";
    }
}

// Core mutation endpoints should enforce POST.
$mustBePost = [
    'add-to-cart.php',
    'apply-coupon.php',
    'move-to-cart.php',
    'move-to-wishlist.php',
    'remove-cart.php',
    'remove-coupon.php',
    'remove-wishlist.php',
    'shipping-rate.php',
    'update-cart.php',
    'place-order.php',
    'payment/razorpay-verify.php',
    'payment/razorpay-failure.php',
    'payment/razorpay-webhook.php',
    'shipping-courier-webhook.php',
];

foreach ($mustBePost as $relativePath) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    assert_contains($file, "REQUEST_METHOD", "method guard present ({$relativePath})", $failures, $checks);
}

// CSRF checks for browser mutation endpoints.
$mustVerifyCsrf = [
    'add-to-cart.php',
    'apply-coupon.php',
    'shipping-rate.php',
    'place-order.php',
    'payment/razorpay-verify.php',
    'payment/razorpay-failure.php',
    'admin/logout.php',
    'customer/logout.php',
];

foreach ($mustVerifyCsrf as $relativePath) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    assert_contains($file, 'verify_csrf', "csrf check present ({$relativePath})", $failures, $checks);
}

// Guest Razorpay checkout must stop before any account/order transaction can
// create a pending order that the authenticated payment callbacks cannot own.
$placeOrderFile = $root . DIRECTORY_SEPARATOR . 'place-order.php';
assert_contains(
    $placeOrderFile,
    "if (\$paymentMethod === 'razorpay' && \$customerId <= 0)",
    'guest Razorpay authentication guard present',
    $failures,
    $checks
);

// Bigship Direct credentials and bearer authentication must remain server-side.
$shippingCourierPlugin = $root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'shipping-courier' . DIRECTORY_SEPARATOR . 'plugin.php';
assert_contains($shippingCourierPlugin, "'/api/outbound/login'", 'Bigship Direct login endpoint retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'Authorization: Bearer '", 'Bigship Direct bearer authentication retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'bigship_password'", 'Bigship Direct password remains server-side configuration', $failures, $checks);
assert_contains($shippingCourierPlugin, "'/api/outbound/user-rate-calculator'", 'Bigship Direct rate calculator retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'courier_partner_id'", 'Bigship courier partner selection retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'totalCharge'", 'Bigship total charge is retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'/api/outbound/create-order'", 'Bigship create-order lifecycle step retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'/api/outbound/courier-wise-shipment-cost'", 'Bigship courier-cost lifecycle step retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'/api/outbound/place-order'", 'Bigship place-order lifecycle step retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'CustomGlobalOrderId'", 'Bigship CustomGlobalOrderId is retained', $failures, $checks);
assert_contains($shippingCourierPlugin, "'/api/outbound/track-order?CustomGlobalOrderId='", 'Bigship tracking endpoint retained', $failures, $checks);
assert_contains($shippingCourierPlugin, 'shipping_courier_apply_bigship_order_status', 'Bigship tracking updates local order status', $failures, $checks);
assert_contains($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'plugins.php', "'BIGSHIP_BASE_URL'", 'Bigship base URL is server configuration', $failures, $checks);
assert_contains($root . DIRECTORY_SEPARATOR . 'secure-config.example.php', "'BIGSHIP_ACCESS_KEY' => ''", 'Bigship example keeps access key empty', $failures, $checks);
assert_contains(
    $placeOrderFile,
    "redirect('/customer/login.php?return=%2Fcheckout.php')",
    'guest Razorpay returns through login to checkout',
    $failures,
    $checks
);
assert_before(
    $placeOrderFile,
    "if (\$paymentMethod === 'razorpay' && \$customerId <= 0)",
    'if ($wantsCreateAccount && $customerId <= 0)',
    'guest Razorpay guard runs before guest account creation',
    $failures,
    $checks
);
assert_before(
    $placeOrderFile,
    "if (\$paymentMethod === 'razorpay' && \$customerId <= 0)",
    '$conn->begin_transaction();',
    'guest Razorpay guard runs before order transaction',
    $failures,
    $checks
);

$razorpayVerifyFile = $root . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'razorpay-verify.php';
$razorpayFailureFile = $root . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'razorpay-failure.php';
assert_contains($razorpayVerifyFile, "hash_hmac('sha256'", 'Razorpay success signature verification retained', $failures, $checks);
assert_contains($razorpayVerifyFile, 'customer_id = ?', 'Razorpay success ownership check retained', $failures, $checks);
assert_contains($razorpayFailureFile, 'customer_id = ?', 'Razorpay failure ownership check retained', $failures, $checks);
assert_contains($placeOrderFile, 'InventoryService::reserve_order_inventory', 'Razorpay inventory reservation retained', $failures, $checks);
assert_contains($razorpayVerifyFile, 'PaymentService::consume_coupon_after_razorpay_capture', 'post-capture coupon behavior retained', $failures, $checks);

// Webhook signature/token protections.
assert_contains(
    $root . DIRECTORY_SEPARATOR . 'payment' . DIRECTORY_SEPARATOR . 'razorpay-webhook.php',
    'HTTP_X_RAZORPAY_SIGNATURE',
    'razorpay webhook signature header validation',
    $failures,
    $checks
);
assert_contains(
    $root . DIRECTORY_SEPARATOR . 'cod-guard-webhook.php',
    'cod_guard_validate_webhook_request',
    'cod guard webhook signature validation',
    $failures,
    $checks
);
assert_contains(
    $root . DIRECTORY_SEPARATOR . 'shipping-courier-webhook.php',
    'shipping_courier_validate_webhook_request',
    'shipping courier webhook signature validation',
    $failures,
    $checks
);

// Agentic docs/spec artifacts should exist.
$artifacts = [
    'AGENTS.md',
    'CLAUDE.md',
    'docs/repo-architecture.md',
    'docs/agentic-ready.md',
    'openapi.yaml',
];

foreach ($artifacts as $relativePath) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    assert_exists($file, "artifact exists ({$relativePath})", $failures, $checks);
}

if ($failures !== []) {
    fwrite(STDERR, "Endpoint contract test failed.\n");
    fwrite(STDERR, 'Checks: ' . $checks . "\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo "Endpoint contract test passed.\n";
echo 'Checks: ' . $checks . "\n";
exit(0);
