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

function admin_otp_attempt_key(string $scope, int $adminId, string $ip): string
{
    return hash('sha256', 'admin_otp|' . strtolower(trim($scope)) . '|' . $adminId . '|' . trim($ip));
}

function admin_otp_rate_limited(mysqli $conn, string $attemptKey): bool
{
    $stmt = $conn->prepare("SELECT attempts, blocked_until FROM admin_login_attempts WHERE attempt_key = ? LIMIT 1");
    $stmt->bind_param('s', $attemptKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return false;
    }
    $blockedUntilTs = admin_utc_mysql_to_timestamp((string) ($row['blocked_until'] ?? ''));
    return $blockedUntilTs !== null && $blockedUntilTs > time();
}

function admin_otp_record_attempt(mysqli $conn, string $attemptKey, bool $success, int $maxAttempts, int $windowSeconds): void
{
    if ($success) {
        $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE attempt_key = ?");
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
        return;
    }
    $windowMinutes = (int) ceil(max(60, $windowSeconds) / 60);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('verify-otp.php');
    }

    $action = (string) ($_POST['action'] ?? 'verify');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($action === 'resend') {
        $resendKey = admin_otp_attempt_key('resend', $pendingAdminId, $ip);
        if (admin_otp_rate_limited($conn, $resendKey)) {
            flash('error', 'Too many OTP resend requests. Please wait and try again.');
            redirect('verify-otp.php');
        }
        $otpStmt = $conn->prepare("SELECT resend_available_at FROM admin_login_otps WHERE admin_id = ? LIMIT 1");
        $otpStmt->bind_param('i', $pendingAdminId);
        $otpStmt->execute();
        $otpRow = $otpStmt->get_result()->fetch_assoc();

        if (!$otpRow) {
            flash('error', 'OTP session expired. Please start login again.');
            redirect('login.php');
        }

        $resendAt = admin_utc_mysql_to_timestamp((string) ($otpRow['resend_available_at'] ?? ''));
        if ($resendAt !== null && $resendAt > time()) {
            flash('error', 'Please wait before requesting a new OTP.');
            redirect('verify-otp.php');
        }

        $otp = (string) random_int(100000, 999999);
        $otpHash = hash('sha256', $otp);
        $update = $conn->prepare(
            "UPDATE admin_login_otps
             SET otp_hash = ?,
                 attempts = 0,
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                 resend_available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                 created_ip = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE admin_id = ?"
        );
        $ttl = ADMIN_OTP_TTL_SECONDS;
        $resend = ADMIN_OTP_RESEND_SECONDS;
        $update->bind_param('siisi', $otpHash, $ttl, $resend, $ip, $pendingAdminId);
        $update->execute();

        try {
            $mailSent = send_admin_login_otp_email($pendingEmail, $pendingName, $otp, true);
            if ($mailSent) {
                admin_otp_record_attempt($conn, $resendKey, true, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
                log_admin_activity($conn, $pendingAdminId, 'admin_otp_resent', 'admin', $pendingAdminId, 'OTP resend successful.', 'ok');
                flash('success', 'New OTP sent to your email.');
            } else {
                admin_otp_record_attempt($conn, $resendKey, false, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
                flash('error', 'OTP resend failed. Check mail configuration.');
            }
        } catch (Throwable $e) {
            admin_otp_record_attempt($conn, $resendKey, false, ADMIN_OTP_RESEND_MAX_ATTEMPTS, ADMIN_OTP_RESEND_WINDOW_SECONDS);
            error_log('[amberfabrics] admin otp resend failed: ' . $e->getMessage());
            flash('error', 'OTP resend failed. Check mail configuration.');
        }

        redirect('verify-otp.php');
    }

    $otpInput = trim((string) ($_POST['otp'] ?? ''));
    $verifyKey = admin_otp_attempt_key('verify', $pendingAdminId, $ip);
    if (admin_otp_rate_limited($conn, $verifyKey)) {
        flash('error', 'Too many OTP verification attempts. Please start login again.');
        redirect('login.php');
    }
    if (!preg_match('/^\d{6}$/', $otpInput)) {
        $errors['otp'] = 'Enter a valid 6-digit OTP.';
    } else {
        $otpStmt = $conn->prepare(
            "SELECT otp_hash, expires_at, attempts
             FROM admin_login_otps
             WHERE admin_id = ?
             LIMIT 1"
        );
        $otpStmt->bind_param('i', $pendingAdminId);
        $otpStmt->execute();
        $otpRow = $otpStmt->get_result()->fetch_assoc();

        if (!$otpRow) {
            flash('error', 'OTP session expired. Start login again.');
            redirect('login.php');
        }

        $expiresAt = admin_utc_mysql_to_timestamp((string) ($otpRow['expires_at'] ?? ''));
        $attempts = (int) ($otpRow['attempts'] ?? 0);
        if ($expiresAt === null || $expiresAt <= time()) {
            $del = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ?");
            $del->bind_param('i', $pendingAdminId);
            $del->execute();
            flash('error', 'OTP expired. Please login again.');
            redirect('login.php');
        }

        if ($attempts >= ADMIN_OTP_MAX_VERIFY_ATTEMPTS) {
            $del = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ?");
            $del->bind_param('i', $pendingAdminId);
            $del->execute();
            flash('error', 'Too many invalid OTP attempts. Start login again.');
            redirect('login.php');
        }

        $isValid = hash_equals((string) $otpRow['otp_hash'], hash('sha256', $otpInput));
        if (!$isValid) {
            admin_otp_record_attempt($conn, $verifyKey, false, ADMIN_OTP_VERIFY_MAX_ATTEMPTS, ADMIN_OTP_VERIFY_WINDOW_SECONDS);
            $inc = $conn->prepare(
                "UPDATE admin_login_otps
                 SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE admin_id = ?"
            );
            $inc->bind_param('i', $pendingAdminId);
            $inc->execute();
            $errors['otp'] = 'Invalid OTP.';
        } else {
            if ($appMfaPassphrase !== '') {
                $passphraseInput = trim((string) ($_POST['passphrase'] ?? ''));
                if ($passphraseInput === '' || !hash_equals($appMfaPassphrase, $passphraseInput)) {
                    admin_otp_record_attempt($conn, $verifyKey, false, ADMIN_OTP_VERIFY_MAX_ATTEMPTS, ADMIN_OTP_VERIFY_WINDOW_SECONDS);
                    $errors['otp'] = 'Verification failed.';
                    log_admin_activity($conn, $pendingAdminId, 'admin_login_mfa_failed', 'admin', $pendingAdminId, 'Passphrase verification failed after OTP.', 'denied');
                    goto otp_render;
                }
            }

            admin_otp_record_attempt($conn, $verifyKey, true, ADMIN_OTP_VERIFY_MAX_ATTEMPTS, ADMIN_OTP_VERIFY_WINDOW_SECONDS);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $pendingAdminId;
            $_SESSION['admin_name'] = $pendingName;
            $_SESSION['admin_role'] = $pendingRole !== '' ? $pendingRole : 'viewer';
            $_SESSION['admin_session_started_at'] = time();
            $_SESSION['admin_last_seen_at'] = time();
            $_SESSION['admin_session_fingerprint'] = hash('sha256', trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) . '|' . trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
            unset(
                $_SESSION['admin_pending_otp_admin_id'],
                $_SESSION['admin_pending_otp_email'],
                $_SESSION['admin_pending_otp_name'],
                $_SESSION['admin_pending_otp_role'],
                $_SESSION['admin_pending_otp_verify_key']
            );

            $del = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ?");
            $del->bind_param('i', $pendingAdminId);
            $del->execute();

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
                error_log('[amberfabrics] admin login metadata update failed: ' . $e->getMessage());
            }
            log_admin_activity($conn, $pendingAdminId, 'admin_login_success', 'admin', $pendingAdminId, 'OTP login completed.', 'ok');

            $securityAlertRecipient = trim((string) _cfg('ADMIN_NOTIFICATION_EMAIL', ''));
            if ($securityAlertRecipient !== '' && function_exists('send_email')) {
                $alertSubject = 'Admin Login Alert';
                $alertBody = "Admin login successful.\nAdmin: {$pendingEmail}\nTime (UTC): " . gmdate('Y-m-d H:i:s') . "\nIP: " . (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                try {
                    send_email($securityAlertRecipient, $alertSubject, $alertBody);
                } catch (Throwable $alertError) {
                    error_log('[amberfabrics] admin login alert email failed: ' . $alertError->getMessage());
                }
            }

            flash('success', 'Welcome back, ' . $pendingName . '!');
            redirect('dashboard.php');
        }
    }
}
otp_render:

$cooldownSeconds = 0;
$otpStateStmt = $conn->prepare("SELECT resend_available_at FROM admin_login_otps WHERE admin_id = ? LIMIT 1");
$otpStateStmt->bind_param('i', $pendingAdminId);
$otpStateStmt->execute();
$otpState = $otpStateStmt->get_result()->fetch_assoc();
if ($otpState) {
    $resendAt = admin_utc_mysql_to_timestamp((string) ($otpState['resend_available_at'] ?? ''));
    if ($resendAt !== null && $resendAt > time()) {
        $cooldownSeconds = max(0, $resendAt - time());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP | Amber Fabrics Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260506c">
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
                        <div class="alert alert-success"><?php echo e($msg); ?></div>
                    <?php endif; ?>
                    <?php if ($msg = flash('error')): ?>
                        <div class="alert alert-danger"><?php echo e($msg); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="verify-otp.php" novalidate class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="verify">
                        <div class="mb-3">
                            <label class="form-label">OTP</label>
                            <input
                                type="text"
                                name="otp"
                                class="<?php echo form_class($errors, 'otp'); ?>"
                                inputmode="numeric"
                                pattern="\d{6}"
                                maxlength="6"
                                required
                                autofocus
                            >
                            <?php echo form_error($errors, 'otp'); ?>
                        </div>
                        <?php if ($appMfaPassphrase !== ''): ?>
                        <div class="mb-3">
                            <label class="form-label">Security Passphrase</label>
                            <input type="password" name="passphrase" class="form-control" autocomplete="off" required>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary w-100">Verify and Login</button>
                    </form>

                    <form method="POST" action="verify-otp.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="resend">
                        <button
                            type="submit"
                            class="btn btn-outline-secondary w-100"
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
</body>
</html>
