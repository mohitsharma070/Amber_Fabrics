<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$reviewTableReady = false;
try {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'product_reviews'"
    );
    $stmt->execute();
    $reviewTableReady = ((int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0);
} catch (Throwable $e) {
    $reviewTableReady = false;
}

$validStatuses = ['pending', 'approved', 'rejected'];
$perPageOptions = [20, 50, 100];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filterStatus = trim((string) ($_POST['filter_status'] ?? ''));
    if (!in_array($filterStatus, array_merge([''], $validStatuses), true)) {
        $filterStatus = 'pending';
    }
    $q = trim((string) ($_POST['q'] ?? ''));
    $page = list_sanitize_page((int) ($_POST['filter_page'] ?? 1));
    $perPage = list_sanitize_per_page((int) ($_POST['filter_per_page'] ?? $perPageOptions[0]), $perPageOptions);
    $returnParams = [
        'status' => $filterStatus,
        'q' => $q,
        'page' => $page,
        'per_page' => $perPage,
    ];
    $returnQuery = list_build_query($returnParams);
    $returnUrl = 'reviews.php' . ($returnQuery !== '' ? ('?' . $returnQuery) : '');

    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect($returnUrl);
    }
    if (!$reviewTableReady) {
        flash('error', 'Review table is not ready. Run setup first.');
        redirect($returnUrl);
    }

    $reviewId = (int) ($_POST['review_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));

    if ($reviewId <= 0 || !in_array($newStatus, $validStatuses, true)) {
        flash('error', 'Invalid moderation request.');
        redirect($returnUrl);
    }

    try {
        $stmt = $conn->prepare("UPDATE product_reviews SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $reviewId);
        $stmt->execute();
        flash('success', 'Review status updated to ' . ucfirst($newStatus) . '.');
    } catch (Throwable $e) {
        flash('error', 'Could not update review status.');
    }

    redirect($returnUrl);
}

$statusFilter = trim((string) ($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, array_merge([''], $validStatuses), true)) {
    $statusFilter = 'pending';
}
$search = trim((string) ($_GET['q'] ?? ''));
$perPage = list_sanitize_per_page((int) ($_GET['per_page'] ?? $perPageOptions[0]), $perPageOptions);
$page = list_sanitize_page((int) ($_GET['page'] ?? 1));

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$reviews = [];
$total = 0;
$pages = 1;

if ($reviewTableReady) {
    try {
        $countStmt = $conn->prepare(
            "SELECT status, COUNT(*) AS total
             FROM product_reviews
             GROUP BY status"
        );
        $countStmt->execute();
        $rows = $countStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $k = (string) ($row['status'] ?? '');
            if (isset($counts[$k])) {
                $counts[$k] = (int) ($row['total'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    }

    $where = [];
    $types = '';
    $params = [];

    if ($statusFilter !== '') {
        $where[] = 'pr.status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $where[] = '(pr.review_text LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR f.name LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    $countSql = "SELECT COUNT(*) AS total FROM product_reviews pr
                 JOIN customers c ON c.id = pr.customer_id
                 JOIN fabrics f ON f.id = pr.product_id
                 {$whereSql}";
    $countStmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = list_clamp_page($page, $pages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT
                pr.id, pr.product_id, pr.customer_id, pr.rating, pr.review_text, pr.status, pr.reviewed_at, pr.updated_at,
                c.name AS customer_name, c.email AS customer_email,
                f.name AS product_name
            FROM product_reviews pr
            JOIN customers c ON c.id = pr.customer_id
            JOIN fabrics f ON f.id = pr.product_id
            {$whereSql}
            ORDER BY
                CASE pr.status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                pr.updated_at DESC,
                pr.id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$metaTitle = 'Review Moderation | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Review Moderation</h1>
        <p class="text-muted mb-0">Process pending and rejected product reviews.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-warning text-dark">Pending: <?php echo (int) $counts['pending']; ?></span>
        <span class="badge bg-success">Approved: <?php echo (int) $counts['approved']; ?></span>
        <span class="badge bg-danger">Rejected: <?php echo (int) $counts['rejected']; ?></span>
    </div>
</div>

<?php if (!$reviewTableReady): ?>
    <div class="alert alert-warning">`product_reviews` table not found. Run `php database/setup.php`.</div>
<?php else: ?>
    <form method="GET" action="reviews.php" class="row g-2 mb-3 admin-filter-form">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>All</option>
                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" value="<?php echo e($search); ?>" placeholder="Customer, email, product, or review text">
        </div>
        <div class="col-md-1">
            <label class="form-label">Rows</label>
            <select name="per_page" class="form-select">
                <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?php echo (int) $opt; ?>" <?php echo $perPage === (int) $opt ? 'selected' : ''; ?>><?php echo (int) $opt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end gap-2 admin-filter-actions">
            <button class="btn btn-primary w-100" type="submit">Apply</button>
            <a class="btn btn-outline-secondary w-100" href="reviews.php">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped align-middle admin-card-table">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Rating</th>
                    <th>Review</th>
                    <th>Status</th>
                    <th>Reviewed At</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviews)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No reviews found for current filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($reviews as $r): ?>
                    <?php
                        $rid = (int) ($r['id'] ?? 0);
                        $status = (string) ($r['status'] ?? 'pending');
                        $rating = max(1, min(5, (int) ($r['rating'] ?? 0)));
                        $statusClass = $status === 'approved' ? 'bg-success' : ($status === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                    <tr>
                        <td>#<?php echo $rid; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo e((string) ($r['product_name'] ?? '')); ?></div>
                            <div class="small text-muted">Product ID: <?php echo (int) ($r['product_id'] ?? 0); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo e((string) ($r['customer_name'] ?? '')); ?></div>
                            <div class="small text-muted"><?php echo e((string) ($r['customer_email'] ?? '')); ?></div>
                        </td>
                        <td>
                            <span class="text-warning"><?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?></span>
                            <div class="small text-muted"><?php echo $rating; ?>/5</div>
                        </td>
                        <td style="max-width: 380px;">
                            <div class="small"><?php echo e((string) ($r['review_text'] ?? '')); ?></div>
                        </td>
                        <td><span class="badge <?php echo $statusClass; ?>"><?php echo e(ucfirst($status)); ?></span></td>
                        <td class="small"><?php echo e((string) ($r['reviewed_at'] ?? '')); ?></td>
                        <td class="text-end admin-row-actions">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                <?php if ($status !== 'approved'): ?>
                                    <form method="POST" action="reviews.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="review_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="status" value="approved">
                                        <input type="hidden" name="filter_status" value="<?php echo e($statusFilter); ?>">
                                        <input type="hidden" name="q" value="<?php echo e($search); ?>">
                                        <input type="hidden" name="filter_page" value="<?php echo (int) $page; ?>">
                                        <input type="hidden" name="filter_per_page" value="<?php echo (int) $perPage; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Approve</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status !== 'rejected'): ?>
                                    <form method="POST" action="reviews.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="review_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <input type="hidden" name="filter_status" value="<?php echo e($statusFilter); ?>">
                                        <input type="hidden" name="q" value="<?php echo e($search); ?>">
                                        <input type="hidden" name="filter_page" value="<?php echo (int) $page; ?>">
                                        <input type="hidden" name="filter_per_page" value="<?php echo (int) $perPage; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status !== 'pending'): ?>
                                    <form method="POST" action="reviews.php" class="d-inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="review_id" value="<?php echo $rid; ?>">
                                        <input type="hidden" name="status" value="pending">
                                        <input type="hidden" name="filter_status" value="<?php echo e($statusFilter); ?>">
                                        <input type="hidden" name="q" value="<?php echo e($search); ?>">
                                        <input type="hidden" name="filter_page" value="<?php echo (int) $page; ?>">
                                        <input type="hidden" name="filter_per_page" value="<?php echo (int) $perPage; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark Pending</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php echo render_pagination($page, $pages, ['status' => $statusFilter, 'q' => $search, 'per_page' => $perPage], 'page', $total, $perPage); ?>
<?php endif; ?>

<?php include 'partials/footer.php'; ?>
