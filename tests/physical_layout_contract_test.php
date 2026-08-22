<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $relative) use ($root, $assert): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = is_file($path) ? file_get_contents($path) : false;
    $assert($contents !== false, $relative . ' must be present.');
    return $contents === false ? '' : $contents;
};

$wrappers = [
    'includes/SiteContext.php' => "presentation/SiteContext.php",
    'includes/customer-auth.php' => "security/customer-auth.php",
    'includes/coupon-functions.php' => "helpers/coupon-functions.php",
    'includes/header.php' => "views/layouts/header.php",
    'includes/footer.php' => "views/layouts/footer.php",
];
foreach ($wrappers as $wrapper => $target) {
    $source = $read($wrapper);
    $assert(str_contains($source, $target), $wrapper . ' must delegate to ' . $target . '.');
    $assert(!preg_match('/\b(?:class|function)\s+[A-Za-z_]/', $source), $wrapper . ' must remain a thin compatibility include.');
    $assert(substr_count(trim($source), "\n") <= 3, $wrapper . ' must remain a small compatibility include.');
}

$implementations = [
    'includes/presentation/SiteContext.php' => 'final class SiteContext',
    'includes/security/customer-auth.php' => 'function customer_session_valid',
    'includes/helpers/coupon-functions.php' => 'function reserve_coupon_for_order',
    'includes/views/layouts/header.php' => '<main id="main-content"',
    'includes/views/layouts/footer.php' => 'interaction-layer-v2.php',
];
foreach ($implementations as $implementation => $marker) {
    $assert(str_contains($read($implementation), $marker), $implementation . ' must own its implementation.');
}

$functions = $read('includes/functions.php');
$init = $read('includes/init.php');
$assert(str_contains($functions, "presentation/SiteContext.php"), 'The internal bootstrap must load SiteContext from presentation directly.');
$assert(str_contains($init, "security/customer-auth.php"), 'The request bootstrap must load customer authentication from security directly.');

foreach ([
    'index.php',
    'catalog.php',
    'cart.php',
    'checkout.php',
    'place-order.php',
    'order-success.php',
] as $entrypoint) {
    $source = $read($entrypoint);
    $assert(str_contains($source, 'includes/init.php'), $entrypoint . ' must remain a document-root HTTP entry point.');
}

if ($failures !== []) {
    fwrite(STDERR, "Physical layout contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "physical_layout_contract_test: OK\n";
