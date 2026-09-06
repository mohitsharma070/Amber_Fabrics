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

    $registrationCleanup = $conn->prepare('DELETE FROM customers WHERE email = ?');
    $registrationCleanup->execute(['e2e-new-registration@example.test']);

    $customerPassword = implode('', ['E2E', 'Auth', '123!']);
    $customerPasswordHash = password_hash($customerPassword, PASSWORD_DEFAULT);
    if (!is_string($customerPasswordHash) || $customerPasswordHash === '') {
        throw new RuntimeException('Unable to hash the E2E customer password.');
    }
    $customerStmt = $conn->prepare(
        "INSERT INTO customers
            (name, email, password_hash, auth_version, phone, country, is_active, email_verified)
         VALUES (?, ?, ?, 1, '9876543210', 'India', 1, 1)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            auth_version = 1,
            phone = VALUES(phone),
            country = VALUES(country),
            is_active = 1,
            email_verified = 1,
            email_verify_token = NULL,
            email_verify_expires = NULL"
    );
    $customerIdStmt = $conn->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $customerIds = [];
    foreach ([
        ['E2E Customer', 'e2e-customer@example.test'],
        ['E2E Other Customer', 'e2e-other-customer@example.test'],
    ] as [$customerName, $customerEmail]) {
        $customerStmt->execute([$customerName, $customerEmail, $customerPasswordHash]);
        $customerIdStmt->execute([$customerEmail]);
        $customerId = (int) (($customerIdStmt->get_result()->fetch_assoc()['id'] ?? 0));
        if ($customerId <= 0) {
            throw new RuntimeException('Seeded customer could not be resolved.');
        }
        $customerIds[$customerEmail] = $customerId;
    }

    $adminStmt = $conn->prepare(
        "INSERT INTO admins (name, email, role, is_active)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            role = VALUES(role),
            is_active = 1"
    );
    $adminIdStmt = $conn->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
    $adminIds = [];
    foreach ([
        ['E2E Operations Admin', 'e2e-operations-admin@example.test', 'operations_manager'],
        ['E2E Viewer Admin', 'e2e-viewer-admin@example.test', 'viewer'],
    ] as [$adminName, $adminEmail, $adminRole]) {
        $adminStmt->execute([$adminName, $adminEmail, $adminRole]);
        $adminIdStmt->execute([$adminEmail]);
        $adminId = (int) (($adminIdStmt->get_result()->fetch_assoc()['id'] ?? 0));
        if ($adminId <= 0) {
            throw new RuntimeException('Seeded admin could not be resolved.');
        }
        $adminIds[$adminEmail] = $adminId;
    }

    $orderStmt = $conn->prepare(
        "INSERT INTO orders (
            order_number, customer_name, customer_phone, customer_email, customer_id,
            address, city, state, pincode, country, subtotal, shipping_amount,
            discount_amount, total_amount, payment_method, payment_status,
            order_status, currency, shipping_cost, total, status
         ) VALUES (?, ?, '9876543210', ?, ?, '42 E2E Test Street', 'Jaipur',
                   'Rajasthan', '302001', 'India', 199.00, 0.00, 0.00, 199.00,
                   'cod', 'pending', 'pending', 'INR', 0.00, 199.00, 'pending')
         ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            customer_phone = VALUES(customer_phone),
            customer_email = VALUES(customer_email),
            customer_id = VALUES(customer_id),
            address = VALUES(address),
            city = VALUES(city),
            state = VALUES(state),
            pincode = VALUES(pincode),
            country = VALUES(country),
            subtotal = VALUES(subtotal),
            shipping_amount = VALUES(shipping_amount),
            discount_amount = VALUES(discount_amount),
            total_amount = VALUES(total_amount),
            payment_method = 'cod',
            payment_status = 'pending',
            order_status = 'pending',
            currency = 'INR',
            shipping_cost = 0.00,
            total = 199.00,
            status = 'pending',
            inventory_reserved_at = NULL,
            inventory_restored_at = NULL,
            updated_at = NOW()"
    );
    $orderIdStmt = $conn->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
    $orders = [
        ['E2E-AUTH-OWNED', 'E2E Customer', 'e2e-customer@example.test', $customerIds['e2e-customer@example.test']],
        ['E2E-AUTH-OTHER', 'E2E Other Customer', 'e2e-other-customer@example.test', $customerIds['e2e-other-customer@example.test']],
        ['E2E-GUEST-ORDER', 'E2E Guest Customer', 'e2e-guest-order@example.test', null],
    ];
    $orderIds = [];
    foreach ($orders as [$orderNumber, $customerName, $customerEmail, $customerId]) {
        $orderStmt->execute([$orderNumber, $customerName, $customerEmail, $customerId]);
        $orderIdStmt->execute([$orderNumber]);
        $orderId = (int) (($orderIdStmt->get_result()->fetch_assoc()['id'] ?? 0));
        if ($orderId <= 0) {
            throw new RuntimeException('Seeded order could not be resolved.');
        }
        $orderIds[$orderNumber] = $orderId;
    }

    $deleteOrderItemsStmt = $conn->prepare('DELETE FROM order_items WHERE order_id = ?');
    $orderItemStmt = $conn->prepare(
        "INSERT INTO order_items (
            order_id, product_id, product_name, unit_type, quantity, price, total,
            fabric_id, fabric_name_snapshot, fabric_sku_snapshot, quantity_meters,
            price_per_meter, line_total
         ) VALUES (?, ?, 'E2E Simple Product', 'piece', 1.00, 199.00, 199.00,
                   ?, 'E2E Simple Product', 'E2E-SIMPLE-SKU', 1.00, 199.00, 199.00)"
    );
    $simpleProductId = (int) ($productIds['E2E-SIMPLE'] ?? 0);
    foreach ($orderIds as $orderId) {
        $deleteOrderItemsStmt->execute([$orderId]);
        $orderItemStmt->execute([$orderId, $simpleProductId, $simpleProductId]);
    }

    $guestOrderId = (int) ($orderIds['E2E-GUEST-ORDER'] ?? 0);
    $deleteGuestTokensStmt = $conn->prepare('DELETE FROM guest_order_access_tokens WHERE order_id = ?');
    $deleteGuestTokensStmt->execute([$guestOrderId]);

    $deleteAdminOtpStmt = $conn->prepare('DELETE FROM admin_login_otps WHERE admin_id = ?');
    $deleteAdminAttemptStmt = $conn->prepare('DELETE FROM admin_login_attempts WHERE attempt_key = ?');
    foreach ($adminIds as $adminEmail => $adminId) {
        $deleteAdminOtpStmt->execute([$adminId]);
        foreach ([
            hash('sha256', strtolower($adminEmail) . '|127.0.0.1'),
            hash('sha256', 'admin_otp|verify|' . $adminId . '|127.0.0.1'),
            hash('sha256', 'admin_otp|resend|' . $adminId . '|127.0.0.1'),
        ] as $attemptKey) {
            $deleteAdminAttemptStmt->execute([$attemptKey]);
        }
    }

    $conn->query("DELETE FROM public_form_attempts WHERE scope = 'customer_register' OR scope LIKE 'guest_order_link_%'");

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
