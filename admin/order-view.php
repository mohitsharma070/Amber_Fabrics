<?php
require_once __DIR__ . '/../includes/init.php';
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

    if ($action === 'update_order') {
        $newOrderStatus = trim((string) ($_POST['order_status'] ?? ''));
        $newPaymentStatus = trim((string) ($_POST['payment_status'] ?? ''));

        if (!in_array($newOrderStatus, $validOrderStatuses, true)) {
            flash('error', 'Invalid order status.');
            redirect('order-view.php?id=' . $id);
        }
        if (!in_array($newPaymentStatus, $validPaymentStatuses, true)) {
            flash('error', 'Invalid payment status.');
            redirect('order-view.php?id=' . $id);
        }

        // Prevent manual refund-related status bypass for Razorpay/UPI.
        if ($newPaymentStatus === 'refunded' || $newOrderStatus === 'refunded') {
            $methodStmt = $conn->prepare("SELECT payment_method FROM orders WHERE id = ? LIMIT 1");
            $methodStmt->bind_param('i', $id);
            $methodStmt->execute();
            $row = $methodStmt->get_result()->fetch_assoc() ?: [];
            $method = strtolower((string) ($row['payment_method'] ?? ''));
            if (in_array($method, ['razorpay', 'upi'], true)) {
                flash('error', 'Use Mark Refunded button. It verifies refund status with payment gateway.');
                redirect('order-view.php?id=' . $id);
            }
        }

        $update = $conn->prepare(
            "UPDATE orders
             SET order_status = ?, payment_status = ?, status = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $legacyStatus = in_array($newOrderStatus, ['pending','confirmed','shipped','delivered','cancelled'], true)
            ? $newOrderStatus
            : 'processing';
        $update->bind_param('sssi', $newOrderStatus, $newPaymentStatus, $legacyStatus, $id);
        $update->execute();

        send_order_status_update_email($conn, $id, $newOrderStatus);

        flash('success', 'Order updated successfully.');
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

    if (in_array($action, ['save_shipment', 'mark_shipped', 'mark_delivered'], true)) {
        $courierName = trim((string) ($_POST['courier_name'] ?? ''));
        $trackingId = trim((string) ($_POST['tracking_id'] ?? ''));
        $trackingUrl = trim((string) ($_POST['tracking_url'] ?? ''));
        $shippingCost = (float) ($_POST['shipping_cost'] ?? 0);

        if ($shippingCost < 0) {
            $shippingCost = 0;
        }
        if ($trackingUrl !== '' && safe_external_url($trackingUrl) === '') {
            flash('error', 'Tracking URL must be a valid http/https URL.');
            redirect('order-view.php?id=' . $id);
        }

        $shipStmt = $conn->prepare("SELECT id, shipped_at, delivered_at FROM shipments WHERE order_id = ? LIMIT 1");
        $shipStmt->bind_param('i', $id);
        $shipStmt->execute();
        $existingShipment = $shipStmt->get_result()->fetch_assoc();

        $shippedAt = $existingShipment['shipped_at'] ?? null;
        $deliveredAt = $existingShipment['delivered_at'] ?? null;

        if ($action === 'mark_shipped') {
            $shippedAt = date('Y-m-d H:i:s');
            $orderUpdate = $conn->prepare("UPDATE orders SET order_status = 'shipped', status = 'shipped', updated_at = NOW() WHERE id = ?");
            $orderUpdate->bind_param('i', $id);
            $orderUpdate->execute();
        }

        if ($action === 'mark_delivered') {
            if (empty($shippedAt)) {
                $shippedAt = date('Y-m-d H:i:s');
            }
            $deliveredAt = date('Y-m-d H:i:s');
            $orderUpdate = $conn->prepare("UPDATE orders SET order_status = 'delivered', status = 'delivered', updated_at = NOW() WHERE id = ?");
            $orderUpdate->bind_param('i', $id);
            $orderUpdate->execute();
        }

        if ($existingShipment) {
            $shipmentId = (int) $existingShipment['id'];
            $updateShip = $conn->prepare(
                "UPDATE shipments
                 SET courier_name = ?, tracking_id = ?, tracking_url = ?, shipping_cost = ?, shipped_at = ?, delivered_at = ?
                 WHERE id = ?"
            );
            $updateShip->bind_param('sssdssi', $courierName, $trackingId, $trackingUrl, $shippingCost, $shippedAt, $deliveredAt, $shipmentId);
            $updateShip->execute();
        } else {
            $insertShip = $conn->prepare(
                "INSERT INTO shipments (order_id, courier_name, tracking_id, tracking_url, shipping_cost, shipped_at, delivered_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insertShip->bind_param('isssdss', $id, $courierName, $trackingId, $trackingUrl, $shippingCost, $shippedAt, $deliveredAt);
            $insertShip->execute();
        }

        $flashMsg = 'Shipment details saved.';
        if ($action === 'mark_shipped') {
            $flashMsg = 'Order marked shipped and shipment updated.';
            send_order_status_update_email($conn, $id, 'shipped');
        } elseif ($action === 'mark_delivered') {
            $flashMsg = 'Order marked delivered and shipment updated.';
            send_order_status_update_email($conn, $id, 'delivered');
        }

        flash('success', $flashMsg);
        redirect('order-view.php?id=' . $id);
    }
}

$orderStmt = $conn->prepare(
    "SELECT id, order_number, customer_name, customer_phone, customer_email,
            address, city, state, pincode, country,
            subtotal, shipping_amount, discount_amount, total_amount,
            payment_method, payment_status, order_status, order_notes, notes, admin_notes, created_at
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
$taxableAmount = max(0.0, (float) ($order['subtotal'] ?? 0) - (float) ($order['discount_amount'] ?? 0));
$gst = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));

$metaTitle = 'Order ' . e((string) $order['order_number']) . ' | Admin';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between mb-4">
    <div>
        <a href="orders.php" class="text-muted small">Back to Orders</a>
        <h1 class="mt-1">Order <?php echo e((string) $order['order_number']); ?></h1>
        <span class="text-muted small"><?php echo date('d M Y, h:i A', strtotime((string) $order['created_at'])); ?></span>
    </div>
    <div>
        <a href="order-invoice.php?id=<?php echo $id; ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">View Invoice</a>
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
                    <div class="d-flex justify-content-between small"><span>Discount</span><span>Rs <?php echo number_format((float) ($order['discount_amount'] ?? 0), 2); ?></span></div>
                    <?php if (!empty($gst['enabled'])): ?>
                    <div class="d-flex justify-content-between small"><span>GST @<?php echo number_format((float) $gst['rate'], 0); ?>% (included)</span><span>Rs <?php echo number_format((float) $gst['gst_amount'], 2); ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between fw-bold mt-2"><span>Total</span><span>Rs <?php echo number_format((float) ($order['total_amount'] ?? 0), 2); ?></span></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Update Order</h5>
                <form method="POST" action="order-view.php?id=<?php echo $id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_order">
                    <div class="mb-3">
                        <label class="form-label">Order Status</label>
                        <select name="order_status" class="form-select">
                            <?php foreach ($validOrderStatuses as $status): ?>
                                <option value="<?php echo e($status); ?>" <?php echo $order['order_status'] === $status ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-select">
                            <?php foreach ($validPaymentStatuses as $status): ?>
                                <option value="<?php echo e($status); ?>" <?php echo $order['payment_status'] === $status ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary">Save Changes</button>
                </form>
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

                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" type="submit">Save Shipment</button>
                        <button class="btn btn-outline-warning" type="submit" name="action" value="mark_shipped">Mark Shipped</button>
                        <button class="btn btn-outline-success" type="submit" name="action" value="mark_delivered">Mark Delivered</button>
                    </div>
                </form>

                <hr>
                <div class="small text-muted">
                    <div>Shipped At: <strong><?php echo !empty($shipment['shipped_at']) ? e((string) $shipment['shipped_at']) : '-'; ?></strong></div>
                    <div>Delivered At: <strong><?php echo !empty($shipment['delivered_at']) ? e((string) $shipment['delivered_at']) : '-'; ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
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
                </div>
                <?php if (($order['order_status'] ?? '') === 'cancelled' && ($order['payment_status'] ?? '') === 'paid'): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $id; ?>" class="mt-3" onsubmit="return confirm('Mark this order as refunded?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_refunded">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Mark Refunded</button>
                    </form>
                <?php endif; ?>
                <?php if (strtolower((string) ($order['payment_method'] ?? '')) === 'razorpay'): ?>
                    <form method="POST" action="order-view.php?id=<?php echo $id; ?>" class="mt-2" onsubmit="return confirm('Sync refund status from Razorpay now?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="sync_refund_status">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Sync Refund Status</button>
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
