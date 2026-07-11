<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

$customerId = (int) $_SESSION['customer_id'];
$orderId    = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT
        o.*,
        c.name AS customer_name,
        c.email AS customer_email,
        (
            o.payment_status IN ('pending', 'failed')
            AND o.order_status IN ('pending', 'confirmed')
            AND o.payment_method IN ('razorpay', 'upi')
            AND o.created_at >= (NOW() - INTERVAL 30 MINUTE)
        ) AS retry_allowed
     FROM orders o JOIN customers c ON c.id = o.customer_id
     WHERE o.id = ? AND o.customer_id = ?"
);
$stmt->bind_param('ii', $orderId, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not found.');
    redirect('/customer/orders.php');
}

$variantImageJoin = order_items_supports_variant($conn)
    ? "LEFT JOIN fabric_variants fv ON fv.id = oi.variant_id"
    : "LEFT JOIN fabric_variants fv ON fv.fabric_id = COALESCE(oi.fabric_id, oi.product_id)
        AND fv.color = oi.color
        AND fv.size = oi.size
        AND fv.is_active = 1";
$itemStmt = $conn->prepare(
    "SELECT oi.*,
            COALESCE(NULLIF(fv.image, ''), NULLIF(f.image, '')) AS product_image
     FROM order_items oi
     LEFT JOIN fabrics f ON f.id = COALESCE(oi.fabric_id, oi.product_id)
     {$variantImageJoin}
     WHERE oi.order_id = ?"
);
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$shipmentSelect = "SELECT courier_name,
                          COALESCE(NULLIF(tracking_id, ''), NULLIF(awb_code, ''), '') AS tracking_id,
                          tracking_url, shipping_cost, shipped_at, delivered_at
                   FROM shipments
                   WHERE order_id = ?
                   LIMIT 1";
$shipmentStmt = $conn->prepare($shipmentSelect);
$shipmentStmt->bind_param('i', $orderId);
$shipmentStmt->execute();
$shipment = $shipmentStmt->get_result()->fetch_assoc() ?: [];
$trackingUrl = InventoryService::safe_external_url((string) ($shipment['tracking_url'] ?? ''));

$returnStmt = $conn->prepare(
    "SELECT id, return_number, status, reason, customer_note, image_1, image_2, admin_note, requested_at, updated_at
     FROM returns
     WHERE order_id = ?
     ORDER BY id DESC
     LIMIT 1"
);
$returnStmt->bind_param('i', $orderId);
$returnStmt->execute();
$returnRequest = $returnStmt->get_result()->fetch_assoc() ?: null;
$returnItems = [];
if ($returnRequest) {
    $riStmt = $conn->prepare(
        "SELECT product_name, unit_type, quantity, line_total, refund_amount
         FROM return_items
         WHERE return_id = ?
         ORDER BY id ASC"
    );
    $rid = (int) ($returnRequest['id'] ?? 0);
    $riStmt->bind_param('i', $rid);
    $riStmt->execute();
    $returnItems = $riStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$activityStmt = $conn->prepare(
    "SELECT action, actor_type, actor_name, details, created_at
     FROM order_activity_logs
     WHERE order_id = ?
     ORDER BY id DESC
     LIMIT 12"
);
$activityStmt->bind_param('i', $orderId);
$activityStmt->execute();
$orderActivity = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orderActivity = apply_filters('order.timeline.events', is_array($orderActivity) ? $orderActivity : [], [
    'audience' => 'customer',
    'order_id' => $orderId,
    'customer_id' => $customerId,
]);
$latestShipmentEvent = null;
foreach ($orderActivity as $ev) {
    $action = strtolower((string) ($ev['action'] ?? ''));
    if (in_array($action, ['shipment_updated', 'order_shipped', 'order_delivered', 'awb_created'], true)) {
        $latestShipmentEvent = $ev;
        break;
    }
}

$shipping = json_decode($order['shipping_address'] ?? '{}', true) ?: [];
if (empty($shipping)) {
    $shipping = [
        'name' => (string) ($order['customer_name'] ?? ''),
        'address' => (string) ($order['address'] ?? ''),
        'city' => (string) ($order['city'] ?? ''),
        'state' => (string) ($order['state'] ?? ''),
        'pincode' => (string) ($order['pincode'] ?? ''),
        'country' => (string) ($order['country'] ?? ''),
        'phone' => (string) ($order['customer_phone'] ?? ''),
        'email' => (string) ($order['customer_email'] ?? ''),
    ];
    $hasAddressBits = false;
    foreach (['address', 'city', 'state', 'pincode', 'country'] as $key) {
        if (trim((string) ($shipping[$key] ?? '')) !== '') {
            $hasAddressBits = true;
            break;
        }
    }
    if (!$hasAddressBits) {
        $shipping = [];
    }
}
$currency = (string) ($order['currency'] ?? 'INR');
$taxableAmount = max(0.0, (float) ($order['subtotal'] ?? 0) - (float) ($order['discount_amount'] ?? 0));
$gst = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));
$displayShipping = (float) (($order['shipping_amount'] ?? 0) > 0 ? $order['shipping_amount'] : ($order['shipping_cost'] ?? 0));
$displayTotal = (float) (($order['total_amount'] ?? 0) > 0 ? $order['total_amount'] : ($order['total'] ?? 0));
$displayDiscount = (float) ($order['discount_amount'] ?? 0);

