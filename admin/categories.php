<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors = [];
$maxSize = 2 * 1024 * 1024; // 2MB
$allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
$lockedAllowedSlugs = locked_storefront_category_slugs();
$lockedSlugListText = implode(', ', $lockedAllowedSlugs);

$processCategoryImageUpload = static function (array $file, string $slug) use ($maxSize, $allowedExt, $allowedMime): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    try {
        $validated = UploadPolicy::validate($file, $allowedExt, $allowedMime, $maxSize, true);
    } catch (Throwable $e) {
        throw new RuntimeException('Only valid JPG, PNG or WEBP images are allowed.');
    }
    $ext = (string) $validated['extension'];

    $safeSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slug));
    $safeSlug = trim($safeSlug, '-');
    if ($safeSlug === '') {
        $safeSlug = 'category';
    }
    $uploadDir = __DIR__ . '/../images/categories/';
    $filename = $safeSlug . '.' . $ext;
    try {
        UploadPolicy::move($file, $uploadDir, $filename);
    } catch (Throwable $e) {
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
        $parentId = 0;
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $usesVariantSize = isset($_POST['uses_variant_size']) ? 1 : 0;
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slugRaw));
        $slug = trim($slug, '-');

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }
        if ($slug === '') {
            $errors[] = 'Category slug is required.';
        }
        if (!in_array($slug, $lockedAllowedSlugs, true)) {
            $errors[] = 'Only these slugs are allowed: ' . $lockedSlugListText . '.';
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
                CategoryAdminService::create($conn, $name, $slug, $imagePath, $status, $usesVariantSize);
                flash('success', 'Category added successfully.');
            } catch (Throwable $e) {
                error_log('[categories] create failed: ' . $e->getMessage());
                flash('error', 'Could not add category. Please try again.');
            }
            redirect('categories.php');
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugRaw = trim((string) ($_POST['slug'] ?? ''));
        $parentId = 0;
        $status = trim((string) ($_POST['status'] ?? 'active'));
        $usesVariantSize = isset($_POST['uses_variant_size']) ? 1 : 0;
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slugRaw));
        $slug = trim($slug, '-');

        if ($id <= 0 || $name === '' || $slug === '') {
            flash('error', 'Please provide valid category data.');
            redirect('categories.php');
        }
        if (!in_array($slug, $lockedAllowedSlugs, true)) {
            flash('error', 'Only locked taxonomy categories can be edited here.');
            redirect('categories.php');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        try {
            $imagePath = CategoryAdminService::image($conn, $id);

            if (!empty($_FILES['image']['name'])) {
                $uploaded = $processCategoryImageUpload($_FILES['image'], $slug);
                if ($uploaded !== null) {
                    $imagePath = $uploaded;
                }
            }

            CategoryAdminService::update($conn, $id, $name, $slug, $imagePath, $status, $usesVariantSize);
            flash('success', 'Category updated.');
        } catch (Throwable $e) {
            error_log('[categories] update failed: ' . $e->getMessage());
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

        try {
            CategoryAdminService::delete($conn, $id, $lockedAllowedSlugs);
            flash('success', 'Category deleted.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            app_log('error', 'category_delete_failed', ['category_id' => $id, 'exception_type' => get_class($e)]);
            flash('error', 'Could not delete category right now.');
        }
        redirect('categories.php');
    }
}

$categories = [];
$parentCategories = [];
try {
    $allCats = CategoryAdminService::all($conn);
    
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

$metaTitle = SiteContext::title('Manage Categories');
$metaDescription = 'Create, edit and delete product categories.';
$metaKeywords = 'admin, categories, manage';
include 'partials/header.php';
?>
<div class="admin-page-header u-flex u-justify-between u-items-center u-mb-3">
    <div>
        <h1 class="u-mb-1">Categories</h1>
        <p class="u-text-muted u-mb-0">Locked taxonomy: top-level only for storefront business rules.</p>
    </div>
</div>
<div class="ui-alert ui-alert--info">
    This catalog uses a fixed top-level category structure for storefront consistency. Allowed slugs:
    <?php foreach ($lockedAllowedSlugs as $index => $allowedSlug): ?>
        <?php if ($index > 0): ?>, <?php endif; ?><code><?php echo e($allowedSlug); ?></code>
    <?php endforeach; ?>.
</div>

<div class="ui-card u-mb-4">
    <div class="ui-card__body">
        <h5 class="ui-card__title">Add Category</h5>
        <?php if (!empty($errors)): ?>
            <div class="ui-alert ui-alert--error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="l-grid l-grid--12 u-gap-3 admin-filter-form" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="l-col-md-quarter">
                <label class="ui-label">Name</label>
                <input type="text" name="name" class="ui-input" required>
            </div>
            <div class="l-col-md-quarter">
                <label class="ui-label">Slug</label>
                <input type="text" name="slug" class="ui-input" placeholder="e.g. fabric-by-meter" required>
            </div>

            <div class="l-col-md-two">
                <label class="ui-label">Status</label>
                <select name="status" class="ui-select">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="l-col-md-quarter">
                <label class="ui-label">Image</label>
                <input type="file" name="image" class="ui-input" accept="image/jpeg,image/png,image/webp">
            </div>
            <div class="l-col-md-two u-flex u-items-center">
                <div class="ui-check u-mt-4">
                    <input class="ui-check__input" type="checkbox" name="uses_variant_size" id="uses_variant_size_create" value="1">
                    <label class="ui-check__label" for="uses_variant_size_create">Use Variant Size</label>
                </div>
            </div>
            <div class="l-col-md-two u-flex u-items-end">
                <button class="ui-button ui-button--primary u-w-full" type="submit">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="ui-table-wrap">
    <table class="ui-table ui-table--striped u-align-middle admin-no-card-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Slug</th>
            <th>Status</th>
            <th>Variant Size</th>
            <th>Image</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($categories)): ?>
            <tr><td colspan="7" class="u-text-center u-text-muted u-py-4">No categories found.</td></tr>
        <?php else: ?>
            <?php foreach ($categories as $cat): ?>
                <tr>

                    <td><?php echo (int) $cat['id']; ?></td>
                    <td>

                        <?php echo e((string) $cat['name']); ?>
                    </td>
                    <td><code><?php echo e((string) $cat['slug']); ?></code></td>

                    <td>
                        <?php if ((string) $cat['status'] === 'active'): ?>
                            <span class="ui-badge ui-badge--success">Active</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge--neutral">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) ($cat['uses_variant_size'] ?? 0) === 1): ?>
                            <span class="ui-badge ui-badge--info">Enabled</span>
                        <?php else: ?>
                            <span class="ui-badge u-bg-soft u-text-ink u-border">Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo !empty($cat['image']) ? 'Set' : 'Not set'; ?></td>
                    <td>
                        <details>
                            <summary class="ui-button ui-button--small ui-button--secondary">Edit</summary>
                            <form method="post" class="l-grid l-grid--12 u-gap-2 u-mt-2" enctype="multipart/form-data">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo (int) $cat['id']; ?>">
                                <div class="l-col-md-quarter">
                                    <input type="text" name="name" class="ui-input ui-input--small" value="<?php echo e((string) $cat['name']); ?>" required>
                                </div>
                                <div class="l-col-md-quarter">
                                    <input type="text" name="slug" class="ui-input ui-input--small" value="<?php echo e((string) $cat['slug']); ?>" required>
                                </div>

                                <div class="l-col-md-two">
                                    <select name="status" class="ui-select ui-select--small">
                                        <option value="active" <?php echo ((string) $cat['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo ((string) $cat['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="l-col-md-quarter">
                                    <input type="file" name="image" class="ui-input ui-input--small" accept="image/jpeg,image/png,image/webp">
                                </div>
                                <div class="l-col-md-two u-flex u-items-center">
                                    <div class="ui-check">
                                        <input class="ui-check__input" type="checkbox" name="uses_variant_size" id="uses_variant_size_<?php echo (int) $cat['id']; ?>" value="1" <?php echo ((int) ($cat['uses_variant_size'] ?? 0) === 1) ? 'checked' : ''; ?>>
                                        <label class="ui-check__label u-text-small" for="uses_variant_size_<?php echo (int) $cat['id']; ?>">Use Size</label>
                                    </div>
                                </div>
                                <div class="l-col-md-two u-grid">
                                    <button class="ui-button ui-button--small ui-button--navy" type="submit">Save</button>
                                </div>
                            </form>
                        </details>
                        <form method="post" class="u-inline-block u-mt-2" data-confirm="Delete category <?php echo e((string) $cat['name']); ?>?" data-confirm-title="Delete Category?" data-confirm-ok="Delete" data-confirm-variant="danger">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $cat['id']; ?>">
                            <button type="submit" class="ui-button ui-button--small ui-button--danger-outline">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'partials/footer.php'; ?>
