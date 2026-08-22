<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

if (is_customer_logged_in()) {
    redirect('/index.php');
}

$errors  = [];
$oldEmail = '';
$returnTo = trim($_GET['return'] ?? '');
// Sanitise return URL: only allow relative paths
if (!preg_match('/^\/[a-zA-Z0-9\/_\-\.?&=%]*$/', $returnTo)) {
    $returnTo = '/index.php';
}
// Block protocol-relative redirects (e.g. //evil.com).
if (strpos($returnTo, '//') === 0) {
    $returnTo = '/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session. Please try again.');
        redirect('/customer/login.php');
    }

    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $oldEmail = $email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (!customer_check_rate_limit($conn, $email, $ip)) {
        $errors['_login'] = 'Too many failed attempts. Please wait ' . CUSTOMER_LOCK_MINUTES . ' minutes before trying again.';
    } else {
        $authentication = CustomerAuthenticationService::authenticate($conn, $email, $password);
        $authenticationStatus = (string) ($authentication['status'] ?? 'invalid');
        $customer = is_array($authentication['customer'] ?? null) ? $authentication['customer'] : [];

        if ($authenticationStatus === 'unverified') {
            $errors['_login'] = 'Please verify your email address before logging in.';
            $errors['_login_raw'] = '<a href="/customer/resend-verification.php">Resend verification email &rsaquo;</a>';
        } elseif ($authenticationStatus === 'inactive') {
            $errors['_login'] = 'Your account is inactive. Please contact support.';
        } elseif ($authenticationStatus === 'authenticated') {
            customer_record_attempt($conn, $email, $ip, true);
            session_regenerate_id(true);
            $_SESSION['customer_id']   = $customer['id'];
            $_SESSION['customer_name'] = $customer['name'];
            $_SESSION['customer_auth_version'] = max(1, (int) ($customer['auth_version'] ?? 1));
            $_SESSION['customer_session_started_at'] = time();
            $_SESSION['customer_last_seen_at'] = time();
            $_SESSION['customer_session_fingerprint'] = customer_session_fingerprint();

            CustomerSessionMergeService::mergeOnLogin($conn, (int) $customer['id']);

            flash('success', 'Welcome back, ' . $customer['name'] . '!');
            redirect($returnTo ?: '/index.php');
        } else {
            $errors['_login'] = 'Invalid email or password.';
        }
    }
}

$metaTitle = SiteContext::title('Login');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Log In</h1>
        <p class="mb-0">Access your account to view orders and shop fabrics.</p>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <?php if (!empty($errors['_login'])): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo e($errors['_login']); ?>
                        <?php if (!empty($errors['_login_raw'])): ?>
                            <br><small><?php echo $errors['_login_raw']; ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="surface-panel p-4">
                    <form method="POST" action="/customer/login.php<?php echo $returnTo ? '?return=' . urlencode($returnTo) : ''; ?>" novalidate>
                        <?php echo csrf_field(); ?>

                        <div class="mb-3">
                            <label class="form-label" for="customer-email">Email Address</label>
                            <input id="customer-email" type="email" name="email" class="<?php echo form_class($errors, 'email'); ?>" value="<?php echo e($oldEmail); ?>" autocomplete="email" required autofocus>
                            <?php echo form_error($errors, 'email'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="customer-password">Password</label>
                            <input id="customer-password" type="password" name="password" class="form-control" autocomplete="current-password" required>
                            <div class="mt-2 text-end">
                                <a href="/customer/forgot-password" class="small">Forgot password?</a>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Log In</button>
                    </form>
                </div>

                <p class="text-center mt-3 text-muted">
                    Don't have an account? <a href="/customer/register">Register</a>
                </p>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
