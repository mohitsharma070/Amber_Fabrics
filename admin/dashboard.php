<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$rangeFrom = trim((string) ($_GET['from'] ?? $monthStart));
$rangeTo = trim((string) ($_GET['to'] ?? $today));

$isValidDate = static function (string $value): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
};

if (!$isValidDate($rangeFrom)) {
    $rangeFrom = $monthStart;
}
if (!$isValidDate($rangeTo)) {
    $rangeTo = $today;
}
if ($rangeFrom > $rangeTo) {
    [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
}

$rangeStartAt = $rangeFrom . ' 00:00:00';
$rangeEndExclusive = date('Y-m-d H:i:s', strtotime($rangeTo . ' +1 day'));
$rangeLabel = date('d M Y', strtotime($rangeFrom)) . ' - ' . date('d M Y', strtotime($rangeTo));

$orderRangeClause = "created_at >= ? AND created_at < ?";
$expenseRangeClause = "expense_date >= ? AND expense_date <= ?";
$activeOrderClause = "NOT (
    order_status = 'pending'
    AND
    payment_status = 'pending'
    AND payment_method IN ('razorpay', 'upi')
    AND created_at < (NOW() - INTERVAL 30 MINUTE)
)";

$totalProducts = (int) ($conn->query("SELECT COUNT(*) FROM fabrics")->fetch_row()[0] ?? 0);
$totalSalesAllTime = (float) ($conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetch_row()[0] ?? 0);
$todaySales = (float) ($conn->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid' AND DATE(created_at) = CURDATE()")->fetch_row()[0] ?? 0);
$totalOrders = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE {$activeOrderClause}")->fetch_row()[0] ?? 0);
$pendingOrders = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE {$activeOrderClause} AND order_status = 'pending'")->fetch_row()[0] ?? 0);
$deliveredOrders = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE {$activeOrderClause} AND order_status = 'delivered'")->fetch_row()[0] ?? 0);
$cancelledOrders = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE {$activeOrderClause} AND order_status = 'cancelled'")->fetch_row()[0] ?? 0);
$staleOnlinePending = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE payment_method IN ('razorpay','upi') AND payment_status = 'pending' AND order_status IN ('pending','confirmed') AND created_at < (NOW() - INTERVAL 30 MINUTE)")->fetch_row()[0] ?? 0);
$refundPendingCount = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid' AND order_status = 'cancelled'")->fetch_row()[0] ?? 0);
$codPendingConfirm = (int) ($conn->query("SELECT COUNT(*) FROM cod_confirmations WHERE status = 'pending'")->fetch_row()[0] ?? 0);
$cronLastRunAt = '';
try {
    $cronStmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'cron_last_run_at' LIMIT 1");
    $cronStmt->execute();
    $cronLastRunAt = (string) ($cronStmt->get_result()->fetch_assoc()['setting_value'] ?? '');
} catch (Throwable $e) {
    $cronLastRunAt = '';
}
$cronLagMinutes = null;
if ($cronLastRunAt !== '') {
    $cronTs = strtotime($cronLastRunAt);
    if ($cronTs !== false) {
        $cronLagMinutes = max(0, (int) floor((time() - $cronTs) / 60));
    }
}
$lowStockProducts = (int) ($conn->query("SELECT COUNT(*) FROM fabrics WHERE status = 'active' AND (CASE WHEN unit_type = 'meter' THEN COALESCE(stock_meters, 0) ELSE COALESCE(stock, 0) END) <= 3")->fetch_row()[0] ?? 0);
$exportInquiries = (int) ($conn->query("SELECT COUNT(*) FROM inquiries WHERE inquiry_type = 'export'")->fetch_row()[0] ?? 0);

$salesStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_sales FROM orders WHERE payment_status = 'paid' AND {$orderRangeClause}");
$salesStmt->bind_param('ss', $rangeStartAt, $rangeEndExclusive);
$salesStmt->execute();
$totalSalesMonth = (float) ($salesStmt->get_result()->fetch_assoc()['total_sales'] ?? 0);

$supportsCostSnapshot = order_items_supports_cost_snapshot($conn);
$costExpression = $supportsCostSnapshot
    ? "COALESCE(oi.cost_price_snapshot, COALESCE(f.cost_price, 0))"
    : "COALESCE(f.cost_price, 0)";
$productCostStmt = $conn->prepare(
    "SELECT COALESCE(SUM(
        (CASE
            WHEN oi.quantity IS NOT NULL AND oi.quantity > 0 THEN oi.quantity
            WHEN oi.quantity_meters IS NOT NULL AND oi.quantity_meters > 0 THEN oi.quantity_meters
            ELSE 0
         END) * {$costExpression}
    ), 0) AS total_product_cost
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN fabrics f ON f.id = oi.product_id
     WHERE o.payment_status = 'paid' AND o.created_at >= ? AND o.created_at < ?"
);
$productCostStmt->bind_param('ss', $rangeStartAt, $rangeEndExclusive);
$productCostStmt->execute();
$productCostEstimate = (float) ($productCostStmt->get_result()->fetch_assoc()['total_product_cost'] ?? 0);

