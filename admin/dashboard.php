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

// --- Consolidated stats: 3 queries instead of 12 individual ones ---

// 1. All order-level counters in a single pass over the orders table.
$_orderStats = $conn->query(
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0)                                          AS total_sales_all_time,
        COALESCE(SUM(CASE WHEN payment_status = 'paid' AND DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END), 0)          AS today_sales,
        COUNT(CASE WHEN {$activeOrderClause} THEN 1 END)                                                                           AS total_orders,
        COUNT(CASE WHEN {$activeOrderClause} AND order_status = 'pending' THEN 1 END)                                             AS pending_orders,
        COUNT(CASE WHEN {$activeOrderClause} AND order_status = 'delivered' THEN 1 END)                                           AS delivered_orders,
        COUNT(CASE WHEN {$activeOrderClause} AND order_status = 'cancelled' THEN 1 END)                                           AS cancelled_orders,
        COUNT(CASE WHEN payment_method IN ('razorpay','upi') AND payment_status = 'pending'
                        AND order_status IN ('pending','confirmed')
                        AND created_at < (NOW() - INTERVAL 30 MINUTE) THEN 1 END)                                                 AS stale_online_pending,
        COUNT(CASE WHEN payment_status = 'paid' AND order_status = 'cancelled' THEN 1 END)                                        AS refund_pending
     FROM orders"
)->fetch_assoc() ?: [];
$totalSalesAllTime  = (float) ($_orderStats['total_sales_all_time'] ?? 0);
$todaySales         = (float) ($_orderStats['today_sales'] ?? 0);
$totalOrders        = (int)   ($_orderStats['total_orders'] ?? 0);
$pendingOrders      = (int)   ($_orderStats['pending_orders'] ?? 0);
$deliveredOrders    = (int)   ($_orderStats['delivered_orders'] ?? 0);
$cancelledOrders    = (int)   ($_orderStats['cancelled_orders'] ?? 0);
$staleOnlinePending = (int)   ($_orderStats['stale_online_pending'] ?? 0);
$refundPendingCount = (int)   ($_orderStats['refund_pending'] ?? 0);

// 2. Count products and low-stock sellable SKUs. Variable-product parent
// inventory is intentionally ignored; each active variant is one SKU.
$totalProducts = (int) ($conn->query('SELECT COUNT(*) FROM fabrics')->fetch_row()[0] ?? 0);
$pieceThreshold = max(0, (float) plugin_setting('inventory-alert', 'piece_threshold', 5));
$meterThreshold = max(0, (float) plugin_setting('inventory-alert', 'meter_threshold', 10));
$lowStockStmt = $conn->prepare(
    "SELECT COUNT(*) FROM (
        SELECT f.id offer_id
        FROM fabrics f
        WHERE f.status='active' AND f.is_available=1 AND f.product_type='simple'
          AND ((f.unit_type='meter' AND f.stock_meters <= COALESCE(f.low_stock_threshold_meters, ?))
            OR (f.unit_type IN ('piece','set') AND f.stock <= COALESCE(f.low_stock_threshold_units, ?)))
        UNION ALL
        SELECT fv.id offer_id
        FROM fabrics f JOIN fabric_variants fv ON fv.fabric_id=f.id AND fv.is_active=1
        WHERE f.status='active' AND f.is_available=1 AND f.product_type='variable'
          AND ((f.unit_type='meter' AND fv.stock_meters <= COALESCE(f.low_stock_threshold_meters, ?))
            OR (f.unit_type IN ('piece','set') AND fv.stock <= COALESCE(f.low_stock_threshold_units, ?)))
     ) low_stock_offers"
);
$lowStockStmt->bind_param('dddd', $meterThreshold, $pieceThreshold, $meterThreshold, $pieceThreshold);
$lowStockStmt->execute();
$lowStockProducts = (int) ($lowStockStmt->get_result()->fetch_row()[0] ?? 0);

// 3. Secondary counts (COD confirmations + export inquiries) via correlated sub-selects.
$_secondaryStats = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM cod_confirmations WHERE status = 'pending') AS cod_pending,
        (SELECT COUNT(*) FROM inquiries WHERE inquiry_type = 'export')    AS export_inquiries"
)->fetch_assoc() ?: [];
$codPendingConfirm = (int) ($_secondaryStats['cod_pending'] ?? 0);
$exportInquiries   = (int) ($_secondaryStats['export_inquiries'] ?? 0);
unset($_orderStats, $_secondaryStats);
// --- End consolidated stats ---

