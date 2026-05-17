<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/coupon-functions.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Invalid order selected.');
    redirect('orders.php');
}

$validOrderStatuses = ['pending','confirmed','packed','shipped','delivered','cancelled','returned','refunded'];
$validPaymentStatuses = ['pending','paid','failed','refunded'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('order-view.php?id=' . $id);
    }

    $action = trim((string) ($_POST['action'] ?? 'update_order'));

    $pluginHandled = apply_filters('admin.order_action.handled', false, [
        'conn' => $conn,
        'order_id' => $id,
        'action' => $action,
        'post' => $_POST,
    ]);
    if ($pluginHandled) {
        redirect('order-view.php?id=' . $id);
    }

    if ($action === 'workflow_transition') {
        $targetStatus = trim((string) ($_POST['target_status'] ?? ''));
        if (!in_array($targetStatus, $validOrderStatuses, true)) {
            flash('error', 'Invalid target status.');
            redirect('order-view.php?id=' . $id);
        }

        $currentStmt = $conn->prepare("SELECT order_status, payment_method, payment_status FROM orders WHERE id = ? LIMIT 1");
        $currentStmt->bind_param('i', $id);
        $currentStmt->execute();
        $currentRow = $currentStmt->get_result()->fetch_assoc() ?: [];
        $currentOrderStatus = (string) ($currentRow['order_status'] ?? '');
        $currentPaymentStatus = (string) ($currentRow['payment_status'] ?? 'pending');
        $method = strtolower((string) ($currentRow['payment_method'] ?? ''));
        if (!can_transition_order_status($currentOrderStatus, $targetStatus)) {
            flash('error', 'Invalid order status transition.');
            redirect('order-view.php?id=' . $id);
        }
        if (in_array($targetStatus, ['shipped', 'delivered'], true) && in_array($method, ['razorpay', 'upi'], true) && strtolower($currentPaymentStatus) !== 'paid') {
            flash('error', 'Online-paid orders can be shipped only after payment is captured.');
            redirect('order-view.php?id=' . $id);
        }

        try {
            $conn->begin_transaction();
            $legacyStatus = in_array($targetStatus, ['pending','confirmed','shipped','delivered','cancelled'], true)
                ? $targetStatus
                : 'processing';
            $update = $conn->prepare(
                "UPDATE orders
                 SET order_status = ?, status = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $update->bind_param('ssi', $targetStatus, $legacyStatus, $id);
            $update->execute();

            if ($targetStatus === 'shipped' || $targetStatus === 'delivered') {
                $shipStmt = $conn->prepare("SELECT id, shipped_at, delivered_at FROM shipments WHERE order_id = ? LIMIT 1 FOR UPDATE");
                $shipStmt->bind_param('i', $id);
                $shipStmt->execute();
                $existingShipment = $shipStmt->get_result()->fetch_assoc() ?: null;
                $shippedAt = !empty($existingShipment['shipped_at']) ? (string) $existingShipment['shipped_at'] : null;
                $deliveredAt = !empty($existingShipment['delivered_at']) ? (string) $existingShipment['delivered_at'] : null;
                $now = date('Y-m-d H:i:s');
                if ($targetStatus === 'shipped' && $shippedAt === null) {
                    $shippedAt = $now;
                }
                if ($targetStatus === 'delivered') {
                    if ($shippedAt === null) {
                        $shippedAt = $now;
                    }
                    if ($deliveredAt === null) {
                        $deliveredAt = $now;
                    }
                }
                if ($existingShipment) {
                    $shipmentId = (int) ($existingShipment['id'] ?? 0);
                    $updShipment = $conn->prepare("UPDATE shipments SET shipped_at = ?, delivered_at = ? WHERE id = ?");
                    $updShipment->bind_param('ssi', $shippedAt, $deliveredAt, $shipmentId);
                    $updShipment->execute();
                } else {
                    $insShipment = $conn->prepare(
                        "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
                         VALUES (?, '', '', '', 0.00, ?, ?)"
                    );
                    $insShipment->bind_param('iss', $id, $shippedAt, $deliveredAt);
                    $insShipment->execute();
                }
            }

            if ($currentOrderStatus !== 'cancelled' && $targetStatus === 'cancelled') {
                restore_order_inventory($conn, $id);
                release_coupon_usage_for_order($conn, $id);
            }
            log_order_activity(
                $conn,
                $id,
                'admin_status_update',
                'admin',
                (int) ($_SESSION['admin_id'] ?? 0),
                (string) ($_SESSION['admin_name'] ?? 'admin'),
                'Order: ' . $currentOrderStatus . ' -> ' . $targetStatus
            );
            $conn->commit();
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback errors
            }
            flash('error', 'Unable to update order right now.');
            redirect('order-view.php?id=' . $id);
        }

        if (in_array($targetStatus, ['confirmed', 'packed', 'shipped', 'delivered'], true)) {
            $awbResult = shiprocket_auto_create_awb_for_order($conn, $id);
            if (empty($awbResult['ok'])) {
                log_order_activity(
                    $conn,
                    $id,
                    'shipment_manual_fallback',
                    'admin',
                    (int) ($_SESSION['admin_id'] ?? 0),
                    (string) ($_SESSION['admin_name'] ?? 'admin'),
                    (string) ($awbResult['reason'] ?? 'Auto AWB failed')
                );
            }
        }

        send_order_status_update_email($conn, $id, $targetStatus);
        flash('success', 'Order moved to ' . ucfirst($targetStatus) . '.');
        redirect('order-view.php?id=' . $id);
    }

    if ($action === 'update_order') {
        flash('error', 'Direct status editing is disabled. Use workflow action buttons.');
        redirect('order-view.php?id=' . $id);
    }

    if ($action === 'mark_refunded') {
        $result = admin_mark_order_refunded($conn, $id);
        if (!empty($result['ok'])) {
            flash('success', (string) ($result['message'] ?? 'Order marked as refunded.'));
        } else {
            flash('error', (string) ($result['message'] ?? 'Refund failed.'));
        }
        redirect('order-view.php?id=' . $id);
    }

    if ($action === 'sync_refund_status') {
        $result = admin_sync_razorpay_refund_status($conn, $id);
        if (!empty($result['ok'])) {
            flash('success', (string) ($result['message'] ?? 'Refund status synced.'));
        } else {
            flash('error', (string) ($result['message'] ?? 'Unable to sync refund status.'));
        }
        redirect('order-view.php?id=' . $id);
    }

    if ($action === 'save_shipment') {
        $courierName = trim((string) ($_POST['courier_name'] ?? ''));
        $trackingId = trim((string) ($_POST['tracking_id'] ?? ''));
        $trackingUrl = trim((string) ($_POST['tracking_url'] ?? ''));
        $shippingCost = (float) ($_POST['shipping_cost'] ?? 0);
        $shipmentChanged = false;
        $previousTrackingId = '';
        $shipmentEmailStatus = '';

        if ($shippingCost < 0) {
            $shippingCost = 0;
        }
        if ($trackingUrl !== '' && safe_external_url($trackingUrl) === '') {
            flash('error', 'Tracking URL must be a valid http/https URL.');
            redirect('order-view.php?id=' . $id);
        }
        try {
            $conn->begin_transaction();
            $orderStateStmt = $conn->prepare(
                "SELECT order_status, payment_method, payment_status
                 FROM orders
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $orderStateStmt->bind_param('i', $id);
            $orderStateStmt->execute();
            $orderState = $orderStateStmt->get_result()->fetch_assoc() ?: [];
            $currentOrderStatus = strtolower((string) ($orderState['order_status'] ?? ''));
            $paymentMethod = strtolower((string) ($orderState['payment_method'] ?? ''));
            $paymentStatus = strtolower((string) ($orderState['payment_status'] ?? 'pending'));
            if ($currentOrderStatus === '') {
                throw new RuntimeException('Order not found.');
            }
            $isOnline = in_array($paymentMethod, ['razorpay', 'upi'], true);
            if ($action === 'save_shipment' && in_array($currentOrderStatus, ['cancelled', 'refunded'], true)) {
                throw new RuntimeException('Shipment details cannot be edited for cancelled/refunded orders.');
            }

            $shipStmt = $conn->prepare("SELECT id, shipped_at, delivered_at FROM shipments WHERE order_id = ? LIMIT 1 FOR UPDATE");
            $shipStmt->bind_param('i', $id);
            $shipStmt->execute();
            $existingShipment = $shipStmt->get_result()->fetch_assoc();
            $previousTrackingId = trim((string) ($existingShipment['tracking_id'] ?? ''));

            $shippedAt = $existingShipment['shipped_at'] ?? null;
            $deliveredAt = $existingShipment['delivered_at'] ?? null;

            if ($existingShipment) {
                $shipmentId = (int) $existingShipment['id'];
                $updateShip = $conn->prepare(
                    "UPDATE shipments
                     SET courier_name = ?, tracking_id = ?, tracking_url = ?, shipping_cost = ?, shipped_at = ?, delivered_at = ?
                     WHERE id = ?"
                );
                $updateShip->bind_param('sssdssi', $courierName, $trackingId, $trackingUrl, $shippingCost, $shippedAt, $deliveredAt, $shipmentId);
                $updateShip->execute();
                $shipmentChanged = true;
            } else {
                $insertShip = $conn->prepare(
                    "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insertShip->bind_param('isssdss', $id, $courierName, $trackingId, $trackingUrl, $shippingCost, $shippedAt, $deliveredAt);
                $insertShip->execute();
                $shipmentChanged = true;
            }
            if ($shipmentChanged) {
                $adminId = (int) ($_SESSION['admin_id'] ?? 0);
                $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');
                $details = 'Courier: ' . ($courierName !== '' ? $courierName : '-') .
                    ' | Tracking ID: ' . ($trackingId !== '' ? $trackingId : '-') .
                    ($trackingUrl !== '' ? (' | URL: ' . $trackingUrl) : '');
                log_order_activity($conn, $id, 'shipment_updated', 'admin', $adminId, $adminName, $details);
            }
            $conn->commit();
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
                // ignore rollback errors
            }
            flash('error', 'Unable to update shipment right now.');
            redirect('order-view.php?id=' . $id);
        }

        $flashMsg = 'Shipment details saved.';
        if ($shipmentChanged && $trackingId !== '' && $trackingId !== $previousTrackingId) {
            $shipmentEmailStatus = 'shipped';
        }

        if ($shipmentEmailStatus !== '') {
            send_order_status_update_email($conn, $id, $shipmentEmailStatus);
        }

        flash('success', $flashMsg);
        redirect('order-view.php?id=' . $id);
    }
}

