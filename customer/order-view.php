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

$itemStmt = $conn->prepare(
    "SELECT oi.*, f.image AS fabric_image
     FROM order_items oi
     LEFT JOIN fabrics f ON f.id = oi.fabric_id
     WHERE oi.order_id = ?"
);
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$shipmentStmt = $conn->prepare(
    "SELECT courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at
     FROM shipments
     WHERE order_id = ?
     LIMIT 1"
);
$shipmentStmt->bind_param('i', $orderId);
$shipmentStmt->execute();
$shipment = $shipmentStmt->get_result()->fetch_assoc() ?: [];
$trackingUrl = safe_external_url((string) ($shipment['tracking_url'] ?? ''));

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

$shipping = json_decode($order['shipping_address'] ?? '{}', true) ?: [];
$symbol   = $order['currency'] === 'USD' ? '$' : 'Rs ';
$taxableAmount = max(0.0, (float) ($order['subtotal'] ?? 0) - (float) ($order['discount_amount'] ?? 0));
$gst = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));

$statusLabels = [
    'pending'    => ['label' => 'Pending',    'class' => 'warning'],
    'confirmed'  => ['label' => 'Confirmed',  'class' => 'info'],
    'processing' => ['label' => 'Processing', 'class' => 'primary'],
    'shipped'    => ['label' => 'Shipped',    'class' => 'primary'],
    'delivered'  => ['label' => 'Delivered',  'class' => 'success'],
    'cancelled'  => ['label' => 'Cancelled',  'class' => 'danger'],
    'refunded'   => ['label' => 'Refunded',   'class' => 'dark'],
];
$effectiveOrderStatus = (string) ($order['order_status'] ?? $order['status'] ?? '');
$s = $statusLabels[$effectiveOrderStatus] ?? ['label' => ucfirst($effectiveOrderStatus), 'class' => 'secondary'];
$isRefundInitiated = in_array(strtolower($effectiveOrderStatus), ['cancelled', 'refunded'], true)
    && in_array(strtolower((string) ($order['payment_method'] ?? '')), ['razorpay', 'upi'], true)
    && strtolower((string) ($order['payment_status'] ?? '')) === 'paid';
$deliveredAtForReturn = trim((string) ($shipment['delivered_at'] ?? ''));
$isWithinReturnWindow = $deliveredAtForReturn !== '' && strtotime($deliveredAtForReturn) >= strtotime('-7 days');
$canRequestReturn = strtolower($effectiveOrderStatus) === 'delivered' && $isWithinReturnWindow && !$returnRequest;

$metaTitle = 'Order ' . e($order['order_number']) . ' | Amber Fabrics';
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
            <a href="/customer/orders.php" class="text-muted small">&larr; Back to My Orders</a>
            <div class="mt-2">
                <a href="/customer/order-invoice.php?id=<?php echo (int) $order['id']; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">View Invoice</a>
            </div>
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
                        <?php if (!empty($item['fabric_image'])): ?>
                            <img src="/images/fabrics/<?php echo e((string) $item['fabric_image']); ?>"
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
                            <div class="text-muted small"><?php echo e(format_quantity_by_unit($qty, $unitType)); ?><?php echo quantity_unit_suffix($unitType); ?> x <?php echo $symbol . number_format($unitPrice, 2); ?><?php echo ($unitType === 'piece' || $unitType === 'set') ? ' each' : '/m'; ?></div>
                        </div>
                        <div class="fw-semibold"><?php echo $symbol . number_format($lineTotal, 2); ?></div>
                    </div>
                    <?php endforeach; ?>

                    <div class="d-flex justify-content-between text-muted small">
                        <span>Subtotal</span>
                        <span><?php echo $symbol . number_format((float)$order['subtotal'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Shipping</span>
                        <span><?php echo $symbol . number_format((float)$order['shipping_cost'], 2); ?></span>
                    </div>
                    <?php if (!empty($gst['enabled'])): ?>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>GST @<?php echo number_format((float) $gst['rate'], 0); ?>% (included)</span>
                        <span><?php echo $symbol . number_format((float) $gst['gst_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top">
                        <span>Total</span>
                        <span><?php echo $symbol . number_format((float)$order['total'], 2); ?> <?php echo e($order['currency']); ?></span>
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
                        Payment: <strong><?php echo $order['payment_status'] === 'paid' ? 'Paid' : ucfirst($order['payment_status']); ?></strong><br>
                        Method: <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
                    </div>
                    <?php if ($isRefundInitiated): ?>
                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        Refund initiated. Amount will be returned to your original payment method as per bank/payment timelines.
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
                    <?php if ($canRequestReturn): ?>
                    <form method="POST" action="/customer/request-return.php" class="mt-2" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
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
                        <div class="mb-2">
                            <label class="form-label small mb-1">Image 1 (required)</label>
                            <input type="file" name="image_1" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Image 2 (required)</label>
                            <input type="file" name="image_2" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="small text-muted mb-2">Return allowed only within 7 days from delivery date.</div>
                        <button type="submit" class="btn btn-outline-secondary w-100">Request Return</button>
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
                        <?php if (!empty($returnRequest['image_1'])): ?>
                            <div class="mt-2">Image 1: <a href="/<?php echo e((string) $returnRequest['image_1']); ?>" target="_blank" rel="noopener noreferrer">View</a></div>
                        <?php endif; ?>
                        <?php if (!empty($returnRequest['image_2'])): ?>
                            <div>Image 2: <a href="/<?php echo e((string) $returnRequest['image_2']); ?>" target="_blank" rel="noopener noreferrer">View</a></div>
                        <?php endif; ?>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>

