<?php
require_once __DIR__ . '/../includes/init.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
}

if (empty($_SESSION['admin_pending_otp_admin_id']) || empty($_SESSION['admin_pending_otp_email'])) {
    flash('error', 'Start login again to verify OTP.');
    redirect('login.php');
}

const ADMIN_OTP_TTL_SECONDS = 300;
const ADMIN_OTP_RESEND_SECONDS = 60;
const ADMIN_OTP_MAX_VERIFY_ATTEMPTS = 5;
const ADMIN_OTP_VERIFY_WINDOW_SECONDS = 900;
const ADMIN_OTP_VERIFY_MAX_ATTEMPTS = 8;
const ADMIN_OTP_RESEND_WINDOW_SECONDS = 900;
const ADMIN_OTP_RESEND_MAX_ATTEMPTS = 6;

$errors = [];
$pendingAdminId = (int) $_SESSION['admin_pending_otp_admin_id'];
$pendingEmail = (string) $_SESSION['admin_pending_otp_email'];
$pendingName = (string) ($_SESSION['admin_pending_otp_name'] ?? 'Admin');
$pendingRole = strtolower(trim((string) ($_SESSION['admin_pending_otp_role'] ?? 'viewer')));
$appMfaPassphrase = trim((string) _cfg('ADMIN_LOGIN_PASSPHRASE', ''));
$appMfaPassphraseRequired = strtolower((string) ($GLOBALS['_app_mode'] ?? 'local')) === 'production'
    || $appMfaPassphrase !== '';

