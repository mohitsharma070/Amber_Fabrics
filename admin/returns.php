<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('returns.php');
    }

    $returnId = (int) ($_POST['return_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));
    $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
    $refundAmount = (float) ($_POST['refund_amount'] ?? 0);
    $validStatuses = ['requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled'];
    $allowedTransitions = [
        'requested' => ['approved', 'rejected', 'cancelled', 'requested'],
        'approved' => ['pickup_scheduled', 'cancelled', 'approved'],
        'pickup_scheduled' => ['in_transit', 'cancelled', 'pickup_scheduled'],
        'in_transit' => ['received', 'cancelled', 'in_transit'],
        'received' => ['refund_initiated', 'received'],
        'refund_initiated' => ['refund_completed', 'refund_initiated'],
        'refund_completed' => ['refund_completed'],
        'rejected' => ['rejected'],
        'cancelled' => ['cancelled'],
    ];

    if ($returnId > 0 && in_array($newStatus, $validStatuses, true)) {
        try {
            $conn->begin_transaction();

            $ctxStmt = $conn->prepare(
                "SELECT r.id, r.order_id, r.status AS return_status, o.payment_method, o.payment_status, o.total_amount
                 FROM returns r
                 JOIN orders o ON o.id = r.order_id
                 WHERE r.id = ?
                 FOR UPDATE"
            );
            $ctxStmt->bind_param('i', $returnId);
            $ctxStmt->execute();
            $ctx = $ctxStmt->get_result()->fetch_assoc();
            if (!$ctx) {
                throw new RuntimeException('Return request not found.');
            }

            $paymentMethod = strtolower((string) ($ctx['payment_method'] ?? ''));
            $paymentStatus = strtolower((string) ($ctx['payment_status'] ?? ''));
            $currentStatus = strtolower((string) ($ctx['return_status'] ?? ''));
            $orderTotal = max(0, (float) ($ctx['total_amount'] ?? 0));

            if ($refundAmount < 0) {
                throw new RuntimeException('Refund amount cannot be negative.');
            }
            if ($refundAmount > $orderTotal) {
                throw new RuntimeException('Refund amount cannot exceed order total.');
            }

            $allowedNext = $allowedTransitions[$currentStatus] ?? [$currentStatus];
            if (!in_array($newStatus, $allowedNext, true)) {
                throw new RuntimeException('Invalid return status transition.');
            }

            if (
                $newStatus === 'refund_completed'
                && in_array($paymentMethod, ['razorpay', 'upi'], true)
                && $paymentStatus === 'paid'
            ) {
                throw new RuntimeException('For paid online orders, complete refund from Order View using gateway-verified refund actions.');
            }

            $approvedAt = null;
            $rejectedAt = null;
            $receivedAt = null;

            if ($newStatus === 'approved') {
                $approvedAt = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'rejected') {
                $rejectedAt = date('Y-m-d H:i:s');
            } elseif ($newStatus === 'received') {
                $receivedAt = date('Y-m-d H:i:s');
            }

            $stmt = $conn->prepare(
                "UPDATE returns
                 SET status = ?, admin_note = ?, refund_amount = ?,
                     approved_at = COALESCE(?, approved_at),
                     rejected_at = COALESCE(?, rejected_at),
                     received_at = COALESCE(?, received_at),
                     updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param('ssdsssi', $newStatus, $adminNote, $refundAmount, $approvedAt, $rejectedAt, $receivedAt, $returnId);
            $stmt->execute();

            if ($newStatus === 'refund_completed') {
                if ($currentStatus !== 'refund_completed') {
                    restore_order_inventory($conn, (int) ($ctx['order_id'] ?? 0));
                }
                $syncStmt = $conn->prepare(
                    "UPDATE orders o
                     JOIN returns r ON r.order_id = o.id
                     SET o.order_status = 'refunded',
                         o.payment_status = CASE WHEN o.payment_status = 'paid' THEN 'refunded' ELSE o.payment_status END,
                         o.updated_at = NOW()
                     WHERE r.id = ?"
                );
                $syncStmt->bind_param('i', $returnId);
                $syncStmt->execute();

                $payStmt = $conn->prepare(
                    "SELECT id, amount, payment_method
                     FROM payments
                     WHERE order_id = ?
                     ORDER BY id DESC
                     LIMIT 1"
                );
                $orderIdForRefund = (int) ($ctx['order_id'] ?? 0);
                $payStmt->bind_param('i', $orderIdForRefund);
                $payStmt->execute();
                $pay = $payStmt->get_result()->fetch_assoc() ?: [];
                $paymentId = (int) ($pay['id'] ?? 0);
                $paymentAmount = (float) ($pay['amount'] ?? 0);
                if ($paymentId > 0) {
                    $amount = $refundAmount > 0 ? $refundAmount : $paymentAmount;
                    if ($amount > 0) {
                        log_refund_ledger(
                            $conn,
                            $orderIdForRefund,
                            $paymentId,
                            $amount,
                            'INR',
                            'processed',
                            (string) ($pay['payment_method'] ?? ''),
                            '',
                            'Refund completed from returns module.'
                        );
                    }
                }
                $adminId = (int) ($_SESSION['admin_id'] ?? 0);
                $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
                log_order_activity($conn, $orderIdForRefund, 'refund_completed', 'admin', $adminId, $adminName, 'Return #' . $returnId . ' marked refund completed.');
            }

            $conn->commit();
            flash('success', 'Return updated successfully.');
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
            }
            flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update return request.');
        }
    } else {
        flash('error', 'Invalid return update.');
    }
    redirect('returns.php');
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
$validStatuses = ['requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$sql = "SELECT r.*, o.order_number, o.payment_method, o.payment_status, o.total_amount, c.name AS customer_name, c.email AS customer_email
        FROM returns r
        JOIN orders o ON o.id = r.order_id
        JOIN customers c ON c.id = r.customer_id";
$types = '';
$params = [];
if ($statusFilter !== '') {
    $sql .= " WHERE r.status = ?";
    $types = 's';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY r.requested_at DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$metaTitle = 'Returns | Admin';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Returns</h1>
</div>

<form class="row g-2 mb-4" method="GET" action="returns.php">
    <div class="col-md-4">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach ($validStatuses as $status): ?>
                <option value="<?php echo e($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo e(strtoupper(str_replace('_', ' ', $status))); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-primary">Filter</button>
        <a href="returns.php" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Return #</th>
                <th>Order #</th>
                <th>Customer</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Images</th>
                <th>Refund Amount</th>
                <th>Requested</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No return requests found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><?php echo e((string) $r['return_number']); ?></td>
                    <td><a href="order-view.php?id=<?php echo (int) $r['order_id']; ?>"><?php echo e((string) $r['order_number']); ?></a></td>
                    <td><?php echo e((string) $r['customer_name']); ?><div class="small text-muted"><?php echo e((string) $r['customer_email']); ?></div></td>
                    <td><?php echo e((string) $r['reason']); ?><div class="small text-muted"><?php echo e((string) ($r['customer_note'] ?? '')); ?></div></td>
                    <td><span class="badge bg-secondary"><?php echo e(strtoupper(str_replace('_', ' ', (string) $r['status']))); ?></span></td>
                    <td class="small">
                        <?php if (!empty($r['image_1'])): ?>
                            <a href="../<?php echo e((string) $r['image_1']); ?>" target="_blank" rel="noopener noreferrer">Image 1</a><br>
                        <?php endif; ?>
                        <?php if (!empty($r['image_2'])): ?>
                            <a href="../<?php echo e((string) $r['image_2']); ?>" target="_blank" rel="noopener noreferrer">Image 2</a>
                        <?php endif; ?>
                        <?php if (empty($r['image_1']) && empty($r['image_2'])): ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>Rs <?php echo number_format((float) $r['refund_amount'], 2); ?></td>
                    <td><?php echo date('d M Y, h:i A', strtotime((string) $r['requested_at'])); ?></td>
                    <td>
                        <form method="POST" action="returns.php" class="d-flex gap-2 flex-column">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="return_id" value="<?php echo (int) $r['id']; ?>">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach ($validStatuses as $status): ?>
                                    <option value="<?php echo e($status); ?>" <?php echo ((string) $r['status'] === $status) ? 'selected' : ''; ?>>
                                        <?php echo e(strtoupper(str_replace('_', ' ', $status))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.01" min="0" name="refund_amount" class="form-control form-control-sm" value="<?php echo e((string) $r['refund_amount']); ?>" placeholder="Refund amount">
                            <input type="text" name="admin_note" class="form-control form-control-sm" value="<?php echo e((string) ($r['admin_note'] ?? '')); ?>" placeholder="Admin note">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'partials/footer.php'; ?>
