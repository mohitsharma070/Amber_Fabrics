<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

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
        $stmt = $conn->prepare("SELECT id, name, password_hash, email_verified FROM customers WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if ($customer && password_verify($password, $customer['password_hash'])) {
            if (!(int) $customer['email_verified']) {
                $errors['_login'] = 'Please verify your email address before logging in.';
                $errors['_login_raw'] = '<a href="/customer/resend-verification.php">Resend verification email &rsaquo;</a>';
            } else {
            customer_record_attempt($conn, $email, $ip, true);
            session_regenerate_id(true);
            $_SESSION['customer_id']   = $customer['id'];
            $_SESSION['customer_name'] = $customer['name'];

            // Merge any guest session cart with the customer's saved DB cart.
            // Quantity normalization must respect each product's unit type.
            $dbCartBundle = cart_load_from_db_bundle($conn, (int) $customer['id']);
            $dbCart = is_array($dbCartBundle['cart'] ?? null) ? $dbCartBundle['cart'] : [];
            $dbMeterMap = is_array($dbCartBundle['meter_map'] ?? null) ? $dbCartBundle['meter_map'] : [];
            $sessionCart = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
            $sessionMeterMap = isset($_SESSION['cart_meter_length']) && is_array($_SESSION['cart_meter_length'])
                ? $_SESSION['cart_meter_length']
                : [];
            $mergedIds = array_values(array_filter(array_unique(array_map(
                'intval',
                array_merge(array_keys($dbCart), array_keys($sessionCart))
            )), static fn($v) => $v > 0));
            $unitMap = [];
            if (!empty($mergedIds)) {
                $ph = implode(',', array_fill(0, count($mergedIds), '?'));
                $unitStmt = $conn->prepare("SELECT id, unit_type FROM fabrics WHERE id IN ($ph)");
                $unitStmt->bind_param(str_repeat('i', count($mergedIds)), ...$mergedIds);
                $unitStmt->execute();
                $unitRows = $unitStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                foreach ($unitRows as $ur) {
                    $uid = (int) ($ur['id'] ?? 0);
                    $unitMap[$uid] = in_array((string) ($ur['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                        ? (string) $ur['unit_type']
                        : 'meter';
                }
            }
            foreach ($sessionCart as $pid => $qty) {
                $productId = (int) $pid;
                if ($productId <= 0) {
                    continue;
                }
                $unitType = $unitMap[$productId] ?? 'meter';
                $currentQty = isset($dbCart[$productId]) ? normalize_quantity_by_unit($dbCart[$productId], $unitType) : 0;
                $incomingQty = normalize_quantity_by_unit($qty, $unitType);
                $dbCart[$productId] = ($unitType === 'meter')
                    ? round((float) $currentQty + (float) $incomingQty, 2)
                    : (int) $currentQty + (int) $incomingQty;
                if ($unitType === 'meter' && isset($sessionMeterMap[$productId]) && is_numeric($sessionMeterMap[$productId]) && (float) $sessionMeterMap[$productId] > 0) {
                    $dbMeterMap[$productId] = round((float) $sessionMeterMap[$productId], 2);
                }
            }

            // Cap merged quantities to current stock so we don't end up with
            // more units in cart than are actually available.
            if (!empty($dbCart)) {
                $mergedIds = array_values(array_filter(array_map('intval', array_keys($dbCart)), static fn($v) => $v > 0));
                if (!empty($mergedIds)) {
                    $ph   = implode(',', array_fill(0, count($mergedIds), '?'));
                    $stok = $conn->prepare("SELECT id, unit_type, stock, stock_meters FROM fabrics WHERE id IN ($ph)");
                    $stok->bind_param(str_repeat('i', count($mergedIds)), ...$mergedIds);
                    $stok->execute();
                    $stockRows = $stok->get_result()->fetch_all(MYSQLI_ASSOC);
                    foreach ($stockRows as $sr) {
                        $sid = (int) $sr['id'];
                        $unitType = in_array((string) ($sr['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
                            ? (string) $sr['unit_type']
                            : 'meter';
                        $avail = ($unitType === 'meter')
                            ? (float) ($sr['stock_meters'] ?? 0)
                            : (float) ($sr['stock'] ?? 0);
                        if ($avail > 0 && isset($dbCart[$sid]) && $dbCart[$sid] > $avail) {
                            $dbCart[$sid] = ($unitType === 'meter') ? round($avail, 2) : (int) floor($avail);
                        }
                    }
                }
            }

            $_SESSION['cart'] = $dbCart;
            $_SESSION['cart_meter_length'] = $dbMeterMap;
            if (!empty($dbCart)) {
                cart_save_to_db($conn, (int) $customer['id'], $dbCart, $dbMeterMap);
            }

            flash('success', 'Welcome back, ' . $customer['name'] . '!');
            redirect($returnTo ?: '/index.php');
            }
        } else {
            customer_record_attempt($conn, $email, $ip, false);
            $errors['_login'] = 'Invalid email or password.';
        }
    }
}

$metaTitle = 'Login | Amber Fabrics';
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
                    <div class="alert alert-danger">
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
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="<?php echo form_class($errors, 'email'); ?>" value="<?php echo e($oldEmail); ?>" required autofocus>
                            <?php echo form_error($errors, 'email'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                            <div class="mt-2 text-end">
                                <a href="/customer/forgot-password.php" class="small">Forgot password?</a>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Log In</button>
                    </form>
                </div>

                <p class="text-center mt-3 text-muted">
                    Don't have an account? <a href="/customer/register.php">Register</a>
                </p>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
