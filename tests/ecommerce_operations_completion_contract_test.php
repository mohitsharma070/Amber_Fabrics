<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root, $assert): string {
    $contents = @file_get_contents($root . '/' . $path);
    $assert($contents !== false, $path . ' must be present.');
    return $contents === false ? '' : $contents;
};

$admin = $read('includes/helpers/admin.php');
$adminsPage = $read('admin/admins.php');
$adminHeader = $read('admin/partials/header.php');
$adminCss = $read('css/admin.css');
$adminJs = $read('js/admin.js');
$operations = $read('admin/operations.php');
$returns = $read('admin/returns.php');
$guestOrder = $read('guest/order.php');
$orderReadService = $read('includes/services/OrderReadService.php');
$eligibility = $read('includes/helpers/inventory-orders.php');
$feed = $read('plugins/product-feed/plugin.php');
$alerts = $read('plugins/inventory-alert/plugin.php');
$dashboard = $read('admin/dashboard.php');
$orderView = $read('admin/order-view.php');
$cron = $read('cron/run-plugins.php');
$courier = $read('plugins/shipping-courier/modules/returns.php');
$migrationPath = 'database/migrations/2026-08-22-ecommerce-operations-completion.sql';
$migration = $read($migrationPath);
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$config = $read('config/plugins.php');
$footer = $read('includes/views/layouts/footer.php');
$openapi = $read('openapi.yaml');
$readme = $read('README.md');
$siteSettings = $read('includes/services/SiteSettingsService.php');

foreach (['viewer', 'catalog_manager', 'operations_manager', 'super_admin', 'admin_can', 'admin_route_capability'] as $needle) {
    $assert(str_contains($admin, $needle), 'Capability RBAC must include ' . $needle . '.');
}
$assert(str_contains($adminsPage, 'At least one active super administrator is required.') && str_contains($adminsPage, 'You cannot deactivate your current'), 'Administrator tooling must protect the final super admin and current account.');
$assert(!str_contains($adminsPage, '<tr><form') && str_contains($adminsPage, '$rowFormId') && str_contains($adminsPage, 'form="<?php echo e($rowFormId); ?>"'), 'Administrator row forms must use valid table markup and explicit form associations.');
$assert(str_contains($adminHeader, 'admin-read-only') && str_contains($adminHeader, 'admin-logout-form') && str_contains($adminCss, '.admin-read-only form[method="post" i]') && str_contains($adminJs, 'control.hidden = true'), 'Read-only capability state must hide mutation controls with no-JavaScript and JavaScript coverage while retaining logout.');
$assert(str_contains($operations, 'cron_run_history') && str_contains($operations, 'payment_attempts') && str_contains($operations, 'stock_ledger') && str_contains($operations, 'admin_activity_logs'), 'Operations Center must expose bounded operational ledgers.');

$assert(str_contains($eligibility, 'return_request_eligibility') && str_contains($eligibility, "DateTimeZone('UTC')") && str_contains($eligibility, "return_request_window_days() . ' days'"), 'Return eligibility must use one inclusive UTC seven-day policy.');
$assert(str_contains($returns, 'LEFT JOIN customers') && str_contains($returns, 'send_return_status_update_email'), 'Admin returns must include guests and send non-blocking status mail.');
$assert(str_contains($orderReadService, 'shipping_courier_reverse_pickups') && str_contains($guestOrder, 'OrderReadService::latestReversePickup') && str_contains($guestOrder, '&& !$returnRequest'), 'Guest order pages must show existing return information and suppress duplicate forms.');

$assert(str_contains($feed, "CONCAT('p-', f.id, '-v-', fv.id)") && str_contains($feed, 'variant_id') && str_contains($feed, 'price_override'), 'Variable product feeds must emit stable variant offers with override pricing.');
$assert(str_contains($alerts, 'variant_id <=> ?') && str_contains($alerts, "'color'"), 'Inventory alerts must use a variant-aware cooldown and email context.');
$assert(str_contains($dashboard, "f.product_type='variable'") && str_contains($dashboard, 'fabric_variants'), 'Dashboard low stock must count active variant SKUs.');
$assert(str_contains($orderView, "\$method === 'cod' && \$targetStatus === 'cancelled'") && str_contains($orderView, "\$method === 'cod' && \$targetStatus === 'confirmed'") && str_contains($orderView, "WHERE order_id = ? AND status = 'pending'"), 'Admin COD workflow transitions must synchronize only pending confirmation rows.');

$assert(str_contains($cron, 'cron_run_history') && str_contains($cron, 'INTERVAL 30 DAY') && str_contains($dashboard, 'cron_last_summary_json'), 'Cron history and sanitized summary must be persisted and rendered.');
$assert(str_contains($courier, 'shipping_courier_reverse_capabilities') && str_contains($courier, 'shipping_courier_claim_reverse_pickup') && str_contains($courier, 'Manual pickup is required'), 'Reverse pickup creation must be capability-gated and claimed atomically.');

foreach (['variant_id', 'cron_run_history', 'initialization_status'] as $needle) {
    $assert(str_contains($migration, $needle) && str_contains($schema, $needle), 'Migration and fresh schema must include ' . $needle . '.');
}
$assert(str_contains($migration, 'DROP TABLE IF EXISTS newsletter_subscribers') && !str_contains($schema, 'CREATE TABLE IF NOT EXISTS newsletter_subscribers'), 'Newsletter must be dropped in the migration and absent from fresh schema.');
$checksum = hash_file('sha256', $root . '/' . $migrationPath);
$assert(is_string($checksum) && str_contains($schema, $checksum), 'Fresh schema must contain the current migration checksum.');
$assert(str_contains($setup, 'cron_run_history') && str_contains($setup, 'idx_shipping_courier_reverse_claim'), 'Fresh setup must align with cron and reverse-pickup schema.');

$assert(!str_contains($config, "'newsletter' =>") && !str_contains($config, "'newsletter',") && !str_contains($footer, 'footer.newsletter'), 'Newsletter runtime configuration and footer hook must be removed.');
$assert(!is_file($root . '/plugins/newsletter/plugin.php') && !is_file($root . '/includes/email-templates/newsletter-confirm.php'), 'Newsletter runtime files must be removed.');
$assert(!is_file($root . '/scripts/export-newsletter-subscribers.php'), 'The unnecessary newsletter export command must be removed with the retired feature.');
$assert(str_contains($openapi, 'seven-calendar-day UTC window') && str_contains($readme, 'newsletter subscriber table'), 'Public documentation must describe the return policy and newsletter retirement.');
$assert(!str_contains($siteSettings, 'Returns and Exchanges') && !str_contains($siteSettings, 'Returns and Replacements') && !str_contains($siteSettings, 'return or exchange products'), 'Fresh policy defaults must be refund-only without duplicated legacy exchange promises.');

if ($failures !== []) {
    fwrite(STDERR, "Ecommerce operations completion contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "ecommerce_operations_completion_contract_test: OK\n";
