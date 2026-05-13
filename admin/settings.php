<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

// Load settings (DB-first with JSON fallback)
$settingsFile = __DIR__ . '/../config/site-settings.json';
$settings = get_site_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {

    // ── Reset announcement dismissals (special action) ──────────────────────
    if (isset($_POST['reset_announcement_dismissals'])) {
        try {
            ensure_announcement_dismissals_table($conn);
            $conn->query("DELETE FROM announcement_dismissals");
            flash('success', 'Announcement dismissals reset.');
        } catch (Throwable $e) {
            error_log('[amberfabrics] reset announcement dismissals failed: ' . $e->getMessage());
            flash('error', 'Could not reset announcement dismissals.');
        }
        redirect('settings.php?tab=announcements');
    }

    $tab = trim((string) ($_POST['tab'] ?? 'general'));

    // ── Only update fields belonging to the submitted tab ──────────────────
    switch ($tab) {
        case 'general':
            $settings['site_name']        = trim($_POST['site_name']        ?? $settings['site_name']);
            $settings['site_description'] = trim($_POST['site_description'] ?? $settings['site_description']);
            $settings['contact_email']    = trim($_POST['contact_email']    ?? $settings['contact_email']);
            $gstRateInput = trim((string) ($_POST['gst_rate'] ?? ($settings['gst_rate'] ?? '18')));
            if (!is_numeric($gstRateInput)) { $gstRateInput = (string) ($settings['gst_rate'] ?? '18'); }
            $gstRate = max(0, min(100, (float) $gstRateInput));
            $settings['gst_rate'] = rtrim(rtrim(number_format($gstRate, 2, '.', ''), '0'), '.');
            break;

        case 'invoice':
            $settings['gst_number']      = trim((string) ($_POST['gst_number']      ?? ($settings['gst_number']      ?? '')));
            $settings['company_address'] = trim((string) ($_POST['company_address'] ?? ($settings['company_address'] ?? '')));
            $settings['company_phone']   = trim((string) ($_POST['company_phone']   ?? ($settings['company_phone']   ?? '')));
            $settings['hsn_code']        = trim((string) ($_POST['hsn_code']        ?? ($settings['hsn_code']        ?? '5208')));
            $settings['pan_number']      = trim((string) ($_POST['pan_number']      ?? ($settings['pan_number']      ?? '')));
            $settings['company_state']   = trim((string) ($_POST['company_state']   ?? ($settings['company_state']   ?? '')));
            break;

        case 'packing':
            $settings['packing_unboxing_notice']    = trim((string) ($_POST['packing_unboxing_notice']    ?? ($settings['packing_unboxing_notice']    ?? '')));
            $settings['packing_cod_notice']         = trim((string) ($_POST['packing_cod_notice']         ?? ($settings['packing_cod_notice']         ?? '')));
            $settings['packing_footer_note']        = trim((string) ($_POST['packing_footer_note']        ?? ($settings['packing_footer_note']        ?? '')));
            $settings['packing_repeat_badge_label'] = trim((string) ($_POST['packing_repeat_badge_label'] ?? ($settings['packing_repeat_badge_label'] ?? '')));
            $repeatMinRaw = (int) ($_POST['packing_repeat_min_orders'] ?? $settings['packing_repeat_min_orders'] ?? 1);
            $settings['packing_repeat_min_orders']  = (string) max(1, $repeatMinRaw);
            break;

        case 'announcements':
            for ($i = 1; $i <= 5; $i++) {
                $textKey    = 'announcement_' . $i . '_text';
                $enabledKey = 'announcement_' . $i . '_enabled';
                $settings[$textKey]    = trim((string) ($_POST[$textKey] ?? ''));
                $settings[$enabledKey] = isset($_POST[$enabledKey]) ? '1' : '0';
            }
            break;

        case 'branding':
            $maxSize     = 2 * 1024 * 1024;
            $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
            $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
            $processImageUpload = static function (array $file, string $targetDir, string $targetNamePrefix) use ($maxSize, $allowedExt, $allowedMime): ?string {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { return null; }
                $ext  = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
                $mime = mime_content_type((string) ($file['tmp_name'] ?? '')) ?: '';
                if (($file['size'] ?? 0) > $maxSize) { throw new RuntimeException('Image must be under 2MB.'); }
                if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true) || !@getimagesize((string) $file['tmp_name'])) {
                    throw new RuntimeException('Only valid JPG, PNG or WEBP images are allowed.');
                }
                if (!is_dir($targetDir)) { @mkdir($targetDir, 0755, true); }
                $filename = $targetNamePrefix . '.' . $ext;
                $target = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file((string) $file['tmp_name'], $target)) { throw new RuntimeException('Failed to upload image.'); }
                return $filename;
            };
            if (!empty($_FILES['branding_logo']['name']) && ($_FILES['branding_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                try {
                    $filename = $processImageUpload($_FILES['branding_logo'], __DIR__ . '/../images/', 'logo');
                    if ($filename) { $settings['branding_logo'] = 'images/' . $filename; }
                } catch (Throwable $e) { flash('error', $e->getMessage()); }
            }
            for ($i = 1; $i <= 6; $i++) {
                $field = 'hero_swatch_' . $i;
                if (!empty($_FILES[$field]['name']) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    try {
                        $filename = $processImageUpload($_FILES[$field], __DIR__ . '/../images/hero/', 'swatch-' . $i);
                        if ($filename) { $settings[$field] = 'images/hero/' . $filename; }
                    } catch (Throwable $e) { flash('error', 'Hero card ' . $i . ': ' . $e->getMessage()); }
                }
            }
            break;
    }

    // ── Persist ─────────────────────────────────────────────────────────────
    try {
        save_site_settings_to_db($conn, $settings);
    } catch (Throwable $e) {
        error_log('[amberfabrics] save site settings to db failed: ' . $e->getMessage());
        flash('error', 'Could not save settings to database.');
    }
    $tmpFile = $settingsFile . '.tmp';
    file_put_contents($tmpFile, json_encode($settings, JSON_PRETTY_PRINT));
    rename($tmpFile, $settingsFile);

    if (!isset($_SESSION['flash']['error'])) {
        flash('success', 'Settings saved.');
    }
    redirect('settings.php?tab=' . urlencode($tab));
}

