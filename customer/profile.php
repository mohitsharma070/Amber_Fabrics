<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

$customerId = (int) $_SESSION['customer_id'];
$errors = [];
$activeForm = '';

$custStmt = $conn->prepare("SELECT name, email, phone, country FROM customers WHERE id = ?");
$custStmt->bind_param('i', $customerId);
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session.');
        redirect('/customer/profile.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $activeForm = 'info';
        $name    = trim($_POST['name']    ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $country = trim($_POST['country'] ?? '');
        if ($name === '') { $errors['name'] = 'Name is required.'; }
        if (empty($errors)) {
            $upd = $conn->prepare("UPDATE customers SET name = ?, phone = ?, country = ? WHERE id = ?");
            $upd->bind_param('sssi', $name, $phone, $country, $customerId);
            $upd->execute();
            $_SESSION['customer_name'] = $name;
            flash('success', 'Profile updated.');
            redirect('/customer/profile.php');
        }
    } elseif ($action === 'change_password') {
        $activeForm = 'password';
        $current = $_POST['current_password']  ?? '';
        $newPass = $_POST['new_password']       ?? '';
        $confirm = $_POST['confirm_password']   ?? '';

        $hashStmt = $conn->prepare("SELECT password_hash FROM customers WHERE id = ?");
        $hashStmt->bind_param('i', $customerId);
        $hashStmt->execute();
        $hash = $hashStmt->get_result()->fetch_assoc()['password_hash'] ?? '';

        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'Current password is incorrect.';
        } elseif (($passwordError = password_strength_error($newPass)) !== null) {
            $errors['new_password'] = $passwordError;
        } elseif ($newPass !== $confirm) {
            $errors['confirm_password'] = 'New passwords do not match.';
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
            $upd->bind_param('si', $newHash, $customerId);
            $upd->execute();
            flash('success', 'Password changed successfully.');
            redirect('/customer/profile.php');
        }
    }
}

// Refresh
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();

$metaTitle = 'My Profile | Amber Fabrics';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="container"><h1>Account Settings</h1></div></section>

<section class="section-block">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6">
                <?php if ($errors && $activeForm === 'info'): ?>
                    <div class="alert alert-danger">Please fix the errors below.</div>
                <?php endif; ?>

                <div class="surface-panel p-4 mb-4">
                    <h5 class="mb-3">Profile Information</h5>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_info">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'name'); ?>" required value="<?php echo e($cust['name']); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'name'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <small class="text-muted">(read only)</small></label>
                            <input type="email" class="form-control" value="<?php echo e($cust['email']); ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo e($cust['phone'] ?? ''); ?>">
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Country</label>
                            <input type="text" name="country" class="form-control" value="<?php echo e($cust['country'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                </div>
            </div>

            <div class="col-md-6">
                <?php if ($errors && $activeForm === 'password'): ?>
                    <div class="alert alert-danger">Please fix the errors below.</div>
                <?php endif; ?>
                <div class="surface-panel p-4">
                    <h5 class="mb-3">Change Password</h5>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'current_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'current_password'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <small class="text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                            <input type="password" name="new_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'new_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'new_password'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'confirm_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'confirm_password'); ?>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Update Password</button>
                    </form>
                </div>

                <div class="mt-3 text-center">
                    <a href="/customer/orders.php">&larr; Back to My Orders</a>
                </div>
                <div class="mt-2 text-center">
                    <form method="POST" action="/customer/logout.php" class="d-inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

