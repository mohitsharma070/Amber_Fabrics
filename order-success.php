<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

$orderNumber = trim($_GET['order'] ?? '');

if ($orderNumber === '') {
    redirect('/index.php');
}

if (is_customer_logged_in()) {
    $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_email, total_amount, payment_method, payment_status, order_status, created_at FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1");
    $customerId = (int) $_SESSION['customer_id']; $stmt->bind_param('si', $orderNumber, $customerId); $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
} else {
    $capabilities = (array) ($_SESSION['guest_order_capabilities'] ?? []);
    $order = null;
    foreach (array_keys($capabilities) as $candidateId) {
        $candidateId = (int) $candidateId;
        if ($candidateId <= 0 || !guest_order_access_allowed($conn, $candidateId)) continue;
        $stmt = $conn->prepare("SELECT id, order_number, customer_name, customer_email, total_amount, payment_method, payment_status, order_status, created_at FROM orders WHERE id = ? AND order_number = ? LIMIT 1");
        $stmt->bind_param('is', $candidateId, $orderNumber); $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if ($order) break;
    }
}

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
$trackingUrl = InventoryService::safe_external_url($shipment['tracking_url'] ?? '');
$paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
$paymentStatus = strtolower((string) ($order['payment_status'] ?? 'pending'));
$paymentLabel = ucfirst(str_replace('_', ' ', $paymentMethod));
$codConfirmation = null;
if ($paymentMethod === 'cod' && function_exists('cod_guard_get_confirmation')) {
    $codConfirmation = cod_guard_get_confirmation($conn, $orderId);
}

$metaTitle = ($paymentMethod === 'cod' && is_array($codConfirmation) && strtolower((string) ($codConfirmation['status'] ?? '')) === 'pending')
    ? SiteContext::title('Order Placed')
    : SiteContext::title('Order Confirmed');
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container text-center">
        <div class="mb-3" style="font-size:3rem;">&#10003;</div>
        <h1>Order Placed Successfully</h1>
        <p class="mb-0">Thank you for shopping with <?php echo e(SiteContext::name()); ?>.</p>
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
                        <div class="d-flex justify-content-between"><span>Total</span><strong><?php echo e(money((float) $order['total_amount'])); ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Payment</span><strong><?php echo e($paymentLabel); ?> (<?php echo e(ucfirst($paymentStatus)); ?>)</strong></div>
                        <div class="d-flex justify-content-between"><span>Order Status</span><strong><?php echo e(ucfirst((string) $order['order_status'])); ?></strong></div>
                    </div>

                    <?php if ($paymentMethod === 'cod' && is_array($codConfirmation) && strtolower((string) ($codConfirmation['status'] ?? '')) === 'pending'): ?>
                    <div class="alert alert-warning text-start mb-3">
                        Please reply YES to the confirmation message to confirm this COD order, or NO to cancel it.
                    </div>
                    <?php elseif ($paymentMethod === 'cod'): ?>
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
                        <?php if (is_customer_logged_in()): ?>
                        <a href="/customer/order-view.php?id=<?php echo (int) $orderId; ?>" class="btn btn-outline-primary">View Order</a>
                        <?php elseif ($paymentMethod === 'razorpay' && in_array($paymentStatus, ['pending', 'failed'], true)): ?>
                        <form method="post" action="/retry-payment.php" class="d-inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo (int) $orderId; ?>">
                            <button class="btn btn-outline-primary" type="submit">Retry Payment</button>
                        </form>
                        <?php endif; ?>
                        <a href="/catalog.php" class="btn btn-primary">Continue Shopping</a>
                        <a href="/contact.php" class="btn btn-outline-secondary">Need Help?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
// Successful guest checkout is one-time session access. Failed/pending Razorpay remains available for retry.
if (!is_customer_logged_in() && ($paymentMethod === 'cod' || $paymentStatus === 'paid')) {
    clear_guest_order_capability($conn, $orderId);
}
?>