$activeTab = preg_replace('/[^a-z]/', '', strtolower(trim((string) ($_GET['tab'] ?? 'general'))));
if (!in_array($activeTab, ['general','invoice','packing','announcements','branding'], true)) {
    $activeTab = 'general';
}

$metaTitle       = 'Admin Settings | Amber Fabrics';
$metaDescription = 'Edit site text, contact info, and branding for Amber Fabrics.';
$metaKeywords    = 'admin, settings, site text, contact, branding, Amber Fabrics';
include 'partials/header.php';
?>
<h2>Site Settings</h2>
<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?php echo e($msg); ?></div>
<?php endif; ?>
<?php if ($err = flash('error')): ?>
    <div class="alert alert-danger"><?php echo e($err); ?></div>
<?php endif; ?>

<!-- Tabs nav -->
<ul class="nav nav-tabs mb-4" id="settingsTabs">
    <?php
    $tabs = [
        'general'       => 'General',
        'invoice'       => 'Invoice Details',
        'packing'       => 'Packing Slip',
        'announcements' => 'Announcements',
        'branding'      => 'Branding',
    ];
    foreach ($tabs as $key => $label):
        $active = ($activeTab === $key) ? 'active' : '';
    ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $active; ?>" href="settings.php?tab=<?php echo $key; ?>"><?php echo $label; ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<?php // ── GENERAL ─────────────────────────────────────────────────────────────
if ($activeTab === 'general'): ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="tab" value="general">
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
        <input type="number" step="0.01" min="0" max="100" class="form-control" id="gst_rate" name="gst_rate"
               value="<?php echo e((string) ($settings['gst_rate'] ?? '18')); ?>">
        <small class="text-muted">Used for GST breakdown on India orders.</small>
    </div>
    <button type="submit" class="btn btn-primary">Save General</button>
