<?php
require_once __DIR__ . '/includes/init.php';

$prefill = [
    'name'    => trim($_POST['name'] ?? ''),
    'email'   => trim($_POST['email'] ?? ''),
    'country' => trim($_POST['country'] ?? ''),
    'phone'   => trim($_POST['phone'] ?? ''),
    'message' => trim($_POST['message'] ?? ''),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('contact.php');
    }
    if (!public_form_rate_limit_allow('contact_form_submit', 5, 600)) {
        flash('error', 'Too many submissions. Please wait a few minutes and try again.');
        redirect('contact.php');
    }

    if ($prefill['name'] === '') {
        $errors['name'] = 'Name is required.';
    }
    if ($prefill['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($prefill['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }
    if ($prefill['name'] !== '' && mb_strlen($prefill['name']) > 120) {
        $errors['name'] = 'Name must be 120 characters or fewer.';
    }
    if ($prefill['country'] !== '' && mb_strlen($prefill['country']) > 100) {
        $errors['country'] = 'Country must be 100 characters or fewer.';
    }
    if ($prefill['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $prefill['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }
    if ($prefill['message'] !== '' && mb_strlen($prefill['message']) > 2000) {
        $errors['message'] = 'Message must be 2000 characters or fewer.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO inquiries (name, email, whatsapp_number, country, message) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss', $prefill['name'], $prefill['email'], $prefill['phone'], $prefill['country'], $prefill['message']);
        $stmt->execute();

        flash('success', 'Message sent. We will get back to you soon.');
        redirect('thank-you.php');
    }
}

$metaTitle = SiteContext::title('Contact');
$metaDescription = 'Contact ' . SiteContext::name() . ' for bulk orders, support, and business inquiries.';
$metaKeywords = 'contact, bulk inquiry, support, ' . SiteContext::name();
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1 class="u-mb-2">International / Bulk Inquiry</h1>
        <p>For international buying and bulk textile requirements, share your details and our team will respond.</p>
    </div>
</section>

<section class="section-block u-pt-0 inquiry-section">
    <div class="l-container">
        <div class="inquiry-shell inquiry-shell--compact animate-in">
                <div class="surface-panel inquiry-card">
                    <form method="POST" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="l-grid l-grid--12 u-gap-3">
                            <div class="l-col-sm-half">
                                <label class="ui-label">Name *</label>
                                <input class="<?php echo form_class($errors, 'name', 'ui-input'); ?>" required name="name" value="<?php echo e($prefill['name']); ?>" placeholder="Your full name">
                                <?php echo form_error($errors, 'name', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">Email *</label>
                                <input class="<?php echo form_class($errors, 'email', 'ui-input'); ?>" required type="email" name="email" value="<?php echo e($prefill['email']); ?>" placeholder="name@company.com">
                                <?php echo form_error($errors, 'email', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">Country</label>
                                <input class="<?php echo form_class($errors, 'country', 'ui-input'); ?>" name="country" value="<?php echo e($prefill['country']); ?>" placeholder="Country">
                                <?php echo form_error($errors, 'country', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">Phone</label>
                                <input class="<?php echo form_class($errors, 'phone', 'ui-input'); ?>" name="phone" type="tel" value="<?php echo e($prefill['phone']); ?>" placeholder="+91 98765 43210">
                                <?php echo form_error($errors, 'phone', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-full">
                                <label class="ui-label">Message</label>
                                <textarea class="<?php echo form_class($errors, 'message', 'ui-input'); ?>" name="message" rows="5" placeholder="How can we help you?"><?php echo e($prefill['message']); ?></textarea>
                                <?php echo form_error($errors, 'message', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-full">
                                <button name="submit" class="ui-button ui-button--primary u-w-full">Submit Inquiry</button>
                            </div>
                        </div>
                    </form>
                </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
