<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/core.php';
require_once $root . '/includes/services/CartService.php';

if (!method_exists(CartService::class, 'cartLineAllowsMeterLength')) {
    fwrite(STDERR, "FAIL: CartService::cartLineAllowsMeterLength policy is missing.\n");
    exit(1);
}

$failures = [];
$assertSame = static function ($actual, $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message;
    }
};

$existingCart = ['10::0' => 2.0];
$existingMeterMap = ['10::0' => 2.0];

$assertSame(
    CartService::cartLineAllowsMeterLength($existingCart, $existingMeterMap, '10::0', 'meter', 2.0),
    true,
    'The same product/variant and meter length must remain mergeable.'
);
$assertSame(
    CartService::cartLineAllowsMeterLength($existingCart, $existingMeterMap, '10::0', 'meter', 1.5),
    false,
    'The same product/variant with a different meter length must be rejected.'
);
$assertSame($existingCart, ['10::0' => 2.0], 'A rejected comparison must not mutate the existing quantity.');
$assertSame($existingMeterMap, ['10::0' => 2.0], 'A rejected comparison must not mutate existing meter metadata.');

$assertSame(
    CartService::cartLineAllowsMeterLength($existingCart, $existingMeterMap, '10::7', 'meter', 1.5),
    true,
    'A different variant key must not be rejected by another variant line.'
);
$assertSame(
    CartService::cartLineAllowsMeterLength($existingCart, [], '10::0', 'piece', null),
    true,
    'Piece products must not be affected by meter metadata.'
);
$assertSame(
    CartService::cartLineAllowsMeterLength($existingCart, [], '10::0', 'set', 'corrupt'),
    true,
    'Set products must not be affected by meter metadata.'
);

foreach ([
    'missing metadata' => [],
    'nonnumeric metadata' => ['10::0' => 'corrupt'],
    'nonpositive metadata' => ['10::0' => 0],
] as $label => $meterMap) {
    $assertSame(
        CartService::cartLineAllowsMeterLength($existingCart, $meterMap, '10::0', 'meter', 2.0),
        false,
        'An existing meter line with ' . $label . ' must fail closed.'
    );
}

$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$consumerSources = [
    'add-to-cart.php' => ['label' => 'Add-to-cart', 'write' => "\$_SESSION['cart'][\$cartKey] ="],
    'move-to-cart.php' => ['label' => 'Wishlist-to-cart', 'write' => "\$_SESSION['cart'][\$cartKey] ="],
    'move-to-wishlist.php' => ['label' => 'Cart-to-wishlist', 'write' => "\$_SESSION['wishlist'][\$cartKey] ="],
    'includes/services/CustomerSessionMergeService.php' => ['label' => 'Login cart merge', 'write' => '$currentQty ='],
];
foreach ($consumerSources as $path => $contract) {
    $source = $read($path);
    $policyPosition = strpos($source, 'CartService::cartLineAllowsMeterLength');
    $writePosition = strpos($source, $contract['write']);
    $assertSame(
        $policyPosition !== false && $writePosition !== false && $policyPosition < $writePosition,
        true,
        $contract['label'] . ' must apply the tested meter-length identity policy before mutation.'
    );
}

foreach (['move-to-cart.php', 'move-to-wishlist.php'] as $transitionPath) {
    $transitionSource = $read($transitionPath);
    $assertSame(
        str_contains($transitionSource, '$combinedQuantity')
            && str_contains($transitionSource, 'CartService::cart_hydrate_items')
            && str_contains($transitionSource, '$quantityAdjusted'),
        true,
        $transitionPath . ' must add colliding quantities and reconcile the result before removing the source line.'
    );
}

$mergeSource = $read('includes/services/CustomerSessionMergeService.php');
$mergeWishlistStart = strpos($mergeSource, 'private static function mergeWishlist');
$mergeWishlistEnd = strpos($mergeSource, 'private static function productUnitMap', $mergeWishlistStart === false ? 0 : $mergeWishlistStart);
$mergeWishlistSource = $mergeWishlistStart === false
    ? ''
    : substr($mergeSource, $mergeWishlistStart, $mergeWishlistEnd === false ? null : $mergeWishlistEnd - $mergeWishlistStart);
$assertSame(
    str_contains($mergeWishlistSource, 'CartService::cartLineAllowsMeterLength')
        && str_contains($mergeWishlistSource, '$existingQty + $incomingQty')
        && str_contains($mergeWishlistSource, 'CartService::cart_hydrate_items'),
    true,
    'Login wishlist collisions must add compatible quantities and reconcile them without overwriting incompatible meter identities.'
);
$assertSame(
    str_contains($mergeSource, 'if ($dbCart !== [])'),
    false,
    'Login merge must persist fail-closed removal even when no valid cart lines remain.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    exit(1);
}

echo "cart_meter_length_identity_test: OK\n";
