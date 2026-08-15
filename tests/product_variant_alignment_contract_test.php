<?php
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$service = (string) file_get_contents($root . '/includes/services/ProductVariantService.php');
$endpoint = (string) file_get_contents($root . '/admin/fabric-variants.php');
$actions = (string) file_get_contents($root . '/admin/product-actions.php');
$editor = (string) file_get_contents($root . '/admin/edit-fabric.php');
$addToCart = (string) file_get_contents($root . '/add-to-cart.php');
$cart = (string) file_get_contents($root . '/includes/services/CartService.php');
$order = (string) file_get_contents($root . '/place-order.php');
$estimate = (string) file_get_contents($root . '/delivery-estimate.php');
$setup = (string) file_get_contents($root . '/database/setup.php');
$legacyCleanup = (string) file_get_contents($root . '/database/migrations/2026-08-17-remove-legacy-placeholder-variants.sql');
$legacyPurge = (string) file_get_contents($root . '/database/migrations/2026-08-18-purge-legacy-placeholder-variants.sql');
$adminFooter = (string) file_get_contents($root . '/admin/partials/footer.php');

$assert(str_contains($service, "product_type'] ?? 'simple') !== 'variable'"), 'Variant service must reject variants on simple products.');
$assert(str_contains($service, 'skuAvailable') && str_contains($service, 'SELECT sku FROM fabrics'), 'Variant SKU must be unique across products and variants.');
$assert(str_contains($service, 'priceOverride > $mrp'), 'Variant price overrides must stay aligned with product MRP.');
$assert(str_contains($service, 'Piece and set stock must be a whole number.'), 'Variant inventory must respect the parent unit type.');
$assert(str_contains($service, "'inherits_price'") && str_contains($service, "'inherits_media'"), 'Variant responses must describe product inheritance.');
$assert(str_contains($endpoint, "'errors' => \$errors") && str_contains($endpoint, 'ProductVariantService::syncAvailability'), 'Variant endpoint must return structured errors and synchronize product availability.');
$assert(str_contains($endpoint, '[variant-save]') && str_contains($endpoint, '$uploadedFiles'), 'Variant saves must clean staged media after failed transactions.');
$assert(str_contains($actions, "action==='enable-variants'") && str_contains($actions, "action==='disable-variants'"), 'Safe simple/variable conversion actions are missing.');
$assert(str_contains($editor, 'Base gallery') && str_contains($editor, 'Inherited') && str_contains($editor, 'effective_stock'), 'Variant editor must show inherited media, pricing, and unit-aware stock.');
$assert(str_contains($editor, 'variant-save-btn') && str_contains($editor, '.finally(function ()'), 'Variant editor must prevent double saves and restore the save state.');
foreach ([$addToCart, $cart, $order, $estimate] as $source) {
    $assert(str_contains($source, 'product_type') && str_contains($source, 'requiresVariant'), 'Storefront, cart, order, and delivery flows must enforce product variant mode.');
}
$assert(!str_contains($setup, 'Seed one default active variant') && !str_contains($setup, 'INSERT IGNORE INTO fabric_variants (fabric_id, color, size, stock'), 'Fresh setup must not create legacy placeholder variants.');
$assert(!str_contains($endpoint, 'cleanup_legacy_default_variants') && !str_contains($endpoint, 'variant_auto_sku') && !str_contains($endpoint, 'sync_fabric_availability_from_variants'), 'Superseded endpoint variant helpers must be removed.');
$assert(str_contains($legacyCleanup, 'DELETE fv') && str_contains($legacyCleanup, 'LEFT JOIN order_items') && str_contains($legacyCleanup, "SET f.product_type = 'simple'"), 'Legacy placeholder cleanup must remove unreferenced rows and preserve order history.');
$assert(str_contains($legacyPurge, 'SET oi.variant_id = NULL') && str_contains($legacyPurge, 'DELETE fv'), 'Testing cleanup must fully purge remaining placeholder variants.');
$assert(str_contains($editor, 'variant-editor-modal') && str_contains($editor, 'modal-xl') && str_contains($editor, "fd.append('sort_order'"), 'Variant add/edit must use a complete modal editor.');
$assert(str_contains($editor, 'document.body.appendChild(modalElement)') && !str_contains($editor, 'modal-dialog-scrollable'), 'Variant modal must be mounted at document level without the unstable nested scroll dialog.');
$assert(str_contains($adminFooter, '<script nonce=') && !str_contains($editor, "Promise.resolve(confirm("), 'Admin confirmations must use the CSP-authorized branded modal.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "product_variant_alignment_contract_test: OK\n";
