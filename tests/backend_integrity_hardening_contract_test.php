<?php
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$adminService = (string) file_get_contents($root . '/includes/services/ProductAdminService.php');
$skuHelper = (string) file_get_contents($root . '/includes/helpers/inquiries-ledger.php');
$importService = (string) file_get_contents($root . '/includes/services/ProductImportService.php');
$editor = (string) file_get_contents($root . '/admin/edit-fabric.php');
$actions = (string) file_get_contents($root . '/admin/product-actions.php');
$variantEndpoint = (string) file_get_contents($root . '/admin/fabric-variants.php');
$variantService = (string) file_get_contents($root . '/includes/services/ProductVariantService.php');
$mediaEndpoint = (string) file_get_contents($root . '/admin/product-media.php');
$mediaHelper = (string) file_get_contents($root . '/includes/helpers/media.php');
$emailService = (string) file_get_contents($root . '/includes/services/EmailService.php');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$setup = (string) file_get_contents($root . '/database/setup.php');
$migration = (string) file_get_contents($root . '/database/migrations/2026-08-19-backend-integrity-hardening.sql');

$assert(str_contains($adminService, 'SELECT sku FROM fabric_variants WHERE sku = ?'), 'Product SKU validation must include variant SKUs.');
$assert(str_contains($skuHelper, 'SELECT sku FROM fabric_variants WHERE sku = ?'), 'Generated product SKUs must include variant SKUs in collision checks.');
$assert(str_contains($importService, 'ProductAdminService::skuAvailable'), 'CSV imports must use cross-table SKU validation.');
$assert(!str_contains($editor, "\$size = (string) (\$fabric['size'] ?? '');\n        \$color = (string) (\$fabric['color'] ?? '');"), 'Editor must not replace submitted size and colour with stale values.');

$saveSection = strpos($actions, "if(\$action==='save-section')");
$transaction = strpos($actions, '$conn->begin_transaction();', $saveSection === false ? 0 : $saveSection);
$extendedSave = strpos($actions, 'ProductAdminService::saveExtended', $saveSection === false ? 0 : $saveSection);
$assert($saveSection !== false && $transaction !== false && $extendedSave !== false && $transaction < $extendedSave, 'Section saves must begin a transaction before writing.');

$assert(str_contains($variantService, 'function hasBusinessReferences'), 'Variant business-reference detection is missing.');
$assert(str_contains($variantEndpoint, "UPDATE fabric_variants SET is_active = 0"), 'Referenced variants must be archived.');
$assert(str_contains($variantEndpoint, "'archived' => !\$hardDelete") && str_contains($variantEndpoint, 'business history references it'), 'Variant removal responses must distinguish archival from deletion.');
$assert(!str_contains($variantEndpoint, 'UPDATE order_items SET variant_id = NULL'), 'Variant deletion must not detach historical orders.');
$assert(str_contains($actions, 'UPDATE fabric_variants SET is_active=0 WHERE fabric_id=?'), 'Switching to simple inventory must archive variants.');
$assert(!str_contains($actions, 'DELETE FROM fabric_variants WHERE fabric_id=?'), 'Inventory-mode changes must not delete variants.');
$assert(str_contains($editor, 'data.archived') && str_contains($editor, "data.archived ? 'info' : 'success'"), 'Variant removal UI must explain archived versus deleted outcomes.');

$assert(str_contains($mediaHelper, 'function fabric_media_delete_if_unreferenced') && str_contains($mediaHelper, 'FROM fabric_media WHERE filename = ?'), 'Reference-aware media cleanup helper is missing.');
$assert(str_contains($mediaEndpoint, 'fabric_media_delete_if_unreferenced'), 'Product media deletion must use reference-aware cleanup.');
$assert(str_contains($variantEndpoint, 'fabric_media_delete_if_unreferenced'), 'Variant media deletion must use reference-aware cleanup.');

$assert(str_contains($emailService, "activation_email_status = 'processing'") && str_contains($emailService, 'INTERVAL 15 MINUTE'), 'Activation email claims must be retryable after a stale lease.');
$assert(str_contains($migration, 'activation_email_claimed_at') && str_contains($migration, 'activation_email_attempts'), 'Activation email delivery migration is incomplete.');

foreach (['product_code','product_type','fabric_media','guest_order_access_tokens','serviceability_status','activation_email_status'] as $required) {
    $assert(str_contains($schema, $required), 'Fresh schema is missing: ' . $required);
    $assert(str_contains($setup, $required), 'Setup repair path is missing: ' . $required);
}

preg_match_all("/\\('([^']+\\.sql)'\\s*,\\s*'([a-f0-9]{64})'\\)/", $schema, $baselineRows, PREG_SET_ORDER);
$baselines = [];
foreach ($baselineRows as $row) $baselines[$row[1]] = $row[2];
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    if ($name === '0000-README.sql') continue;
    $assert(isset($baselines[$name]), 'Fresh schema does not baseline migration: ' . $name);
    if (isset($baselines[$name])) $assert(hash_file('sha256', $file) === $baselines[$name], 'Fresh-schema checksum is stale for migration: ' . $name);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "backend_integrity_hardening_contract_test: OK\n";