$effectiveOrderStatus = (string) ($order['order_status'] ?? $order['status'] ?? '');
$s = InventoryService::order_status_meta($effectiveOrderStatus);
$payMeta = InventoryService::payment_status_meta((string) ($order['payment_status'] ?? 'pending'));
$isRefundInitiated = in_array(strtolower($effectiveOrderStatus), ['cancelled', 'refunded'], true)
    && in_array(strtolower((string) ($order['payment_method'] ?? '')), ['razorpay', 'upi'], true)
    && strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
$deliveredAtForReturn = trim((string) ($shipment['delivered_at'] ?? ''));
$isWithinReturnWindow = $deliveredAtForReturn !== '' && strtotime($deliveredAtForReturn) >= strtotime('-7 days');
$canRequestReturn = strtolower($effectiveOrderStatus) === 'delivered' && $isWithinReturnWindow && !$returnRequest;

$metaTitle = 'Order ' . e($order['order_number']) . ' | ' . site_name();
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Order <?php echo e($order['order_number']); ?></h1>
        <p class="mb-0 text-muted">Placed on <?php echo date('d M Y, H:i', strtotime($order['created_at'])); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="mb-3">
            <a href="/customer/orders.php" class="app-back-link">&larr; Back to My Orders</a>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <!-- Order items -->
                <div class="surface-panel p-4 mb-4">
                    <h5 class="mb-3">Items Ordered</h5>
                    <?php foreach ($items as $item): ?>
                    <?php
                        $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $item['unit_type'] : 'meter';
                        $qty = (($item['quantity'] ?? 0) > 0 ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0));
                        $unitPrice = (float) (($item['price'] ?? 0) > 0 ? $item['price'] : ($item['price_per_meter'] ?? 0));
                        $lineTotal = (float) (($item['total'] ?? 0) > 0 ? $item['total'] : ($item['line_total'] ?? 0));
                    ?>
                    <div class="d-flex gap-3 align-items-start mb-3 pb-3 border-bottom">
                        <?php if (!empty($item['product_image'])): ?>
                            <img src="/images/fabrics/<?php echo e((string) $item['product_image']); ?>"
                                 alt="<?php echo e((string) ($item['fabric_name_snapshot'] ?? 'Product')); ?>"
                                 style="width:60px;height:60px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                        <?php else: ?>
                            <div style="width:60px;height:60px;background:#eee;border-radius:4px;flex-shrink:0;"></div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo e($item['fabric_name_snapshot']); ?></div>
                            <?php if ($item['fabric_sku_snapshot']): ?>
                                <div class="text-muted small">SKU: <?php echo e($item['fabric_sku_snapshot']); ?></div>
                            <?php endif; ?>
                            <div class="text-muted small">
                                <?php echo e(format_quantity_by_unit($qty, $unitType)); ?><?php echo InventoryService::quantity_unit_suffix($unitType); ?> x <?php echo e(money($unitPrice, $currency)); ?><?php echo ($unitType === 'piece' || $unitType === 'set') ? ' each' : '/m'; ?>
                                <?php if ($unitType === 'set' && (int) ($item['units_per_set'] ?? 0) > 0): ?>
                                    | <?php echo (int) round($qty); ?> sets x <?php echo (int) $item['units_per_set']; ?> = <?php echo (int) round($qty) * (int) $item['units_per_set']; ?> pieces
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="fw-semibold"><?php echo e(money($lineTotal, $currency)); ?></div>
                    </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between text-muted small">
                        <span>Subtotal</span>
                        <span><?php echo e(money((float) $order['subtotal'], $currency)); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Shipping</span>
                        <span><?php echo e(money($displayShipping, $currency)); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Discount</span>
                        <span>- <?php echo e(money($displayDiscount, $currency)); ?></span>
                    </div>
                    <?php if (!empty($gst['enabled'])): ?>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Including GST</span>
                        <span><?php echo e(money((float) $gst['gst_amount'], $currency)); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top">
                        <span>Total</span>
                        <span><?php echo e(money($displayTotal, $currency, true)); ?></span>
                    </div>
                </div>

                <?php if ($order['notes']): ?>
                <div class="surface-panel p-4 mb-4">
                    <h6 class="mb-2">Your Notes</h6>
                    <p class="mb-0 text-muted"><?php echo e($order['notes']); ?></p>
                </div>
                <?php endif; ?>

                <div class="surface-panel p-4 mb-4">
                    <h6 class="mb-2">Shipment Details</h6>
                    <?php if (empty($shipment)): ?>
                        <p class="mb-0 text-muted small">Not shipped yet. Tracking details will appear here after dispatch.</p>
                    <?php else: ?>
                    <div class="text-muted small">
                        <div>Courier: <strong><?php echo !empty($shipment['courier_name']) ? e((string) $shipment['courier_name']) : '-'; ?></strong></div>
                        <div>Tracking ID: <strong><?php echo !empty($shipment['tracking_id']) ? e((string) $shipment['tracking_id']) : '-'; ?></strong></div>
                        <div>Shipped At: <strong><?php echo !empty($shipment['shipped_at']) ? e((string) $shipment['shipped_at']) : '-'; ?></strong></div>
                        <div>Delivered At: <strong><?php echo !empty($shipment['delivered_at']) ? e((string) $shipment['delivered_at']) : '-'; ?></strong></div>
                    </div>
                    <?php if ($trackingUrl !== ''): ?>
                        <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm mt-3">Track Package</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Status -->
                <div class="surface-panel p-4 mb-4">
                    <h6 class="mb-2">Order Status</h6>
                    <span class="badge bg-<?php echo $s['class']; ?> fs-6 px-3 py-2"><?php echo $s['label']; ?></span>
                    <div class="mt-3 text-muted small">
                        Payment: <strong><?php echo e($payMeta['label']); ?></strong><br>
                        Method: <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
                    </div>
                    <?php if ($isRefundInitiated): ?>
                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        Refund initiated. Amount will be returned to your original payment method as per bank/payment timelines.
                    </div>
                    <?php endif; ?>
                    <?php if ($latestShipmentEvent): ?>
                    <div class="alert alert-primary mt-3 mb-0 py-2 small">
                        Shipment Update: <?php echo e((string) ($latestShipmentEvent['display_details'] ?? $latestShipmentEvent['details'] ?? 'Your shipment has been updated.')); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (strtolower((string) ($order['payment_status'] ?? '')) === 'refunded'): ?>
                    <div class="alert alert-success mt-3 mb-0 py-2 small">
                        Refund processed on payment gateway. Bank/card/UPI credit can take 2-7 working days (sometimes up to 10 working days).
                    </div>
                    <?php endif; ?>
                    <?php
                    $canRetry = (int)($order['retry_allowed'] ?? 0) === 1;
                    $canCancel = in_array($effectiveOrderStatus, ['pending', 'confirmed'], true);
                    ?>
                    <?php if ($canRetry): ?>
                    <form method="POST" action="/retry-payment.php" class="mt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <button type="submit" class="btn btn-warning w-100">Retry Payment</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canCancel): ?>
                    <form method="POST" action="/customer/cancel-order.php" class="mt-2" onsubmit="return confirm('Cancel this order?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger w-100">Cancel Order</button>
                    </form>
                    <?php endif; ?>
                    <?php
                    $showInvoice = strtolower((string) ($order['payment_status'] ?? 'pending')) === 'paid';
                    ?>
                    <?php if ($showInvoice): ?>
                    <a href="/invoice.php?order=<?php echo e($order['order_number']); ?>" target="_blank"
                       class="btn btn-outline-secondary w-100 mt-2">
                        View / Download Invoice
                    </a>
                    <?php endif; ?>
                    <?php if ($canRequestReturn): ?>
                    <form method="POST" action="/customer/request-return.php" class="mt-2" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                        <div class="small text-muted mb-2">Step 1: Select items and quantities</div>
                        <div class="mb-2 p-2 border rounded bg-light">
                            <div class="small fw-semibold mb-2">Select items/qty to return</div>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $riOrderItemId = (int) ($item['id'] ?? 0);
                                $riUnitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $item['unit_type'] : 'meter';
                                $riQty = (($item['quantity'] ?? 0) > 0 ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0));
                                if ($riQty <= 0 || $riOrderItemId <= 0) { continue; }
                                $riDefaultQty = '0';
                                $riIsFixedSingleQty = ($riUnitType === 'piece' || $riUnitType === 'set') && (int) round($riQty) === 1;
                                if ($riIsFixedSingleQty) {
                                    $riDefaultQty = '1';
                                }
                                ?>
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <div class="small text-muted"><?php echo e((string) ($item['fabric_name_snapshot'] ?? 'Item')); ?> (max <?php echo e(format_quantity_by_unit($riQty, $riUnitType)); ?><?php echo InventoryService::quantity_unit_suffix($riUnitType); ?>)</div>
                                    <input type="number"
                                           class="form-control form-control-sm"
                                           name="return_qty[<?php echo $riOrderItemId; ?>]"
                                           min="0"
                                           max="<?php echo e((string) $riQty); ?>"
                                           step="<?php echo $riUnitType === 'meter' ? '0.01' : '1'; ?>"
                                           value="<?php echo e($riDefaultQty); ?>"
                                           <?php echo $riIsFixedSingleQty ? 'readonly aria-readonly="true"' : ''; ?>
                                           style="max-width:110px;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted mb-2">Step 2: Tell us why</div>
                        <div class="mb-2">
                            <select name="reason" class="form-select form-select-sm" required>
                                <option value="">Select return reason</option>
                                <option value="Damaged Item">Damaged Item</option>
                                <option value="Wrong Item Delivered">Wrong Item Delivered</option>
                                <option value="Quality Not as Expected">Quality Not as Expected</option>
                                <option value="Size/Specification Issue">Size/Specification Issue</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <textarea name="customer_note" class="form-control form-control-sm" rows="2" placeholder="Optional note"></textarea>
                        </div>
                        <div class="small text-muted mb-2">Step 3: Upload issue photos</div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Image 1 (required)</label>
                            <input type="file" name="image_1" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Image 2 (required)</label>
                            <input type="file" name="image_2" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="small text-muted mb-2">Step 4: Review and submit. Return allowed only within 7 days from delivery date.</div>
                        <button type="submit" class="btn btn-outline-secondary w-100">Submit Return Request</button>
                    </form>
                    <?php endif; ?>
                    <?php if (strtolower($effectiveOrderStatus) === 'delivered' && !$returnRequest && !$isWithinReturnWindow): ?>
                    <div class="alert alert-secondary mt-2 mb-0 py-2 small">
                        Return window closed (7 days from delivery).
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($returnRequest): ?>
                <div class="surface-panel p-4 mb-4">
                    <h6 class="mb-2">Return Request</h6>
                    <div class="small text-muted">
                        <div>Return #: <strong><?php echo e((string) $returnRequest['return_number']); ?></strong></div>
                        <div>Status: <strong><?php echo e(strtoupper(str_replace('_', ' ', (string) $returnRequest['status']))); ?></strong></div>
                        <div>Reason: <strong><?php echo e((string) $returnRequest['reason']); ?></strong></div>
                        <div>Requested At: <strong><?php echo e((string) $returnRequest['requested_at']); ?></strong></div>
                        <?php if (!empty($returnRequest['customer_note'])): ?>
                            <div>Note: <?php echo e((string) $returnRequest['customer_note']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($returnRequest['admin_note'])): ?>
                            <div>Admin Note: <?php echo e((string) $returnRequest['admin_note']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($returnItems)): ?>
                            <div class="mt-2">Items:</div>
                            <?php foreach ($returnItems as $ri): ?>
                                <div>- <?php echo e((string) ($ri['product_name'] ?? 'Item')); ?>: <?php echo e(format_quantity_by_unit((float) ($ri['quantity'] ?? 0), (string) ($ri['unit_type'] ?? 'meter'))); ?><?php echo InventoryService::quantity_unit_suffix((string) ($ri['unit_type'] ?? 'meter')); ?> (<?php echo e(money((float) ($ri['line_total'] ?? 0), $currency)); ?>)</div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($returnRequest['image_1'])): ?>
                            <div class="mt-2">Image 1: <a href="/<?php echo e((string) $returnRequest['image_1']); ?>" target="_blank" rel="noopener noreferrer">View</a></div>
                        <?php endif; ?>
                        <?php if (!empty($returnRequest['image_2'])): ?>
                            <div>Image 2: <a href="/<?php echo e((string) $returnRequest['image_2']); ?>" target="_blank" rel="noopener noreferrer">View</a></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($orderActivity)): ?>
                <div class="surface-panel p-4 mb-4">
                    <h6 class="mb-3">Order Timeline</h6>
                    <div class="order-timeline">
                        <?php foreach ($orderActivity as $ev): ?>
                        <div class="order-timeline-item">
                            <div class="order-timeline-dot"></div>
                            <div class="order-timeline-body">
                                <?php $timelineActionLabel = (string) ($ev['display_action'] ?? ucwords(str_replace('_', ' ', (string) ($ev['action'] ?? 'update')))); ?>
                                <div class="d-flex justify-content-between gap-2 flex-wrap">
                                    <strong><?php echo e($timelineActionLabel); ?></strong>
                                    <span class="text-muted small"><?php echo date('d M Y, h:i A', strtotime((string) ($ev['created_at'] ?? 'now'))); ?></span>
                                </div>
                                <?php if (!empty($ev['display_details']) || !empty($ev['details'])): ?>
                                    <div class="text-muted small"><?php echo e((string) ($ev['display_details'] ?? $ev['details'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Shipping address -->
                <?php if (!empty($shipping)): ?>
                <div class="surface-panel p-4">
                    <h6 class="mb-2">Shipping Address</h6>
                    <address class="mb-0 text-muted small" style="font-style:normal;">
                        <?php echo e($shipping['name']    ?? ''); ?><br>
                        <?php echo e($shipping['address'] ?? ''); ?><br>
                        <?php echo e($shipping['city']    ?? ''); ?>
                        <?php if (!empty($shipping['state'])): ?>, <?php echo e($shipping['state']); ?><?php endif; ?>
                        <?php if (!empty($shipping['pincode'])): ?> - <?php echo e($shipping['pincode']); ?><?php endif; ?><br>
                        <?php echo e($shipping['country'] ?? ''); ?><br>
                        <?php if (!empty($shipping['phone'])): ?>&#128222; <?php echo e($shipping['phone']); ?><br><?php endif; ?>
                        <?php if (!empty($shipping['email'])): ?>Email: <?php echo e($shipping['email']); ?><?php endif; ?>
                    </address>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php do_action('customer.order_view.after', [
    'conn' => $conn,
    'order' => $order,
    'order_id' => $orderId,
    'customer_id' => $customerId,
    'items' => $items,
    'shipment' => $shipment,
    'return_request' => $returnRequest,
    'order_activity' => $orderActivity,
]); ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
