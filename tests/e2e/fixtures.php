<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "E2E fixtures are CLI-only.\n");
    exit(1);
}

$requestedMode = (string) (getenv('APP_MODE') ?: '');
$requestedAppEnvironment = (string) (getenv('APP_ENV') ?: '');
$confirmation = (string) (getenv('E2E_FIXTURE_CONFIRM') ?: '');
$preflightErrors = e2e_fixture_policy_errors(
    $requestedMode,
    $confirmation,
    $requestedAppEnvironment,
    'pending_e2e'
);
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
    (string) ($GLOBALS['_app_config']['APP_ENV'] ?? ''),
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
    [
        'product_code' => 'E2E-HIGH-MOQ-50',
        'name' => 'E2E High MOQ 50 Product',
        'sku' => 'E2E-HIGH-MOQ-50-SKU',
        'category' => 'bedsheets',
        'product_type' => 'simple',
        'slug' => 'e2e-high-moq-50-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => 'Amber',
        'description' => 'Deterministic high minimum-order simple product for local Playwright tests.',
        'price' => 199.00,
        'sale_price' => null,
        'cost_price' => 100.00,
        'stock' => 100.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 50.00,
        'qty_step' => 5.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-HIGH-MOQ-25',
        'name' => 'E2E High MOQ 25 Product',
        'sku' => 'E2E-HIGH-MOQ-25-SKU',
        'category' => 'bedsheets',
        'product_type' => 'simple',
        'slug' => 'e2e-high-moq-25-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => 'Amber',
        'description' => 'Deterministic minimum-equals-stock simple product for local Playwright tests.',
        'price' => 199.00,
        'sale_price' => null,
        'cost_price' => 100.00,
        'stock' => 25.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 25.00,
        'qty_step' => 1.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-MOQ-1-STOCK-10',
        'name' => 'E2E MOQ 1 Stock 10 Product',
        'sku' => 'E2E-MOQ-1-STOCK-10-SKU',
        'category' => 'bedsheets',
        'product_type' => 'simple',
        'slug' => 'e2e-moq-1-stock-10-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => 'Amber',
        'description' => 'Deterministic ordinary quantity-range product for local Playwright tests.',
        'price' => 199.00,
        'sale_price' => null,
        'cost_price' => 100.00,
        'stock' => 10.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 1.00,
        'qty_step' => 1.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-HIGH-MOQ-LOW-STOCK',
        'name' => 'E2E High MOQ Low Stock Product',
        'sku' => 'E2E-HIGH-MOQ-LOW-STOCK-SKU',
        'category' => 'bedsheets',
        'product_type' => 'simple',
        'slug' => 'e2e-high-moq-low-stock-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => 'Amber',
        'description' => 'Deterministic below-minimum stock product for local Playwright tests.',
        'price' => 199.00,
        'sale_price' => null,
        'cost_price' => 100.00,
        'stock' => 24.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 25.00,
        'qty_step' => 1.00,
        'status' => 'active',
        'is_available' => 1,
    ],
    [
        'product_code' => 'E2E-HIGH-MOQ-VARIANT',
        'name' => 'E2E High MOQ Variant Product',
        'sku' => 'E2E-HIGH-MOQ-VARIANT-PARENT',
        'category' => 'bedsheets',
        'product_type' => 'variable',
        'slug' => 'e2e-high-moq-variant-product',
        'unit_type' => 'piece',
        'meter_options' => '',
        'size' => '',
        'color' => '',
        'description' => 'Deterministic high minimum-order variant product for local Playwright tests.',
        'price' => 249.00,
        'sale_price' => null,
        'cost_price' => 120.00,
        'stock' => 0.00,
        'stock_meters' => 0.00,
        'min_order_meters' => 25.00,
        'qty_step' => 1.00,
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
    $highMoqVariantProductId = (int) ($productIds['E2E-HIGH-MOQ-VARIANT'] ?? 0);
    $deleteVariantsStmt = $conn->prepare('DELETE FROM fabric_variants WHERE fabric_id = ?');
    $deleteVariantsStmt->execute([$variantProductId]);
    $deleteVariantsStmt->execute([$highMoqVariantProductId]);

    $variantStmt = $conn->prepare(
        "INSERT INTO fabric_variants (
            fabric_id, color, size, sku, pack_label, units_per_set,
            price_override, stock, stock_meters, is_active, sort_order
         ) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, 0, 1, ?)"
    );
    $variantStmt->execute([$variantProductId, 'Navy', 'Small', 'E2E-VAR-NAVY-S', 249.00, 15.00, 1]);
    $variantStmt->execute([$variantProductId, 'Amber', 'Large', 'E2E-VAR-AMBER-L', 279.00, 15.00, 2]);
    $variantStmt->execute([$highMoqVariantProductId, 'Navy', 'Standard', 'E2E-HIGH-MOQ-VAR-NAVY', 249.00, 100.00, 1]);
    $variantStmt->execute([$highMoqVariantProductId, 'Amber', 'Standard', 'E2E-HIGH-MOQ-VAR-AMBER', 249.00, 25.00, 2]);
    $variantStmt->execute([$highMoqVariantProductId, 'Stone', 'Standard', 'E2E-HIGH-MOQ-VAR-STONE', 249.00, 24.00, 3]);

    $couponStmt = $conn->prepare(
        "INSERT INTO coupons (
            code, discount_type, discount_value, min_order_amount, max_discount,
            start_date, end_date, usage_limit, used_count, status
         ) VALUES ('E2E10', 'percent', 10.00, 100.00, 500.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 10000, 0, 'active')
         ON DUPLICATE KEY UPDATE
            discount_type = 'percent',
            discount_value = 10.00,
            min_order_amount = 100.00,
            max_discount = 500.00,
            start_date = CURDATE(),
            end_date = DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
            usage_limit = 10000,
            status = 'active'"
    );
    $couponStmt->execute();

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
