<?php
require_once __DIR__ . '/../includes/init.php';

$orderId = (int) ($_GET['id'] ?? OrderAccessService::guestOrderId());
$order = OrderAccessService::order($conn, $orderId);
if (!$order) {
    flash('error', 'Your secure order session expired.');
    redirect('/guest/order-access');
}

$items = OrderReadService::items($conn, $orderId);
$shipment = OrderReadService::guestShipment($conn, $orderId);
$returnRequest = OrderReadService::latestGuestReturn($conn, $orderId);
$returnItems = [];
$reversePickup = null;
if ($returnRequest) {
    $returnId = (int) $returnRequest['id'];
    $returnItems = OrderReadService::returnItems($conn, $returnId);
    $reversePickup = OrderReadService::latestReversePickup($conn, $returnId);
}

$tracking = ExternalUrlPolicy::sanitize((string) ($shipment['tracking_url'] ?? ''));
$reverseTracking = ExternalUrlPolicy::sanitize((string) ($reversePickup['tracking_url'] ?? ''));
$status = CommercePresenter::orderStatus((string) $order['order_status']);
$canCancel = in_array((string) $order['order_status'], ['pending', 'confirmed'], true);
$canRetry = in_array((string) $order['payment_status'], ['pending', 'failed'], true)
    && $order['payment_method'] === 'razorpay' && strtotime((string) $order['created_at']) >= time() - 1800;
$returnEligible = (string) $order['order_status'] === 'delivered'
    && return_request_is_eligible((string) ($shipment['delivered_at'] ?? '')) && !$returnRequest;
$metaTitle = SiteContext::title('Order ' . $order['order_number']);
include __DIR__ . '/../includes/header.php';
?>
<div class="l-container u-pt-3 u-text-end"><a class="ui-button ui-button--small ui-button--outline" href="/guest/support?order_id=<?php echo $orderId; ?>">Order Support</a></div>
<section class="page-hero"><div class="l-container"><h1>Order <?php echo e((string) $order['order_number']); ?></h1><p class="u-mb-0">Secure guest order management</p></div></section>
<section class="section-block"><div class="l-container"><div class="l-grid l-grid--12 u-gap-4"><div class="l-col-lg-eight">
<div class="surface-panel u-p-4"><h5>Items</h5><?php foreach ($items as $item): ?><div class="u-flex u-justify-between u-border-bottom u-py-2"><span><?php echo e((string) ($item['fabric_name_snapshot'] ?? $item['product_name'] ?? 'Product')); ?></span><strong><?php echo e(money((float) ($item['line_total'] ?? $item['total'] ?? 0), (string) $order['currency'])); ?></strong></div><?php endforeach; ?><div class="u-flex u-justify-between u-pt-3"><strong>Total</strong><strong><?php echo e(money((float) ($order['total_amount'] ?: $order['total']), (string) $order['currency'], true)); ?></strong></div></div>