</form>

<?php // ── INVOICE ─────────────────────────────────────────────────────────────
elseif ($activeTab === 'invoice'): ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="tab" value="invoice">
    <p class="text-muted">These details appear on every customer and admin tax invoice.</p>
    <div class="mb-3">
        <label for="gst_number" class="form-label">GSTIN</label>
        <input type="text" class="form-control" id="gst_number" name="gst_number"
               value="<?php echo e((string) ($settings['gst_number'] ?? '')); ?>"
               placeholder="e.g. 08AAAAA0000A1Z5" maxlength="15">
        <small class="text-muted">Leave blank if not applicable.</small>
    </div>
    <div class="mb-3">
        <label for="pan_number" class="form-label">PAN Number</label>
        <input type="text" class="form-control" id="pan_number" name="pan_number"
               value="<?php echo e((string) ($settings['pan_number'] ?? '')); ?>"
               placeholder="e.g. AAAAA0000A" maxlength="10">
    </div>
    <div class="mb-3">
        <label for="company_address" class="form-label">Company / Seller Address</label>
        <textarea class="form-control" id="company_address" name="company_address" rows="3"
                  placeholder="Street, City, State, PIN"><?php echo e((string) ($settings['company_address'] ?? '')); ?></textarea>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="company_phone" class="form-label">Company Phone</label>
            <input type="text" class="form-control" id="company_phone" name="company_phone"
                   value="<?php echo e((string) ($settings['company_phone'] ?? '')); ?>"
                   placeholder="+91 XXXXX XXXXX">
        </div>
        <div class="col-md-6">
            <label for="company_state" class="form-label">Company State <small class="text-muted">(for IGST vs CGST+SGST)</small></label>
            <input type="text" class="form-control" id="company_state" name="company_state"
                   value="<?php echo e((string) ($settings['company_state'] ?? '')); ?>"
                   placeholder="e.g. Rajasthan">
            <small class="text-muted">Must match state name as entered by customers.</small>
        </div>
    </div>
    <div class="mb-3">
        <label for="hsn_code" class="form-label">Default HSN Code</label>
        <input type="text" class="form-control" id="hsn_code" name="hsn_code"
               value="<?php echo e((string) ($settings['hsn_code'] ?? '5208')); ?>"
               placeholder="5208" maxlength="8">
        <small class="text-muted">Default 5208 for woven cotton fabric.</small>
    </div>
    <button type="submit" class="btn btn-primary">Save Invoice Details</button>
</form>

<?php // ── PACKING SLIP ─────────────────────────────────────────────────────────
elseif ($activeTab === 'packing'): ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="tab" value="packing">
    <p class="text-muted">Text printed on every packing slip. Leave blank to hide that section.</p>
    <div class="mb-3">
        <label for="packing_unboxing_notice" class="form-label">Unboxing Video Notice</label>
        <textarea class="form-control" id="packing_unboxing_notice" name="packing_unboxing_notice" rows="2"><?php echo e((string) ($settings['packing_unboxing_notice'] ?? '')); ?></textarea>
        <small class="text-muted">Shown below the payment bar on every slip.</small>
    </div>
    <div class="mb-3">
        <label for="packing_cod_notice" class="form-label">COD Warning Message</label>
        <textarea class="form-control" id="packing_cod_notice" name="packing_cod_notice" rows="2"><?php echo e((string) ($settings['packing_cod_notice'] ?? '')); ?></textarea>
        <small class="text-muted">Shown only on Cash on Delivery orders.</small>
    </div>
    <div class="mb-3">
        <label for="packing_footer_note" class="form-label">Footer Note</label>
        <textarea class="form-control" id="packing_footer_note" name="packing_footer_note" rows="2"><?php echo e((string) ($settings['packing_footer_note'] ?? '')); ?></textarea>
        <small class="text-muted">Bottom note on every slip (e.g. tampered packaging warning).</small>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label for="packing_repeat_badge_label" class="form-label">Repeat Customer Badge Label</label>
            <input type="text" class="form-control" id="packing_repeat_badge_label" name="packing_repeat_badge_label"
                   value="<?php echo e((string) ($settings['packing_repeat_badge_label'] ?? '')); ?>">
            <small class="text-muted">Leave blank to hide badge.</small>
        </div>
        <div class="col-md-6">
            <label for="packing_repeat_min_orders" class="form-label">Repeat Customer Threshold</label>
            <input type="number" class="form-control" id="packing_repeat_min_orders" name="packing_repeat_min_orders"
                   min="1" step="1" value="<?php echo e((string) ($settings['packing_repeat_min_orders'] ?? '1')); ?>">
            <small class="text-muted">Min. previous orders to show badge. Default: 1.</small>
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save Packing Slip</button>
</form>

