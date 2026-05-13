<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

// Load settings (DB-first with JSON fallback)
$settingsFile = __DIR__ . '/../config/site-settings.json';
$settings = get_site_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (isset($_POST['reset_announcement_dismissals'])) {
        try {
            ensure_announcement_dismissals_table($conn);
            $conn->query("DELETE FROM announcement_dismissals");
            flash('success', 'Announcement dismissals reset. All visitors will see the announcement bar again.');
        } catch (Throwable $e) {
            error_log('[amberfabrics] reset announcement dismissals failed: ' . $e->getMessage());
            flash('error', 'Could not reset announcement dismissals. Please try again.');
        }
        redirect('settings.php');
    }

    $settings['site_name'] = trim($_POST['site_name'] ?? $settings['site_name']);
    $settings['site_description'] = trim($_POST['site_description'] ?? $settings['site_description']);
    $settings['contact_email'] = trim($_POST['contact_email'] ?? $settings['contact_email']);
    $gstRateInput = trim((string) ($_POST['gst_rate'] ?? ($settings['gst_rate'] ?? '18')));
    if (!is_numeric($gstRateInput)) {
        $gstRateInput = (string) ($settings['gst_rate'] ?? '18');
    }
    $gstRate = (float) $gstRateInput;
    if ($gstRate < 0) { $gstRate = 0; }
    if ($gstRate > 100) { $gstRate = 100; }
    $settings['gst_rate'] = rtrim(rtrim(number_format($gstRate, 2, '.', ''), '0'), '.');
    for ($i = 1; $i <= 5; $i++) {
        $textKey = 'announcement_' . $i . '_text';
        $enabledKey = 'announcement_' . $i . '_enabled';
        $settings[$textKey] = trim((string) ($_POST[$textKey] ?? ''));
        $settings[$enabledKey] = isset($_POST[$enabledKey]) ? '1' : '0';
    }
    
    $maxSize = 2 * 1024 * 1024; // 2MB
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];

    $processImageUpload = static function (array $file, string $targetDir, string $targetNamePrefix) use ($maxSize, $allowedExt, $allowedMime): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = mime_content_type((string) ($file['tmp_name'] ?? '')) ?: '';
        if (($file['size'] ?? 0) > $maxSize) {
            throw new RuntimeException('Image must be under 2MB.');
        }
        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true) || !@getimagesize((string) $file['tmp_name'])) {
            throw new RuntimeException('Only valid JPG, PNG or WEBP images are allowed.');
        }
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }
        $filename = $targetNamePrefix . '.' . $ext;
        $target = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('Failed to upload image.');
        }
        return $filename;
    };

    // Handle logo upload
    if (!empty($_FILES['branding_logo']['name']) && ($_FILES['branding_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        try {
            $uploadDir = __DIR__ . '/../images/';
            $filename = $processImageUpload($_FILES['branding_logo'], $uploadDir, 'logo');
            if ($filename) {
                $settings['branding_logo'] = 'images/' . $filename;
                flash('success', 'Logo uploaded successfully.');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }

    // Handle hero swatch uploads
    for ($i = 1; $i <= 6; $i++) {
        $field = 'hero_swatch_' . $i;
        if (!empty($_FILES[$field]['name']) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $uploadDir = __DIR__ . '/../images/hero/';
                $filename = $processImageUpload($_FILES[$field], $uploadDir, 'swatch-' . $i);
                if ($filename) {
                    $settings[$field] = 'images/hero/' . $filename;
                }
            } catch (Throwable $e) {
                flash('error', 'Hero card ' . $i . ': ' . $e->getMessage());
            }
        }
    }


    // Persist to DB as primary source.
    try {
        save_site_settings_to_db($conn, $settings);
    } catch (Throwable $e) {
        error_log('[amberfabrics] save site settings to db failed: ' . $e->getMessage());
        flash('error', 'Could not save settings to database.');
    }

    // Keep JSON as fallback snapshot for compatibility.
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    
    if (!isset($_SESSION['flash']['error'])) {
        flash('success', 'Settings updated successfully.');
    }
    redirect('settings.php');
}

$metaTitle = 'Admin Settings | Amber Fabrics';
$metaDescription = 'Edit site text, contact info, and branding for Amber Fabrics.';
$metaKeywords = 'admin, settings, site text, contact, branding, Amber Fabrics';
include 'partials/header.php';
?>
<h2>Site Settings</h2>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?php echo e($msg); ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <div class="mb-3">
        <label for="site_name" class="form-label">Site Name</label>
        <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo e($settings['site_name']); ?>">
    </div>
    <div class="mb-3">
        <label for="site_description" class="form-label">Site Description</label>
        <textarea class="form-control" id="site_description" name="site_description" rows="2"><?php echo e($settings['site_description']); ?></textarea>
    </div>
    <div class="mb-3">
        <label for="contact_email" class="form-label">Contact Email</label>
        <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?php echo e($settings['contact_email']); ?>">
    </div>
    <div class="mb-3">
        <label for="gst_rate" class="form-label">GST Rate (%)</label>
        <input
            type="number"
            step="0.01"
            min="0"
            max="100"
            class="form-control"
            id="gst_rate"
            name="gst_rate"
            value="<?php echo e((string) ($settings['gst_rate'] ?? '18')); ?>"
        >
        <small class="text-muted">Used for billing display and invoice GST breakdown for India orders.</small>
    </div>

    <h4 class="mt-4">Announcement Bar</h4>
    <p class="text-muted">Manage up to 5 rotating announcement messages for homepage.</p>
    <div class="mb-3">
        <button
            type="submit"
            name="reset_announcement_dismissals"
            value="1"
            class="btn btn-outline-warning btn-sm"
            onclick="return confirm('Reset announcement dismissals for all visitors?');"
        >
            Reset Announcement Dismissals
        </button>
    </div>
    <div class="row g-3 mb-3">
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <?php
            $textKey = 'announcement_' . $i . '_text';
            $enabledKey = 'announcement_' . $i . '_enabled';
            $isEnabled = ((string) ($settings[$enabledKey] ?? '0')) === '1';
            ?>
            <div class="col-12">
                <div class="border rounded p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0 fw-semibold" for="<?php echo e($textKey); ?>">Announcement <?php echo $i; ?></label>
                        <div class="form-check m-0">
                            <input class="form-check-input" type="checkbox" id="<?php echo e($enabledKey); ?>" name="<?php echo e($enabledKey); ?>" <?php echo $isEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="<?php echo e($enabledKey); ?>">Enabled</label>
                        </div>
                    </div>
                    <textarea class="form-control" id="<?php echo e($textKey); ?>" name="<?php echo e($textKey); ?>" rows="2" placeholder="Type announcement message"><?php echo e((string) ($settings[$textKey] ?? '')); ?></textarea>
                </div>
            </div>
        <?php endfor; ?>
    </div>
    <div class="mb-3">
        <label for="branding_logo" class="form-label">Branding Logo</label>
        <div class="mb-2">
            <?php if (!empty($settings['branding_logo']) && file_exists(__DIR__ . '/../' . $settings['branding_logo'])): ?>
                <div class="p-3 bg-dark rounded d-inline-block">
                    <img src="../<?php echo e($settings['branding_logo']); ?>" alt="Current Logo" class="settings-logo-preview">
                </div>
            <?php else: ?>
                <div class="alert alert-info">No logo currently set</div>
            <?php endif; ?>
        </div>
        <input type="file" class="form-control" id="branding_logo" name="branding_logo" accept="image/jpeg,image/png,image/webp">
        <small class="text-muted">Recommended: PNG or WEBP with transparent background. Max 2MB. Dimensions: 120x120px or similar.</small>
    </div>

    <h4 class="mt-4">Hero Section Cards</h4>
    <p class="text-muted">Upload images for the 6 hero cards shown on homepage.</p>
    <div class="row g-3 mb-3">
        <?php for ($i = 1; $i <= 6; $i++): $key = 'hero_swatch_' . $i; ?>
            <div class="col-md-4">
                <label class="form-label">Hero Card <?php echo $i; ?></label>
                <?php if (!empty($settings[$key]) && file_exists(__DIR__ . '/../' . $settings[$key])): ?>
                    <div class="mb-2">
                        <img src="../<?php echo e($settings[$key]); ?>" alt="Hero Card <?php echo $i; ?>" class="img-fluid rounded border" style="max-height:120px;object-fit:cover;">
                    </div>
                <?php endif; ?>
                <input type="file" class="form-control" name="<?php echo e($key); ?>" accept="image/jpeg,image/png,image/webp">
            </div>
        <?php endfor; ?>
    </div>

    <div class="mt-4 pt-2">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>