function admin_clear_pending_otp_session(): void
{
    unset(
        $_SESSION['admin_pending_otp_admin_id'],
        $_SESSION['admin_pending_otp_email'],
        $_SESSION['admin_pending_otp_name'],
        $_SESSION['admin_pending_otp_role']
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('verify-otp.php');
    }

    $action = (string) ($_POST['action'] ?? 'verify');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($action === 'resend') {
        $resendKey = AdminOtpService::otpAttemptKey('resend', $pendingAdminId, $ip);
        if (AdminOtpService::isRateLimited($conn, $resendKey)) {
            flash('error', 'Too many OTP resend requests. Please wait and try again.');
            redirect('verify-otp.php');
        }
        $issued = AdminOtpService::issue(
            $conn,
            $pendingAdminId,
            $ip,
            ADMIN_OTP_TTL_SECONDS,
            ADMIN_OTP_RESEND_SECONDS,
            true
        );
        if (($issued['status'] ?? '') === 'missing') {
            admin_clear_pending_otp_session();
            flash('error', 'OTP session expired. Please start login again.');
            redirect('login.php');
        }
        if (($issued['status'] ?? '') === 'cooldown') {
            flash('error', 'Please wait before requesting a new OTP.');
            redirect('verify-otp.php');
        }
        $otp = (string) ($issued['otp'] ?? '');
        $otpHash = (string) ($issued['otp_hash'] ?? '');

        try {
            $mailSent = EmailService::send_admin_login_otp_email($pendingEmail, $pendingName, $otp, true);
            if ($mailSent) {
                AdminOtpService::recordRateAttempt($conn, $resendKey, true, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
                log_admin_activity($conn, $pendingAdminId, 'admin_otp_resent', 'admin', $pendingAdminId, 'OTP resend successful.', 'ok');
                $localMailLog = strtolower((string) ($GLOBALS['_app_mode'] ?? '')) === 'local'
                    && strtolower(trim(_cfg('MAIL_DRIVER', 'smtp'))) === 'log';
                flash(
                    'success',
                    $localMailLog
                        ? 'New local OTP created. Open tmp/local-mail.log to read it.'
                        : 'New OTP sent to your email.'
                );
            } else {
                AdminOtpService::invalidateIssuedOtp($conn, $pendingAdminId, $otpHash);
                AdminOtpService::recordRateAttempt($conn, $resendKey, false, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
                flash('error', 'OTP resend failed. Check mail configuration.');
            }
        } catch (Throwable $e) {
            AdminOtpService::invalidateIssuedOtp($conn, $pendingAdminId, $otpHash);
            AdminOtpService::recordRateAttempt($conn, $resendKey, false, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
            error_log('[app] admin otp resend failed: ' . $e->getMessage());
            flash('error', 'OTP resend failed. Check mail configuration.');
        }

        redirect('verify-otp.php');
    }

    $otpInput = trim((string) ($_POST['otp'] ?? ''));
    if (!preg_match('/^\d{6}$/', $otpInput)) {
        $errors['otp'] = 'Enter a valid 6-digit OTP.';
    } else {
        $verification = AdminOtpService::verify(
            $conn,
            $pendingAdminId,
            $ip,
            $otpInput,
            trim((string) ($_POST['passphrase'] ?? '')),
            $appMfaPassphrase,
            $appMfaPassphraseRequired,
            ADMIN_OTP_MAX_VERIFY_ATTEMPTS,
            ADMIN_OTP_VERIFY_MAX_ATTEMPTS,
            ADMIN_OTP_VERIFY_WINDOW_SECONDS
        );
        $verificationStatus = (string) ($verification['status'] ?? 'missing');
        if ($verificationStatus === 'missing') {
            admin_clear_pending_otp_session();
            flash('error', 'OTP session expired. Start login again.');
            redirect('login.php');
        }
        if ($verificationStatus === 'expired') {
            admin_clear_pending_otp_session();
            flash('error', 'OTP expired. Please login again.');
            redirect('login.php');
        }
        if ($verificationStatus === 'blocked') {
            admin_clear_pending_otp_session();
            flash('error', 'Too many OTP verification attempts. Please start login again.');
            redirect('login.php');
        }
        if ($verificationStatus === 'exhausted') {
            admin_clear_pending_otp_session();
            flash('error', 'Too many invalid OTP attempts. Start login again.');
            redirect('login.php');
        }
        if ($verificationStatus === 'inactive') {
            admin_clear_pending_otp_session();
            flash('error', 'Admin access is unavailable. Contact a super admin.');
            redirect('login.php');
        }
        if ($verificationStatus === 'invalid') {
            if (!empty($verification['passphrase_failed'])) {
                $errors['otp'] = 'Verification failed.';
                log_admin_activity($conn, $pendingAdminId, 'admin_login_mfa_failed', 'admin', $pendingAdminId, 'Passphrase verification failed after OTP.', 'denied');
            } else {
                $errors['otp'] = 'Invalid OTP.';
            }
        } elseif ($verificationStatus === 'success') {
            $adminStatus = is_array($verification['admin'] ?? null) ? $verification['admin'] : [];
            $pendingName = trim((string) ($adminStatus['name'] ?? $pendingName));
            if ($pendingName === '') {
                $pendingName = 'Admin';
            }
            $pendingEmail = trim((string) ($adminStatus['email'] ?? $pendingEmail));
            $pendingRole = strtolower(trim((string) ($adminStatus['role'] ?? $pendingRole)));
            if ($pendingRole === '') {
                $pendingRole = 'viewer';
            }

            session_regenerate_id(true);
            $_SESSION['admin_id'] = $pendingAdminId;
            $_SESSION['admin_name'] = $pendingName;
            $_SESSION['admin_role'] = $pendingRole !== '' ? $pendingRole : 'viewer';
            $_SESSION['admin_session_started_at'] = time();
            $_SESSION['admin_last_seen_at'] = time();
            $_SESSION['admin_session_fingerprint'] = admin_session_fingerprint();
            admin_clear_pending_otp_session();

            try {
                $nowUtc = gmdate('Y-m-d H:i:s');
                $loginIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
                $loginUa = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                $updAdmin = $conn->prepare(
                    "UPDATE admins
                     SET last_login_at = ?, last_login_ip = ?, last_login_user_agent = ?
                     WHERE id = ?"
                );
                $updAdmin->bind_param('sssi', $nowUtc, $loginIp, $loginUa, $pendingAdminId);
                $updAdmin->execute();
            } catch (Throwable $e) {
                error_log('[app] admin login metadata update failed: ' . $e->getMessage());
            }
            log_admin_activity($conn, $pendingAdminId, 'admin_login_success', 'admin', $pendingAdminId, 'OTP login completed.', 'ok');

            $securityAlertRecipient = trim((string) _cfg('ADMIN_NOTIFICATION_EMAIL', ''));
            if ($securityAlertRecipient !== '' && function_exists('send_email')) {
                $alertSubject = 'Admin Login Alert';
                $alertBody = "Admin login successful.\nAdmin: {$pendingEmail}\nTime (UTC): " . gmdate('Y-m-d H:i:s') . "\nIP: " . (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                try {
                    send_email($securityAlertRecipient, $alertSubject, $alertBody);
                } catch (Throwable $alertError) {
                    error_log('[app] admin login alert email failed: ' . $alertError->getMessage());
                }
            }

            flash('success', 'Welcome back, ' . $pendingName . '!');
            redirect('dashboard.php');
        }
    }
}
otp_render:

$cooldownSeconds = AdminOtpService::cooldownSeconds($conn, $pendingAdminId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e('Verify OTP | ' . SiteContext::name() . ' Admin'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260822a">
    <link rel="stylesheet" href="../css/admin.css?v=20260822a">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Verify OTP</h1>
                    <p class="text-muted small mb-4">Enter the 6-digit OTP sent to <?php echo e($pendingEmail); ?>.</p>

                    <?php if ($msg = flash('success')): ?>
                        <div class="alert alert-success" role="status"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if ($msg = flash('error')): ?>
                        <div class="alert alert-danger" role="alert"><?php echo e($msg); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="verify-otp.php" novalidate class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3">
                            <label class="form-label" for="admin-otp">OTP</label>
                            <input
                                id="admin-otp"
                                type="text"
                                name="otp"
                                class="<?php echo form_class($errors, 'otp'); ?>"
                                inputmode="numeric"
                                pattern="\d{6}"
                                maxlength="6"
                                autocomplete="one-time-code"
                                required
                                autofocus
                            >
                            <?php echo form_error($errors, 'otp'); ?>
                        </div>
                        <?php if ($appMfaPassphrase !== ''): ?>
                        <div class="mb-3">
                            <label class="form-label" for="admin-passphrase">Security Passphrase</label>
                            <input id="admin-passphrase" type="password" name="passphrase" class="form-control" autocomplete="current-password" required>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary w-100">Verify and Login</button>
                    </form>

                    <form method="POST" action="verify-otp.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="resend">
                        <button
                            type="submit"
                            id="admin-otp-resend"
                            class="btn btn-outline-secondary w-100"
                            data-cooldown="<?php echo (int) $cooldownSeconds; ?>"
                            <?php echo $cooldownSeconds > 0 ? 'disabled' : ''; ?>
                        >
                            <?php echo $cooldownSeconds > 0 ? 'Resend OTP in ' . $cooldownSeconds . 's' : 'Resend OTP'; ?>
                        </button>
                    </form>

                    <div class="mt-3 text-center">
                        <a href="login.php" class="small">Use another email</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if ($cooldownSeconds > 0): ?>
<script nonce="<?php echo e($cspNonce); ?>">
(function () {
    var button = document.getElementById('admin-otp-resend');
    if (!button) return;
    var remaining = Number(button.dataset.cooldown || 0);
    var timer = window.setInterval(function () {
        remaining -= 1;
        if (remaining <= 0) {
            window.clearInterval(timer);
            button.disabled = false;
            button.textContent = 'Resend OTP';
            return;
        }
        button.textContent = 'Resend OTP in ' + remaining + 's';
    }, 1000);
})();
</script>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/partials/interaction-layer.php'; ?>
<script src="../js/script.js?v=20260822a" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
