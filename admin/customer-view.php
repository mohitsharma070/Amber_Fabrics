<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$customerId = (int) ($_GET['id'] ?? 0);
if ($customerId <= 0) {
    flash('error', 'Invalid customer.');
    redirect('customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'toggle_active') {
        $newState = ((int) ($_POST['new_state'] ?? 1)) === 1 ? 1 : 0;
        $stmt = $conn->prepare("UPDATE customers SET is_active = ? WHERE id = ? LIMIT 1");
        $stmt->bind_param('ii', $newState, $customerId);
        $stmt->execute();
        flash('success', $newState === 1 ? 'Customer reactivated.' : 'Customer deactivated.');
        redirect('customer-view.php?id=' . $customerId);
    }
}

$stmt = $conn->prepare("SELECT id, name, email, phone, country, is_active, created_at FROM customers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
if (!$customer) {
    flash('error', 'Customer not found.');
    redirect('customers.php');
}

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS orders_count,
        COALESCE(SUM(CASE WHEN payment_status IN ('paid','refunded') THEN total_amount ELSE 0 END), 0) AS lifetime_value,
        COALESCE(SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count
     FROM orders
     WHERE customer_id = ?"
);
$summaryStmt->bind_param('i', $customerId);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: ['orders_count' => 0, 'lifetime_value' => 0, 'cancelled_count' => 0];

$ordersStmt = $conn->prepare(
    "SELECT id, order_number, order_status, payment_status, total_amount, created_at
     FROM orders
     WHERE customer_id = ?
     ORDER BY id DESC
     LIMIT 15"
);
$ordersStmt->bind_param('i', $customerId);
$ordersStmt->execute();
$orders = $ordersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$returnsStmt = $conn->prepare(
    "SELECT return_number, status, reason, refund_amount, requested_at
     FROM returns
     WHERE customer_id = ?
     ORDER BY id DESC
     LIMIT 10"
);
$returnsStmt->bind_param('i', $customerId);
$returnsStmt->execute();
$returns = $returnsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$addrStmt = $conn->prepare(
    "SELECT label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping
     FROM customer_addresses
     WHERE customer_id = ?
     ORDER BY is_default_shipping DESC, id DESC"
);
$addrStmt->bind_param('i', $customerId);
$addrStmt->execute();
$addresses = $addrStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$wishlistStmt = $conn->prepare("SELECT COUNT(*) AS total FROM wishlist_items WHERE customer_id = ?");
$wishlistStmt->bind_param('i', $customerId);
$wishlistStmt->execute();
$wishlistCount = (int) (($wishlistStmt->get_result()->fetch_assoc()['total'] ?? 0));

$cartStmt = $conn->prepare(
    "SELECT COUNT(ci.id) AS total
     FROM cart c
     LEFT JOIN cart_items ci ON ci.cart_id = c.id
     WHERE c.customer_id = ?"
);
$cartStmt->bind_param('i', $customerId);
$cartStmt->execute();
$cartCount = (int) (($cartStmt->get_result()->fetch_assoc()['total'] ?? 0));

$couponStmt = $conn->prepare(
    "SELECT cu.used_at, cp.code, o.order_number
     FROM coupon_usages cu
     JOIN coupons cp ON cp.id = cu.coupon_id
     JOIN orders o ON o.id = cu.order_id
     WHERE cu.customer_id = ?
     ORDER BY cu.id DESC
     LIMIT 10"
);
$couponStmt->bind_param('i', $customerId);
$couponStmt->execute();
$couponUsages = $couponStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$riskStmt = $conn->prepare(
    "SELECT sr.risk_band, sr.risk_score, o.order_number, sr.assessed_at
     FROM shipping_rto_risks sr
     JOIN orders o ON o.id = sr.order_id
     WHERE o.customer_id = ?
     ORDER BY sr.id DESC
     LIMIT 5"
);
$riskStmt->bind_param('i', $customerId);
$riskStmt->execute();
$risks = $riskStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$metaTitle = 'Customer Detail | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1"><?php echo e((string) ($customer['name'] ?? 'Customer')); ?></h1>
        <div class="text-muted"><?php echo e((string) ($customer['email'] ?? '')); ?><?php if (!empty($customer['phone'])): ?> | <?php echo e((string) $customer['phone']); ?><?php endif; ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="customers.php" class="btn btn-outline-secondary">Back</a>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="new_state" value="<?php echo ((int) ($customer['is_active'] ?? 1) === 1) ? 0 : 1; ?>">
            <button type="submit" class="btn <?php echo ((int) ($customer['is_active'] ?? 1) === 1) ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                <?php echo ((int) ($customer['is_active'] ?? 1) === 1) ? 'Deactivate' : 'Reactivate'; ?>
            </button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Orders</div><div class="h4 mb-0"><?php echo (int) ($summary['orders_count'] ?? 0); ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Lifetime Value</div><div class="h4 mb-0">Rs <?php echo number_format((float) ($summary['lifetime_value'] ?? 0), 2); ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Wishlist Items</div><div class="h4 mb-0"><?php echo $wishlistCount; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Cart Items</div><div class="h4 mb-0"><?php echo $cartCount; ?></div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card p-3 mb-3">
            <h5>Order History</h5>
            <?php if (empty($orders)): ?><div class="text-muted">No orders.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>#</th><th>Status</th><th>Payment</th><th>Total</th><th>Date</th></tr></thead><tbody>
                <?php foreach ($orders as $o): ?>
                    <tr><td><a href="order-view.php?id=<?php echo (int) ($o['id'] ?? 0); ?>"><?php echo e((string) ($o['order_number'] ?? '')); ?></a></td><td><?php echo e((string) ($o['order_status'] ?? '')); ?></td><td><?php echo e((string) ($o['payment_status'] ?? '')); ?></td><td>Rs <?php echo number_format((float) ($o['total_amount'] ?? 0), 2); ?></td><td><?php echo e((string) ($o['created_at'] ?? '')); ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>

        <div class="card p-3 mb-3">
            <h5>Returns / Refunds</h5>
            <?php if (empty($returns)): ?><div class="text-muted">No returns.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Return #</th><th>Status</th><th>Reason</th><th>Refund</th><th>Requested</th></tr></thead><tbody>
                <?php foreach ($returns as $r): ?>
                    <tr><td><?php echo e((string) ($r['return_number'] ?? '')); ?></td><td><?php echo e((string) ($r['status'] ?? '')); ?></td><td><?php echo e((string) ($r['reason'] ?? '')); ?></td><td>Rs <?php echo number_format((float) ($r['refund_amount'] ?? 0), 2); ?></td><td><?php echo e((string) ($r['requested_at'] ?? '')); ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 mb-3">
            <h5>Address Book</h5>
            <?php if (empty($addresses)): ?><div class="text-muted">No addresses.</div><?php else: foreach ($addresses as $a): ?>
                <div class="border rounded p-2 mb-2 small">
                    <div class="fw-semibold"><?php echo e((string) ($a['label'] ?? 'Address')); ?><?php if ((int) ($a['is_default_shipping'] ?? 0) === 1): ?> <span class="badge bg-success">Default</span><?php endif; ?></div>
                    <div><?php echo e((string) ($a['full_name'] ?? '')); ?>, <?php echo e((string) ($a['phone'] ?? '')); ?></div>
                    <div><?php echo e((string) ($a['address_line'] ?? '')); ?></div>
                    <div><?php echo e((string) ($a['city'] ?? '')); ?>, <?php echo e((string) ($a['state'] ?? '')); ?> - <?php echo e((string) ($a['pincode'] ?? '')); ?></div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <div class="card p-3 mb-3">
            <h5>Coupon Usage</h5>
            <?php if (empty($couponUsages)): ?><div class="text-muted">No coupon usage.</div><?php else: foreach ($couponUsages as $cu): ?>
                <div class="small mb-2"><strong><?php echo e((string) ($cu['code'] ?? '')); ?></strong> on <?php echo e((string) ($cu['order_number'] ?? '')); ?><br><span class="text-muted"><?php echo e((string) ($cu['used_at'] ?? '')); ?></span></div>
            <?php endforeach; endif; ?>
        </div>
        <div class="card p-3">
            <h5>RTO / COD Risk</h5>
            <?php if (empty($risks)): ?><div class="text-muted">No risk records.</div><?php else: foreach ($risks as $rk): ?>
                <div class="small mb-2"><strong><?php echo e(strtoupper((string) ($rk['risk_band'] ?? 'low'))); ?></strong> (<?php echo (int) ($rk['risk_score'] ?? 0); ?>) - <?php echo e((string) ($rk['order_number'] ?? '')); ?><br><span class="text-muted"><?php echo e((string) ($rk['assessed_at'] ?? '')); ?></span></div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
