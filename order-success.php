<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

$orderNumber = trim($_GET['order'] ?? '');
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

if ($orderNumber === '') {
    redirect('/index.php');
}

$stmt = $conn->prepare(
    "SELECT id, order_number, customer_name, customer_email, total_amount, payment_method, payment_status, order_status, created_at
     FROM orders
     WHERE order_number = ? AND customer_id = ?
     LIMIT 1"
);
$stmt->bind_param('si', $orderNumber, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not found.');
    redirect('/index.php');
}

$shipmentStmt = $conn->prepare(
    "SELECT courier_name, tracking_id, tracking_url, shipped_at, delivered_at
     FROM shipments
     WHERE order_id = ?
     LIMIT 1"
);
$orderId = (int) $order['id'];
$shipmentStmt->bind_param('i', $orderId);
$shipmentStmt->execute();
$shipment = $shipmentStmt->get_result()->fetch_assoc();
$trackingUrl = safe_external_url($shipment['tracking_url'] ?? '');
$paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
$paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
$paymentLabel = ucfirst(str_replace('_', ' ', $paymentMethod));

$metaTitle = 'Order Confirmed | Amber Fabrics';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container text-center">
        <div class="mb-3" style="font-size:3rem;">&#10003;</div>
        <h1>Order Placed Successfully</h1>
        <p class="mb-0">Thank you for shopping with Amber Fabrics.</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                <div class="surface-panel p-4 text-center">
                    <h5 class="mb-1">Order Number</h5>
                    <p class="fs-4 fw-bold mb-3"><?php echo e($order['order_number']); ?></p>

                    <div class="text-start small mb-3">
                        <div class="d-flex justify-content-between"><span>Name</span><strong><?php echo e($order['customer_name']); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Email</span><strong><?php echo e($order['customer_email']); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Total</span><strong>Rs <?php echo number_format((float) $order['total_amount'], 2); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Payment</span><strong><?php echo e($paymentLabel); ?> (<?php echo e(ucfirst($paymentStatus)); ?>)</strong></div>
                        <div class="d-flex justify-content-between"><span>Order Status</span><strong><?php echo e(ucfirst((string) $order['order_status'])); ?></strong></div>
                    </div>

                    <?php if ($paymentMethod === 'cod'): ?>
                    <div class="alert alert-info text-start mb-3">
                        COD selected. Please keep exact amount ready at delivery.
                    </div>
                    <?php elseif ($paymentStatus === 'paid'): ?>
                    <div class="alert alert-success text-start mb-3">
                        Online payment received successfully.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($shipment)): ?>
                    <div class="alert alert-light border text-start mb-3">
                        <strong>Tracking Information</strong><br>
                        Courier: <?php echo e((string) ($shipment['courier_name'] ?? '-')); ?><br>
                        Tracking ID: <?php echo e((string) ($shipment['tracking_id'] ?? '-')); ?><br>
                        <?php if ($trackingUrl !== ''): ?>
                            Track URL: <a href="<?php echo e($trackingUrl); ?>" target="_blank" rel="noopener">Track Shipment</a><br>
                        <?php endif; ?>
                        Shipped At: <?php echo !empty($shipment['shipped_at']) ? e((string) $shipment['shipped_at']) : '-'; ?><br>
                        Delivered At: <?php echo !empty($shipment['delivered_at']) ? e((string) $shipment['delivered_at']) : '-'; ?>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 justify-content-center">
                        <a href="/catalog.php" class="btn btn-primary">Continue Shopping</a>
                        <a href="/contact.php" class="btn btn-outline-secondary">Need Help?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
