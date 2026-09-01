<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "E2E fixtures are CLI-only.\n");
    exit(1);
}

$requestedMode = (string) (getenv('APP_MODE') ?: '');
$confirmation = (string) (getenv('E2E_FIXTURE_CONFIRM') ?: '');
$preflightErrors = e2e_fixture_policy_errors($requestedMode, $confirmation, 'pending_e2e');
if ($preflightErrors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $preflightErrors) . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/config/db.php';

$databaseRow = $conn->query('SELECT DATABASE() AS database_name')->fetch_assoc();
$databaseName = trim((string) ($databaseRow['database_name'] ?? ''));
$policyErrors = e2e_fixture_policy_errors(
    (string) ($GLOBALS['_app_mode'] ?? ''),
    $confirmation,
    $databaseName
);
if ($policyErrors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $policyErrors) . PHP_EOL);
    exit(1);
}

$products = [
    [
        'product_code' => 'E2E-SIMPLE',
        'name' => 'E2E Simple Product',
        'sku' => 'E2E-SIMPLE-SKU',
        'category' => 'bedsheets',
        'product_type' => 'simple',
        'slug' => 'e2e-simple-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => 'Amber',
        'description' => 'Deterministic simple product for local Playwright tests.',
        'price' => 199.00,
        'sale_price' => null,
        'cost_price' => 100.00,
        'stock' => 20.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 1.00,
        'qty_step' => 1.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-VARIANT',
        'name' => 'E2E Variant Product',
        'sku' => 'E2E-VARIANT-PARENT',
        'category' => 'bedsheets',
        'product_type' => 'variable',
        'slug' => 'e2e-variant-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => '',
        'description' => 'Deterministic variable product for local Playwright tests.',
        'price' => 249.00,
        'sale_price' => null,
        'cost_price' => 120.00,
        'stock' => 0.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 1.00,
        'qty_step' => 1.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-METER',
        'name' => 'E2E Meter Product',
        'sku' => 'E2E-METER-SKU',
        'category' => 'fabric-by-meter',
        'product_type' => 'simple',
        'slug' => 'e2e-meter-product',
        'unit_type' => 'meter',
        'meter_options' => '1,2.5',
        'size' => '',
        'color' => 'Natural',
        'description' => 'Deterministic meter product for local Playwright tests.',
        'price' => 150.00,
        'sale_price' => null,
        'cost_price' => 75.00,
        'stock' => 0.00,
        'stock_meters' => 50.00,
        'min_order_meters' => 1.00,
        'qty_step' => 0.50,
        'status' => 'active',
        'is_available' => 1,
    ],
];

$inTransaction = false;
try {
    $conn->begin_transaction();
    $inTransaction = true;

    $categoryStmt = $conn->prepare(
        "INSERT INTO categories (name, slug, parent_id, status, default_unit_type)
         VALUES (?, ?, NULL, 'active', ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            status = 'active',
            parent_id = NULL,
            default_unit_type = VALUES(default_unit_type)"
    );
    $categoryStmt->execute(['Bedsheets', 'bedsheets', null]);
    $categoryStmt->execute(['Fabric by Meter', 'fabric-by-meter', 'meter']);

    $productStmt = $conn->prepare(
        "INSERT INTO fabrics (
            product_code, name, sku, category, product_type, slug, unit_type,
            meter_options, size, color, description, price, sale_price, cost_price,
            stock, stock_meters, min_order_meters, qty_step, status, is_available,
            published_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            sku = VALUES(sku),
            category = VALUES(category),
            product_type = VALUES(product_type),
            slug = VALUES(slug),
            unit_type = VALUES(unit_type),
            meter_options = VALUES(meter_options),
            size = VALUES(size),
            color = VALUES(color),
            description = VALUES(description),
            price = VALUES(price),
            sale_price = VALUES(sale_price),
            cost_price = VALUES(cost_price),
            stock = VALUES(stock),
            stock_meters = VALUES(stock_meters),
            min_order_meters = VALUES(min_order_meters),
            qty_step = VALUES(qty_step),
            status = VALUES(status),
            is_available = VALUES(is_available),
            published_at = NOW(),
            created_at = NOW(),
            updated_at = NOW()"
    );
    $productIdStmt = $conn->prepare('SELECT id FROM fabrics WHERE product_code = ? LIMIT 1');
    $productIds = [];
    foreach ($products as $product) {
        $productStmt->execute(array_values($product));
        $productIdStmt->execute([$product['product_code']]);
        $productId = (int) (($productIdStmt->get_result()->fetch_assoc()['id'] ?? 0));
        if ($productId <= 0) {
            throw new RuntimeException('Seeded product could not be resolved.');
        }
        $productIds[$product['product_code']] = $productId;
    }

    $variantProductId = (int) ($productIds['E2E-VARIANT'] ?? 0);
    $deleteVariantsStmt = $conn->prepare('DELETE FROM fabric_variants WHERE fabric_id = ?');
    $deleteVariantsStmt->execute([$variantProductId]);

    $variantStmt = $conn->prepare(
        "INSERT INTO fabric_variants (
            fabric_id, color, size, sku, pack_label, units_per_set,
            price_override, stock, stock_meters, is_active, sort_order
         ) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, 0, 1, ?)"
    );
    $variantStmt->execute([$variantProductId, 'Navy', 'Small', 'E2E-VAR-NAVY-S', 249.00, 15.00, 1]);
    $variantStmt->execute([$variantProductId, 'Amber', 'Large', 'E2E-VAR-AMBER-L', 279.00, 15.00, 2]);

    $conn->commit();
    $inTransaction = false;
    echo "E2E fixtures seeded.\n";
} catch (Throwable $e) {
    if ($inTransaction) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Preserve the original fixture failure.
        }
    }
    error_log('[e2e-fixtures] seed failed: ' . get_class($e));
    fwrite(STDERR, "Unable to seed E2E fixtures.\n");
    exit(1);
}
