<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$passes = [];
$warnings = [];
$failures = [];

$markPass = static function (string $message) use (&$passes): void {
    $passes[] = $message;
    fwrite(STDOUT, '[PASS] ' . $message . PHP_EOL);
};
$markWarn = static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
    fwrite(STDOUT, '[WARN] ' . $message . PHP_EOL);
};
$markFail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
};

try {
    require __DIR__ . '/../config/db.php';
    $markPass('Bootstrap loaded.');
} catch (Throwable $e) {
    $markFail('Bootstrap failed: ' . $e->getMessage());
    exit(1);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    $markFail('Database connection is not available.');
    exit(1);
}

function fetch_indexes(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $index = (string) ($row['INDEX_NAME'] ?? '');
        $col = (string) ($row['COLUMN_NAME'] ?? '');
        if ($table === '' || $index === '' || $col === '') {
            continue;
        }
        if (!isset($map[$table])) {
            $map[$table] = [];
        }
        if (!isset($map[$table][$index])) {
            $map[$table][$index] = [];
        }
        $map[$table][$index][] = $col;
    }
    return $map;
}

function has_index_prefix(array $tableIndexes, array $requiredPrefix): bool
{
    foreach ($tableIndexes as $cols) {
        $ok = true;
        foreach ($requiredPrefix as $i => $requiredCol) {
            if (!isset($cols[$i]) || strtolower((string) $cols[$i]) !== strtolower($requiredCol)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return true;
        }
    }
    return false;
}

$indexes = fetch_indexes($conn);

$required = [
    [
        'table' => 'fabrics',
        'prefix' => ['status', 'category'],
        'label' => 'Catalog filter path (status, category)',
        'suggest' => "CREATE INDEX idx_fabrics_status_category ON fabrics(status, category);",
    ],
    [
        'table' => 'fabrics',
        'prefix' => ['created_at', 'id'],
        'label' => 'Catalog sort path (created_at, id)',
        'suggest' => "CREATE INDEX idx_fabrics_created_id ON fabrics(created_at, id);",
    ],
    [
        'table' => 'fabric_variants',
        'prefix' => ['fabric_id', 'is_active'],
        'label' => 'Variant join path (fabric_id, is_active)',
        'suggest' => "CREATE INDEX idx_fabric_variants_fabric_active ON fabric_variants(fabric_id, is_active);",
    ],
    [
        'table' => 'orders',
        'prefix' => ['created_at'],
        'label' => 'Admin orders newest/oldest sort',
        'suggest' => "CREATE INDEX idx_orders_created_at ON orders(created_at);",
    ],
    [
        'table' => 'orders',
        'prefix' => ['order_status', 'payment_status'],
        'label' => 'Admin orders status filtering',
        'suggest' => "CREATE INDEX idx_orders_status_payment ON orders(order_status, payment_status);",
    ],
    [
        'table' => 'order_items',
        'prefix' => ['order_id'],
        'label' => 'Admin order items fanout lookup',
        'suggest' => "CREATE INDEX idx_order_items_order_id ON order_items(order_id);",
    ],
    [
        'table' => 'shipments',
        'prefix' => ['order_id'],
        'label' => 'Admin shipment lookup by order',
        'suggest' => "CREATE INDEX idx_shipments_order_id ON shipments(order_id);",
    ],
];

foreach ($required as $check) {
    $table = (string) $check['table'];
    $tableIndexes = $indexes[$table] ?? [];
    if (empty($tableIndexes)) {
        $markWarn("No indexes found for table {$table}. Recommendation: " . $check['suggest']);
        continue;
    }
    if (has_index_prefix($tableIndexes, (array) $check['prefix'])) {
        $markPass($check['label'] . ' index coverage OK.');
    } else {
        $markWarn($check['label'] . ' index prefix missing. Recommendation: ' . $check['suggest']);
    }
}

// Check fulltext support used by catalog search path.
$ftStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'fabrics'
       AND INDEX_NAME = 'ft_fabrics_catalog_search'
       AND INDEX_TYPE = 'FULLTEXT'"
);
$ftStmt->execute();
$ftRow = $ftStmt->get_result()->fetch_assoc();
$ftReady = ((int) ($ftRow['total'] ?? 0)) > 0;
if ($ftReady) {
    $markPass('Catalog FULLTEXT index is present: ft_fabrics_catalog_search');
} else {
    $markWarn('Catalog FULLTEXT index missing. Recommendation: CREATE FULLTEXT INDEX ft_fabrics_catalog_search ON fabrics(name, sku, material, category, dispatch_time, color, size);');
}

fwrite(STDOUT, PHP_EOL . 'Index Audit Summary' . PHP_EOL);
fwrite(STDOUT, 'Passed: ' . count($passes) . PHP_EOL);
fwrite(STDOUT, 'Warnings: ' . count($warnings) . PHP_EOL);
fwrite(STDOUT, 'Failed: ' . count($failures) . PHP_EOL);

if (!empty($failures)) {
    fwrite(STDERR, 'Result: FAIL' . PHP_EOL);
    exit(1);
}
if (!empty($warnings)) {
    fwrite(STDOUT, 'Result: WARN' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, 'Result: PASS' . PHP_EOL);
exit(0);
