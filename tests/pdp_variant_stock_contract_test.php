<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$productDetail = (string) file_get_contents($root . '/js/product-detail.js');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$selectedStockStart = strpos($productDetail, 'function selectedVariantStock(v)');
$selectedStockEnd = strpos($productDetail, 'function updateVariantQuantity(v)', $selectedStockStart === false ? 0 : $selectedStockStart);
$selectedStockHelper = ($selectedStockStart !== false && $selectedStockEnd !== false)
    ? substr($productDetail, $selectedStockStart, $selectedStockEnd - $selectedStockStart)
    : '';
$badgeStart = strpos($productDetail, 'if (stockBadgeEl && VARIANTS.length > 0)');
$badgeEnd = strpos($productDetail, 'var canAdd = VARIANTS.length > 0 ? updateVariantQuantity(v) : true;', $badgeStart === false ? 0 : $badgeStart);
$badgeBlock = ($badgeStart !== false && $badgeEnd !== false)
    ? substr($productDetail, $badgeStart, $badgeEnd - $badgeStart)
    : '';
$quantityStart = strpos($productDetail, 'function updateVariantQuantity(v)');
$quantityEnd = strpos($productDetail, 'function updateVariantState(color, size)', $quantityStart === false ? 0 : $quantityStart);
$quantityBlock = ($quantityStart !== false && $quantityEnd !== false)
    ? substr($productDetail, $quantityStart, $quantityEnd - $quantityStart)
    : '';

$assert(
    str_contains($selectedStockHelper, 'parseFloat(isMeterUnit ? v.stock_meters : v.stock)')
        && str_contains($selectedStockHelper, 'Number.isFinite(stock) ? Math.max(0, stock) : 0'),
    'Selected PDP variant stock must select stock_meters only for meter products and safely normalize invalid or negative values.'
);
$assert(
    str_contains($quantityBlock, 'var stock = selectedVariantStock(v);')
        && str_contains($badgeBlock, 'var stockNumber = selectedVariantStock(v);'),
    'PDP purchase controls and the selected-variant badge must use the same canonical stock calculation.'
);
$assert(
    !str_contains($badgeBlock, 'parseFloat(v.stock_meters) > 0 ? parseFloat(v.stock_meters) : parseFloat(v.stock)'),
    'The selected-variant badge must not infer availability from whichever stock field is positive.'
);

$normalize = static function (string $unitType, mixed $stock, mixed $stockMeters): float {
    $candidate = $unitType === 'meter' ? $stockMeters : $stock;
    return is_numeric($candidate) ? max(0.0, (float) $candidate) : 0.0;
};
$cases = [
    ['piece uses piece stock despite meter stock', 'piece', 10, 5, 10.0, true],
    ['set uses set stock despite meter stock', 'set', 10, 5, 10.0, true],
    ['meter uses meter stock despite piece stock', 'meter', 10, 5, 5.0, true],
    ['meter zero stock stays unavailable despite piece stock', 'meter', 10, 0, 0.0, false],
    ['negative stock normalizes to zero', 'piece', -2, 5, 0.0, false],
    ['invalid meter stock normalizes to zero', 'meter', 10, 'not-a-number', 0.0, false],
];
foreach ($cases as [$label, $unitType, $stock, $stockMeters, $expectedStock, $expectedAvailable]) {
    $actualStock = $normalize($unitType, $stock, $stockMeters);
    $assert(
        abs($actualStock - $expectedStock) < 0.0001 && ($actualStock > 0) === $expectedAvailable,
        $label . ' must have the expected normalized stock and availability.'
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "pdp_variant_stock_contract_test: OK\n";
