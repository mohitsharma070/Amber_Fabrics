<?php
require_once __DIR__ . '/../includes/init.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
}

const ADMIN_OTP_TTL_SECONDS = 300;
const ADMIN_OTP_RESEND_SECONDS = 60;
const ADMIN_OTP_REQUEST_WINDOW_SECONDS = 900;
const ADMIN_OTP_REQUEST_MAX_ATTEMPTS = 5;

$errors = [];
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('login.php');
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $oldEmail = $email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $attemptKey = AdminOtpService::loginAttemptKey($email, $ip);
        if (AdminOtpService::isRateLimited($conn, $attemptKey)) {
            $errors['_login'] = 'Too many attempts. Please wait 15 minutes and try again.';
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, role, is_active FROM admins WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();

            if (!$admin) {
                AdminOtpService::recordRateAttempt($conn, $attemptKey, false, ADMIN_OTP_REQUEST_MAX_ATTEMPTS, ADMIN_OTP_REQUEST_WINDOW_SECONDS);
                $errors['_login'] = 'Unable to process login request.';
            } else {
                if (isset($admin['is_active']) && (int) ($admin['is_active'] ?? 1) !== 1) {
                    AdminOtpService::recordRateAttempt($conn, $attemptKey, false, ADMIN_OTP_REQUEST_MAX_ATTEMPTS, ADMIN_OTP_REQUEST_WINDOW_SECONDS);
                    $errors['_login'] = 'Unable to process login request.';
                    log_admin_activity($conn, (int) ($admin['id'] ?? 0), 'admin_login_blocked_inactive', 'admin', (int) ($admin['id'] ?? 0), 'Inactive admin login attempt.', 'denied');
                    goto render_login;
                }

                $adminId = (int) $admin['id'];
                $issued = AdminOtpService::issue(
                    $conn,
                    $adminId,
                    $ip,
                    ADMIN_OTP_TTL_SECONDS,
                    ADMIN_OTP_RESEND_SECONDS
                );
                if (($issued['status'] ?? '') === 'cooldown') {
                    $cooldownSeconds = (int) ($issued['cooldown'] ?? 1);
                    $errors['_login'] = 'OTP already sent. Please wait ' . $cooldownSeconds . ' seconds before requesting a new OTP.';
                    log_admin_activity($conn, (int) ($admin['id'] ?? 0), 'admin_otp_send_throttled', 'admin', (int) ($admin['id'] ?? 0), 'OTP send throttled due to cooldown.', 'denied');
                    goto render_login;
                }

                $otp = (string) ($issued['otp'] ?? '');
                $otpHash = (string) ($issued['otp_hash'] ?? '');

                $mailSent = false;
                try {
                    $mailSent = EmailService::send_admin_login_otp_email(
                        (string) $admin['email'],
                        (string) $admin['name'],
                        $otp,
                        false
                    );
                } catch (Throwable $e) {
                    error_log('[app] admin otp email send failed: ' . $e->getMessage());
                }

                if (!$mailSent) {
                    AdminOtpService::invalidateIssuedOtp($conn, $adminId, $otpHash);
                    $errors['_login'] = 'OTP email could not be sent. Check mail configuration.';
                } else {
                    AdminOtpService::recordRateAttempt($conn, $attemptKey, true, ADMIN_OTP_REQUEST_MAX_ATTEMPTS, ADMIN_OTP_REQUEST_WINDOW_SECONDS);
                    $_SESSION['admin_pending_otp_admin_id'] = $adminId;
                    $_SESSION['admin_pending_otp_email'] = (string) $admin['email'];
                    $_SESSION['admin_pending_otp_name'] = (string) $admin['name'];
                    $_SESSION['admin_pending_otp_role'] = strtolower(trim((string) ($admin['role'] ?? 'viewer')));
                    $localMailLog = strtolower((string) ($GLOBALS['_app_mode'] ?? '')) === 'local'
                        && strtolower(trim(_cfg('MAIL_DRIVER', 'smtp'))) === 'log';
                    flash(
                        'success',
                        $localMailLog
                            ? 'Local OTP created. Open tmp/local-mail.log to read it.'
                            : 'OTP sent to your email.'
                    );
                    redirect('verify-otp.php');
                }
            }
        }
    }
}
render_login:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(SiteContext::title('Admin Login')); ?></title>
    <link href="/css/bootstrap-5.3.3.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260901a">
    <link rel="stylesheet" href="../css/admin.css?v=20260822a">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Admin OTP Login</h1>
                    <p class="text-muted small mb-4">Enter your admin email to receive a one-time login code.</p>

                    <?php if ((string) ($_GET['logged_out'] ?? '') === '1'): ?>
                        <div class="alert alert-success" role="status">You have been logged out securely.</div>
                    <?php endif; ?>

                    <?php if ($msg = flash('success')): ?>
                        <div class="alert alert-success" role="status"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if ($msg = flash('error')): ?>
                        <div class="alert alert-danger" role="alert"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors['_login'])): ?>
                        <div class="alert alert-danger" role="alert"><?php echo e($errors['_login']); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label" for="admin-email">Email Address</label>
                            <input
                                id="admin-email"
                                type="email"
                                name="email"
                                class="<?php echo form_class($errors, 'email'); ?>"
                                value="<?php echo e($oldEmail); ?>"
                                autocomplete="email"
                                required
                                autofocus
                            >
                            <?php echo form_error($errors, 'email'); ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send OTP</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require dirname(__DIR__) . '/includes/partials/interaction-layer.php'; ?>
<script src="../js/script.js?v=20260902a" defer></script>
<script src="/js/bootstrap.bundle-5.3.3.min.js"></script>
</body>
</html>
