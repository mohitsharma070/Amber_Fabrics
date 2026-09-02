<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/helpers/product-cards.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};

$defaultColumns = product_card_select_columns();
$catalogColumns = product_card_select_columns([], false);
$assert(
    str_contains($defaultColumns, 'FROM fabric_media fm'),
    'Existing product-card consumers must retain the primary-media subquery by default.'
);
$assert(
    !str_contains($catalogColumns, 'FROM fabric_media fm') && str_contains($catalogColumns, "'' AS image"),
    'The catalog must be able to defer primary-media loading until after pagination.'
);

$rows = [
    ['id' => 10, 'variant_id' => 101, 'image' => 'stale.jpg'],
    ['id' => 10, 'variant_id' => 102, 'image' => 'stale.jpg'],
    ['id' => 20, 'variant_id' => 0, 'image' => 'stale.jpg'],
    ['id' => 30, 'variant_id' => 0, 'image' => 'stale.jpg'],
];
$orderedMedia = [
    ['fabric_id' => 10, 'filename' => 'primary-10.jpg'],
    ['fabric_id' => 10, 'filename' => 'secondary-10.jpg'],
    ['fabric_id' => 20, 'filename' => 'primary-20.jpg'],
];
$hydrated = product_card_apply_primary_media($rows, $orderedMedia);

$assertSame('primary-10.jpg', $hydrated[0]['image'] ?? null, 'The first ordered product image must remain primary.');
$assertSame('primary-10.jpg', $hydrated[1]['image'] ?? null, 'All variant cards for one product must reuse its primary image.');
$assertSame('primary-20.jpg', $hydrated[2]['image'] ?? null, 'Each product must receive its own primary image.');
$assertSame('', $hydrated[3]['image'] ?? null, 'Products without media must retain the empty-image fallback.');
$assertSame([], product_card_apply_primary_media([], $orderedMedia), 'An empty result page must remain empty.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "catalog_product_card_media_query_test: OK\n";