<?php if ($returnRequest): ?>
<div class="surface-panel u-p-4 u-mt-4"><h5>Refund Return</h5><dl class="l-grid l-grid--12 u-text-small u-mb-3"><dt class="l-col-sm-third">Return number</dt><dd class="l-col-sm-eight"><?php echo e((string) $returnRequest['return_number']); ?></dd><dt class="l-col-sm-third">Status</dt><dd class="l-col-sm-eight"><?php echo e(strtoupper(str_replace('_', ' ', (string) $returnRequest['status']))); ?></dd><dt class="l-col-sm-third">Reason</dt><dd class="l-col-sm-eight"><?php echo e((string) $returnRequest['reason']); ?></dd><?php if (!empty($returnRequest['admin_note'])): ?><dt class="l-col-sm-third">Store update</dt><dd class="l-col-sm-eight"><?php echo e((string) $returnRequest['admin_note']); ?></dd><?php endif; ?><?php if ((float) $returnRequest['refund_amount'] > 0): ?><dt class="l-col-sm-third">Refund amount</dt><dd class="l-col-sm-eight"><?php echo e(money((float) $returnRequest['refund_amount'])); ?></dd><?php endif; ?></dl>
<?php foreach ($returnItems as $returnItem): ?><div class="u-flex u-justify-between u-border-top u-py-2 u-text-small"><span><?php echo e((string) $returnItem['product_name']); ?> × <?php echo e(format_quantity_by_unit((float) $returnItem['quantity'], (string) $returnItem['unit_type'])); ?><?php echo e(CommercePresenter::quantityUnitSuffix((string) $returnItem['unit_type'])); ?></span><span><?php echo e(money((float) $returnItem['line_total'])); ?></span></div><?php endforeach; ?>
<?php if ($reversePickup): ?><div class="ui-alert ui-alert--neutral u-border u-mt-3 u-mb-0 u-text-small"><strong>Pickup:</strong> <?php echo e(strtoupper(str_replace('_', ' ', (string) ($reversePickup['provider_status'] ?: $reversePickup['initialization_status'])))); ?><?php if (!empty($reversePickup['tracking_id'])): ?><br>Tracking: <?php echo e((string) $reversePickup['tracking_id']); ?><?php endif; ?><?php if ($reverseTracking): ?><br><a href="<?php echo e($reverseTracking); ?>" target="_blank" rel="noopener noreferrer">Track return pickup</a><?php endif; ?></div><?php endif; ?></div>
<?php elseif ($returnEligible): ?>
<div class="surface-panel u-p-4 u-mt-4"><h5>Request a Refund Return</h5><p class="u-text-small u-text-muted">Available within <?php echo return_request_window_days(); ?> calendar days of confirmed delivery.</p><form method="post" action="/customer/request-return.php" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="order_id" value="<?php echo $orderId; ?>"><?php foreach ($items as $item): ?><label class="ui-label u-text-small"><?php echo e((string) ($item['fabric_name_snapshot'] ?? 'Product')); ?> quantity</label><input class="ui-input u-mb-2" type="number" step="<?php echo ($item['unit_type'] ?? 'meter') === 'meter' ? '0.01' : '1'; ?>" min="0" max="<?php echo e((string) (($item['quantity'] ?? 0) > 0 ? $item['quantity'] : $item['quantity_meters'])); ?>" name="return_qty[<?php echo (int) $item['id']; ?>]" value="0"><?php endforeach; ?><select class="ui-select u-mb-2" name="reason" required><option value="">Reason</option><option>Damaged Item</option><option>Wrong Item Delivered</option><option>Quality Not as Expected</option><option>Other</option></select><textarea class="ui-input u-mb-2" name="customer_note" placeholder="Optional note"></textarea><input class="ui-input u-mb-2" type="file" name="image_1" accept="image/jpeg,image/png,image/webp" required><input class="ui-input u-mb-2" type="file" name="image_2" accept="image/jpeg,image/png,image/webp" required><button class="ui-button ui-button--secondary">Submit Refund Return</button></form></div>
<?php elseif ((string) $order['order_status'] === 'delivered'): ?><div class="ui-alert ui-alert--neutral u-mt-4">The <?php echo return_request_window_days(); ?>-day refund return window is closed.</div><?php endif; ?>
</div><div class="l-col-lg-third"><div class="surface-panel u-p-4"><h5>Status</h5><span class="ui-badge ui-badge--<?php echo e(ui_tone((string) $status['class'])); ?>"><?php echo e((string) $status['label']); ?></span><p class="u-text-small u-text-muted u-mt-3">Estimated delivery: <?php echo e(DeliveryEstimateService::formatRange($order['estimated_delivery_start'] ?? null, $order['estimated_delivery_end'] ?? null) ?: 'Shared after dispatch'); ?></p><?php if ($tracking): ?><a class="ui-button ui-button--outline u-w-full u-mb-2" href="<?php echo e($tracking); ?>" target="_blank" rel="noopener">Track Package</a><?php endif; ?><?php if (strtolower((string) $order['payment_status']) === 'paid'): ?><a class="ui-button ui-button--secondary u-w-full u-mb-2" href="/invoice.php?order=<?php echo e((string) $order['order_number']); ?>" target="_blank">Invoice</a><?php endif; ?><?php if ($canRetry): ?><form method="post" action="/guest/retry-payment.php"><?php echo csrf_field(); ?><input type="hidden" name="order_id" value="<?php echo $orderId; ?>"><button class="ui-button ui-button--warning u-w-full u-mb-2">Retry Payment</button></form><?php endif; ?><?php if ($canCancel): ?><form method="post" action="/guest/cancel-order.php" data-confirm="Cancel this order?"><?php echo csrf_field(); ?><input type="hidden" name="order_id" value="<?php echo $orderId; ?>"><button class="ui-button ui-button--danger-outline u-w-full">Cancel Order</button></form><?php endif; ?><a class="ui-button ui-button--link u-w-full u-mt-2" href="/contact?order=<?php echo e((string) $order['order_number']); ?>">Get support</a></div></div></div></div></section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
