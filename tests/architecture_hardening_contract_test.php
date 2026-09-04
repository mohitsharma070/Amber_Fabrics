<?php
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migrationPath = 'database/migrations/2026-08-25-architecture-hardening.sql';
$migration = $read($migrationPath);
$schema = $read('database/schema.sql');
$setup = $read('database/setup.php');
$categories = $read('admin/categories.php');
$config = $read('config/db.php');
$example = $read('config/secure-config.production.example.php');
$observability = $read('includes/helpers/observability.php');
$init = $read('includes/init.php');
$cron = $read('cron/run-plugins.php');
$functions = $read('includes/functions.php');
$inventoryService = $read('includes/services/InventoryService.php');
$inventoryHelpers = $read('includes/helpers/inventory-orders.php');
$paymentService = $read('includes/services/PaymentService.php');
$bigshipService = $read('includes/services/BigshipService.php');
$httpHelper = $read('includes/helpers/email-tax-ui.php');
$metaPlugin = $read('plugins/meta-capi/plugin.php');
$codPlugin = $read('plugins/cod-guard/plugin.php');
$customerLogin = $read('customer/login.php');
$customerProfile = $read('customer/profile.php');

$assert(
    str_contains($migration, 'information_schema.COLUMNS')
        && str_contains($migration, "COLUMN_NAME = 'uses_variant_size'")
        && str_contains($migration, 'ALTER TABLE categories ADD COLUMN uses_variant_size'),
    'Category schema change must be an idempotent deployment migration.'
);
$assert(!str_contains($categories, 'ALTER TABLE'), 'Admin category requests must never perform DDL.');
foreach ([$schema, $setup] as $source) {
    $assert(str_contains($source, 'uses_variant_size TINYINT(1) NOT NULL DEFAULT 0'), 'Fresh schema and setup must define the category flag.');
}
$checksum = hash_file('sha256', $root . '/' . $migrationPath);
$assert(is_string($checksum) && str_contains($schema, $checksum), 'Fresh schema must baseline the architecture migration checksum.');
$assert(
    str_contains($cron, "'categories' => ['uses_variant_size', 'default_unit_type']")
        && str_contains($cron, "'2026-08-25-architecture-hardening.sql' => 'Architecture hardening'"),
    'Cron readiness must detect a missing category migration or column.'
);

foreach (['RAZORPAY_HTTP_TIMEOUT_SEC', 'RAZORPAY_HTTP_CONNECT_TIMEOUT_SEC', 'RAZORPAY_HTTP_CA_BUNDLE', 'RAZORPAY_HTTP_SKIP_TLS_VERIFY'] as $key) {
    $assert(str_contains($config, "'{$key}'") && str_contains($example, "'{$key}'"), 'Razorpay HTTP setting must be environment-configurable and documented: ' . $key);
}
$assert(
    str_contains($config, "app_config_flag_enabled(\$config['RAZORPAY_HTTP_SKIP_TLS_VERIFY']")
        && str_contains($config, 'RAZORPAY_HTTP_SKIP_TLS_VERIFY must be 0 in production'),
    'Production must fail closed when Razorpay TLS verification is disabled.'
);

$assert(str_contains($observability, 'function app_request_id') && str_contains($observability, 'function app_log'), 'Request-correlated logging helpers are missing.');
$assert(str_contains($init, "header('X-Request-ID: '") && str_contains($init, "app_log('critical', 'php_fatal'"), 'Bootstrap must expose request IDs and correlate fatal logs.');

require_once $root . '/includes/helpers/observability.php';
$GLOBALS['app_request_id'] = '';
$_SERVER['HTTP_X_REQUEST_ID'] = "invalid\r\nheader";
$generatedId = app_request_id();
$assert((bool) preg_match('/^[a-f0-9]{32}$/', $generatedId), 'Invalid inbound request IDs must be replaced.');
$safeContext = app_log_context_value([
    'order_id' => 42,
    'access_token' => 'must-not-appear',
    'nested' => ['password' => 'must-not-appear-either'],
]);
$assert(($safeContext['order_id'] ?? null) === 42, 'Safe structured-log context must be retained.');
$assert(($safeContext['access_token'] ?? null) === '[redacted]', 'Token values must be redacted from structured logs.');
$assert(($safeContext['nested']['password'] ?? null) === '[redacted]', 'Nested secret values must be redacted from structured logs.');
app_log('error', ' Provider Failure ', ['exception_type' => RuntimeException::class]);
unset($_SERVER['HTTP_X_REQUEST_ID'], $GLOBALS['app_request_id']);

foreach ([
    'domain/OrderLifecycle.php',
    'domain/OnlinePaymentMethod.php',
    'domain/CheckoutInput.php',
    'presentation/CommercePresenter.php',
    'security/ExternalUrlPolicy.php',
    'security/UploadPolicy.php',
    'integrations/HttpClientPolicy.php',
    'integrations/JsonHttpClient.php',
    'services/CustomerAuthenticationService.php',
    'services/CustomerSessionMergeService.php',
    'services/CustomerAddressService.php',
    'services/CustomerAccountService.php',
    'services/CategoryAdminService.php',
    'services/CheckoutReadService.php',
    'services/OrderItemSnapshotService.php',
    'services/CheckoutPricingService.php',
    'services/OrderPersistenceService.php',
    'services/CouponService.php',
    'services/OrderReadService.php',
    'services/ProductReadService.php',
    'services/CustomerReadService.php',
] as $module) {
    $assert(str_contains($functions, $module), 'Shared bootstrap must load architecture module: ' . $module);
}
foreach (['OrderLifecycle::canTransition', 'CommercePresenter::orderStatus', 'CommercePresenter::paymentStatus', 'OnlinePaymentMethod::normalize', 'ExternalUrlPolicy::sanitize'] as $delegate) {
    $assert(str_contains($inventoryService, $delegate), 'InventoryService compatibility wrapper is missing: ' . $delegate);
    $assert(str_contains($inventoryHelpers, $delegate), 'Legacy helper compatibility wrapper is missing: ' . $delegate);
}

