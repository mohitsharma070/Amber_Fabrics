<?php
require_once __DIR__ . '/../includes/init.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
}
$metaTitle = 'Admin Login | Amber Fabrics';
$metaDescription = 'Admin login page for Amber Fabrics. Secure access to management tools.';
$metaKeywords = 'admin, login, secure, Amber Fabrics';

function login_attempt_state(mysqli $conn, string $attemptKey): array
{
    try {
        $stmt = $conn->prepare("SELECT attempts, blocked_until FROM admin_login_attempts WHERE attempt_key = ? LIMIT 1");
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $e) {
        error_log('[admin-auth] login attempt table unavailable: ' . $e->getMessage());
        return ['attempts' => 0, 'blocked_until_ts' => 0];
    }

    if (!$row) {
        return ['attempts' => 0, 'blocked_until_ts' => 0];
    }

    $blockedUntil = !empty($row['blocked_until']) ? strtotime($row['blocked_until']) : 0;

    return [
        'attempts' => (int) ($row['attempts'] ?? 0),
        'blocked_until_ts' => $blockedUntil ?: 0,
    ];
}

function update_login_attempt(mysqli $conn, string $attemptKey, int $attempts, int $blockedUntilTs): void
{
    $blockedUntil = $blockedUntilTs > 0 ? date('Y-m-d H:i:s', $blockedUntilTs) : null;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO admin_login_attempts (attempt_key, attempts, blocked_until, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), blocked_until = VALUES(blocked_until), updated_at = NOW()"
        );
        $stmt->bind_param('sis', $attemptKey, $attempts, $blockedUntil);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[admin-auth] login attempt write failed: ' . $e->getMessage());
    }
}

function clear_login_attempt(mysqli $conn, string $attemptKey): void
{
    try {
        $stmt = $conn->prepare("DELETE FROM admin_login_attempts WHERE attempt_key = ?");
        $stmt->bind_param('s', $attemptKey);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[admin-auth] login attempt clear failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect('login.php');
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    $attemptIdentity = $email !== '' ? strtolower($email) : 'anonymous';
    $attemptKey = hash('sha256', $attemptIdentity . '|' . $clientIp);
    $attempt = login_attempt_state($conn, $attemptKey);

    if ($attempt['blocked_until_ts'] > time()) {
        flash('error', 'Too many attempts. Try again in a few minutes.');
        redirect('login.php');
    }

    if ($email === '' || $password === '') {
        flash('error', 'Email and password are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address.');
    } else {
        $stmt = $conn->prepare("SELECT id, name, password_hash, force_password_reset FROM admins WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            clear_login_attempt($conn, $attemptKey);
            if (!empty($admin['force_password_reset'])) {
                $_SESSION['must_reset_password'] = true;
                flash('error', 'Please set a new password to continue.');
                redirect('password-reset.php');
            }
            unset($_SESSION['must_reset_password']);
            flash('success', 'Welcome back '.$admin['name'].'!');
            redirect('dashboard.php');
        } else {
            $newAttempts = $attempt['attempts'] + 1;
            $blockedUntilTs = $newAttempts >= 5 ? time() + 300 : 0;
            update_login_attempt($conn, $attemptKey, $newAttempts, $blockedUntilTs);
            flash('error', 'Invalid credentials.');
        }
    }
}
?>

<!DOCTYPE html>
<html>

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | Amber Fabrics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css?v=20260313a">
</head>

<body class="admin-shell">

<div class="container py-5 admin-login-wrap">

<div class="surface-panel">
    <div class="text-center mb-4">
        <h1 class="h3 mb-2">Admin Login</h1>
        <p class="text-muted mb-0">Access operations dashboard</p>
    </div>

        <?php if ($msg = flash('error')): ?>
            <div class="alert alert-danger"><?php echo e($msg); ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
        <?php echo csrf_field(); ?>

        <div class="col-12">
            <label class="form-label">Email</label>
            <input class="form-control" name="email" placeholder="Email" required value="<?php echo e($_POST['email'] ?? ''); ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" placeholder="Password" required>
        </div>
        <div class="col-12">
            <button name="login" class="btn btn-dark w-100">Login</button>
        </div>

        </form>
</div>

<p class="text-center text-muted mt-3">Use your configured admin credentials.</p>

</div>

<script src="../js/script.js?v=20260313a" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

