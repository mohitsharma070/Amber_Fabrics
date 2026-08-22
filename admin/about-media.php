<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors = [];
$old = [
    'media_type' => 'image',
    'alt_text' => '',
    'sort_order' => '0',
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('about-media.php');
    }

    $action = trim((string) ($_POST['action'] ?? 'add'));

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT file_name, poster_image FROM about_media WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            $del = $conn->prepare("DELETE FROM about_media WHERE id = ?");
            $del->bind_param('i', $id);
            $del->execute();
            if (!empty($row['file_name'])) {
                UploadPolicy::deleteStoredFile(__DIR__ . '/../images/about', (string) $row['file_name']);
            }
            if (!empty($row['poster_image'])) {
                UploadPolicy::deleteStoredFile(__DIR__ . '/../images/about', (string) $row['poster_image']);
            }
            flash('success', 'About media deleted.');
        } else {
            flash('error', 'Media record not found.');
        }
        redirect('about-media.php');
    }

    $mediaType = trim((string) ($_POST['media_type'] ?? 'image'));
    $altText = trim((string) ($_POST['alt_text'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $old = [
        'media_type' => $mediaType,
        'alt_text' => $altText,
        'sort_order' => (string) $sortOrder,
        'is_active' => $isActive,
    ];

    if (!in_array($mediaType, ['image', 'video'], true)) {
        $errors['media_type'] = 'Invalid media type selected.';
    }

    if (empty($_FILES['media_file']['name'])) {
        $errors['media_file'] = 'Please upload an image or video file.';
    }

    $uploadDir = __DIR__ . '/../images/about';
    UploadPolicy::ensureDirectory($uploadDir, 0775);

    $fileName = null;
    $posterName = null;

    if (empty($errors) && !empty($_FILES['media_file']['name'])) {
        $file = $_FILES['media_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['media_file'] = 'Media upload failed. Please try again.';
        } else {
            try {
                if ($mediaType === 'image') {
                    UploadPolicy::validate($file, ['jpg', 'jpeg', 'png', 'webp'], ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024, true);
                } else {
                    UploadPolicy::validate($file, ['mp4', 'webm', 'ogg'], ['video/mp4', 'video/webm', 'video/ogg'], 25 * 1024 * 1024);
                }
                $fileName = random_filename($file['name']);
                UploadPolicy::move($file, $uploadDir, $fileName);
            } catch (Throwable $e) {
                $errors['media_file'] = $mediaType === 'image' ? 'Image must be a valid JPG, PNG or WEBP under 5MB.' : 'Video must be a valid MP4, WEBM or OGG under 25MB.';
            }
        }
    }

    if (empty($errors) && $mediaType === 'video' && !empty($_FILES['poster_image']['name'])) {
        $poster = $_FILES['poster_image'];
        if (($poster['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['poster_image'] = 'Poster image upload failed.';
        } else {
            try {
                UploadPolicy::validate($poster, ['jpg', 'jpeg', 'png', 'webp'], ['image/jpeg', 'image/png', 'image/webp'], 5 * 1024 * 1024, true);
                $posterName = random_filename($poster['name']);
                UploadPolicy::move($poster, $uploadDir, $posterName);
            } catch (Throwable $e) {
                $errors['poster_image'] = 'Poster image must be a valid JPG, PNG or WEBP under 5MB.';
            }
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "INSERT INTO about_media (media_type, file_name, poster_image, alt_text, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssii', $mediaType, $fileName, $posterName, $altText, $sortOrder, $isActive);
        $stmt->execute();
        flash('success', 'About media added.');
        redirect('about-media.php');
    }
}

$items = [];
try {
    $res = $conn->query("SELECT id, media_type, file_name, poster_image, alt_text, sort_order, is_active, created_at FROM about_media ORDER BY sort_order ASC, id ASC");
    if ($res) {
        $items = $res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    $items = [];
}

$metaTitle = SiteContext::title('About Media');
$metaDescription = 'Manage About page images and videos.';
$metaKeywords = 'admin, about media, images, videos';
include 'partials/header.php';
?>

<div class="u-flex u-justify-between u-items-center u-mb-3">
    <div>
        <h1 class="u-mb-1">About Media</h1>
        <p class="u-text-muted u-mb-0">Manage images and videos shown in the About page media section.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="ui-alert ui-alert--warning">Please fix the upload errors below.</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="l-grid l-grid--12 u-gap-3 u-mb-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add">

    <div class="l-col-md-quarter">
        <label class="ui-label">Media Type *</label>
        <select name="media_type" class="<?php echo form_class($errors, 'media_type', 'ui-select'); ?>" required>
            <option value="image" <?php echo $old['media_type'] === 'image' ? 'selected' : ''; ?>>Image</option>
            <option value="video" <?php echo $old['media_type'] === 'video' ? 'selected' : ''; ?>>Video</option>
        </select>
        <?php echo form_error($errors, 'media_type'); ?>
    </div>

    <div class="l-col-md-five">
        <label class="ui-label">Media File *</label>
        <input type="file" name="media_file" class="<?php echo form_class($errors, 'media_file'); ?>" accept="image/*,video/mp4,video/webm,video/ogg" required>
        <?php echo form_error($errors, 'media_file'); ?>
    </div>

    <div class="l-col-md-third">
        <label class="ui-label">Poster Image (optional for video)</label>
        <input type="file" name="poster_image" class="<?php echo form_class($errors, 'poster_image'); ?>" accept="image/*">
        <?php echo form_error($errors, 'poster_image'); ?>
    </div>

    <div class="l-col-md-half">
        <label class="ui-label">Alt Text</label>
        <input type="text" name="alt_text" class="ui-input" value="<?php echo e($old['alt_text']); ?>" placeholder="Describe the media for accessibility">
    </div>

    <div class="l-col-md-quarter">
        <label class="ui-label">Sort Order</label>
        <input type="number" name="sort_order" class="ui-input" value="<?php echo e($old['sort_order']); ?>">
    </div>

    <div class="l-col-md-quarter u-flex u-items-end">
        <div class="ui-check u-mb-2">
            <input class="ui-check__input" type="checkbox" id="is_active" name="is_active" <?php echo !empty($old['is_active']) ? 'checked' : ''; ?>>
            <label class="ui-check__label" for="is_active">Active on About page</label>
        </div>
    </div>

    <div class="l-col-full">
        <button type="submit" class="ui-button ui-button--primary">Upload Media</button>
    </div>
</form>

<div class="ui-table-wrap">
    <table class="ui-table ui-table--striped u-align-middle admin-card-table">
        <thead class="ui-table__head--dark">
            <tr>
                <th>Preview</th>
                <th>Type</th>
                <th>Alt Text</th>
                <th>Sort</th>
                <th>Status</th>
                <th>Added</th>
                <th class="u-text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr class="admin-empty-row"><td colspan="7" class="u-text-center u-text-muted">No media uploaded yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <tr>
                <td data-label="Preview">
                    <?php if ($item['media_type'] === 'video'): ?>
                        <video width="90" height="68" preload="metadata" controls>
                            <source src="../images/about/<?php echo e($item['file_name']); ?>" type="video/mp4">
                        </video>
                    <?php else: ?>
                        <img src="../images/about/<?php echo e($item['file_name']); ?>" width="90" class="u-rounded" alt="<?php echo e($item['alt_text'] ?: 'About media image'); ?>">
                    <?php endif; ?>
                </td>
                <td data-label="Type"><?php echo ucfirst((string) $item['media_type']); ?></td>
                <td data-label="Alt Text"><?php echo e($item['alt_text'] ?: '-'); ?></td>
                <td data-label="Sort"><?php echo (int) $item['sort_order']; ?></td>
                <td data-label="Status">
                    <span class="ui-badge <?php echo !empty($item['is_active']) ? 'ui-badge--success' : 'ui-badge--neutral'; ?>">
                        <?php echo !empty($item['is_active']) ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td data-label="Added"><?php echo e(date('d M Y', strtotime((string) $item['created_at']))); ?></td>
                <td data-label="Actions" class="u-text-end">
                    <form method="POST" class="u-inline" data-confirm="Delete this media item?" data-confirm-title="Delete Media Item?" data-confirm-ok="Delete" data-confirm-variant="danger">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <button class="ui-button ui-button--small ui-button--danger-outline">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'partials/footer.php'; ?>
