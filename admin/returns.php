<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$validStatuses = ['requested','approved','rejected','pickup_scheduled','in_transit','received','refund_initiated','refund_completed','cancelled'];
$perPageOptions = [10, 20, 50];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filterStatus = trim((string) ($_POST['filter_status'] ?? ''));
    if (!in_array($filterStatus, $validStatuses, true)) {
        $filterStatus = '';
    }
    $filterPerPage = list_sanitize_per_page((int) ($_POST['filter_per_page'] ?? $perPageOptions[0]), $perPageOptions);
    $filterPage = list_sanitize_page((int) ($_POST['filter_page'] ?? 1));
    $returnState = [
        'status' => $filterStatus,
        'per_page' => $filterPerPage,
        'page' => $filterPage,
    ];
    $returnUrl = 'returns.php';
    $returnQuery = list_build_query($returnState);
    if ($returnQuery !== '') {
        $returnUrl .= '?' . $returnQuery;
    }

    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect($returnUrl);
    }

    $pluginHandled = apply_filters('admin.return_action.handled', false, [
        'conn' => $conn,
        'action' => trim((string) ($_POST['action'] ?? '')),
        'return_id' => (int) ($_POST['return_id'] ?? 0),
        'post' => $_POST,
    ]);
    if ($pluginHandled) {
        redirect($returnUrl);
    }

    $returnId = (int) ($_POST['return_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));
    $adminNote = trim((string) ($_POST['admin_note'] ?? ''));
    $refundAmount = (float) ($_POST['refund_amount'] ?? 0);
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
                "SELECT r.id, r.order_id, r.status AS return_status, o.payment_method, o.payment_status, o.total_amount,
                        COALESCE(ri_tot.return_total, 0) AS return_total
                 FROM returns r
                 JOIN orders o ON o.id = r.order_id
                 LEFT JOIN (
                    SELECT return_id, SUM(line_total) AS return_total
                    FROM return_items
                    GROUP BY return_id
                 ) ri_tot ON ri_tot.return_id = r.id
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
            $returnTotal = max(0, (float) ($ctx['return_total'] ?? 0));

            if ($refundAmount < 0) {
                throw new RuntimeException('Refund amount cannot be negative.');
            }
            if ($refundAmount > $returnTotal) {
                throw new RuntimeException('Refund amount cannot exceed returned items total.');
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
                    InventoryService::restock_return_items_inventory($conn, $returnId);
                }

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
                $amount = $refundAmount > 0 ? $refundAmount : min($paymentAmount, $returnTotal);
                if ($paymentId > 0) {
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
                if ($amount > 0 && $returnTotal > 0) {
                    $allocStmt = $conn->prepare(
                        "UPDATE return_items
                         SET refund_amount = ROUND((line_total / ?) * ?, 2)
                         WHERE return_id = ?"
                    );
                    $allocStmt->bind_param('ddi', $returnTotal, $amount, $returnId);
                    $allocStmt->execute();
                }
                $isFullRefund = $amount >= ($orderTotal - 0.01) && $orderTotal > 0;
                $syncStmt = $conn->prepare(
                    "UPDATE orders o
                     JOIN returns r ON r.order_id = o.id
                     SET o.order_status = CASE WHEN ? = 1 THEN 'refunded' ELSE 'returned' END,
                         o.payment_status = CASE WHEN ? = 1 AND o.payment_status = 'paid' THEN 'refunded' ELSE o.payment_status END,
                         o.updated_at = NOW()
                     WHERE r.id = ?"
                );
                $fullFlag = $isFullRefund ? 1 : 0;
                $syncStmt->bind_param('iii', $fullFlag, $fullFlag, $returnId);
                $syncStmt->execute();
                $adminId = (int) ($_SESSION['admin_id'] ?? 0);
                $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
                log_order_activity($conn, $orderIdForRefund, 'refund_completed', 'admin', $adminId, $adminName, 'Return #' . $returnId . ' marked refund completed. Amount: ' . number_format($amount, 2, '.', ''));
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
    redirect($returnUrl);
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}
$perPage = list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions);
$page = list_sanitize_page((int) ($_GET['page'] ?? 1));

$countSql = "SELECT COUNT(*) AS total FROM returns r";
$types = '';
$params = [];
if ($statusFilter !== '') {
    $countSql .= " WHERE r.status = ?";
    $types = 's';
    $params[] = $statusFilter;
}
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$pages = max(1, (int) ceil($total / $perPage));
$page = list_clamp_page($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT r.*, o.order_number, o.payment_method, o.payment_status, o.total_amount, c.name AS customer_name, c.email AS customer_email,
               COALESCE(ri_tot.return_total, 0) AS return_total
        FROM returns r
        JOIN orders o ON o.id = r.order_id
        JOIN customers c ON c.id = r.customer_id
        LEFT JOIN (
            SELECT return_id, SUM(line_total) AS return_total
            FROM return_items
            GROUP BY return_id
        ) ri_tot ON ri_tot.return_id = r.id";
if ($statusFilter !== '') {
    $sql .= " WHERE r.status = ?";
}
$sql .= " ORDER BY r.requested_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$listTypes = $types . 'ii';
$listParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$returnItemsMap = [];
if (!empty($rows)) {
    $returnIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows)));
    if (!empty($returnIds)) {
        $placeholders = implode(',', array_fill(0, count($returnIds), '?'));
        $types = str_repeat('i', count($returnIds));
        $itemStmt = $conn->prepare(
            "SELECT return_id, product_name, unit_type, quantity, line_total, restocked_qty, refund_amount
             FROM return_items
             WHERE return_id IN ($placeholders)
             ORDER BY return_id ASC, id ASC"
        );
        $itemStmt->bind_param($types, ...$returnIds);
        $itemStmt->execute();
        $riRows = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($riRows as $ri) {
            $rid = (int) ($ri['return_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            if (!isset($returnItemsMap[$rid])) {
                $returnItemsMap[$rid] = [];
            }
            $returnItemsMap[$rid][] = $ri;
        }
    }
}

