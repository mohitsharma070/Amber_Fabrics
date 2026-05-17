<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$allowedStatuses = ['new', 'qualified', 'quoted', 'won', 'lost', 'contacted'];
$perPageOptions = [15, 30, 50];
$sortMap = [
    'newest' => 'created_at DESC',
    'oldest' => 'created_at ASC',
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'status_asc' => 'status ASC',
    'status_desc' => 'status DESC',
];

$sanitizeStatus = static function (string $status) use ($allowedStatuses): string {
    return in_array($status, $allowedStatuses, true) ? $status : '';
};

$stateToQuery = static function (array $state, bool $includePage = true): string {
    $params = [
        'q' => $state['q'] ?? '',
        'status' => $state['status'] ?? '',
        'sort' => $state['sort'] ?? 'newest',
        'per_page' => $state['per_page'] ?? 15,
    ];
    if ($includePage) {
        $params['page'] = $state['page'] ?? 1;
    }
    return list_build_query($params);
};

$state = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => $sanitizeStatus(trim((string) ($_GET['status'] ?? ''))),
    'sort' => list_sanitize_sort(trim((string) ($_GET['sort'] ?? 'newest')), $sortMap),
    'per_page' => list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions),
    'page' => list_sanitize_page((int) ($_GET['page'] ?? 1)),
];

$search = $state['q'];
$statusFilter = $state['status'];
$sort = $state['sort'];
$perPage = $state['per_page'];
$page = $state['page'];
$offset = ($page - 1) * $perPage;
$orderBy = $sortMap[$sort];

$where = ["inquiry_type = 'export'"];
$types = '';
$params = [];

if ($search !== '') {
    $like = "%{$search}%";
    $where[] = "(name LIKE ? OR email LIKE ? OR company_name LIKE ? OR product_interested LIKE ?)";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS total FROM inquiries {$whereSql}";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$pages = max(1, (int) ceil($total / $perPage));

if ($page > $pages) {
    $page = list_clamp_page($page, $pages);
    $state['page'] = $page;
    $offset = ($page - 1) * $perPage;
}

$listSql = "SELECT id, name, company_name, email, whatsapp_number, country, product_interested, quantity, status, created_at
            FROM inquiries
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?";
$stmt = $conn->prepare($listSql);
$typesWithLimit = $types . 'ii';
$paramsWithLimit = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
$stmt->execute();
$inquiries = fetch_all_assoc($stmt->get_result());

$metaTitle = 'Export Inquiries | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Export Inquiries</h1>
        <p class="text-muted mb-0">Showing <?php echo count($inquiries); ?> of <?php echo $total; ?> export inquiries</p>
    </div>
</div>

<form class="row g-2 mb-3 admin-filter-form" method="GET">
    <div class="col-md-3">
        <input class="form-control" name="q" placeholder="Name, email, company, product" value="<?php echo e($search); ?>">
    </div>
    <div class="col-md-3">
        <select class="form-select" name="status">
            <option value="">All Status</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="sort">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
            <option value="status_asc" <?php echo $sort === 'status_asc' ? 'selected' : ''; ?>>Status A-Z</option>
            <option value="status_desc" <?php echo $sort === 'status_desc' ? 'selected' : ''; ?>>Status Z-A</option>
        </select>
    </div>
    <div class="col-md-2">
        <select class="form-select" name="per_page">
            <?php foreach ($perPageOptions as $size): ?>
                <option value="<?php echo $size; ?>" <?php echo $perPage === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-auto d-flex gap-2 admin-filter-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        <a class="btn btn-outline-secondary" href="export-inquiries.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped align-middle admin-card-table">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Company</th>
                <th>Email</th>
                <th>WhatsApp</th>
                <th>Country</th>
                <th>Product</th>
                <th>Quantity</th>
                <th>Status</th>
                <th>Received</th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($inquiries)): ?>
                <tr><td colspan="11" class="text-center text-muted">No export inquiries found.</td></tr>
            <?php endif; ?>
            <?php foreach ($inquiries as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td><?php echo e($row['name']); ?></td>
                <td><?php echo e($row['company_name']); ?></td>
                <td><a href="mailto:<?php echo e($row['email']); ?>"><?php echo e($row['email']); ?></a></td>
                <td><?php echo e($row['whatsapp_number']); ?></td>
                <td><?php echo e($row['country']); ?></td>
                <td><?php echo e($row['product_interested']); ?></td>
                <td><?php echo e($row['quantity']); ?></td>
                <td><?php echo ucfirst(e($row['status'])); ?></td>
                <td><?php echo e($row['created_at']); ?></td>
                <td class="text-end admin-row-actions"><a class="btn btn-sm btn-primary" href="inquiry-view.php?id=<?php echo (int) $row['id']; ?>"><i class="bi bi-eye me-1"></i>View</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php echo render_pagination($page, $pages, $state, 'page', $total, $perPage); ?>

<?php include 'partials/footer.php'; ?>
