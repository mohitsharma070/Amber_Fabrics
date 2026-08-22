<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session. Please try again.');
        redirect('/customer/forgot-password.php');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!public_form_rate_limit_allow('forgot_pw_' . $ip, 5, 600)) {
        $errors['_rate_limit'] = 'Too many requests. Please try again after 10 minutes.';
    }

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if ($customer) {
            $token   = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = (new DateTime('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');
            $upd = $conn->prepare(
                "UPDATE customers SET reset_token = ?, reset_token_expires = ? WHERE id = ?"
            );
            $upd->bind_param('ssi', $tokenHash, $expires, $customer['id']);
            $upd->execute();

            // Send email with reset link
            EmailService::send_customer_password_reset_email($email, $token);
        }
        // Always show success to prevent email enumeration
        $success = true;
    }
}

$metaTitle = SiteContext::title('Forgot Password');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1>Reset Password</h1>
    </div>
</section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-justify-center">
            <div class="l-col-md-half l-col-lg-third">
                <?php if ($success): ?>
                    <div class="ui-alert ui-alert--success">
                        If that email exists, we've sent a password reset link. Check your inbox.
                    </div>
                    <p class="u-text-center"><a href="/customer/login" class="app-back-link">&larr; Back to login</a></p>
                <?php else: ?>
                    <?php if ($errors): ?>
                        <div class="ui-alert ui-alert--error">Please fix the errors below.</div>
                    <?php endif; ?>
                    <div class="surface-panel u-p-4">
                        <p class="u-text-muted u-mb-3">Enter your email address and we'll send you a reset link.</p>
                        <form method="POST" action="/customer/forgot-password.php">
                            <?php echo csrf_field(); ?>
                            <div class="u-mb-3">
                                <label class="ui-label">Email Address</label>
                                <input type="email" name="email" class="<?php echo form_class($errors, 'email', 'ui-input'); ?>" required>
                                <?php echo form_error($errors, 'email', 'ui-field-error'); ?>
                            </div>
                            <button type="submit" class="ui-button ui-button--primary u-w-full">Send Reset Link</button>
                        </form>
                    </div>
                    <p class="u-text-center u-mt-3"><a href="/customer/login" class="app-back-link">&larr; Back to login</a></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
