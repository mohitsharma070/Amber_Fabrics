<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('password-reset.php');
    }

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if ($password === '' || $confirm === '') {
        $errors[] = 'Password and confirmation are required.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } elseif (($passwordError = password_strength_error($password)) !== null) {
        $errors[] = $passwordError;
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE admins SET password_hash = ?, force_password_reset = 0 WHERE id = ?");
        $stmt->bind_param('si', $hash, $_SESSION['admin_id']);
        $stmt->execute();
        unset($_SESSION['must_reset_password']);
        flash('success', 'Password updated. You can continue.');
        redirect('dashboard.php');
    } else {
        flash('error', implode(' ', $errors));
    }
}
?>
<?php
$metaTitle = 'Admin Password Reset | Amber Fabrics';
$metaDescription = 'Admin password reset page for Amber Fabrics. Securely update your credentials.';
$metaKeywords = 'admin, password reset, secure, Amber Fabrics';
include 'partials/header.php'; ?>

<h1 class="mb-4">Set a New Password</h1>

<form method="POST" class="col-md-6">
    <?php echo csrf_field(); ?>
    <div class="mb-3">
        <label class="form-label">New password <small class="text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
        <input type="password" name="password" class="form-control" required minlength="10">
    </div>
    <div class="mb-3">
        <label class="form-label">Confirm password</label>
        <input type="password" name="confirm" class="form-control" required minlength="10">
    </div>
    <button class="btn btn-primary">Update Password</button>
</form>

<?php include 'partials/footer.php'; ?>
