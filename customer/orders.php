<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

$customerId = (int) $_SESSION['customer_id'];

$stmt = $conn->prepare(
    "SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_status,
        o.payment_status,
        o.payment_method,
        o.currency,
        o.total,
        o.notes,
        o.created_at,
        (
            SELECT r.status
            FROM returns r
            WHERE r.order_id = o.id
            ORDER BY r.id DESC
            LIMIT 1
        ) AS return_status,
        (
            SELECT r.return_number
            FROM returns r
            WHERE r.order_id = o.id
            ORDER BY r.id DESC
            LIMIT 1
        ) AS return_number,
        COALESCE(SUM(CASE
            WHEN oi.quantity IS NOT NULL AND oi.quantity > 0 THEN oi.quantity
            WHEN oi.quantity_meters IS NOT NULL AND oi.quantity_meters > 0 THEN oi.quantity_meters
            ELSE 0
        END), 0) AS total_qty,
        (
            o.payment_status IN ('pending', 'failed')
            AND o.order_status IN ('pending', 'confirmed')
            AND o.payment_method IN ('razorpay', 'upi')
            AND o.created_at >= (NOW() - INTERVAL 30 MINUTE)
        ) AS retry_allowed
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
      WHERE o.customer_id = ?
        AND NOT (
            o.order_status = 'pending'
            AND
            o.payment_status = 'pending'
            AND o.payment_method IN ('razorpay', 'upi')
            AND o.created_at < (NOW() - INTERVAL 30 MINUTE)
        )
     GROUP BY o.id, o.order_number, o.status, o.order_status, o.payment_status, o.payment_method, o.currency, o.total, o.notes, o.created_at
     ORDER BY o.created_at DESC"
);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statusLabels = [
    'pending'    => ['label' => 'Pending',    'class' => 'warning'],
    'confirmed'  => ['label' => 'Confirmed',  'class' => 'info'],
    'processing' => ['label' => 'Processing', 'class' => 'primary'],
    'shipped'    => ['label' => 'Shipped',    'class' => 'primary'],
    'delivered'  => ['label' => 'Delivered',  'class' => 'success'],
    'cancelled'  => ['label' => 'Cancelled',  'class' => 'danger'],
];

function order_failure_reason(string $notes): string
{
    $lines = preg_split("/\r\n|\n|\r/", $notes);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '' && stripos($line, 'Razorpay payment failed') === 0) {
            return $line;
        }
    }
    return '';
}

function order_failure_message_for_customer(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    $reason = $raw;
    if (preg_match('/reason:\s*(.+)$/i', $raw, $m)) {
        $reason = trim((string) $m[1]);
    }

    if (stripos($reason, 'International cards are not supported') !== false) {
        return 'Payment failed: This card type is not enabled for this merchant. Please use UPI/COD or another card.';
    }

    return 'Payment failed: ' . $reason;
}

function order_refund_initiated(string $orderStatus, string $paymentMethod, string $paymentStatus): bool
{
    return in_array($orderStatus, ['cancelled', 'refunded'], true)
        && in_array($paymentMethod, ['razorpay', 'upi'], true)
        && $paymentStatus === 'paid';
}

$metaTitle = 'My Orders | Amber Fabrics';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="container"><h1>My Orders</h1></div>
</section>

<section class="section-block">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <p class="text-muted mb-0"><?php echo count($orders); ?> order<?php echo count($orders) !== 1 ? 's' : ''; ?></p>
        </div>

        <?php if (empty($orders)): ?>
            <div class="text-center py-5">
                <p class="text-muted">You haven't placed any orders yet.</p>
                <a href="/catalog.php" class="btn btn-primary">Browse Fabrics</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o):
                        $effectiveOrderStatus = (string) ($o['order_status'] ?? $o['status'] ?? '');
                        $s      = $statusLabels[$effectiveOrderStatus] ?? ['label' => ucfirst($effectiveOrderStatus), 'class' => 'secondary'];
                        $symbol = $o['currency'] === 'USD' ? '$' : '&#8377;';
                        $totalQty = (float) ($o['total_qty'] ?? 0);
                        $canRetry = (int)($o['retry_allowed'] ?? 0) === 1;
                        $canCancel = in_array($effectiveOrderStatus, ['pending', 'confirmed'], true);
                        $isRefundInitiated = order_refund_initiated(
                            strtolower($effectiveOrderStatus),
                            strtolower((string) ($o['payment_method'] ?? '')),
                            strtolower((string) ($o['payment_status'] ?? ''))
                        );
                        $returnStatus = strtolower((string) ($o['return_status'] ?? ''));
                    ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($o['order_number']); ?></td>
                            <td class="text-muted small"><?php echo date('d M Y', strtotime($o['created_at'])); ?></td>
                            <td class="text-muted small"><?php echo e(format_meter_quantity($totalQty)); ?> total</td>
                            <td><?php echo $symbol . number_format((float) $o['total'], 2); ?> <?php echo e($o['currency']); ?></td>
                            <td class="text-muted small"><?php echo ucfirst(str_replace('_', ' ', $o['payment_method'])); ?></td>
                            <td><span class="badge bg-<?php echo $s['class']; ?>"><?php echo $s['label']; ?></span></td>
                            <td class="d-flex gap-2 flex-wrap">
                                <a href="/customer/order-view.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                <?php if ($canRetry): ?>
                                <form method="POST" action="/retry-payment.php" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">Retry Payment</button>
                                </form>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                <form method="POST" action="/customer/cancel-order.php" class="d-inline" onsubmit="return confirm('Cancel this order?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel Order</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($returnStatus !== ''): ?>
                        <tr>
                            <td colspan="7" class="small pt-0 border-0">
                                <div class="alert alert-secondary py-2 px-3 mb-0" role="alert">
                                    Return <?php echo e((string) ($o['return_number'] ?? '')); ?>: <?php echo e(strtoupper(str_replace('_', ' ', $returnStatus))); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php
                            $failureReason = '';
                            if (($o['payment_status'] ?? '') === 'failed') {
                                $failureReason = order_failure_reason((string) ($o['notes'] ?? ''));
                            }
                        ?>
                        <?php if ($failureReason !== ''): ?>
                        <?php $failureMessage = order_failure_message_for_customer($failureReason); ?>
                        <tr>
                            <td colspan="7" class="small text-warning-emphasis pt-0 border-0">
                                <div class="alert alert-warning py-2 px-3 mb-0" role="alert">
                                    <?php echo e($failureMessage); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($isRefundInitiated): ?>
                        <tr>
                            <td colspan="7" class="small text-info-emphasis pt-0 border-0">
                                <div class="alert alert-info py-2 px-3 mb-0" role="alert">
                                    Refund initiated. Amount will be returned to your original payment method as per bank/payment timelines.
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (strtolower((string) ($o['payment_status'] ?? '')) === 'refunded'): ?>
                        <tr>
                            <td colspan="7" class="small text-success-emphasis pt-0 border-0">
                                <div class="alert alert-success py-2 px-3 mb-0" role="alert">
                                    Refund processed on payment gateway. Bank/card/UPI credit can take 2-7 working days (sometimes up to 10 working days).
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

