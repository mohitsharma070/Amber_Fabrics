<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/security/customer-auth.php';

$orderNumber = trim($_GET['order'] ?? '');
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

if ($orderNumber === '') {
    redirect('/index.php');
}

$lastOrder = isset($_SESSION['last_order']) && is_array($_SESSION['last_order'])
    ? $_SESSION['last_order']
    : [];

if ($customerId > 0) {
    $stmt = $conn->prepare(
        "SELECT id, order_number, customer_name, customer_email, total_amount, payment_method, payment_status, order_status, created_at
         FROM orders
         WHERE order_number = ? AND customer_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('si', $orderNumber, $customerId);
} else {
    $lastOrderId = (int) ($lastOrder['id'] ?? 0);
    $lastOrderNumber = trim((string) ($lastOrder['order_number'] ?? ''));
    if ($lastOrderId <= 0 || $lastOrderNumber === '' || !hash_equals($lastOrderNumber, $orderNumber)) {
        flash('error', 'This order confirmation is no longer available in this browser session.');
        redirect('/index.php');
    }
    $stmt = $conn->prepare(
        "SELECT id, order_number, customer_name, customer_email, total_amount, payment_method, payment_status, order_status, created_at
         FROM orders
         WHERE id = ? AND order_number = ?
         LIMIT 1"
    );
    $stmt->bind_param('is', $lastOrderId, $orderNumber);
}
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
$trackingUrl = ExternalUrlPolicy::sanitize($shipment['tracking_url'] ?? '');
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
    <div class="l-container u-text-center">
        <div class="order-success-mark u-mb-3" aria-hidden="true">&#10003;</div>
        <h1>Order Placed Successfully</h1>
        <p class="u-mb-0">Thank you for shopping with <?php echo e(SiteContext::name()); ?>.</p>
    </div>
</section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-justify-center">
            <div class="l-col-md-seven l-col-lg-half">
                <div class="surface-panel u-p-4 u-text-center">
                    <h5 class="u-mb-1">Order Number</h5>
                    <p class="u-text-large u-font-bold u-mb-3"><?php echo e($order['order_number']); ?></p>

                    <div class="u-text-start u-text-small u-mb-3">
                        <div class="u-flex u-justify-between"><span>Name</span><strong><?php echo e($order['customer_name']); ?></strong></div>
                        <div class="u-flex u-justify-between"><span>Email</span><strong><?php echo e($order['customer_email']); ?></strong></div>
                        <div class="u-flex u-justify-between"><span>Total</span><strong><?php echo e(money((float) $order['total_amount'])); ?></strong></div>
                        <div class="u-flex u-justify-between"><span>Payment</span><strong><?php echo e($paymentLabel); ?> (<?php echo e(ucfirst($paymentStatus)); ?>)</strong></div>
                        <div class="u-flex u-justify-between"><span>Order Status</span><strong><?php echo e(ucfirst((string) $order['order_status'])); ?></strong></div>
                    </div>

                    <?php if ($paymentMethod === 'cod' && is_array($codConfirmation) && strtolower((string) ($codConfirmation['status'] ?? '')) === 'pending'): ?>
                    <div class="ui-alert ui-alert--warning u-text-start u-mb-3">
                        Please reply YES to the confirmation message to confirm this COD order, or NO to cancel it.
                    </div>
                    <?php elseif ($paymentMethod === 'cod'): ?>
                    <div class="ui-alert ui-alert--info u-text-start u-mb-3">
                        COD selected. Please keep exact amount ready at delivery.
                    </div>
                    <?php elseif ($paymentStatus === 'paid'): ?>
                    <div class="ui-alert ui-alert--success u-text-start u-mb-3">
                        Online payment received successfully.
                    </div>
                    <?php elseif ($paymentMethod === 'razorpay'): ?>
                    <div class="ui-alert ui-alert--warning u-text-start u-mb-3">
                        Your payment is still being verified. We will email you when its status is updated.
                    </div>
                    <?php endif; ?>

                    <?php if ($customerId <= 0): ?>
                    <div class="ui-alert ui-alert--neutral u-border u-text-start u-mb-3">
                        Save your order number. Confirmation and future updates will be sent to <?php echo e((string) $order['customer_email']); ?>.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($shipment)): ?>
                    <div class="ui-alert ui-alert--neutral u-border u-text-start u-mb-3">
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

                    <div class="u-flex u-gap-2 u-justify-center">
                        <?php if ($paymentMethod === 'razorpay' && $paymentStatus !== 'paid' && (int) ($_SESSION['pending_order_id'] ?? 0) === $orderId): ?>
                            <a href="/payment/razorpay-create.php" class="ui-button ui-button--warning">Retry Payment</a>
                        <?php endif; ?>
                        <?php if ($customerId > 0): ?>
                            <a href="/customer/order-view?id=<?php echo (int) $orderId; ?>" class="ui-button ui-button--outline">View Order</a>
                        <?php endif; ?>
                        <a href="/catalog.php" class="ui-button ui-button--primary">Continue Shopping</a>
                        <a href="/contact.php" class="ui-button ui-button--secondary">Need Help?</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