$cronLastRunAt = '';
$cronLastSuccessAt = '';
$cronLastStatus = '';
$cronFailedJobs = 0;
$cronDegradedJobs = 0;
$cronDurationMs = 0;
$cronSummary = [];
try {
    $cronStmt = $conn->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('cron_last_run_at','cron_last_success_at','cron_last_status','cron_last_failed_jobs','cron_last_degraded_jobs','cron_last_duration_ms','cron_last_summary_json')");
    $cronStmt->execute();
    $cronSettings = [];
    foreach ($cronStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $cronRow) {
        $cronSettings[(string) $cronRow['setting_key']] = (string) ($cronRow['setting_value'] ?? '');
    }
    $cronLastRunAt = (string) ($cronSettings['cron_last_run_at'] ?? '');
    $cronLastSuccessAt = (string) ($cronSettings['cron_last_success_at'] ?? '');
    $cronLastStatus = strtolower((string) ($cronSettings['cron_last_status'] ?? ''));
    $cronFailedJobs = (int) ($cronSettings['cron_last_failed_jobs'] ?? 0);
    $cronDegradedJobs = (int) ($cronSettings['cron_last_degraded_jobs'] ?? 0);
    $cronDurationMs = (int) ($cronSettings['cron_last_duration_ms'] ?? 0);
    $decodedCronSummary = json_decode((string) ($cronSettings['cron_last_summary_json'] ?? '[]'), true);
    $cronSummary = is_array($decodedCronSummary) ? array_slice($decodedCronSummary, 0, 10) : [];
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
$cronExpectedMinutes = max(1, (int) _cfg('CRON_EXPECTED_INTERVAL_MINUTES', '10'));
$cronOverdueMinutes = max(20, $cronExpectedMinutes * 2);
$cronOverdue = $cronLagMinutes === null || $cronLagMinutes > $cronOverdueMinutes;
$cronUnhealthy = in_array($cronLastStatus, ['failed', 'degraded'], true);

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

$metaTitle = SiteContext::title('Admin Dashboard');
$metaDescription = 'Ecommerce dashboard overview for ' . SiteContext::name() . '.';
$metaKeywords = 'admin, dashboard, ecommerce, orders, products, inquiries, profit';
include 'partials/header.php';
?>

<div class="dashboard-container">
    <div class="dashboard-header admin-page-header">
        <div>
            <h1 class="u-mb-1">Store Dashboard</h1>
            <p class="u-text-muted u-mb-0">Performance snapshot for <?php echo e($rangeLabel); ?></p>
        </div>
        <form method="GET" class="u-gap-2 u-items-end admin-dashboard-filter">
            <div>
                <label for="from" class="ui-label u-mb-1">From</label>
                <input id="from" type="date" name="from" class="ui-input" value="<?php echo e($rangeFrom); ?>">
            </div>
            <div>
                <label for="to" class="ui-label u-mb-1">To</label>
                <input id="to" type="date" name="to" class="ui-input" value="<?php echo e($rangeTo); ?>">
            </div>
            <div class="admin-filter-actions">
                <button class="ui-button ui-button--primary" type="submit"><?php echo ui_icon('funnel'); ?>Apply</button>
                <a class="ui-button ui-button--secondary" href="dashboard.php"><?php echo ui_icon('arrow-counterclockwise'); ?>Reset</a>
            </div>
        </form>
    </div>

    <?php if (!$cronOverdue && $cronLastStatus === 'success'): ?>
    <div class="u-mb-3">
        <span class="ui-badge ui-badge--success">Cron healthy</span>
        <span class="u-text-small u-text-muted u-ms-2">Last run <?php echo (int) $cronLagMinutes; ?> minute(s) ago in <?php echo (int) $cronDurationMs; ?> ms.</span>
    </div>
    <?php endif; ?>

    <?php if ($staleOnlinePending > 0 || $refundPendingCount > 0 || $codPendingConfirm > 0 || $cronOverdue || $cronUnhealthy): ?>
    <div class="ui-alert <?php echo $cronLastStatus === 'failed' ? 'ui-alert--error' : 'ui-alert--warning'; ?> u-mb-3">
        <div class="u-font-semibold u-mb-1">Operational Alerts</div>
        <div class="u-text-small">
            <?php if ($staleOnlinePending > 0): ?>Stale online pending orders: <strong><?php echo $staleOnlinePending; ?></strong>. <?php endif; ?>
            <?php if ($refundPendingCount > 0): ?>Refund queue (cancelled + paid): <strong><?php echo $refundPendingCount; ?></strong>. <?php endif; ?>
            <?php if ($codPendingConfirm > 0): ?>Pending COD confirmations: <strong><?php echo $codPendingConfirm; ?></strong>. <?php endif; ?>
            <?php if ($cronLagMinutes === null): ?>Cron last-run timestamp not found.<?php elseif ($cronLagMinutes > $cronOverdueMinutes): ?>Cron last run <?php echo (int) $cronLagMinutes; ?> minutes ago.<?php endif; ?>
            <?php if ($cronLastStatus === 'failed'): ?>Cron failed with <strong><?php echo $cronFailedJobs; ?></strong> failed job(s).<?php elseif ($cronLastStatus === 'degraded'): ?>Cron completed with <strong><?php echo $cronDegradedJobs; ?></strong> degraded job(s).<?php endif; ?>
            <?php if ($cronLastStatus !== 'success' && $cronLastSuccessAt !== ''): ?> Last fully successful run: <?php echo e($cronLastSuccessAt); ?>.<?php endif; ?>
        </div>
        <?php if ($cronSummary !== []): ?>
            <ul class="u-text-small u-mb-0 u-mt-2">
                <?php foreach ($cronSummary as $cronDetail): ?>
                    <?php if (!is_array($cronDetail)) continue; ?>
                    <li>
                        <strong><?php echo e((string) ($cronDetail['job'] ?? 'Unknown job')); ?></strong>
                        <?php if (trim((string) ($cronDetail['error'] ?? '')) !== ''): ?>: <?php echo e(CronService::sanitizeError((string) $cronDetail['error'])); ?><?php endif; ?>
                        <?php foreach ((array) ($cronDetail['callbacks'] ?? []) as $callback): ?>
                            <?php if (!is_array($callback)) continue; ?>
                            <div><?php echo e((string) ($callback['callback'] ?? 'Unknown callback')); ?>: <?php echo e(CronService::sanitizeError((string) ($callback['error'] ?? 'No details supplied.'))); ?></div>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="u-text-small" href="operations.php?view=cron">View cron history</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="dashboard-kpi-grid">
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Total Sales</p>
            <h3 class="kpi-value"><?php echo e(money($totalSalesAllTime)); ?></h3>
            <p class="kpi-sub">All-time paid sales</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Today Sales</p>
            <h3 class="kpi-value"><?php echo e(money($todaySales)); ?></h3>
            <p class="kpi-sub">Paid orders today</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Net Profit / Loss</p>
            <h3 class="kpi-value <?php echo $netProfit >= 0 ? 'kpi-positive' : 'kpi-negative'; ?>"><?php echo e(money($netProfit)); ?></h3>
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
            <h3 class="kpi-value"><?php echo e(money($productCostEstimate)); ?></h3>
            <p class="kpi-sub">COGS in selected range</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Total Expenses</p>
            <h3 class="kpi-value"><?php echo e(money($totalExpenses)); ?></h3>
            <p class="kpi-sub">Recorded in selected range</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Shipping (Expense)</p>
            <h3 class="kpi-value"><?php echo e(money($shippingExpense)); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Marketing (Expense)</p>
            <h3 class="kpi-value"><?php echo e(money($marketingExpense)); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Packaging (Expense)</p>
            <h3 class="kpi-value"><?php echo e(money($packagingExpense)); ?></h3>
            <p class="kpi-sub">From expenses</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Payment Fees</p>
            <h3 class="kpi-value"><?php echo e(money($paymentFeesExpense)); ?></h3>
            <p class="kpi-sub">Detected from expense notes</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Returns</p>
            <h3 class="kpi-value"><?php echo e(money($returnsExpense)); ?></h3>
            <p class="kpi-sub">Refunded/returned order value</p>
        </div>
        <div class="dashboard-kpi-card">
            <p class="kpi-label">Export Inquiries</p>
            <h3 class="kpi-value"><?php echo number_format($exportInquiries); ?></h3>
            <p class="kpi-sub">Total global inquiry leads</p>
        </div>
    </div>

    <div class="dashboard-actions">
        <a class="ui-button ui-button--navy" href="fabrics.php">Manage Products</a>
        <a class="ui-button ui-button--primary" href="orders.php">View Orders</a>
        <a class="ui-button ui-button--danger-outline" href="orders.php?refund_queue=1">Refund Queue</a>
        <a class="ui-button ui-button--secondary" href="expenses.php">Manage Expenses</a>
        <a class="ui-button ui-button--secondary" href="inquiries.php">Export Inquiries</a>
        <a class="ui-button ui-button--outline" href="settings.php">Site Settings</a>
    </div>

    <div class="ui-card u-mt-4 u-mb-4">
        <div class="ui-card__body">
            <div class="u-flex u-justify-between u-items-center u-mb-3">
                <h5 class="u-mb-0">Net Profit Formula Breakdown (<?php echo e($rangeLabel); ?>)</h5>
                <span class="ui-badge <?php echo $netProfit >= 0 ? 'ui-badge--success' : 'ui-badge--error'; ?>">
                    <?php echo $netProfit >= 0 ? 'Profit' : 'Loss'; ?>
                </span>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table ui-table--compact u-align-middle u-mb-0">
                    <tbody>
                        <tr>
                            <th scope="row">Sales</th>
                            <td class="u-text-end"><?php echo e(money($totalSalesMonth)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Product Cost</th>
                            <td class="u-text-end">- <?php echo e(money($productCostEstimate)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Shipping</th>
                            <td class="u-text-end">- <?php echo e(money($shippingExpense)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Marketing</th>
                            <td class="u-text-end">- <?php echo e(money($marketingExpense)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Packaging</th>
                            <td class="u-text-end">- <?php echo e(money($packagingExpense)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Payment Fees</th>
                            <td class="u-text-end">- <?php echo e(money($paymentFeesExpense)); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Less: Returns</th>
                            <td class="u-text-end">- <?php echo e(money($returnsExpense)); ?></td>
                        </tr>
                        <tr class="ui-table__head--light u-font-bold">
                            <th scope="row">Net Profit / Loss</th>
                            <td class="u-text-end <?php echo $netProfit >= 0 ? 'u-text-success' : 'u-text-danger'; ?>"><?php echo e(money($netProfit)); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="l-grid l-grid--12 u-gap-4">
        <div class="l-col-lg-eight">
            <div class="ui-card u-mb-4">
                <div class="ui-card__body">
                    <div class="u-flex u-justify-between u-mb-2">
                        <h5 class="u-mb-0">Sales Trend (Last 6 Months)</h5>
                        <span class="u-text-small u-text-muted">Paid orders</span>
                    </div>
                    <canvas id="salesTrendChart" height="120" data-admin-chart="<?php echo ui_data_json(['labels' => $salesLabels, 'series' => $salesSeries, 'label' => 'Sales (INR)']); ?>"></canvas>
                </div>
            </div>
            <div class="ui-card u-h-full">
                <div class="ui-card__body">
                    <div class="u-flex u-justify-between u-mb-3">
                        <h5 class="u-mb-0">Recent Orders (<?php echo e($rangeLabel); ?>)</h5>
                        <a href="orders.php" class="u-text-small">View all -></a>
                    </div>
                    <?php if (!empty($recentOrders)): ?>
                    <div class="ui-table-wrap">
                        <table class="ui-table ui-table--compact u-mb-0">
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
                                    <td class="u-font-mono"><?php echo e((string) $order['order_number']); ?></td>
                                    <td><?php echo e((string) $order['customer_name']); ?></td>
                                    <td><?php echo e(money((float) ($order['total_amount'] ?? 0))); ?></td>
                                    <td>
                                        <span class="ui-badge <?php echo ($order['payment_status'] ?? '') === 'paid' ? 'ui-badge--success' : 'ui-badge--neutral'; ?>">
                                            <?php echo ucfirst(e((string) $order['payment_status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ui-badge ui-badge--<?php echo e(ui_tone((string) ($statusColors[$order['order_status']] ?? 'secondary'))); ?>">
                                            <?php echo ucfirst(e((string) $order['order_status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M', strtotime((string) $order['created_at'])); ?></td>
                                    <td><a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="u-text-small">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <p class="u-text-muted u-mb-0">No orders found for this date range.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="l-col-lg-third">
            <div class="ui-card u-mb-4">
                <div class="ui-card__body">
                    <h6 class="ui-card__title u-mb-3">Priority Alerts</h6>
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

            <div class="ui-card">
                <div class="ui-card__body">
                    <h6 class="ui-card__title u-mb-3">Quick Shortcuts</h6>
                    <div class="u-grid u-gap-2">
                        <a href="add-fabric.php" class="ui-button ui-button--small ui-button--secondary">Add New Product</a>
                        <a href="coupons.php" class="ui-button ui-button--small ui-button--secondary">Manage Coupons</a>
                        <a href="customers.php" class="ui-button ui-button--small ui-button--secondary">Customer Accounts</a>
                    </div>
                </div>
            </div>
            <div class="ui-card u-mt-4">
                <div class="ui-card__body">
                    <h6 class="ui-card__title u-mb-3">Top Selling Products (<?php echo e($rangeLabel); ?>)</h6>
                    <?php if (empty($topProducts)): ?>
                        <p class="u-text-muted u-text-small u-mb-0">No paid sales in this date range yet.</p>
                    <?php else: ?>
                        <div class="dashboard-mini-list">
                            <?php foreach ($topProducts as $tp): ?>
                                <div class="mini-item">
                                    <span class="u-truncate u-pe-2"><?php echo e((string) ($tp['product_name'] ?? 'Product')); ?></span>
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

<?php include 'partials/footer.php'; ?>