$metaTitle = 'Returns | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Returns</h1>
</div>

<form class="row g-2 mb-4 admin-filter-form" method="GET" action="returns.php">
    <div class="col-md-4">
        <select name="status" class="form-select">
            <option value="">All Status</option>
            <?php foreach ($validStatuses as $status): ?>
                <option value="<?php echo e($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo e(strtoupper(str_replace('_', ' ', $status))); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="per_page" class="form-select">
            <?php foreach ($perPageOptions as $opt): ?>
                <option value="<?php echo (int) $opt; ?>" <?php echo $perPage === (int) $opt ? 'selected' : ''; ?>><?php echo (int) $opt; ?> / page</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-auto d-flex gap-2 admin-filter-actions">
        <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a href="returns.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle admin-card-table">
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
                <?php $returnItems = $returnItemsMap[(int) ($r['id'] ?? 0)] ?? []; ?>
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
                    <td>
                        <?php echo e(money((float) $r['refund_amount'])); ?>
                        <div class="small text-muted">Return total: <?php echo e(money((float) ($r['return_total'] ?? 0))); ?></div>
                        <?php if (!empty($returnItems)): ?>
                            <div class="return-breakdown-mobile">
                                <div class="small fw-semibold mb-1">Return Item Breakdown</div>
                                <?php foreach ($returnItems as $ri): ?>
                                    <?php
                                    $riUnit = in_array((string) ($ri['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $ri['unit_type'] : 'meter';
                                    $riQty = (float) ($ri['quantity'] ?? 0);
                                    $riRestocked = (float) ($ri['restocked_qty'] ?? 0);
                                    ?>
                                    <div class="return-breakdown-mobile-item">
                                        <div class="small fw-semibold"><?php echo e((string) ($ri['product_name'] ?? 'Item')); ?></div>
                                        <div class="small text-muted">
                                            Returned: <?php echo e(format_quantity_by_unit($riQty, $riUnit)) . e(InventoryService::quantity_unit_suffix($riUnit)); ?> |
                                            Line: <?php echo e(money((float) ($ri['line_total'] ?? 0))); ?> |
                                            Restocked: <?php echo e(format_quantity_by_unit($riRestocked, $riUnit)) . e(InventoryService::quantity_unit_suffix($riUnit)); ?> |
                                            Refund: <?php echo e(money((float) ($ri['refund_amount'] ?? 0))); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d M Y, h:i A', strtotime((string) $r['requested_at'])); ?></td>
                    <td class="admin-row-actions">
                        <form method="POST" action="returns.php" class="d-flex gap-2 flex-column">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="return_id" value="<?php echo (int) $r['id']; ?>">
                            <input type="hidden" name="filter_status" value="<?php echo e($statusFilter); ?>">
                            <input type="hidden" name="filter_per_page" value="<?php echo (int) $perPage; ?>">
                            <input type="hidden" name="filter_page" value="<?php echo (int) $page; ?>">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach ($validStatuses as $status): ?>
                                    <option value="<?php echo e($status); ?>" <?php echo ((string) $r['status'] === $status) ? 'selected' : ''; ?>>
                                        <?php echo e(strtoupper(str_replace('_', ' ', $status))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" step="0.01" min="0" name="refund_amount" class="form-control form-control-sm" value="<?php echo e((string) $r['refund_amount']); ?>" placeholder="Refund amount">
                            <input type="text" name="admin_note" class="form-control form-control-sm" value="<?php echo e((string) ($r['admin_note'] ?? '')); ?>" placeholder="Admin note">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-circle me-1"></i>Update</button>
                        </form>
                        <?php do_action('admin.return_row.actions', [
                            'conn' => $conn,
                            'return' => $r,
                            'filter_status' => $statusFilter,
                            'filter_per_page' => $perPage,
                            'filter_page' => $page,
                        ]); ?>
                    </td>
                </tr>
                <tr class="table-light return-breakdown-row">
                    <td></td>
                    <td colspan="8">
                        <?php if (empty($returnItems)): ?>
                            <div class="small text-muted">No return items captured.</div>
                        <?php else: ?>
                            <div class="small fw-semibold mb-2">Return Item Breakdown</div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-end">Returned Qty</th>
                                            <th class="text-end">Line Total</th>
                                            <th class="text-end">Restocked Qty</th>
                                            <th class="text-end">Allocated Refund</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($returnItems as $ri): ?>
                                            <?php
                                            $riUnit = in_array((string) ($ri['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $ri['unit_type'] : 'meter';
                                            $riQty = (float) ($ri['quantity'] ?? 0);
                                            $riRestocked = (float) ($ri['restocked_qty'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?php echo e((string) ($ri['product_name'] ?? 'Item')); ?></td>
                                                <td class="text-end"><?php echo e(format_quantity_by_unit($riQty, $riUnit)) . e(InventoryService::quantity_unit_suffix($riUnit)); ?></td>
                                                <td class="text-end"><?php echo e(money((float) ($ri['line_total'] ?? 0))); ?></td>
                                                <td class="text-end"><?php echo e(format_quantity_by_unit($riRestocked, $riUnit)) . e(InventoryService::quantity_unit_suffix($riUnit)); ?></td>
                                                <td class="text-end"><?php echo e(money((float) ($ri['refund_amount'] ?? 0))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo render_pagination($page, $pages, ['status' => $statusFilter, 'per_page' => $perPage], 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>
