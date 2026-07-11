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
