<?php
$source = (string) file_get_contents(__DIR__ . '/../add-to-cart.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assert(
    str_contains($source, "if (\$postedVariantId > 0) {") &&
    str_contains($source, "\$candidate = InventoryService::get_variant_by_id(\$conn, \$postedVariantId);") &&
    str_contains($source, "if (!\$candidate || (int) (\$candidate['fabric_id'] ?? 0) !== \$productId || (int) (\$candidate['is_active'] ?? 0) !== 1) {") &&
    str_contains($source, "'Selected variant is unavailable or invalid.'"),
    'Variant lookup must explicitly validate posted variant ID and reject if invalid.'
);

$assert(
    str_contains($source, "if (\$selectedColor !== '') {") &&
    str_contains($source, "\$varColor = trim((string) (\$candidate['color'] ?? ''));") &&
    str_contains($source, "'Selected variant does not match the chosen colour.'"),
    'Variant lookup must reject posted variant if it does not match selected colour.'
);

$assert(
    str_contains($source, "} elseif (\$selectedColor !== '' || \$selectedSize !== '') {") &&
    str_contains($source, "\$variant = InventoryService::find_variant(\$conn, \$productId, \$selectedColor, \$selectedSize);") &&
    str_contains($source, "'Selected colour/size combination is unavailable.'"),
    'Variant lookup must reject explicitly selected color/size if no matching variant is found, instead of falling back.'
);

$assert(
    str_contains($source, "} else {") &&
    str_contains($source, "\$variant = InventoryService::get_first_active_in_stock_variant(\$conn, \$productId, \$unitType);"),
    'Variant lookup should fall back to first active in-stock variant only if no explicit variant, color, or size is submitted.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "add-to-cart variant contract tests passed.\n";

