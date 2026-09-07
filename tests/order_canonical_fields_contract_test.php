<?php
if (PHP_SAPI !== 'cli') { exit(2); }

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { $failures[] = $message; }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$readService = $read('includes/services/OrderReadService.php');
$assert(strpos($readService, 'o.total_amount') !== false, 'Customer order list must select canonical total_amount.');
$assert(strpos($readService, 'o.order_notes') !== false, 'Customer order list must select canonical order_notes.');
$assert(strpos($readService, 'o.total,') === false, 'Customer order list must not select legacy orders.total.');
$assert(strpos($readService, 'o.notes,') === false, 'Customer order list must not select legacy orders.notes.');
$assert(strpos($readService, 'o.status,') === false, 'Customer order list must not select legacy orders.status.');

foreach (['customer/order-view.php', 'customer/orders.php', 'guest/order.php', 'includes/services/EmailService.php'] as $path) {
    $source = $read($path);
    foreach (['total', 'shipping_cost', 'status', 'notes'] as $field) {
        $assert(strpos($source, "\$order['{$field}']") === false, $path . ' must not read legacy orders.' . $field . '.');
        if ($path === 'customer/orders.php') {
            $assert(strpos($source, "\$o['{$field}']") === false, $path . ' must not read legacy orders.' . $field . '.');
        }
    }
}

$assert(strpos($read('place-order.php'), "'order_notes' => \$orderNotesWithCoupon") !== false, 'Checkout must pass canonical order_notes into persistence.');
$assert(strpos($read('plugins/shipping-courier/modules/shipment-lifecycle.php'), 'OrderFieldCompatibilityService::legacyStatus') !== false, 'Courier status compatibility mapping must be centralized.');

if ($failures) {
    fwrite(STDERR, "Canonical order field contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Canonical order field contract tests passed.\n";
