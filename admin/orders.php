<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('orders.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'mark_refunded') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $result = admin_mark_order_refunded($conn, $orderId);
            if (!empty($result['ok'])) {
                flash('success', (string) ($result['message'] ?? 'Order marked as refunded.'));
            } else {
                flash('error', (string) ($result['message'] ?? 'Refund failed.'));
            }
        }
        redirect('orders.php?refund_queue=1');
    }
}

$perPageOptions = [15, 30, 50];
$sortMap = [
    'newest' => 'o.created_at DESC',
    'oldest' => 'o.created_at ASC',
    'amount_high' => 'o.total_amount DESC',
    'amount_low' => 'o.total_amount ASC',
];

$sort = list_sanitize_sort(trim($_GET['sort'] ?? 'newest'), $sortMap);
$perPage = list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions);
$page = list_sanitize_page((int) ($_GET['page'] ?? 1));
$search = trim($_GET['q'] ?? '');
$orderStatus = trim($_GET['order_status'] ?? '');
$paymentStatus = trim($_GET['payment_status'] ?? '');
$refundQueue = (int) ($_GET['refund_queue'] ?? 0) === 1;
$orderBy = $sortMap[$sort];

$validOrderStatuses = ['pending','confirmed','packed','shipped','delivered','cancelled','returned','refunded'];
$validPaymentStatuses = ['pending','paid','failed','refunded'];

if (!in_array($orderStatus, $validOrderStatuses, true)) {
    $orderStatus = '';
}
if (!in_array($paymentStatus, $validPaymentStatuses, true)) {
    $paymentStatus = '';
}

if ($refundQueue) {
    $orderStatus = 'cancelled';
    $paymentStatus = 'paid';
}

$where = [];
$types = '';
$params = [];

// Hide abandoned online-payment orders (pending > 30 minutes),
// same behavior as customer side.
$where[] = "NOT (
    o.order_status = 'pending'
    AND
    o.payment_status = 'pending'
    AND o.payment_method IN ('razorpay', 'upi')
    AND o.created_at < (NOW() - INTERVAL 30 MINUTE)
)";

