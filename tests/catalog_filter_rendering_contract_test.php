<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$catalog = (string) file_get_contents($root . '/catalog.php');
$partialPath = $root . '/includes/partials/catalog-filter-fields.php';
$assert(is_file($partialPath), 'Catalog filter fields must be rendered by a focused shared partial.');
$assert(
    str_contains($catalog, '$catalogFilterFields = [')
        && substr_count($catalog, "catalog-filter-fields.php") === 2,
    'Catalog must define one filter model and render it once per desktop and mobile mode.'
);

if (is_file($partialPath)) {
    $catalogFilterFields = [
        'category' => ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'value' => 'cotton', 'options' => [['value' => '', 'label' => 'All Categories'], ['value' => 'cotton', 'label' => 'Cotton']]],
        'min_price' => ['name' => 'min_price', 'label' => 'Minimum Price', 'type' => 'number', 'value' => 125, 'min' => 0, 'placeholder' => 'Min'],
        'max_price' => ['name' => 'max_price', 'label' => 'Maximum Price', 'type' => 'number', 'value' => 900, 'min' => 0, 'placeholder' => 'Max'],
        'in_stock' => ['name' => 'in_stock', 'label' => 'In Stock Only', 'type' => 'checkbox', 'value' => '1', 'checked' => true],
        'material' => ['name' => 'material', 'label' => 'Material', 'type' => 'text', 'value' => 'Cotton', 'placeholder' => 'Cotton, Linen...'],
        'color' => ['name' => 'color', 'label' => 'Color', 'type' => 'text', 'value' => 'Indigo', 'placeholder' => 'Indigo, Red...'],
        'size' => ['name' => 'size', 'label' => 'Size / Pack', 'type' => 'text', 'value' => 'Queen', 'placeholder' => 'L, Queen, Pack of 2...'],
        'dispatch' => ['name' => 'dispatch', 'label' => 'Dispatch Time', 'type' => 'text', 'value' => '2-3 days', 'placeholder' => '2-3 days, 1 week...'],
        'sort' => ['name' => 'sort', 'label' => 'Sort', 'type' => 'select', 'value' => 'price_asc', 'options' => [['value' => 'newest', 'label' => 'Newest First'], ['value' => 'oldest', 'label' => 'Oldest First'], ['value' => 'name_asc', 'label' => 'Name A-Z'], ['value' => 'name_desc', 'label' => 'Name Z-A'], ['value' => 'price_asc', 'label' => 'Price Low-High'], ['value' => 'price_desc', 'label' => 'Price High-Low']]],
        'per_page' => ['name' => 'per_page', 'label' => 'Per Page', 'type' => 'select', 'value' => 20, 'options' => [['value' => 10, 'label' => '10'], ['value' => 20, 'label' => '20'], ['value' => 30, 'label' => '30']]],
    ];

    $render = static function (string $mode, string $idPrefix) use ($partialPath, $catalogFilterFields): string {
        $catalogFilterMode = $mode;
        $catalogFilterIdPrefix = $idPrefix;
        ob_start();
        include $partialPath;
        return (string) ob_get_clean();
    };

    $desktop = $render('desktop', 'catalog_');
    $mobile = $render('mobile', 'catalog_mobile_');
    foreach ($catalogFilterFields as $key => $field) {
        $name = (string) $field['name'];
        foreach ([['markup' => $desktop, 'prefix' => 'catalog_'], ['markup' => $mobile, 'prefix' => 'catalog_mobile_']] as $surface) {
            $id = $surface['prefix'] . $name;
            $quotedId = preg_quote($id, '/');
            $quotedName = preg_quote($name, '/');
            $hasControl = preg_match('/<(?:input|select)\\b(?=[^>]*\\bid="' . $quotedId . '")(?=[^>]*\\bname="' . $quotedName . '")[^>]*>/s', $surface['markup']) === 1;
            $hasLabel = preg_match('/<label\\b(?=[^>]*\\bfor="' . $quotedId . '")[^>]*>.*?<\\/label>/s', $surface['markup']) === 1;
            $assert($hasControl && $hasLabel, ucfirst($key) . ' must retain one labelled ' . $name . ' control in ' . $surface['prefix'] . ' mode.');
        }
    }

    foreach (['newest', 'oldest', 'name_asc', 'name_desc', 'price_asc', 'price_desc', '10', '20', '30'] as $optionValue) {
        $needle = 'value="' . $optionValue . '"';
        $assert(str_contains($desktop, $needle) && str_contains($mobile, $needle), 'Desktop and mobile filters must expose option value ' . $optionValue . '.');
    }
    $assert(str_contains($desktop, 'value="cotton"') && str_contains($mobile, 'value="cotton"'), 'Desktop and mobile category filters must use the same configured options.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "catalog_filter_rendering_contract_test: OK\n";