$financialSelect = orders_structured_financial_columns_ready($conn)
    ? "coupon_id, coupon_code, coupon_discount, shipping_quote_token, shipping_source, courier_id, courier_name, cod_fee, base_shipping"
    : "NULL AS coupon_id, NULL AS coupon_code, 0.00 AS coupon_discount, NULL AS shipping_quote_token, NULL AS shipping_source, NULL AS courier_id, NULL AS courier_name, 0.00 AS cod_fee, 0.00 AS base_shipping";
$orderStmt = $conn->prepare(
    "SELECT id, order_number, customer_name, customer_phone, customer_email,
            address, city, state, pincode, country,
            subtotal, shipping_amount, discount_amount, total_amount,
            payment_method, payment_status, order_status, order_notes, notes, admin_notes, created_at,
            {$financialSelect}
     FROM orders
     WHERE id = ?
     LIMIT 1"
);
$orderStmt->bind_param('i', $id);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

$razorpayAuditLines = [];
$systemNotes = trim((string) ($order['notes'] ?? ''));
if ($systemNotes !== '') {
    $parts = preg_split('/\R+/', $systemNotes);
    if (is_array($parts)) {
        foreach ($parts as $line) {
            $clean = trim((string) $line);
            if ($clean !== '' && stripos($clean, 'Razorpay') !== false) {
                $razorpayAuditLines[] = $clean;
            }
        }
    }
}