if ($search !== '') {
    $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    $like = "%{$search}%";
    $types .= 'sss';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($orderStatus !== '') {
    $where[] = 'o.order_status = ?';
    $types .= 's';
    $params[] = $orderStatus;
}
if ($paymentStatus !== '') {
    $where[] = 'o.payment_status = ?';
    $types .= 's';
    $params[] = $paymentStatus;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "SELECT COUNT(*) AS total FROM orders o {$whereSql}";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

$pages = max(1, (int) ceil($total / $perPage));
$page = list_clamp_page($page, $pages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.total_amount,
                   o.payment_method, o.payment_status, o.order_status, o.created_at
            FROM orders o
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?";
$listStmt = $conn->prepare($listSql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$listStmt->bind_param($allTypes, ...$allParams);
$listStmt->execute();
$orders = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$orderProductNamesMap = [];
$orderQtyDisplayMap = [];
$orderTrackingMap = [];

if (!empty($orders)) {
    $orderIds = array_map(static fn($o) => (int) ($o['id'] ?? 0), $orders);
    $orderIds = array_values(array_filter($orderIds, static fn($id) => $id > 0));

    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $types = str_repeat('i', count($orderIds));

        $itemSql = "SELECT order_id, product_name, fabric_name_snapshot, unit_type, quantity, quantity_meters
                    FROM order_items
                    WHERE order_id IN ($placeholders)
                    ORDER BY id ASC";
        $itemStmt = $conn->prepare($itemSql);
        $itemStmt->bind_param($types, ...$orderIds);
        $itemStmt->execute();
        $orderItems = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($orderItems as $item) {
            $oid = (int) ($item['order_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }

            $name = trim((string) ($item['product_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($item['fabric_name_snapshot'] ?? 'Product'));
            }

            $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                ? (string) $item['unit_type']
                : 'meter';
            $qtyRaw = ((float) ($item['quantity'] ?? 0) > 0)
                ? (float) $item['quantity']
                : (float) ($item['quantity_meters'] ?? 0);
            $qtyText = format_quantity_by_unit($qtyRaw, $unitType);

            if ($unitType === 'piece') {
                $unitLabel = ((float) $qtyRaw === 1.0) ? 'piece' : 'pieces';
            } elseif ($unitType === 'set') {
                $unitLabel = ((float) $qtyRaw === 1.0) ? 'set' : 'sets';
            } else {
                $unitLabel = 'meter';
            }

            if (!isset($orderProductNamesMap[$oid])) {
                $orderProductNamesMap[$oid] = [];
                $orderQtyDisplayMap[$oid] = [];
            }

            $orderProductNamesMap[$oid][] = $name;
            $orderQtyDisplayMap[$oid][] = $qtyText . ' ' . $unitLabel;
        }

        $shipSql = "SELECT order_id, tracking_id FROM shipments WHERE order_id IN ($placeholders)";
        $shipStmt = $conn->prepare($shipSql);
        $shipStmt->bind_param($types, ...$orderIds);
        $shipStmt->execute();
        $shipRows = $shipStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($shipRows as $ship) {
            $oid = (int) ($ship['order_id'] ?? 0);
            $trackingId = trim((string) ($ship['tracking_id'] ?? ''));
            if ($oid > 0 && $trackingId !== '') {
                $orderTrackingMap[$oid] = $trackingId;
            }
        }
    }
}

$statusBadge = [
    'pending' => 'warning',
    'confirmed' => 'info',
    'packed' => 'primary',
    'shipped' => 'primary',
    'delivered' => 'success',
    'cancelled' => 'danger',
    'returned' => 'secondary',
    'refunded' => 'dark',
];

$paymentBadge = [
    'pending' => 'secondary',
    'paid' => 'success',
    'failed' => 'danger',
    'refunded' => 'dark',
];

$metaTitle = 'Orders | Admin';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Orders</h1>
    <?php if (!$refundQueue): ?>
        <a href="orders.php?refund_queue=1" class="btn btn-outline-danger">Refund Queue</a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-4" method="GET" action="orders.php">
    <div class="col-md-3">
        <input class="form-control" name="q" value="<?php echo e($search); ?>" placeholder="Order #, name or phone">
    </div>
    <div class="col-md-3">
        <select name="order_status" class="form-select">
            <option value="">All Order Status</option>
            <?php foreach ($validOrderStatuses as $status): ?>
                <option value="<?php echo e($status); ?>" <?php echo $orderStatus === $status ? 'selected' : ''; ?>>
                    <?php echo ucfirst($status); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="payment_status" class="form-select">
            <option value="">All Payment Status</option>
            <?php foreach ($validPaymentStatuses as $status): ?>
                <option value="<?php echo e($status); ?>" <?php echo $paymentStatus === $status ? 'selected' : ''; ?>>
                    <?php echo ucfirst($status); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="sort" class="form-select">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
            <option value="amount_high" <?php echo $sort === 'amount_high' ? 'selected' : ''; ?>>Amount High-Low</option>
            <option value="amount_low" <?php echo $sort === 'amount_low' ? 'selected' : ''; ?>>Amount Low-High</option>
        </select>
    </div>
    <div class="col-md-auto d-flex gap-2">
        <button class="btn btn-primary">Filter</button>
        <a href="orders.php" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>

<?php if ($refundQueue): ?>
    <div class="alert alert-warning py-2">
        Showing refund queue: <strong>Cancelled + Paid</strong> orders.
    </div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer Name</th>
                <th>Phone</th>
                <th>Product Name</th>
                <th>Quantity with Unit</th>
                <th>Total Amount</th>
                <th>Payment Method</th>
                <th>Payment Status</th>
                <th>Order Status</th>
                <th>Tracking ID</th>
                <th>Created Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="12" class="text-center text-muted py-4">No orders found.</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($orders as $order): ?>
                <?php
                    $oid = (int) ($order['id'] ?? 0);
                    $productNames = isset($orderProductNamesMap[$oid]) ? array_values(array_unique($orderProductNamesMap[$oid])) : [];
                    $qtyDisplay = $orderQtyDisplayMap[$oid] ?? [];
                    $trackingId = (string) ($orderTrackingMap[$oid] ?? '');
                ?>
                <tr>
                    <td class="fw-semibold"><?php echo e($order['order_number']); ?></td>
                    <td><?php echo e($order['customer_name']); ?></td>
                    <td><?php echo e($order['customer_phone']); ?></td>
                    <td>
                        <?php if (!empty($productNames)): ?>
                            <?php foreach ($productNames as $pname): ?>
                                <div><?php echo e($pname); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($qtyDisplay)): ?>
                            <?php foreach ($qtyDisplay as $qtext): ?>
                                <div><?php echo e($qtext); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>Rs <?php echo number_format((float) ($order['total_amount'] ?? 0), 2); ?></td>
                    <td><?php echo strtoupper(e((string) $order['payment_method'])); ?></td>
                    <td>
                        <?php $pb = $paymentBadge[$order['payment_status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?php echo $pb; ?>"><?php echo ucfirst(e((string) $order['payment_status'])); ?></span>
                    </td>
                    <td>
                        <?php $sb = $statusBadge[$order['order_status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?php echo $sb; ?>"><?php echo ucfirst(e((string) $order['order_status'])); ?></span>
                    </td>
                    <td><?php echo $trackingId !== '' ? e($trackingId) : '-'; ?></td>
                    <td><?php echo date('d M Y, h:i A', strtotime((string) $order['created_at'])); ?></td>
                    <td>
                        <a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                        <?php if (($order['order_status'] ?? '') === 'cancelled' && ($order['payment_status'] ?? '') === 'paid'): ?>
                            <form method="POST" action="orders.php?refund_queue=1" class="d-inline" onsubmit="return confirm('Mark this order as refunded?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="mark_refunded">
                                <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Mark Refunded</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo render_pagination($page, $pages, ['q' => $search, 'order_status' => $orderStatus, 'payment_status' => $paymentStatus, 'sort' => $sort, 'per_page' => $perPage, 'refund_queue' => $refundQueue ? '1' : ''], 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>
