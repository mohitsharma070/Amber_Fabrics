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

$placeOrder = $read('place-order.php');
$functions = $read('includes/functions.php');
$persistence = $read('includes/services/OrderPersistenceService.php');

$assert(str_contains($functions, 'services/OrderPersistenceService.php'), 'Shared bootstrap must load the order persistence service.');
foreach ([
    'insertOrder',
    'saveDeliveryEstimate',
    'insertItems',
    'insertPendingPayment',
    'markZeroAmountPaid',
    'upsertQuotedShipment',
] as $method) {
    $assert(str_contains($placeOrder, 'OrderPersistenceService::' . $method), 'Order endpoint must delegate persistence operation: ' . $method);
}

$assert(!preg_match('/INSERT\s+INTO\s+(orders|order_items|payments|shipments)\b/i', $placeOrder), 'Order endpoint must not contain extracted INSERT statements.');
$assert(!str_contains($persistence, 'begin_transaction') && !str_contains($persistence, '->commit(') && !str_contains($persistence, '->rollback('), 'Order persistence service must remain transaction-neutral.');
$assert(str_contains($placeOrder, '$conn->begin_transaction()') && str_contains($placeOrder, '$conn->commit()') && str_contains($placeOrder, '$conn->rollback()'), 'Order endpoint must retain transaction ownership and rollback handling.');
$assert(str_contains($placeOrder, 'InventoryService::reserve_order_inventory') && str_contains($placeOrder, 'CouponService::reserveForOrder'), 'Inventory and coupon reservation must remain in the transaction coordinator.');
$assert(str_contains($placeOrder, 'do_action(\'order.after_create\'') && str_contains($placeOrder, 'OutboxService::enqueueOrderAfterCommit'), 'Hooks and durable side-effect enqueue must remain in the transaction coordinator.');

$transactionStart = strpos($placeOrder, '$conn->begin_transaction()');
$orderInsert = strpos($placeOrder, 'OrderPersistenceService::insertOrder');
$itemInsert = strpos($placeOrder, 'OrderPersistenceService::insertItems');
$inventoryReservation = strpos($placeOrder, 'InventoryService::reserve_order_inventory');
$couponReservation = strpos($placeOrder, 'CouponService::reserveForOrder');
$outboxEnqueue = strpos($placeOrder, 'OutboxService::enqueueOrderAfterCommit');
$transactionCommit = strpos($placeOrder, '$conn->commit()');
$assert(
    $transactionStart !== false
        && $orderInsert !== false
        && $itemInsert !== false
        && $inventoryReservation !== false
        && $couponReservation !== false
        && $outboxEnqueue !== false
        && $transactionCommit !== false
        && $transactionStart < $orderInsert
        && $orderInsert < $itemInsert
        && $itemInsert < $inventoryReservation
        && $inventoryReservation < $couponReservation
        && $couponReservation < $outboxEnqueue
        && $outboxEnqueue < $transactionCommit,
    'Order, inventory, coupon, and outbox work must remain inside the original transaction order.'
);

foreach ([
    'INSERT INTO orders',
    'INSERT INTO order_items',
    'INSERT INTO payments',
    'INSERT INTO shipments',
    'PaymentService::orders_structured_financial_columns_ready',
    'order_items_supports_variant',
    'order_items_supports_tax_snapshot',
    'order_items_supports_cost_snapshot',
    'Zero-amount order auto-confirmed. No payment collection required.',
] as $contract) {
    $assert(str_contains($persistence, $contract), 'Extracted persistence compatibility is missing: ' . $contract);
}

$assert(substr_count($persistence, 'INSERT INTO order_items') === 8, 'All eight legacy order-item schema combinations must remain supported.');

if ($failures !== []) {
    fwrite(STDERR, "Order persistence architecture contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Order persistence architecture contracts passed.\n";