$itemStmt = $conn->prepare(
    "SELECT oi.*, f.image
     FROM order_items oi
     LEFT JOIN fabrics f ON f.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC"
);
$itemStmt->bind_param('i', $id);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$shipmentStmt = $conn->prepare(
    "SELECT id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at
     FROM shipments
     WHERE order_id = ?
     LIMIT 1"
);
$shipmentStmt->bind_param('i', $id);
$shipmentStmt->execute();
$shipment = $shipmentStmt->get_result()->fetch_assoc() ?: [
    'courier_name' => '',
    'tracking_id' => '',
    'tracking_url' => '',
    'shipping_cost' => '0.00',
    'shipped_at' => null,
    'delivered_at' => null,
];
$activityStmt = $conn->prepare(
    "SELECT action, actor_type, actor_name, details, created_at
     FROM order_activity_logs
     WHERE order_id = ?
     ORDER BY id DESC
     LIMIT 25"
);
$activityStmt->bind_param('i', $id);
$activityStmt->execute();
$orderActivity = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orderActivity = apply_filters('order.timeline.events', is_array($orderActivity) ? $orderActivity : [], [
    'audience' => 'admin',
    'order_id' => $id,
    'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
]);
$taxableAmount = max(0.0, (float) ($order['subtotal'] ?? 0) - (float) ($order['discount_amount'] ?? 0));
$gst = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));
$workflowStatuses = ['pending', 'confirmed', 'packed', 'shipped', 'delivered'];
$currentWorkflowStatus = strtolower((string) ($order['order_status'] ?? 'pending'));
$currentWorkflowIndex = array_search($currentWorkflowStatus, $workflowStatuses, true);
$currentWorkflowIndex = ($currentWorkflowIndex === false) ? 0 : (int) $currentWorkflowIndex;
$nextStatusActions = [];
$paymentMethodNow = strtolower((string) ($order['payment_method'] ?? ''));
$paymentStatusNow = strtolower((string) ($order['payment_status'] ?? 'pending'));
foreach ($validOrderStatuses as $candidateStatus) {
    if (!can_transition_order_status((string) ($order['order_status'] ?? ''), $candidateStatus)) {
        continue;
    }
    if (in_array($candidateStatus, ['shipped', 'delivered'], true) && in_array($paymentMethodNow, ['razorpay', 'upi'], true) && $paymentStatusNow !== 'paid') {
        continue;
    }
    $nextStatusActions[] = $candidateStatus;
}
$metaTitle = 'Order ' . e((string) $order['order_number']) . ' | Admin';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between mb-4">
    <div>
        <a href="orders.php" class="app-back-link">&larr; Back to Orders</a>
        <h1 class="mt-1">Order <?php echo e((string) $order['order_number']); ?></h1>
        <span class="text-muted small"><?php echo date('d M Y, h:i A', strtotime((string) $order['created_at'])); ?></span>
        <div class="order-workflow-steps" aria-label="Order workflow">
            <?php foreach ($workflowStatuses as $index => $workflowStatus): ?>
                <?php
                $stepClass = 'order-workflow-step';
                if ($index < $currentWorkflowIndex) {
                    $stepClass .= ' is-done';
                } elseif ($index === $currentWorkflowIndex) {
                    $stepClass .= ' is-current';
                }
                ?>
                <span class="<?php echo e($stepClass); ?>"><?php echo e(ucfirst($workflowStatus)); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Order Items</h5>
                <?php if (empty($items)): ?>
                    <p class="text-muted mb-0">No items found for this order.</p>
                <?php else: ?>
                    <?php foreach ($items as $item):
                        $name = (string) ($item['product_name'] ?: ($item['fabric_name_snapshot'] ?? 'Product'));
                        $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true) ? (string) $item['unit_type'] : 'meter';
                        $qty = (($item['quantity'] ?? 0) > 0 ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0));
                        $price = (float) (($item['price'] ?? 0) > 0 ? $item['price'] : ($item['price_per_meter'] ?? 0));
                        $lineTotal = (float) (($item['total'] ?? 0) > 0 ? $item['total'] : ($item['line_total'] ?? 0));
                    ?>
                    <div class="d-flex gap-3 align-items-start py-2 border-bottom">
                        <?php if (!empty($item['image'])): ?>
                            <img src="../images/fabrics/<?php echo e((string) $item['image']); ?>" alt="<?php echo e($name); ?>" class="rounded" style="width:50px;height:50px;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:50px;height:50px;background:#eee;border-radius:4px;flex-shrink:0;"></div>
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo e($name); ?></div>
                            <div class="text-muted small">
                                Qty: <?php echo e(format_quantity_by_unit($qty, $unitType)); ?><?php echo quantity_unit_suffix($unitType); ?>
                                <?php if ($unitType === 'set' && (int) ($item['units_per_set'] ?? 0) > 0): ?>
                                    (<?php echo (int) round($qty); ?> sets x <?php echo (int) $item['units_per_set']; ?> = <?php echo (int) round($qty) * (int) $item['units_per_set']; ?> pieces)
                                <?php endif; ?>
                                <?php if (!empty($item['size'])): ?> | Size: <?php echo e((string) $item['size']); ?><?php endif; ?>
                                <?php if (!empty($item['color'])): ?> | Color: <?php echo e((string) $item['color']); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="small text-muted">Rs <?php echo number_format($price, 2); ?><?php echo ($unitType === 'piece' || $unitType === 'set') ? ' each' : '/m'; ?></div>
                            <div class="fw-semibold">Rs <?php echo number_format($lineTotal, 2); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="mt-3 pt-2 border-top">
                    <div class="d-flex justify-content-between small"><span>Subtotal</span><span>Rs <?php echo number_format((float) ($order['subtotal'] ?? 0), 2); ?></span></div>
                    <div class="d-flex justify-content-between small"><span>Shipping</span><span>Rs <?php echo number_format((float) ($order['shipping_amount'] ?? 0), 2); ?></span></div>
                    <div class="d-flex justify-content-between small"><span>Discount</span><span>- Rs <?php echo number_format((float) ($order['discount_amount'] ?? 0), 2); ?></span></div>
                    <?php if (!empty($gst['enabled'])): ?>
                    <div class="d-flex justify-content-between small"><span>Including GST</span><span>Rs <?php echo number_format((float) $gst['gst_amount'], 2); ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between fw-bold mt-2"><span>Total</span><span>Rs <?php echo number_format((float) ($order['total_amount'] ?? 0), 2); ?></span></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Order Actions</h5>
                <?php if (empty($nextStatusActions)): ?>
                    <p class="text-muted small mb-0">No further workflow actions available for this order.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($nextStatusActions as $nextStatus): ?>
                            <form method="POST" action="order-view.php?id=<?php echo $id; ?>" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="workflow_transition">
                                <input type="hidden" name="target_status" value="<?php echo e($nextStatus); ?>">
                                <button class="btn btn-sm <?php echo $nextStatus === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-primary'; ?>" type="submit">
                                    <?php echo ucfirst($nextStatus); ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Shipment Details</h5>
                <form method="POST" action="order-view.php?id=<?php echo $id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_shipment">

                    <div class="mb-3">
                        <label class="form-label">Courier Name</label>
                        <input type="text" class="form-control" name="courier_name" value="<?php echo e((string) ($shipment['courier_name'] ?? '')); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tracking ID</label>
                        <input type="text" class="form-control" name="tracking_id" value="<?php echo e((string) ($shipment['tracking_id'] ?? '')); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tracking URL</label>
                        <input type="url" class="form-control" name="tracking_url" value="<?php echo e((string) ($shipment['tracking_url'] ?? '')); ?>" placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Shipping Cost</label>
                        <input type="number" class="form-control" step="0.01" min="0" name="shipping_cost" value="<?php echo e((string) ($shipment['shipping_cost'] ?? '0.00')); ?>">
                    </div>
                    <div class="d-flex gap-2 flex-wrap mt-3 pt-2 border-top">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save2 me-1" aria-hidden="true"></i>Save Shipment</button>
                    </div>
                </form>

                <hr>
                <div class="small text-muted">
                    <div>Shipped At: <strong><?php echo !empty($shipment['shipped_at']) ? e((string) $shipment['shipped_at']) : '-'; ?></strong></div>
                    <div>Delivered At: <strong><?php echo !empty($shipment['delivered_at']) ? e((string) $shipment['delivered_at']) : '-'; ?></strong></div>
                </div>
            </div>
        </div>

        <?php if (!empty($orderActivity)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Order Timeline</h5>
                <div class="small">
                    <?php foreach ($orderActivity as $ev): ?>
                        <div class="border rounded p-2 mb-2">
                            <?php $timelineActionLabel = (string) ($ev['display_action'] ?? ucwords(str_replace('_', ' ', (string) ($ev['action'] ?? 'update')))); ?>
                            <div class="d-flex justify-content-between gap-2 flex-wrap">
                                <strong><?php echo e($timelineActionLabel); ?></strong>
                                <span class="text-muted"><?php echo date('d M Y, h:i A', strtotime((string) ($ev['created_at'] ?? 'now'))); ?></span>
                            </div>
                            <?php if (!empty($ev['display_details']) || !empty($ev['details'])): ?>
                                <div class="text-muted"><?php echo e((string) ($ev['display_details'] ?? $ev['details'])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title">Quick Actions</h6>
                <div class="d-flex flex-column gap-2">
                    <a href="invoice.php?order=<?php echo e((string) $order['order_number']); ?>" target="_blank"
                       class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print Invoice</a>
                    <a href="packing-slip.php?order=<?php echo e((string) $order['order_number']); ?>" target="_blank"
                       class="btn btn-outline-secondary btn-sm"><i class="bi bi-box2 me-1" aria-hidden="true"></i>Packing Slip</a>
                </div>
            </div>
        </div>

        <?php do_action('admin.order_view.sidebar', [
            'conn' => $conn,
            'order' => $order,
        ]); ?>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title">Customer Details</h6>
                <div class="small text-muted">
                    <div><strong><?php echo e((string) $order['customer_name']); ?></strong></div>
                    <div><?php echo e((string) $order['customer_phone']); ?></div>
                    <div><?php echo e((string) $order['customer_email']); ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title">Address</h6>
                <address class="mb-0 small text-muted" style="font-style:normal;">
                    <?php echo e((string) $order['address']); ?><br>
                    <?php echo e((string) $order['city']); ?>, <?php echo e((string) $order['state']); ?><br>
                    <?php echo e((string) $order['pincode']); ?><br>
                    <?php echo e((string) $order['country']); ?>
                </address>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title">Payment</h6>
                <div class="small text-muted">
                    <div>Method: <strong><?php echo strtoupper(e((string) $order['payment_method'])); ?></strong></div>
                    <div>Status: <strong><?php echo ucfirst(e((string) $order['payment_status'])); ?></strong></div>
                    <?php if (!empty($order['coupon_code'])): ?>
                        <div>Coupon: <strong><?php echo e((string) $order['coupon_code']); ?></strong> (Rs <?php echo number_format((float) ($order['coupon_discount'] ?? 0), 2); ?>)</div>
                    <?php endif; ?>
                    <div>Base Shipping: <strong>Rs <?php echo number_format((float) ($order['base_shipping'] ?? 0), 2); ?></strong></div>
                    <div>COD Fee: <strong>Rs <?php echo number_format((float) ($order['cod_fee'] ?? 0), 2); ?></strong></div>
                    <?php if (!empty($order['shipping_source'])): ?>
                        <div>Shipping Source: <strong><?php echo e((string) $order['shipping_source']); ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($order['courier_name'])): ?>
                        <div>Checkout Courier: <strong><?php echo e((string) $order['courier_name']); ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if (($order['order_status'] ?? '') === 'cancelled' && ($order['payment_status'] ?? '') === 'paid'): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $id; ?>" class="mt-3" data-confirm-modal data-confirm-title="Confirm Refund" data-confirm-message="Mark this order as refunded now?" data-confirm-ok="Mark Refunded">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_refunded">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Mark Refunded</button>
                    </form>
                <?php endif; ?>
                <?php if (strtolower((string) ($order['payment_method'] ?? '')) === 'razorpay'): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $id; ?>" class="mt-2" data-confirm-modal data-confirm-title="Sync Refund Status" data-confirm-message="Sync refund status from Razorpay now?" data-confirm-ok="Sync Now">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync_refund_status">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Sync Refund Status</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($razorpayAuditLines)): ?>
        <div class="card mb-4 border-warning">
            <div class="card-body">
                <h6 class="card-title text-warning">Razorpay Audit</h6>
                <div class="small text-muted">
                    <?php foreach ($razorpayAuditLines as $auditLine): ?>
                        <div><?php echo e($auditLine); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <h6 class="card-title">Order Status</h6>
                <div class="small text-muted">
                    <strong><?php echo ucfirst(e((string) $order['order_status'])); ?></strong>
                </div>
            </div>
        </div>

        <?php if (!empty($order['order_notes'])): ?>
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Order Notes</h6>
                <p class="small text-muted mb-0"><?php echo nl2br(e((string) $order['order_notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
