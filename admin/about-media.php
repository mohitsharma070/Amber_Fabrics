<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$conn->query(
    "CREATE TABLE IF NOT EXISTS about_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        media_type ENUM('image','video') NOT NULL DEFAULT 'image',
        file_name VARCHAR(255) NOT NULL,
        poster_image VARCHAR(255) DEFAULT NULL,
        alt_text VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_about_media_active_sort (is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

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
                @unlink(__DIR__ . '/../images/about/' . $row['file_name']);
            }
            if (!empty($row['poster_image'])) {
                @unlink(__DIR__ . '/../images/about/' . $row['poster_image']);
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
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $fileName = null;
    $posterName = null;

    if (empty($errors) && !empty($_FILES['media_file']['name'])) {
        $file = $_FILES['media_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['media_file'] = 'Media upload failed. Please try again.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($mediaType === 'image') {
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
                $mime = mime_content_type($file['tmp_name']) ?: '';
                if ($file['size'] > 5 * 1024 * 1024) {
                    $errors['media_file'] = 'Image must be under 5MB.';
                } elseif (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true) || !@getimagesize($file['tmp_name'])) {
                    $errors['media_file'] = 'Image must be JPG, PNG or WEBP.';
                }
            } else {
                $allowedExt = ['mp4', 'webm', 'ogg'];
                $allowedMime = ['video/mp4', 'video/webm', 'video/ogg'];
                $mime = mime_content_type($file['tmp_name']) ?: '';
                if ($file['size'] > 25 * 1024 * 1024) {
                    $errors['media_file'] = 'Video must be under 25MB.';
                } elseif (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
                    $errors['media_file'] = 'Video must be MP4, WEBM or OGG.';
                }
            }

            if (empty($errors)) {
                $fileName = random_filename($file['name']);
                if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
                    $errors['media_file'] = 'Media upload failed.';
                }
            }
        }
    }

    if (empty($errors) && $mediaType === 'video' && !empty($_FILES['poster_image']['name'])) {
        $poster = $_FILES['poster_image'];
        if (($poster['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['poster_image'] = 'Poster image upload failed.';
        } else {
            $posterExt = strtolower(pathinfo($poster['name'], PATHINFO_EXTENSION));
            $posterMime = mime_content_type($poster['tmp_name']) ?: '';
            $allowedPosterExt = ['jpg', 'jpeg', 'png', 'webp'];
            $allowedPosterMime = ['image/jpeg', 'image/png', 'image/webp'];
            if ($poster['size'] > 5 * 1024 * 1024) {
                $errors['poster_image'] = 'Poster image must be under 5MB.';
            } elseif (!in_array($posterExt, $allowedPosterExt, true) || !in_array($posterMime, $allowedPosterMime, true) || !@getimagesize($poster['tmp_name'])) {
                $errors['poster_image'] = 'Poster image must be JPG, PNG or WEBP.';
            } else {
                $posterName = random_filename($poster['name']);
                if (!move_uploaded_file($poster['tmp_name'], $uploadDir . '/' . $posterName)) {
                    $errors['poster_image'] = 'Poster image upload failed.';
                }
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

$metaTitle = 'About Media | Amber Fabrics';
$metaDescription = 'Manage About page images and videos.';
$metaKeywords = 'admin, about media, images, videos';
include 'partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">About Media</h1>
        <p class="text-muted mb-0">Manage images and videos shown in the About page media section.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-warning">Please fix the upload errors below.</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="row g-3 mb-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add">

    <div class="col-md-3">
        <label class="form-label">Media Type *</label>
        <select name="media_type" class="<?php echo form_class($errors, 'media_type', 'form-select'); ?>" required>
            <option value="image" <?php echo $old['media_type'] === 'image' ? 'selected' : ''; ?>>Image</option>
            <option value="video" <?php echo $old['media_type'] === 'video' ? 'selected' : ''; ?>>Video</option>
        </select>
        <?php echo form_error($errors, 'media_type'); ?>
    </div>

    <div class="col-md-5">
        <label class="form-label">Media File *</label>
        <input type="file" name="media_file" class="<?php echo form_class($errors, 'media_file'); ?>" accept="image/*,video/mp4,video/webm,video/ogg" required>
        <?php echo form_error($errors, 'media_file'); ?>
    </div>

    <div class="col-md-4">
        <label class="form-label">Poster Image (optional for video)</label>
        <input type="file" name="poster_image" class="<?php echo form_class($errors, 'poster_image'); ?>" accept="image/*">
        <?php echo form_error($errors, 'poster_image'); ?>
    </div>

    <div class="col-md-6">
        <label class="form-label">Alt Text</label>
        <input type="text" name="alt_text" class="form-control" value="<?php echo e($old['alt_text']); ?>" placeholder="Describe the media for accessibility">
    </div>

    <div class="col-md-3">
        <label class="form-label">Sort Order</label>
        <input type="number" name="sort_order" class="form-control" value="<?php echo e($old['sort_order']); ?>">
    </div>

    <div class="col-md-3 d-flex align-items-end">
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?php echo !empty($old['is_active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_active">Active on About page</label>
        </div>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">Upload Media</button>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-striped align-middle admin-card-table">
        <thead class="table-dark">
            <tr>
                <th>Preview</th>
                <th>Type</th>
                <th>Alt Text</th>
                <th>Sort</th>
                <th>Status</th>
                <th>Added</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr class="admin-empty-row"><td colspan="7" class="text-center text-muted">No media uploaded yet.</td></tr>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <tr>
                <td data-label="Preview">
                    <?php if ($item['media_type'] === 'video'): ?>
                        <video width="90" height="68" preload="metadata" controls>
                            <source src="../images/about/<?php echo e($item['file_name']); ?>" type="video/mp4">
                        </video>
                    <?php else: ?>
                        <img src="../images/about/<?php echo e($item['file_name']); ?>" width="90" class="rounded" alt="<?php echo e($item['alt_text'] ?: 'About media image'); ?>">
                    <?php endif; ?>
                </td>
                <td data-label="Type"><?php echo ucfirst((string) $item['media_type']); ?></td>
                <td data-label="Alt Text"><?php echo e($item['alt_text'] ?: '-'); ?></td>
                <td data-label="Sort"><?php echo (int) $item['sort_order']; ?></td>
                <td data-label="Status">
                    <span class="badge <?php echo !empty($item['is_active']) ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo !empty($item['is_active']) ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td data-label="Added"><?php echo e(date('d M Y', strtotime((string) $item['created_at']))); ?></td>
                <td data-label="Actions" class="text-end">
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this media item?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'partials/footer.php'; ?>

