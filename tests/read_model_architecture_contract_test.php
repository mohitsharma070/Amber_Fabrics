<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$functions = $read('includes/functions.php');
$orderRead = $read('includes/services/OrderReadService.php');
$productRead = $read('includes/services/ProductReadService.php');
$customerRead = $read('includes/services/CustomerReadService.php');
$orderAccess = $read('includes/services/OrderAccessService.php');
$customerOrders = $read('customer/orders.php');
$customerOrder = $read('customer/order-view.php');
$guestOrder = $read('guest/order.php');
$adminOrder = $read('admin/order-view.php');
$fabric = $read('fabric.php');
$metaPixel = $read('plugins/meta-pixel/plugin.php');
$metaCapi = $read('plugins/meta-capi/plugin.php');
$cart = $read('includes/services/CartService.php');
$sessionMerge = $read('includes/services/CustomerSessionMergeService.php');
$account = $read('includes/services/CustomerAccountService.php');
$international = $read('international-buyers.php');
$backInStock = $read('plugins/back-in-stock-alert/plugin.php');
$abandonedCart = $read('plugins/abandoned-cart-email/plugin.php');
$support = $read('plugins/support-tickets/plugin.php');
$inventory = $read('includes/services/InventoryService.php');

foreach (['OrderReadService.php', 'ProductReadService.php', 'CustomerReadService.php'] as $service) {
    $assert(str_contains($functions, 'services/' . $service), 'Shared bootstrap must load read service: ' . $service);
}
$assert(!str_contains($orderRead, 'begin_transaction') && !str_contains($orderRead, '->commit(') && !str_contains($orderRead, '->rollback('), 'OrderReadService must remain transaction-neutral.');
$assert(str_contains($orderRead, 'o.id = ? AND o.customer_id = ?'), 'Customer order detail reads must enforce ownership in SQL.');
$assert(str_contains($orderRead, 'WHERE o.customer_id = ?') && str_contains($orderRead, 'INTERVAL 30 MINUTE'), 'Customer order lists must preserve ownership and stale-payment filtering.');
$assert(str_contains($orderAccess, 'if (!self::canAccess($orderId))') && str_contains($orderAccess, 'OrderReadService::orderById'), 'Guest order reads must remain behind the established access grant.');
$assert(strpos($adminOrder, 'require_admin()') < strpos($adminOrder, 'OrderReadService::adminOrder'), 'Admin order reads must remain behind require_admin().');

$assert(str_contains($customerOrders, 'OrderReadService::customerOrders') && !str_contains($customerOrders, '$conn->prepare'), 'Customer order list must delegate its SQL.');
foreach ([$customerOrder, $guestOrder] as $endpoint) {
    $assert(!str_contains($endpoint, '$conn->prepare'), 'Customer and guest order detail endpoints must not own SQL.');
}
foreach (['itemsWithImages', 'customerShipment', 'latestCustomerReturn', 'returnItems', 'activity'] as $method) {
    $assert(str_contains($customerOrder, 'OrderReadService::' . $method), 'Customer order detail must delegate read: ' . $method);
}
foreach (['items', 'guestShipment', 'latestGuestReturn', 'returnItems', 'latestReversePickup'] as $method) {
    $assert(str_contains($guestOrder, 'OrderReadService::' . $method), 'Guest order detail must delegate read: ' . $method);
}
foreach (['adminOrder', 'itemsWithImages', 'adminShipment', 'activity'] as $method) {
    $assert(str_contains($adminOrder, 'OrderReadService::' . $method), 'Admin order detail must delegate read: ' . $method);
}

$assert(str_contains($fabric, 'ProductReadService::activeByReference'), 'Product detail must delegate its canonical active-product read.');
$assert(str_contains($metaPixel, 'ProductReadService::analyticsProduct') && str_contains($metaCapi, 'ProductReadService::analyticsProduct'), 'Both Meta integrations must share the same product read.');
$assert(str_contains($cart, 'ProductReadService::unitTypeRows') && str_contains($sessionMerge, 'ProductReadService::unitTypeRows'), 'Cart persistence and login merge must share product unit reads.');
$assert(str_contains($account, 'CustomerReadService::contactById') && str_contains($international, 'CustomerReadService::contactById'), 'Account and international inquiry prefill must share customer contact reads.');
$assert(substr_count($backInStock, 'CustomerReadService::emailById') === 2, 'Back-in-stock paths must share customer email reads.');
$assert(str_contains($abandonedCart, 'CustomerReadService::identityById') && str_contains($support, 'CustomerReadService::identityById'), 'Customer-aware plugins must share identity reads.');

$applicationFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_ends_with($path, '/includes/services/InventoryService.php')) {
        continue;
    }
    $applicationFiles[] = $path;
}
foreach ($applicationFiles as $path) {
    $source = (string) file_get_contents($path);
    $assert(
        !preg_match('/InventoryService::(?:order_status_meta|payment_status_meta|quantity_unit_suffix|safe_external_url)\s*\(/', $source),
        'Application caller must use extracted presenter/security policy directly: ' . $path
    );
}
foreach (['CommercePresenter::orderStatus', 'CommercePresenter::paymentStatus', 'CommercePresenter::quantityUnitSuffix', 'ExternalUrlPolicy::sanitize'] as $delegate) {
    $assert(str_contains($inventory, $delegate), 'InventoryService compatibility wrapper must remain: ' . $delegate);
}

if ($failures !== []) {
    fwrite(STDERR, "Read-model architecture contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Read-model architecture contracts passed.\n";
