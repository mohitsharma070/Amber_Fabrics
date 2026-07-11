<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

$token   = trim($_GET['token'] ?? '');
$tokenHash = '';
$errors  = [];
$invalid = false;

// Reject empty or suspiciously short tokens before any DB query to prevent
// empty-string hash collision attacks.
if ($token === '' || strlen($token) < 32) {
    $invalid = true;
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT id FROM customers WHERE reset_token = ? AND reset_token_expires > UTC_TIMESTAMP()"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    if (!$customer) {
        $invalid = true;
    }
}

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session. Please try again.');
        redirect('/customer/reset-password.php?token=' . urlencode($token));
    }

    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $passwordError = password_strength_error($password);
    if ($passwordError !== null) { $errors['password'] = $passwordError; }
    if ($password !== $confirm){ $errors['confirm_password'] = 'Passwords do not match.'; }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd  = $conn->prepare(
            "UPDATE customers SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?"
        );
        $upd->bind_param('si', $hash, $customer['id']);
        $upd->execute();
        flash('success', 'Password reset successfully. Please log in.');
        redirect('/customer/login.php');
    }
}

$metaTitle = SiteContext::title('Reset Password');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="container"><h1>Set New Password</h1></div></section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <?php if ($invalid): ?>
                    <div class="alert alert-danger">This reset link is invalid or has expired. <a href="/customer/forgot-password.php">Request a new one</a>.</div>
                <?php else: ?>
                    <div class="surface-panel p-4">
                        <form method="POST" action="/customer/reset-password.php?token=<?php echo urlencode($token); ?>">
                            <?php echo csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label">New Password <small class="text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                                <input type="password" name="password" class="<?php echo form_class($errors, 'password'); ?>" required>
                                <?php echo form_error($errors, 'password'); ?>
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" class="<?php echo form_class($errors, 'confirm_password'); ?>" required>
                                <?php echo form_error($errors, 'confirm_password'); ?>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Set New Password</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
