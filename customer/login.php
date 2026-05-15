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
        $stmt = $conn->prepare("SELECT id, name, password_hash, email_verified, is_active FROM customers WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if ($customer && password_verify($password, $customer['password_hash'])) {
            if (!(int) $customer['email_verified']) {
                $errors['_login'] = 'Please verify your email address before logging in.';
                $errors['_login_raw'] = '<a href="/customer/resend-verification.php">Resend verification email &rsaquo;</a>';
            } elseif (isset($customer['is_active']) && (int) $customer['is_active'] !== 1) {
                $errors['_login'] = 'Your account is inactive. Please contact support.';
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
            $cartParseKey = static function (string $rawKey): array {
                $parts = explode('::', $rawKey, 2);
                $pid = (int) ($parts[0] ?? 0);
                return [$pid, $parts[1] ?? ''];
            };
            $mergedIds = array_values(array_filter(array_unique(array_map(
                static function ($key) use ($cartParseKey) {
                    [$pid] = $cartParseKey((string) $key);
                    return $pid;
                },
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
            foreach ($sessionCart as $cartKey => $qty) {
                [$productId] = $cartParseKey((string) $cartKey);
                if ($productId <= 0) {
                    continue;
                }
                $unitType = $unitMap[$productId] ?? 'meter';
                $currentQty = isset($dbCart[$cartKey]) ? normalize_quantity_by_unit($dbCart[$cartKey], $unitType) : 0;
                $incomingQty = normalize_quantity_by_unit($qty, $unitType);
                $dbCart[$cartKey] = ($unitType === 'meter')
                    ? round((float) $currentQty + (float) $incomingQty, 2)
                    : (int) $currentQty + (int) $incomingQty;
                if ($unitType === 'meter' && isset($sessionMeterMap[$cartKey]) && is_numeric($sessionMeterMap[$cartKey]) && (float) $sessionMeterMap[$cartKey] > 0) {
                    $dbMeterMap[$cartKey] = round((float) $sessionMeterMap[$cartKey], 2);
                }
            }

            // Cap merged quantities to current stock so we don't end up with
            // more units in cart than are actually available.
            if (!empty($dbCart)) {
                $mergedIds = [];
                foreach (array_keys($dbCart) as $key) {
                    [$pid] = $cartParseKey((string) $key);
                    if ($pid > 0) {
                        $mergedIds[] = $pid;
                    }
                }
                $mergedIds = array_values(array_unique($mergedIds));
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
                        foreach ($dbCart as $key => $qVal) {
                            [$kPid] = $cartParseKey((string) $key);
                            if ($kPid !== $sid) {
                                continue;
                            }
                            if ($avail > 0 && $qVal > $avail) {
                                $dbCart[$key] = ($unitType === 'meter') ? round($avail, 2) : (int) floor($avail);
                            }
                        }
                    }
                }
            }

            $_SESSION['cart'] = $dbCart;
            $_SESSION['cart_meter_length'] = $dbMeterMap;
            if (!empty($dbCart)) {
                cart_save_to_db($conn, (int) $customer['id'], $dbCart, $dbMeterMap);
            }

            // Merge guest wishlist with persisted wishlist and keep it in DB.
            $dbWishlistBundle = wishlist_load_from_db_bundle($conn, (int) $customer['id']);
            $dbWishlist = is_array($dbWishlistBundle['wishlist'] ?? null) ? $dbWishlistBundle['wishlist'] : [];
            $dbWishlistSizeMap = is_array($dbWishlistBundle['size_map'] ?? null) ? $dbWishlistBundle['size_map'] : [];
            $dbWishlistMeterMap = is_array($dbWishlistBundle['meter_map'] ?? null) ? $dbWishlistBundle['meter_map'] : [];
            $sessionWishlist = isset($_SESSION['wishlist']) && is_array($_SESSION['wishlist']) ? $_SESSION['wishlist'] : [];
            $sessionWishlistSizeMap = isset($_SESSION['wishlist_size']) && is_array($_SESSION['wishlist_size'])
                ? $_SESSION['wishlist_size']
                : [];
            $sessionWishlistMeterMap = isset($_SESSION['wishlist_meter_length']) && is_array($_SESSION['wishlist_meter_length'])
                ? $_SESSION['wishlist_meter_length']
                : [];
            foreach ($sessionWishlist as $wishlistKey => $wishlistQty) {
                [$wishlistPid] = $cartParseKey((string) $wishlistKey);
                if ($wishlistPid <= 0) {
                    continue;
                }
                $existingQty = isset($dbWishlist[$wishlistKey]) ? normalize_meter_quantity($dbWishlist[$wishlistKey], 1.0) : 0.0;
                $incomingQty = normalize_meter_quantity($wishlistQty, 1.0);
                $dbWishlist[$wishlistKey] = max($existingQty, $incomingQty);
                if (isset($sessionWishlistSizeMap[$wishlistKey])) {
                    $dbWishlistSizeMap[$wishlistKey] = (string) $sessionWishlistSizeMap[$wishlistKey];
                }
                if (isset($sessionWishlistMeterMap[$wishlistKey]) && is_numeric($sessionWishlistMeterMap[$wishlistKey]) && (float) $sessionWishlistMeterMap[$wishlistKey] > 0) {
                    $dbWishlistMeterMap[$wishlistKey] = round((float) $sessionWishlistMeterMap[$wishlistKey], 2);
                }
            }
            $_SESSION['wishlist'] = $dbWishlist;
            $_SESSION['wishlist_size'] = $dbWishlistSizeMap;
            $_SESSION['wishlist_meter_length'] = $dbWishlistMeterMap;
            $_SESSION['wishlist_loaded_for'] = (int) $customer['id'];
            wishlist_save_to_db($conn, (int) $customer['id'], $dbWishlist, $dbWishlistMeterMap, $dbWishlistSizeMap);

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
