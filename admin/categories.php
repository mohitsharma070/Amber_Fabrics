<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors = [];
$maxSize = 2 * 1024 * 1024; // 2MB
$allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

$processCategoryImageUpload = static function (array $file, string $slug) use ($maxSize, $allowedExt, $allowedMime): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = mime_content_type((string) ($file['tmp_name'] ?? '')) ?: '';
    if (($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('Image must be under 2MB.');
    }
    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true) || !@getimagesize((string) $file['tmp_name'])) {
        throw new RuntimeException('Only valid JPG, PNG or WEBP images are allowed.');
    }

    $safeSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slug));
    $safeSlug = trim($safeSlug, '-');
    if ($safeSlug === '') {
        $safeSlug = 'category';
    }
    $uploadDir = __DIR__ . '/../images/categories/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $filename = $safeSlug . '.' . $ext;
    $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new RuntimeException('Failed to save uploaded image.');
    }
    return '/images/categories/' . $filename;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('categories.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugRaw = trim((string) ($_POST['slug'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slugRaw));
        $slug = trim($slug, '-');

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }
        if ($slug === '') {
            $errors[] = 'Category slug is required.';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if (empty($errors)) {
            try {
                $imagePath = null;
                if (!empty($_FILES['image']['name'])) {
                    $imagePath = $processCategoryImageUpload($_FILES['image'], $slug);
                }
                $parentIdNull = $parentId > 0 ? $parentId : null;
                $stmt = $conn->prepare("INSERT INTO categories (name, slug, parent_id, image, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssiis', $name, $slug, $parentIdNull, $imagePath, $status);
                $stmt->execute();
                flash('success', 'Category added successfully.');
            } catch (Throwable $e) {
                flash('error', 'Could not add category. ' . $e->getMessage());
            }
            redirect('categories.php');
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugRaw = trim((string) ($_POST['slug'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slugRaw));
        $slug = trim($slug, '-');

        if ($id <= 0 || $name === '' || $slug === '') {
            flash('error', 'Please provide valid category data.');
            redirect('categories.php');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        try {
            $stmtCurrent = $conn->prepare("SELECT image FROM categories WHERE id = ? LIMIT 1");
            $stmtCurrent->bind_param('i', $id);
            $stmtCurrent->execute();
            $current = $stmtCurrent->get_result()->fetch_assoc();
            $imagePath = (string) ($current['image'] ?? '');

            if (!empty($_FILES['image']['name'])) {
                $uploaded = $processCategoryImageUpload($_FILES['image'], $slug);
                if ($uploaded !== null) {
                    $imagePath = $uploaded;
                }
            }

            $parentIdNull = $parentId > 0 ? $parentId : null;
            $stmt = $conn->prepare("UPDATE categories SET name = ?, slug = ?, parent_id = ?, image = ?, status = ? WHERE id = ?");
            $stmt->bind_param('ssiisi', $name, $slug, $parentIdNull, $imagePath, $status, $id);
            $stmt->execute();
            flash('success', 'Category updated.');
        } catch (Throwable $e) {
            flash('error', 'Could not update category. Slug may already exist.');
        }
        redirect('categories.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid category selected.');
            redirect('categories.php');
        }

        $slug = '';
        $catStmt = $conn->prepare("SELECT slug FROM categories WHERE id = ? LIMIT 1");
        $catStmt->bind_param('i', $id);
        $catStmt->execute();
        $cat = $catStmt->get_result()->fetch_assoc();
        if ($cat) {
            $slug = (string) ($cat['slug'] ?? '');
        }

        if ($slug !== '') {
            $usedStmt = $conn->prepare("SELECT COUNT(*) AS total FROM fabrics WHERE category = ?");
            $usedStmt->bind_param('s', $slug);
            $usedStmt->execute();
            $usedCount = (int) ($usedStmt->get_result()->fetch_assoc()['total'] ?? 0);
            if ($usedCount > 0) {
                flash('error', 'Cannot delete this category because products are using it.');
                redirect('categories.php');
            }
        }

        $del = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $del->bind_param('i', $id);
        $del->execute();
        flash('success', 'Category deleted.');
        redirect('categories.php');
    }
}

$categories = [];
$parentCategories = [];
try {
    $stmt = $conn->prepare("SELECT id, name, slug, parent_id, image, status, created_at FROM categories ORDER BY parent_id ASC, name ASC");
    $stmt->execute();
    $allCats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($allCats as $cat) {
        $categories[] = $cat;
        if (($cat['parent_id'] ?? null) === null) {
            $parentCategories[] = $cat;
        }
    }
} catch (Throwable $e) {
    $categories = [];
    $parentCategories = [];
}

$metaTitle = 'Manage Categories | Amber Fabrics';
$metaDescription = 'Create, edit and delete product categories.';
$metaKeywords = 'admin, categories, manage';
include 'partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Categories</h1>
        <p class="text-muted mb-0">Manage product categories for your catalog.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">Add Category</h5>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Slug</label>
                <input type="text" name="slug" class="form-control" placeholder="e.g. bedsheets" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Parent Category</label>
                <select name="parent_id" class="form-select">
                    <option value="">None (Top-level)</option>
                    <?php foreach ($parentCategories as $parent): ?>
                        <option value="<?php echo (int) $parent['id']; ?>"><?php echo e((string) $parent['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Image</label>
                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped align-middle">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Slug</th>
            <th>Parent</th>
            <th>Status</th>
            <th>Image</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($categories)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No categories found.</td></tr>
        <?php else: ?>
            <?php foreach ($categories as $cat): ?>
                <tr <?php echo (($cat['parent_id'] ?? null) !== null) ? 'style="background-color:#f9f9f9;"' : ''; ?>>
                    <td><?php echo (int) $cat['id']; ?></td>
                    <td>
                        <?php if (($cat['parent_id'] ?? null) !== null): ?>
                            <span style="margin-left:20px;">↳</span>
                        <?php endif; ?>
                        <?php echo e((string) $cat['name']); ?>
                    </td>
                    <td><code><?php echo e((string) $cat['slug']); ?></code></td>
                    <td>
                        <?php 
                        $parentName = '';
                        if (($cat['parent_id'] ?? null) !== null) {
                            foreach ($categories as $potentialParent) {
                                if ((int) $potentialParent['id'] === (int) ($cat['parent_id'] ?? 0)) {
                                    $parentName = (string) $potentialParent['name'];
                                    break;
                                }
                            }
                        }
                        echo $parentName ? e($parentName) : '<span class="text-muted">-</span>';
                        ?>
                    </td>
                    <td>
                        <?php if ((string) $cat['status'] === 'active'): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo !empty($cat['image']) ? 'Set' : 'Not set'; ?></td>
                    <td>
                        <details>
                            <summary class="btn btn-sm btn-outline-secondary">Edit</summary>
                            <form method="post" class="row g-2 mt-2" enctype="multipart/form-data">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo (int) $cat['id']; ?>">
                                <div class="col-md-3">
                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo e((string) $cat['name']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <input type="text" name="slug" class="form-control form-control-sm" value="<?php echo e((string) $cat['slug']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <select name="parent_id" class="form-select form-select-sm">
                                        <option value="">None (Top-level)</option>
                                        <?php foreach ($parentCategories as $parent): ?>
                                            <?php if ((int) $parent['id'] !== (int) $cat['id']): ?>
                                                <option value="<?php echo (int) $parent['id']; ?>" <?php echo (int) ($cat['parent_id'] ?? 0) === (int) $parent['id'] ? 'selected' : ''; ?>>
                                                    <?php echo e((string) $parent['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="active" <?php echo ((string) $cat['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ((string) $cat['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="file" name="image" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="col-md-2 d-grid">
                                    <button class="btn btn-sm btn-dark" type="submit">Save</button>
                                </div>
                            </form>
                        </details>
                        <form method="post" class="d-inline-block mt-2" onsubmit="return confirm('Delete category <?php echo e((string) $cat['name']); ?>?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $cat['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'partials/footer.php'; ?>
