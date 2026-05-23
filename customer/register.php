<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

if (is_customer_logged_in()) {
    redirect('/index.php');
}

$errors = [];
$old = ['name' => '', 'email' => '', 'phone' => '', 'country' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session. Please try again.');
        redirect('/customer/register.php');
    }

    if (!public_form_rate_limit_allow('customer_register', 10, 600)) {
        flash('error', 'Too many attempts. Please wait a few minutes and try again.');
        redirect('/customer/register.php');
    }

    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $country  = trim($_POST['country']  ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $old = compact('name', 'email', 'phone', 'country');

    if ($name === '')   { $errors['name'] = 'Full name is required.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid email address.'; }
    $passwordError = password_strength_error($password);
    if ($passwordError !== null) { $errors['password'] = $passwordError; }
    if ($password !== $confirm) { $errors['confirm_password'] = 'Passwords do not match.'; }

    if (empty($errors)) {
        // Check duplicate
        $chk = $conn->prepare("SELECT id FROM customers WHERE email = ?");
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $errors['email'] = 'Unable to create account. Please try a different email or log in.';
        }
    }

    if (empty($errors)) {
        $hash  = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $verifyExpires = (new DateTime('now', new DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s');
        $stmt  = $conn->prepare(
            "INSERT INTO customers (name, email, password_hash, phone, country, email_verified, email_verify_token, email_verify_expires)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)"
        );
        $stmt->bind_param('sssssss', $name, $email, $hash, $phone, $country, $tokenHash, $verifyExpires);
        $stmt->execute();

        $emailSent = send_customer_verification_email($email, $name, $token);

        if ($emailSent) {
            flash('success', 'Account created! Please check your email to verify your address before logging in.');
        } else {
            flash('warning', 'Account created, but we could not send the verification email right now. '
                . 'Please <a href="/customer/resend-verification.php">request a new verification email</a>.');
        }
        redirect('/customer/login.php');
    }
}

$metaTitle = 'Register | Amber Fabrics';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Create an Account</h1>
        <p class="mb-0">Register to browse our catalog and place orders.</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">Please fix the errors below.</div>
                <?php endif; ?>

                <div class="surface-panel p-4">
                    <form method="POST" action="/customer/register.php" novalidate>
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="<?php echo form_class($errors, 'name'); ?>" value="<?php echo e($old['name']); ?>" required>
                            <?php echo form_error($errors, 'name'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="<?php echo form_class($errors, 'email'); ?>" value="<?php echo e($old['email']); ?>" required>
                            <?php echo form_error($errors, 'email'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo e($old['phone']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" class="form-control" value="<?php echo e($old['country']); ?>" placeholder="e.g. India, USA, Germany">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password * <small class="text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                            <input type="password" name="password" class="<?php echo form_class($errors, 'password'); ?>" required>
                            <?php echo form_error($errors, 'password'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" name="confirm_password" class="<?php echo form_class($errors, 'confirm_password'); ?>" required>
                            <?php echo form_error($errors, 'confirm_password'); ?>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Create Account</button>
                    </form>
                </div>

                <p class="text-center mt-3 text-muted">
                    Already have an account? <a href="/customer/login.php">Log in</a>
                </p>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
