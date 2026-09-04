<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string) file_get_contents($root . '/database/schema.sql');
$setup = (string) file_get_contents($root . '/database/setup.php');
$migrationPath = $root . '/database/migrations/2026-09-02-catalog-query-performance.sql';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$indexDefinition = 'idx_fabrics_catalog_created (status, category, created_at, id)';
$assert(str_contains($schema, $indexDefinition), 'Fresh installs must retain the measured category/newest catalog index.');
$assert(
    str_contains($migration, 'information_schema.STATISTICS')
        && str_contains($migration, 'ALTER TABLE fabrics ADD INDEX idx_fabrics_catalog_created (status, category, created_at, id)')
        && !str_contains($migration, 'CREATE INDEX IF NOT EXISTS')
        && substr_count($migration, 'ADD INDEX') === 1,
    'Upgraded installs must add only the measured catalog index through MySQL/MariaDB-compatible idempotent SQL.'
);
$assert(
    str_contains($setup, "!\$indexExists(\$conn, 'fabrics', 'idx_fabrics_catalog_created')")
        && str_contains($setup, 'CREATE INDEX idx_fabrics_catalog_created ON fabrics(status, category, created_at, id)'),
    'Manual setup must align upgraded installs with the measured catalog index contract.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "catalog_query_index_contract_test: OK\n";
