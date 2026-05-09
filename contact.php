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

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO inquiries (name, email, whatsapp_number, country, message) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss', $prefill['name'], $prefill['email'], $prefill['phone'], $prefill['country'], $prefill['message']);
        $stmt->execute();

        flash('success', 'Message sent. We will get back to you soon.');
        redirect('thank-you.php');
    }
}

$metaTitle = 'Contact | Amber Fabrics';
$metaDescription = 'Contact Amber Fabrics for bulk orders, support, and business inquiries.';
$metaKeywords = 'contact, bulk inquiry, support, Amber Fabrics';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1 class="mb-2">International / Bulk Inquiry</h1>
        <p>For international buying and bulk textile requirements, share your details and our team will respond.</p>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="row g-4 justify-content-center">
            <div class="col-lg-6 animate-in">
                <div class="surface-panel">
                    <form method="POST" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label">Name *</label>
                                <input class="<?php echo form_class($errors, 'name'); ?>" required name="name" value="<?php echo e($prefill['name']); ?>" placeholder="Your full name">
                                <?php echo form_error($errors, 'name'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Email *</label>
                                <input class="<?php echo form_class($errors, 'email'); ?>" required type="email" name="email" value="<?php echo e($prefill['email']); ?>" placeholder="name@company.com">
                                <?php echo form_error($errors, 'email'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Country</label>
                                <input class="form-control" name="country" value="<?php echo e($prefill['country']); ?>" placeholder="Country">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Phone</label>
                                <input class="form-control" name="phone" type="tel" value="<?php echo e($prefill['phone']); ?>" placeholder="+91 98765 43210">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="5" placeholder="How can we help you?"><?php echo e($prefill['message']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <button name="submit" class="btn btn-primary w-100">Submit Inquiry</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
