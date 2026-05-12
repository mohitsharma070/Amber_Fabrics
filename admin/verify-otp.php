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

function admin_utc_mysql_to_timestamp(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$dt) {
        return null;
    }

    return $dt->getTimestamp();
}

$errors = [];
$pendingAdminId = (int) $_SESSION['admin_pending_otp_admin_id'];
$pendingEmail = (string) $_SESSION['admin_pending_otp_email'];
$pendingName = (string) ($_SESSION['admin_pending_otp_name'] ?? 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('verify-otp.php');
    }

    $action = (string) ($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

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
            $mail = _mailer_base();
            $mail->addAddress($pendingEmail, $pendingName);
            $mail->Subject = 'Amber Fabrics Admin Login OTP (Resend)';
            $mail->Body = implode("\r\n", [
                'Hi ' . $pendingName . ',',
                '',
                'Your new admin login OTP is: ' . $otp,
                'It is valid for 5 minutes.',
                '',
                'If you did not request this OTP, ignore this email.',
                '',
                'Amber Fabrics',
            ]);
            $mail->send();
            flash('success', 'New OTP sent to your email.');
        } catch (Throwable $e) {
            error_log('[amberfabrics] admin otp resend failed: ' . $e->getMessage());
            flash('error', 'OTP resend failed. Check mail configuration.');
        }

        redirect('verify-otp.php');
    }

    $otpInput = trim((string) ($_POST['otp'] ?? ''));
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
            $inc = $conn->prepare(
                "UPDATE admin_login_otps
                 SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE admin_id = ?"
            );
            $inc->bind_param('i', $pendingAdminId);
            $inc->execute();
            $errors['otp'] = 'Invalid OTP.';
        } else {
            $adminStmt = $conn->prepare("SELECT force_password_reset FROM admins WHERE id = ? LIMIT 1");
            $adminStmt->bind_param('i', $pendingAdminId);
            $adminStmt->execute();
            $adminRow = $adminStmt->get_result()->fetch_assoc();
            $mustResetPassword = !empty($adminRow['force_password_reset']);

            session_regenerate_id(true);
            $_SESSION['admin_id'] = $pendingAdminId;
            $_SESSION['admin_name'] = $pendingName;
            unset(
                $_SESSION['admin_pending_otp_admin_id'],
                $_SESSION['admin_pending_otp_email'],
                $_SESSION['admin_pending_otp_name']
            );

            $del = $conn->prepare("DELETE FROM admin_login_otps WHERE admin_id = ?");
            $del->bind_param('i', $pendingAdminId);
            $del->execute();

            if ($mustResetPassword) {
                $_SESSION['must_reset_password'] = true;
                flash('error', 'Please set a new password to continue.');
                redirect('password-reset.php');
            }

            unset($_SESSION['must_reset_password']);
            flash('success', 'Welcome back, ' . $pendingName . '!');
            redirect('dashboard.php');
        }
    }
}

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
