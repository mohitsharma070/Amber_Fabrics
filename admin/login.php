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

function admin_attempt_key(string $email, string $ip): string
{
    return hash('sha256', strtolower(trim($email)) . '|' . trim($ip));
}

function admin_rate_limit_status(mysqli $conn, string $attemptKey): array
{
    $stmt = $conn->prepare("SELECT attempts, blocked_until FROM admin_login_attempts WHERE attempt_key = ? LIMIT 1");
    $stmt->bind_param('s', $attemptKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['blocked' => false, 'attempts' => 0];
    }

    $blockedUntilTs = admin_utc_mysql_to_timestamp((string) ($row['blocked_until'] ?? ''));
    if ($blockedUntilTs !== null && $blockedUntilTs > time()) {
        return ['blocked' => true, 'attempts' => (int) $row['attempts']];
    }

    return ['blocked' => false, 'attempts' => (int) $row['attempts']];
}

function admin_record_attempt(mysqli $conn, string $attemptKey, bool $success): void
{
    if ($success) {
        $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE attempt_key = ?");
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
        return;
    }

    $windowMinutes = (int) ceil(ADMIN_OTP_REQUEST_WINDOW_SECONDS / 60);
    $maxAttempts = ADMIN_OTP_REQUEST_MAX_ATTEMPTS;
    $stmt = $conn->prepare(
        "INSERT INTO admin_login_attempts (attempt_key, attempts, blocked_until)
         VALUES (?, 1, NULL)
         ON DUPLICATE KEY UPDATE
             attempts = attempts + 1,
             blocked_until = CASE
                 WHEN (attempts + 1) >= ? THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
                 ELSE blocked_until
             END,
             updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('sii', $attemptKey, $maxAttempts, $windowMinutes);
    $stmt->execute();
}

function admin_otp_issue_cooldown_seconds(mysqli $conn, int $adminId): int
{
    if ($adminId <= 0) {
        return 0;
    }
    $stmt = $conn->prepare("SELECT resend_available_at FROM admin_login_otps WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return 0;
    }
    $resendAt = admin_utc_mysql_to_timestamp((string) ($row['resend_available_at'] ?? ''));
    if ($resendAt === null || $resendAt <= time()) {
        return 0;
    }
    return max(0, $resendAt - time());
}

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
        $attemptKey = admin_attempt_key($email, $ip);
        $rateStatus = admin_rate_limit_status($conn, $attemptKey);
        if ($rateStatus['blocked']) {
            $errors['_login'] = 'Too many attempts. Please wait 15 minutes and try again.';
        } else {
            $stmt = $conn->prepare("SELECT id, name, email, role, is_active FROM admins WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $admin = $stmt->get_result()->fetch_assoc();

            if (!$admin) {
                admin_record_attempt($conn, $attemptKey, false);
                $errors['_login'] = 'Unable to process login request.';
            } else {
                if (isset($admin['is_active']) && (int) ($admin['is_active'] ?? 1) !== 1) {
                    admin_record_attempt($conn, $attemptKey, false);
                    $errors['_login'] = 'Unable to process login request.';
                    log_admin_activity($conn, (int) ($admin['id'] ?? 0), 'admin_login_blocked_inactive', 'admin', (int) ($admin['id'] ?? 0), 'Inactive admin login attempt.', 'denied');
                    goto render_login;
                }

                $cooldownSeconds = admin_otp_issue_cooldown_seconds($conn, (int) $admin['id']);
                if ($cooldownSeconds > 0) {
                    $errors['_login'] = 'OTP already sent. Please wait ' . $cooldownSeconds . ' seconds before requesting a new OTP.';
                    log_admin_activity($conn, (int) ($admin['id'] ?? 0), 'admin_otp_send_throttled', 'admin', (int) ($admin['id'] ?? 0), 'OTP send throttled due to cooldown.', 'denied');
                    goto render_login;
                }

                $otp = (string) random_int(100000, 999999);
                $otpHash = hash('sha256', $otp);

                $upsert = $conn->prepare(
                    "INSERT INTO admin_login_otps (admin_id, otp_hash, expires_at, attempts, resend_available_at, created_ip)
                     VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?)
                     ON DUPLICATE KEY UPDATE
                         otp_hash = VALUES(otp_hash),
                         expires_at = VALUES(expires_at),
                         attempts = 0,
                         resend_available_at = VALUES(resend_available_at),
                         created_ip = VALUES(created_ip),
                         updated_at = CURRENT_TIMESTAMP"
                );
                $adminId = (int) $admin['id'];
                $ttl = ADMIN_OTP_TTL_SECONDS;
                $resend = ADMIN_OTP_RESEND_SECONDS;
                $upsert->bind_param('isiis', $adminId, $otpHash, $ttl, $resend, $ip);
                $upsert->execute();

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
                    $errors['_login'] = 'OTP email could not be sent. Check mail configuration.';
                } else {
                    admin_record_attempt($conn, $attemptKey, true);
                    $_SESSION['admin_pending_otp_admin_id'] = $adminId;
                    $_SESSION['admin_pending_otp_email'] = (string) $admin['email'];
                    $_SESSION['admin_pending_otp_name'] = (string) $admin['name'];
                    $_SESSION['admin_pending_otp_role'] = strtolower(trim((string) ($admin['role'] ?? 'viewer')));
                    flash('success', 'OTP sent to your email.');
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260506c">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Admin OTP Login</h1>
                    <p class="text-muted small mb-4">Enter your admin email to receive a one-time login code.</p>

                    <?php if ($msg = flash('success')): ?>
                        <div class="alert alert-success"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if ($msg = flash('error')): ?>
                        <div class="alert alert-danger"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($errors['_login'])): ?>
                        <div class="alert alert-danger"><?php echo e($errors['_login']); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input
                                type="email"
                                name="email"
                                class="<?php echo form_class($errors, 'email'); ?>"
                                value="<?php echo e($oldEmail); ?>"
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
</body>
</html>
