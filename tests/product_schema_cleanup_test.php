<?php
require dirname(__DIR__) . '/config/db.php';

$obsolete = [
    'print_style','material','gsm','width','moq','lead_time','dispatch_time','wash_care',
    'seo_title','seo_description','image','image2','image3','image4','video',
    'price_inr','price_usd','is_featured',
];

$result = $conn->query('SHOW COLUMNS FROM fabrics');
$columns = [];
while ($row = $result->fetch_assoc()) $columns[] = (string) $row['Field'];
$remaining = array_values(array_intersect($obsolete, $columns));
if ($remaining) {
    fwrite(STDERR, 'FAIL: obsolete fabrics columns remain: ' . implode(', ', $remaining) . PHP_EOL);
    exit(1);
}

foreach (['catalog_data','price','sale_price','cost_price','fabric_media'] as $required) {
    if ($required === 'fabric_media') {
        $table = $conn->query("SHOW TABLES LIKE 'fabric_media'");
        if (!$table || $table->num_rows !== 1) {
            fwrite(STDERR, "FAIL: fabric_media table is missing.\n");
            exit(1);
        }
    } elseif (!in_array($required, $columns, true)) {
        fwrite(STDERR, "FAIL: replacement column {$required} is missing.\n");
        exit(1);
    }
}

$queries = [
    "SELECT f.id, f.price, f.catalog_data, (SELECT fm.filename FROM fabric_media fm WHERE fm.fabric_id=f.id AND fm.media_type='image' ORDER BY fm.is_primary DESC, fm.sort_order, fm.id LIMIT 1) AS image FROM fabrics f LIMIT 1",
    "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(catalog_data, '$.attr_material')), '') AS material FROM fabrics LIMIT 1",
];
foreach ($queries as $sql) {
    if (!$conn->query($sql)) {
        fwrite(STDERR, 'FAIL: replacement query failed: ' . $conn->error . PHP_EOL);
        exit(1);
    }
}

echo "product_schema_cleanup_test: OK\n";