$expenseBreakdownStmt = $conn->prepare(
    "SELECT type, COALESCE(SUM(amount), 0) AS total_amount
     FROM expenses
     WHERE {$expenseRangeClause}
     GROUP BY type"
);
$expenseBreakdownStmt->bind_param('ss', $rangeFrom, $rangeTo);
$expenseBreakdownStmt->execute();
$expenseRows = $expenseBreakdownStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$expenseMap = [
    'Marketing' => 0.00,
    'Packaging' => 0.00,
    'Shipping' => 0.00,
    'Product Purchase' => 0.00,
    'Website' => 0.00,
    'Other' => 0.00,
];
$totalExpenses = 0.00;
foreach ($expenseRows as $er) {
    $type = (string) ($er['type'] ?? 'Other');
    $amt = (float) ($er['total_amount'] ?? 0);
    if (!isset($expenseMap[$type])) {
        $expenseMap[$type] = 0.00;
    }
    $expenseMap[$type] += $amt;
    $totalExpenses += $amt;
}

$marketingExpense = (float) ($expenseMap['Marketing'] ?? 0.00);
$packagingExpense = (float) ($expenseMap['Packaging'] ?? 0.00);
$shippingExpense = (float) ($expenseMap['Shipping'] ?? 0.00);

$paymentFeesStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_fees
     FROM expenses
     WHERE {$expenseRangeClause}
       AND (
            LOWER(COALESCE(note, '')) LIKE '%payment fee%'
         OR LOWER(COALESCE(note, '')) LIKE '%gateway%'
         OR LOWER(COALESCE(note, '')) LIKE '%razorpay%'
         OR LOWER(COALESCE(note, '')) LIKE '%transaction fee%'
       )"
);
$paymentFeesStmt->bind_param('ss', $rangeFrom, $rangeTo);
$paymentFeesStmt->execute();
$paymentFeesExpense = (float) ($paymentFeesStmt->get_result()->fetch_assoc()['total_fees'] ?? 0);

$returnsStmt = $conn->prepare(
    "SELECT COALESCE(SUM(total_amount), 0) AS total_returns
     FROM orders
     WHERE {$orderRangeClause}
       AND (payment_status = 'refunded' OR order_status IN ('returned', 'refunded'))"
);
$returnsStmt->bind_param('ss', $rangeStartAt, $rangeEndExclusive);
$returnsStmt->execute();
$returnsExpense = (float) ($returnsStmt->get_result()->fetch_assoc()['total_returns'] ?? 0);

$netProfit = $totalSalesMonth - $productCostEstimate - $shippingExpense - $marketingExpense - $packagingExpense - $paymentFeesExpense - $returnsExpense;

