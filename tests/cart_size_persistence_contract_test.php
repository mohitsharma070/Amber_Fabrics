<?php
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$cartServiceSource = (string) file_get_contents(__DIR__ . '/../includes/services/CartService.php');
$mergeServiceSource = (string) file_get_contents(__DIR__ . '/../includes/services/CustomerSessionMergeService.php');

// 1. cart_load_from_db_bundle returns size_map
$assert(str_contains($cartServiceSource, "'size_map' => \$sizeMap"), 'cart_load_from_db_bundle must return size_map');

// 2. cart_save_to_db accepts sizeMap and defaults to session
$assert(
    str_contains($cartServiceSource, "cart_save_to_db(mysqli \$conn, int \$customerId, array \$cart, ?array \$meterMap = null, ?array \$sizeMap = null)") ||
    str_contains($cartServiceSource, "cart_save_to_db(mysqli \$conn, int \$customerId, array \$cart, ?array \$meterMap = null, ?array \$sizeMap = null)"),
    'cart_save_to_db must accept ?array $sizeMap = null'
);

$assert(
    str_contains($cartServiceSource, "\$sizeMap = (isset(\$_SESSION['cart_size']) && is_array(\$_SESSION['cart_size']))"),
    'cart_save_to_db must fall back to $_SESSION["cart_size"] if null'
);

$assert(
    str_contains($cartServiceSource, "\$selectedSize = trim((string) (\$sizeMap[\$rawKey] ?? ''))") &&
    !str_contains($cartServiceSource, "\$selectedSize = trim((string) (\$_SESSION['cart_size'][\$rawKey] ?? ''))"),
    'cart_save_to_db must use $sizeMap instead of $_SESSION["cart_size"] inside the loop'
);

// 3. CustomerSessionMergeService extracts size_map
$assert(
    str_contains($mergeServiceSource, "\$dbSizeMap = is_array(\$dbCartBundle['size_map'] ?? null) ? \$dbCartBundle['size_map'] : [];"),
    'CustomerSessionMergeService must extract dbSizeMap from the loaded cart bundle'
);

// 4. CustomerSessionMergeService merges size_map
$assert(
    str_contains($mergeServiceSource, "\$dbSizeMap[\$cartKey] = (string) \$sessionSizeMap[\$cartKey];"),
    'CustomerSessionMergeService must override/merge session sizes into dbSizeMap'
);

// 5. CustomerSessionMergeService saves dbSizeMap to session and passes it to cart_save_to_db
$assert(
    str_contains($mergeServiceSource, "\$_SESSION['cart_size'] = \$dbSizeMap;"),
    'CustomerSessionMergeService must update $_SESSION["cart_size"] with the merged size map'
);

$assert(
    str_contains($mergeServiceSource, "CartService::cart_save_to_db(\$conn, \$customerId, \$dbCart, \$dbMeterMap, \$dbSizeMap);"),
    'CustomerSessionMergeService must explicitly pass $dbSizeMap to cart_save_to_db'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Persistence tests passed.\n";

