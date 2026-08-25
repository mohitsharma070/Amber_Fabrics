<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

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
            "UPDATE customers
             SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL, auth_version = auth_version + 1
             WHERE id = ? AND reset_token = ? AND reset_token_expires > UTC_TIMESTAMP()"
        );
        $upd->bind_param('sis', $hash, $customer['id'], $tokenHash);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            $invalid = true;
            $errors['password'] = 'This reset link was already used or has expired.';
        } else {
            flash('success', 'Password reset successfully. Please log in.');
            redirect('/customer/login.php');
        }
    }
}

$metaTitle = SiteContext::title('Reset Password');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="l-container"><h1>Set New Password</h1></div></section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-justify-center">
            <div class="l-col-md-half l-col-lg-third">
                <?php if ($invalid): ?>
                    <div class="ui-alert ui-alert--error" role="alert">This reset link is invalid or has expired. <a href="/customer/forgot-password">Request a new one</a>.</div>
                <?php else: ?>
                    <div class="surface-panel u-p-4">
                        <form method="POST" action="/customer/reset-password.php?token=<?php echo urlencode($token); ?>">
                            <?php echo csrf_field(); ?>
                            <div class="u-mb-3">
                                <label for="password" class="ui-label">New Password <small class="u-text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                                <input id="password" type="password" name="password" class="<?php echo form_class($errors, 'password', 'ui-input'); ?>" required>
                                <?php echo form_error($errors, 'password', 'ui-field-error'); ?>
                            </div>
                            <div class="u-mb-4">
                                <label for="confirm_password" class="ui-label">Confirm Password</label>
                                <input id="confirm_password" type="password" name="confirm_password" class="<?php echo form_class($errors, 'confirm_password', 'ui-input'); ?>" required>
                                <?php echo form_error($errors, 'confirm_password', 'ui-field-error'); ?>
                            </div>
                            <button type="submit" class="ui-button ui-button--primary u-w-full">Set New Password</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