<?php // ── ANNOUNCEMENTS ─────────────────────────────────────────────────────────
elseif ($activeTab === 'announcements'): ?>
<form method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="tab" value="announcements">
    <p class="text-muted">Up to 5 rotating announcement messages for the homepage bar.</p>
    <div class="mb-3">
        <button type="submit" name="reset_announcement_dismissals" value="1"
                class="btn btn-outline-warning btn-sm"
                onclick="return confirm('Reset announcement dismissals for all visitors?');">
            Reset Announcement Dismissals
        </button>
    </div>
    <div class="row g-3 mb-4">
        <?php for ($i = 1; $i <= 5; $i++):
            $textKey    = 'announcement_' . $i . '_text';
            $enabledKey = 'announcement_' . $i . '_enabled';
            $isEnabled  = ((string) ($settings[$enabledKey] ?? '0')) === '1';
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
                <textarea class="form-control" id="<?php echo e($textKey); ?>" name="<?php echo e($textKey); ?>" rows="2"
                          placeholder="Type announcement message"><?php echo e((string) ($settings[$textKey] ?? '')); ?></textarea>
            </div>
        </div>
        <?php endfor; ?>
    </div>
    <button type="submit" class="btn btn-primary">Save Announcements</button>
</form>

<?php // ── BRANDING ─────────────────────────────────────────────────────────────
elseif ($activeTab === 'branding'): ?>
<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="tab" value="branding">
    <div class="mb-4">
        <label for="branding_logo" class="form-label fw-semibold">Site Logo</label>
        <div class="mb-2">
            <?php if (!empty($settings['branding_logo']) && file_exists(__DIR__ . '/../' . $settings['branding_logo'])): ?>
                <div class="p-3 bg-dark rounded d-inline-block">
                    <img src="../<?php echo e($settings['branding_logo']); ?>" alt="Current Logo" class="settings-logo-preview">
                </div>
            <?php else: ?>
                <div class="alert alert-info">No logo currently set.</div>
            <?php endif; ?>
        </div>
        <input type="file" class="form-control" id="branding_logo" name="branding_logo" accept="image/jpeg,image/png,image/webp">
        <small class="text-muted">PNG or WEBP with transparent background recommended. Max 2MB.</small>
    </div>
    <h5>Hero Section Cards</h5>
    <p class="text-muted">Upload images for the 6 hero cards shown on the homepage.</p>
    <div class="row g-3 mb-4">
        <?php for ($i = 1; $i <= 6; $i++): $key = 'hero_swatch_' . $i; ?>
        <div class="col-md-4">
            <label class="form-label">Hero Card <?php echo $i; ?></label>
            <?php if (!empty($settings[$key]) && file_exists(__DIR__ . '/../' . $settings[$key])): ?>
                <div class="mb-2">
                    <img src="../<?php echo e($settings[$key]); ?>" alt="Hero Card <?php echo $i; ?>"
                         class="img-fluid rounded border" style="max-height:120px;object-fit:cover;">
                </div>
            <?php endif; ?>
            <input type="file" class="form-control" name="<?php echo e($key); ?>" accept="image/jpeg,image/png,image/webp">
        </div>
        <?php endfor; ?>
    </div>
    <button type="submit" class="btn btn-primary">Save Branding</button>
</form>
<?php endif; ?>
<?php include 'partials/footer.php'; ?>
