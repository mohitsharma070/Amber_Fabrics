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

$stmt = $conn->prepare(
    "SELECT c.id, c.name, c.email, c.country, c.phone, c.is_active, c.created_at,
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Customers</h1>
    <span class="badge bg-secondary fs-6"><?php echo number_format($total); ?> total</span>
</div>

<form method="GET" class="mb-4">
    <div class="input-group" style="max-width:380px;">
        <input type="text" name="search" class="form-control" placeholder="Search name, email, country..." value="<?php echo e($search); ?>">
        <button class="btn btn-outline-secondary">Search</button>
        <?php if ($search): ?><a href="customers.php" class="btn btn-outline-danger">Clear</a><?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
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
                    <tr><td colspan="7" class="text-center text-muted py-4">No customers found.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo e($c['name']); ?></td>
                        <td><a href="mailto:<?php echo e($c['email']); ?>"><?php echo e($c['email']); ?></a></td>
                        <td><?php echo e($c['country'] ?: '-'); ?></td>
                        <td><?php echo e($c['phone'] ?: '-'); ?></td>
                        <td><?php echo e($c['order_count']); ?></td>
                        <td>
                            <?php if ($c['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
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