$recentOrdersStmt = $conn->prepare(
    "SELECT id, order_number, customer_name, total_amount, payment_status, order_status, created_at
     FROM orders
     WHERE created_at >= ? AND created_at < ?
       AND {$activeOrderClause}
     ORDER BY created_at DESC
     LIMIT 8"
);
$recentOrdersStmt->bind_param('ss', $rangeStartAt, $rangeEndExclusive);
$recentOrdersStmt->execute();
$recentOrders = $recentOrdersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$sixMonthSalesStmt = $conn->prepare(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(total_amount), 0) AS total
     FROM orders
     WHERE payment_status = 'paid'
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY ym ASC"
);
$sixMonthSalesStmt->execute();
$sixMonthRows = $sixMonthSalesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$salesMap = [];
foreach ($sixMonthRows as $r) {
    $salesMap[(string) ($r['ym'] ?? '')] = (float) ($r['total'] ?? 0);
}
$salesLabels = [];
$salesSeries = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} month"));
    $salesLabels[] = date('M y', strtotime($key . '-01'));
    $salesSeries[] = $salesMap[$key] ?? 0;
}

$topProductsStmt = $conn->prepare(
    "SELECT
        COALESCE(NULLIF(oi.fabric_name_snapshot, ''), oi.product_name, 'Product') AS product_name,
        COALESCE(SUM(CASE
            WHEN oi.quantity_meters IS NOT NULL AND oi.quantity_meters > 0 THEN oi.quantity_meters
            WHEN oi.quantity IS NOT NULL AND oi.quantity > 0 THEN oi.quantity
            ELSE 0
        END), 0) AS qty_sold
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'paid'
       AND o.created_at >= ? AND o.created_at < ?
     GROUP BY product_name
     ORDER BY qty_sold DESC
     LIMIT 5"
);
$topProductsStmt->bind_param('ss', $rangeStartAt, $rangeEndExclusive);
$topProductsStmt->execute();
$topProducts = $topProductsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statusColors = [
    'pending' => 'warning',
    'confirmed' => 'info',
    'packed' => 'primary',
    'shipped' => 'primary',
    'delivered' => 'success',
    'cancelled' => 'danger',
    'returned' => 'secondary',
    'refunded' => 'dark',
];

$metaTitle = 'Admin Dashboard | Amber Fabrics';
$metaDescription = 'Ecommerce dashboard overview for Amber Fabrics.';
$metaKeywords = 'admin, dashboard, ecommerce, orders, products, inquiries, profit';
include 'partials/header.php';
?>

