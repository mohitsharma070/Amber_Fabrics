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
            o.payment_status = 'pending'
            AND o.payment_method IN ('razorpay', 'stripe', 'upi')
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
    "SELECT oi.* FROM order_items oi
     WHERE oi.order_id = ?"
);
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$shipping = json_decode($order['shipping_address'] ?? '{}', true) ?: [];
$symbol   = $order['currency'] === 'USD' ? '$' : 'Rs ';

$statusLabels = [
    'pending'    => ['label' => 'Pending',    'class' => 'warning'],
    'confirmed'  => ['label' => 'Confirmed',  'class' => 'info'],
    'processing' => ['label' => 'Processing', 'class' => 'primary'],
    'shipped'    => ['label' => 'Shipped',    'class' => 'primary'],
    'delivered'  => ['label' => 'Delivered',  'class' => 'success'],
    'cancelled'  => ['label' => 'Cancelled',  'class' => 'danger'],
];
$s = $statusLabels[$order['status']] ?? ['label' => ucfirst($order['status']), 'class' => 'secondary'];

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
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <!-- Order items -->
                <div class="surface-panel p-4 mb-4">
                    <h5 class="mb-3">Items Ordered</h5>
                    <?php foreach ($items as $item): ?>
                    <?php
                        $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece'], true) ? (string) $item['unit_type'] : 'meter';
                        $qty = (($item['quantity'] ?? 0) > 0 ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0));
                        $unitPrice = (float) (($item['price'] ?? 0) > 0 ? $item['price'] : ($item['price_per_meter'] ?? 0));
                        $lineTotal = (float) (($item['total'] ?? 0) > 0 ? $item['total'] : ($item['line_total'] ?? 0));
                    ?>
                    <div class="d-flex gap-3 align-items-start mb-3 pb-3 border-bottom">
                        <div style="width:60px;height:60px;background:#eee;border-radius:4px;flex-shrink:0;"></div>
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
                    <?php
                    $canRetry = (int)($order['retry_allowed'] ?? 0) === 1;
                    $canCancel = in_array((string) ($order['status'] ?? ''), ['pending', 'confirmed'], true);
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
                </div>

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