require_once $root . '/includes/domain/OrderLifecycle.php';
require_once $root . '/includes/domain/OnlinePaymentMethod.php';
require_once $root . '/includes/presentation/CommercePresenter.php';
require_once $root . '/includes/security/ExternalUrlPolicy.php';
$assert(OrderLifecycle::canTransition('pending', 'confirmed'), 'Pending orders must still transition to confirmed.');
$assert(!OrderLifecycle::canTransition('delivered', 'pending'), 'Delivered orders must not regress to pending.');
$assert(OnlinePaymentMethod::normalize(' UPI ') === 'upi', 'Online payment normalization changed during extraction.');
$assert(CommercePresenter::orderStatus('delivered')['class'] === 'success', 'Order badge semantics changed during extraction.');
$assert(ExternalUrlPolicy::sanitize('javascript:alert(1)') === '', 'Unsafe outbound-link schemes must remain rejected.');

$assert(str_contains($paymentService, 'JsonHttpClient::request'), 'Razorpay HTTP must use the shared JSON client.');
$assert(str_contains($bigshipService, 'HttpClientPolicy::curlOptions') && str_contains($bigshipService, 'HttpClientPolicy::urlAllowed'), 'Bigship must use the shared transport security policy.');
$assert(str_contains($httpHelper, 'JsonHttpClient::request'), 'Generic JSON integrations must use the shared JSON client.');
$assert(str_contains($metaPlugin, 'allowed_hosts') && str_contains($metaPlugin, 'graph.facebook.com'), 'Meta CAPI must constrain its provider host.');
$assert(str_contains($codPlugin, 'JsonHttpClient::request'), 'COD Guard outbound JSON must use the shared JSON client.');

require_once $root . '/includes/integrations/HttpClientPolicy.php';
$previousMode = $GLOBALS['_app_mode'] ?? null;
$GLOBALS['_app_mode'] = 'production';
$assert(HttpClientPolicy::urlAllowed('https://api.razorpay.com/v1/orders', ['api.razorpay.com']), 'Approved HTTPS provider URLs must be accepted.');
$assert(!HttpClientPolicy::urlAllowed('http://api.razorpay.com/v1/orders', ['api.razorpay.com']), 'Production provider HTTP must be rejected.');
$assert(!HttpClientPolicy::urlAllowed('https://user:secret@api.razorpay.com/v1/orders', ['api.razorpay.com']), 'Provider URLs containing credentials must be rejected.');
$assert(!HttpClientPolicy::urlAllowed('https://attacker.example/v1/orders', ['api.razorpay.com']), 'Provider host allowlists must be enforced.');
if ($previousMode === null) {
    unset($GLOBALS['_app_mode']);
} else {
    $GLOBALS['_app_mode'] = $previousMode;
}

$uploadConsumers = [
    'admin/about-media.php',
    'admin/categories.php',
    'admin/settings.php',
    'admin/product-media.php',
    'admin/fabric-variants.php',
    'customer/request-return.php',
    'includes/helpers/media.php',
    'plugins/shipping-courier/modules/reference-and-rates.php',
];
foreach ($uploadConsumers as $consumer) {
    $assert(str_contains($read($consumer), 'UploadPolicy::'), 'Upload handling must delegate to UploadPolicy: ' . $consumer);
}
require_once $root . '/includes/security/UploadPolicy.php';
$assert(!UploadPolicy::deleteStoredFile($root, '../composer.json'), 'Upload deletion must reject path traversal.');

$assert(str_contains($customerLogin, 'CustomerSessionMergeService::mergeOnLogin'), 'Customer login must delegate cart/wishlist merging.');
$assert(str_contains($customerLogin, 'CustomerAuthenticationService::authenticate'), 'Customer login must delegate credential verification.');
$assert(!str_contains($customerLogin, 'INSERT INTO customer_cart'), 'Customer login must not own persistence merge SQL.');
$assert(!str_contains($customerLogin, '$conn->prepare'), 'Customer login controller must not own SQL statements.');
$assert(str_contains($customerProfile, 'CustomerAddressService::save') && str_contains($customerProfile, 'CustomerAccountService::updateProfile'), 'Customer profile mutations must delegate to focused services.');
$assert(!str_contains($customerProfile, '$conn->prepare'), 'Customer profile controller must not own SQL statements.');
$assert(str_contains($categories, 'CategoryAdminService::create') && str_contains($categories, 'CategoryAdminService::delete'), 'Admin categories must delegate persistence and transaction rules.');
$assert(!str_contains($categories, '$conn->prepare'), 'Admin category controller must not own SQL statements.');

if ($failures !== []) {
    fwrite(STDERR, "Architecture hardening contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Architecture hardening contracts passed.\n";
