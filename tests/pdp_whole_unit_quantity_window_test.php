<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fabric = (string) file_get_contents($root . '/fabric.php');
$productDetail = (string) file_get_contents($root . '/js/product-detail.js');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$wholeUnitWindow = static function (int $minimum, int $stock, int $step): array {
    $start = max(1, $minimum);
    $increment = max(1, $step);
    $available = max(0, $stock);
    if ($available < $start) {
        return [];
    }
    $limit = min($available, $start + (19 * $increment));
    $quantities = [];
    for ($quantity = $start; $quantity <= $limit; $quantity += $increment) {
        $quantities[] = $quantity;
    }
    return $quantities;
};

$cases = [
    ['MOQ 50 / stock 100 preserves the configured five-piece step', 50, 100, 5, [50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100]],
    ['MOQ 25 / stock 25 exposes the one valid purchase quantity', 25, 25, 1, [25]],
    ['MOQ 1 / stock 10 retains the ordinary full stock range', 1, 10, 1, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
    ['MOQ above stock exposes no invalid quantity', 25, 24, 1, []],
];
foreach ($cases as [$label, $minimum, $stock, $step, $expected]) {
    $assert($wholeUnitWindow($minimum, $stock, $step) === $expected, $label . '.');
}

$assert(
    str_contains($fabric, '$availableWholeUnits >= $qtyStart')
        && str_contains($fabric, 'min($availableWholeUnits, $qtyStart + (19 * $qtyStep))'),
    'Server-rendered whole-unit options must use the MOQ-relative 20-option window and never raise stock to the minimum.'
);
$assert(
    str_contains($productDetail, 'var available = Math.max(0, Math.floor(stock));')
        && str_contains($productDetail, 'available >= start ? Math.min(available, start + ((20 - 1) * step)) : 0'),
    'Variant changes must rebuild the same MOQ-relative 20-option window without exposing quantities above stock.'
);
$assert(
    str_contains($productDetail, 'var step = Math.max(1, Math.round(quantityStep));'),
    'Whole-unit quantity windowing must preserve the configured integer quantity step.'
);
$assert(
    str_contains($fabric, "if (\$unitType === 'meter')")
        && str_contains($productDetail, 'if (isMeterUnit)'),
    'Meter quantity behavior must remain on its established separate path.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "pdp_whole_unit_quantity_window_test: OK\n";
