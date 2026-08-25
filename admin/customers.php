<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where  .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.country LIKE ?)";
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}

$countStmt = $conn->prepare("SELECT COUNT(*) FROM customers c WHERE $where");
if ($types !== '') { $countStmt->bind_param($types, ...$params); }
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_row()[0];
$pages = max(1, (int) ceil($total / $perPage));

$hasIsActive = false;
try {
    $colStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'customers'
           AND COLUMN_NAME = 'is_active'"
    );
    $colStmt->execute();
    $colRow = $colStmt->get_result()->fetch_assoc();
    $hasIsActive = ((int) ($colRow['total'] ?? 0)) > 0;
} catch (Throwable $e) {
    $hasIsActive = false;
}

$activeSelect = $hasIsActive ? 'c.is_active' : '1 AS is_active';

$stmt = $conn->prepare(
    "SELECT c.id, c.name, c.email, c.country, c.phone, {$activeSelect}, c.created_at,
            COUNT(o.id) AS order_count
     FROM customers c
     LEFT JOIN orders o ON o.customer_id = c.id
     WHERE $where
     GROUP BY c.id
     ORDER BY c.created_at DESC
     LIMIT ? OFFSET ?"
);
array_push($params, $perPage, $offset);
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$metaTitle = 'Customers | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header u-flex u-justify-between u-items-center u-mb-4">
    <h1 class="u-mb-0">Customers</h1>
    <span class="ui-badge ui-badge--neutral u-text-small"><?php echo number_format($total); ?> total</span>
</div>

<form method="GET" class="l-grid l-grid--12 u-gap-2 u-mb-4 admin-filter-form">
    <div class="l-col-md-five">
        <input type="text" name="search" class="ui-input" placeholder="Search name, email, country..." aria-label="Search customers" value="<?php echo e($search); ?>">
    </div>
    <div class="l-col-auto u-flex u-gap-2 admin-filter-actions">
        <button class="ui-button ui-button--secondary"><?php echo ui_icon('search'); ?>Search</button>
        <?php if ($search): ?><a href="customers.php" class="ui-button ui-button--danger-outline"><?php echo ui_icon('x-circle'); ?>Clear</a><?php endif; ?>
    </div>
</form>

<div class="ui-card">
    <div class="ui-table-wrap">
        <table class="ui-table ui-table--hover u-mb-0 admin-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Phone</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" class="u-text-center u-text-muted u-py-4">No customers found.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><a href="customer-view.php?id=<?php echo (int) ($c['id'] ?? 0); ?>"><?php echo e($c['name']); ?></a></td>
                        <td><a href="mailto:<?php echo e($c['email']); ?>"><?php echo e($c['email']); ?></a></td>
                        <td><?php echo e($c['country'] ?: '-'); ?></td>
                        <td><?php echo e($c['phone'] ?: '-'); ?></td>
                        <td><?php echo e($c['order_count']); ?></td>
                        <td>
                            <?php if ($c['is_active']): ?>
                                <span class="ui-badge ui-badge--success">Active</span>
                            <?php else: ?>
                                <span class="ui-badge ui-badge--neutral">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php echo render_pagination($page, $pages, ['search' => $search], 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>