<div class="dashboard-container">
    <div class="dashboard-header admin-page-header d-flex justify-content-between align-items-end flex-wrap gap-3">
        <div>
            <h1 class="mb-1">Store Dashboard</h1>
            <p class="text-muted mb-0">Performance snapshot for <?php echo e($rangeLabel); ?></p>
        </div>
        <form method="GET" class="d-flex gap-2 align-items-end admin-dashboard-filter">
            <div>
                <label class="form-label mb-1">From</label>
                <input type="date" name="from" class="form-control" value="<?php echo e($rangeFrom); ?>">
            </div>
            <div>
                <label class="form-label mb-1">To</label>
                <input type="date" name="to" class="form-control" value="<?php echo e($rangeTo); ?>">
            </div>
            <div class="admin-filter-actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-outline-secondary" href="dashboard.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
            </div>
        </form>
    </div>

    <?php if ($staleOnlinePending > 0 || $refundPendingCount > 0 || $codPendingConfirm > 0 || $cronLagMinutes === null || $cronLagMinutes > 20): ?>
    <div class="alert alert-warning mb-3">
        <div class="fw-semibold mb-1">Operational Alerts</div>
        <div class="small">
            <?php if ($staleOnlinePending > 0): ?>Stale online pending orders: <strong><?php echo $staleOnlinePending; ?></strong>. <?php endif; ?>
            <?php if ($refundPendingCount > 0): ?>Refund queue (cancelled + paid): <strong><?php echo $refundPendingCount; ?></strong>. <?php endif; ?>
            <?php if ($codPendingConfirm > 0): ?>Pending COD confirmations: <strong><?php echo $codPendingConfirm; ?></strong>. <?php endif; ?>
            <?php if ($cronLagMinutes === null): ?>Cron last-run timestamp not found.<?php elseif ($cronLagMinutes > 20): ?>Cron last run <?php echo (int) $cronLagMinutes; ?> minutes ago.<?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="dashboard-kpi-grid">
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Total Sales</p>
            <h3 class="kpi-value">Rs <?php echo number_format($totalSalesAllTime, 2); ?></h3>
            <p class="kpi-sub">All-time paid sales</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Today Sales</p>
            <h3 class="kpi-value">Rs <?php echo number_format($todaySales, 2); ?></h3>
            <p class="kpi-sub">Paid orders today</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Net Profit / Loss</p>
            <h3 class="kpi-value <?php echo $netProfit >= 0 ? 'kpi-positive' : 'kpi-negative'; ?>">Rs <?php echo number_format($netProfit, 2); ?></h3>
            <p class="kpi-sub">For selected date range</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Total Orders</p>
            <h3 class="kpi-value"><?php echo number_format($totalOrders); ?></h3>
            <p class="kpi-sub">All active orders</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Pending Orders</p>
            <h3 class="kpi-value"><?php echo number_format($pendingOrders); ?></h3>
            <p class="kpi-sub">Awaiting processing</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Delivered Orders</p>
            <h3 class="kpi-value"><?php echo number_format($deliveredOrders); ?></h3>
            <p class="kpi-sub">Completed deliveries</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Cancelled Orders</p>
            <h3 class="kpi-value"><?php echo number_format($cancelledOrders); ?></h3>
            <p class="kpi-sub">Cancelled order count</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Low Stock Products</p>
            <h3 class="kpi-value"><?php echo number_format($lowStockProducts); ?></h3>
            <p class="kpi-sub">Stock <= 3 units/meters</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Product Cost</p>
            <h3 class="kpi-value">Rs <?php echo number_format($productCostEstimate, 2); ?></h3>
            <p class="kpi-sub">COGS in selected range</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Total Expenses</p>
            <h3 class="kpi-value">Rs <?php echo number_format($totalExpenses, 2); ?></h3>
            <p class="kpi-sub">Recorded in selected range</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Shipping (Expense)</p>
            <h3 class="kpi-value">Rs <?php echo number_format($shippingExpense, 2); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Marketing (Expense)</p>
            <h3 class="kpi-value">Rs <?php echo number_format($marketingExpense, 2); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Packaging (Expense)</p>
            <h3 class="kpi-value">Rs <?php echo number_format($packagingExpense, 2); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Payment Fees</p>
            <h3 class="kpi-value">Rs <?php echo number_format($paymentFeesExpense, 2); ?></h3>
            <p class="kpi-sub">Detected from expense notes</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Returns</p>
            <h3 class="kpi-value">Rs <?php echo number_format($returnsExpense, 2); ?></h3>
            <p class="kpi-sub">Refunded/returned order value</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Export Inquiries</p>
            <h3 class="kpi-value"><?php echo number_format($exportInquiries); ?></h3>
            <p class="kpi-sub">Total global inquiry leads</p>
        </div>
    </div>

    <div class="dashboard-actions">
        <a class="btn btn-dark" href="fabrics.php">Manage Products</a>
        <a class="btn btn-primary" href="orders.php">View Orders</a>
        <a class="btn btn-outline-danger" href="orders.php?refund_queue=1">Refund Queue</a>
        <a class="btn btn-outline-secondary" href="expenses.php">Manage Expenses</a>
        <a class="btn btn-outline-secondary" href="inquiries.php">Export Inquiries</a>
        <a class="btn btn-outline-primary" href="settings.php">Site Settings</a>
    </div>

    <div class="card mt-4 mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Net Profit Formula Breakdown (<?php echo e($rangeLabel); ?>)</h5>
                <span class="badge <?php echo $netProfit >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                    <?php echo $netProfit >= 0 ? 'Profit' : 'Loss'; ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr>
                            <th scope="row">Sales</th>
                            <td class="text-end">Rs <?php echo number_format($totalSalesMonth, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Product Cost</th>
                            <td class="text-end">- Rs <?php echo number_format($productCostEstimate, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Shipping</th>
                            <td class="text-end">- Rs <?php echo number_format($shippingExpense, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Marketing</th>
                            <td class="text-end">- Rs <?php echo number_format($marketingExpense, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Packaging</th>
                            <td class="text-end">- Rs <?php echo number_format($packagingExpense, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Payment Fees</th>
                            <td class="text-end">- Rs <?php echo number_format($paymentFeesExpense, 2); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Returns</th>
                            <td class="text-end">- Rs <?php echo number_format($returnsExpense, 2); ?></td>
                        </tr>
                        <tr class="table-light fw-bold">
                            <th scope="row">Net Profit / Loss</th>
                            <td class="text-end <?php echo $netProfit >= 0 ? 'text-success' : 'text-danger'; ?>">Rs <?php echo number_format($netProfit, 2); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <h5 class="mb-0">Sales Trend (Last 6 Months)</h5>
                        <span class="small text-muted">Paid orders</span>
                    </div>
                    <canvas id="salesTrendChart" height="120"></canvas>
                </div>
            </div>
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <h5 class="mb-0">Recent Orders (<?php echo e($rangeLabel); ?>)</h5>
                        <a href="orders.php" class="small">View all -></a>
                    </div>
                    <?php if (!empty($recentOrders)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td class="font-monospace"><?php echo e((string) $order['order_number']); ?></td>
                                    <td><?php echo e((string) $order['customer_name']); ?></td>
                                    <td>Rs <?php echo number_format((float) ($order['total_amount'] ?? 0), 2); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($order['payment_status'] ?? '') === 'paid' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst(e((string) $order['payment_status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusColors[$order['order_status']] ?? 'secondary'; ?>">
                                            <?php echo ucfirst(e((string) $order['order_status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M', strtotime((string) $order['created_at'])); ?></td>
                                    <td><a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="small">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No orders found for this date range.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="card-title mb-3">Priority Alerts</h6>
                    <div class="dashboard-mini-list">
                        <div class="mini-item">
                            <span class="mini-dot dot-warning"></span>
                            Pending Orders
                            <strong><?php echo number_format($pendingOrders); ?></strong>
                        </div>
                        <div class="mini-item">
                            <span class="mini-dot dot-danger"></span>
                            Low Stock Products
                            <strong><?php echo number_format($lowStockProducts); ?></strong>
                        </div>
                        <div class="mini-item">
                            <span class="mini-dot dot-success"></span>
                            Delivered Orders
                            <strong><?php echo number_format($deliveredOrders); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="card-title mb-3">Quick Shortcuts</h6>
                    <div class="d-grid gap-2">
                        <a href="add-fabric.php" class="btn btn-sm btn-outline-dark">Add New Product</a>
                        <a href="coupons.php" class="btn btn-sm btn-outline-secondary">Manage Coupons</a>
                        <a href="customers.php" class="btn btn-sm btn-outline-secondary">Customer Accounts</a>
                    </div>
                </div>
            </div>
            <div class="card mt-4">
                <div class="card-body">
                    <h6 class="card-title mb-3">Top Selling Products (<?php echo e($rangeLabel); ?>)</h6>
                    <?php if (empty($topProducts)): ?>
                        <p class="text-muted small mb-0">No paid sales in this date range yet.</p>
                    <?php else: ?>
                        <div class="dashboard-mini-list">
                            <?php foreach ($topProducts as $tp): ?>
                                <div class="mini-item">
                                    <span class="text-truncate pe-2"><?php echo e((string) ($tp['product_name'] ?? 'Product')); ?></span>
                                    <strong><?php echo number_format((float) ($tp['qty_sold'] ?? 0)); ?> sold</strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var el = document.getElementById('salesTrendChart');
    if (!el || typeof Chart === 'undefined') return;
    new Chart(el, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($salesLabels); ?>,
            datasets: [{
                label: 'Sales (INR)',
                data: <?php echo json_encode($salesSeries); ?>,
                tension: 0.35,
                borderColor: '#0f766e',
                backgroundColor: 'rgba(15,118,110,0.12)',
                fill: true,
                pointRadius: 3,
                pointHoverRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (v) { return 'Rs ' + Number(v).toLocaleString(); }
                    }
                }
            }
        }
    });
})();
</script>

<?php include 'partials/footer.php'; ?>
