<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

if (is_customer_logged_in()) {
    redirect('/index.php');
}

$errors  = [];
$sent    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session. Please try again.');
        redirect('/customer/resend-verification.php');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!public_form_rate_limit_allow('resend_verify_' . $ip, 3, 600)) {
        $errors['_rate_limit'] = 'Too many requests. Please wait 10 minutes before trying again.';
    }

    $email = trim($_POST['email'] ?? '');
    if (empty($errors) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (empty($errors)) {
        // Look up unverified customer — always show the same response to prevent email enumeration
        $stmt = $conn->prepare(
            "SELECT id, name FROM customers WHERE email = ? AND email_verified = 0 LIMIT 1"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if ($customer) {
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $verifyExpires = (new DateTime('now', new DateTimeZone('UTC')))->modify('+24 hours')->format('Y-m-d H:i:s');

            $upd = $conn->prepare(
                "UPDATE customers SET email_verify_token = ?, email_verify_expires = ? WHERE id = ?"
            );
            $upd->bind_param('ssi', $tokenHash, $verifyExpires, $customer['id']);
            $upd->execute();

            send_customer_verification_email($email, (string) $customer['name'], $token);
        }

        // Always show success to avoid disclosing which emails are registered
        $sent = true;
    }
}

$metaTitle = 'Resend Verification Email | Amber Fabrics';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Resend Verification Email</h1>
        <p class="mb-0">Enter the email address you registered with and we'll send a new verification link.</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">

                <?php if ($sent): ?>
                    <div class="alert alert-success">
                        If that email address has an unverified account, a new verification link has been sent.
                        Please check your inbox (and spam folder).
                    </div>
                    <p class="text-center"><a href="/customer/login.php">Back to Login</a></p>
                <?php else: ?>
                    <?php if (!empty($errors['_rate_limit'])): ?>
                        <div class="alert alert-danger"><?php echo e($errors['_rate_limit']); ?></div>
                    <?php endif; ?>

                    <div class="surface-panel p-4">
                        <form method="POST" action="/customer/resend-verification.php" novalidate>
                            <?php echo csrf_field(); ?>

                            <div class="mb-3">
                                <label class="form-label">Email Address *</label>
                                <input type="email" name="email"
                                    class="<?php echo form_class($errors, 'email'); ?>"
                                    value="<?php echo e($_POST['email'] ?? ''); ?>"
                                    required autofocus>
                                <?php echo form_error($errors, 'email'); ?>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Send Verification Email</button>
                        </form>
                    </div>

                    <p class="text-center mt-3 text-muted">
                        Already verified? <a href="/customer/login.php">Log in</a>
                    </p>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
