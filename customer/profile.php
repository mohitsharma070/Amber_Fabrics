<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

require_customer();

$customerId = (int) $_SESSION['customer_id'];
$errors = [];
$activeForm = '';
$addressEditId = (int) ($_GET['edit_address'] ?? 0);
$addressForm = [
    'id' => 0,
    'label' => '',
    'full_name' => '',
    'phone' => '',
    'address_line' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'country' => 'India',
    'is_default_shipping' => 0,
];

$custStmt = $conn->prepare("SELECT name, email, phone, country FROM customers WHERE id = ?");
$custStmt->bind_param('i', $customerId);
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();
$addressList = customer_addresses_list($conn, $customerId);
foreach ($addressList as $row) {
    if ((int) ($row['id'] ?? 0) === $addressEditId) {
        $addressForm = [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'address_line' => (string) ($row['address_line'] ?? ''),
            'city' => (string) ($row['city'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'pincode' => (string) ($row['pincode'] ?? ''),
            'country' => (string) ($row['country'] ?? 'India'),
            'is_default_shipping' => (int) ($row['is_default_shipping'] ?? 0),
        ];
        break;
    }
}

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
    } elseif ($action === 'save_address') {
        $activeForm = 'address';
        if (!customer_addresses_table_ready($conn)) {
            $errors['_address'] = 'Address book is not available right now.';
        } else {
            $addressId = (int) ($_POST['address_id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $addrPhone = trim((string) ($_POST['address_phone'] ?? ''));
            $addressLine = trim((string) ($_POST['address_line'] ?? ''));
            $addrCity = trim((string) ($_POST['address_city'] ?? ''));
            $addrState = trim((string) ($_POST['address_state'] ?? ''));
            $addrPincode = trim((string) ($_POST['address_pincode'] ?? ''));
            $addrCountry = trim((string) ($_POST['address_country'] ?? 'India'));
            $isDefault = isset($_POST['is_default_shipping']) ? 1 : 0;

            $addressForm = [
                'id' => $addressId,
                'label' => $label,
                'full_name' => $fullName,
                'phone' => $addrPhone,
                'address_line' => $addressLine,
                'city' => $addrCity,
                'state' => $addrState,
                'pincode' => $addrPincode,
                'country' => $addrCountry,
                'is_default_shipping' => $isDefault,
            ];

            if ($fullName === '') { $errors['full_name'] = 'Full name is required.'; }
            if ($addressLine === '') { $errors['address_line'] = 'Address is required.'; }
            if ($addrCity === '') { $errors['address_city'] = 'City is required.'; }
            if ($addrCountry === '') { $errors['address_country'] = 'Country is required.'; }

            if (empty($errors)) {
                try {
                    $conn->begin_transaction();
                    if ($isDefault === 1) {
                        $resetDefault = $conn->prepare("UPDATE customer_addresses SET is_default_shipping = 0 WHERE customer_id = ?");
                        $resetDefault->bind_param('i', $customerId);
                        $resetDefault->execute();
                    }

                    if ($addressId > 0) {
                        $check = $conn->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1");
                        $check->bind_param('ii', $addressId, $customerId);
                        $check->execute();
                        if (!$check->get_result()->fetch_assoc()) {
                            throw new RuntimeException('Address not found.');
                        }
                        $upd = $conn->prepare(
                            "UPDATE customer_addresses
                             SET label = ?, full_name = ?, phone = ?, address_line = ?, city = ?, state = ?, pincode = ?, country = ?, is_default_shipping = ?, updated_at = NOW()
                             WHERE id = ? AND customer_id = ?"
                        );
                        $upd->bind_param('ssssssssiii', $label, $fullName, $addrPhone, $addressLine, $addrCity, $addrState, $addrPincode, $addrCountry, $isDefault, $addressId, $customerId);
                        $upd->execute();
                    } else {
                        $cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM customer_addresses WHERE customer_id = ?");
                        $cntStmt->bind_param('i', $customerId);
                        $cntStmt->execute();
                        $total = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);
                        if ($total === 0) {
                            $isDefault = 1;
                        }
                        $ins = $conn->prepare(
                            "INSERT INTO customer_addresses (customer_id, label, full_name, phone, address_line, city, state, pincode, country, is_default_shipping)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $ins->bind_param('issssssssi', $customerId, $label, $fullName, $addrPhone, $addressLine, $addrCity, $addrState, $addrPincode, $addrCountry, $isDefault);
                        $ins->execute();
                    }
                    $conn->commit();
                    flash('success', 'Address saved.');
                    redirect('/customer/profile.php');
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    $errors['_address'] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to save address.';
                }
            }
        }
    } elseif ($action === 'delete_address') {
        if (customer_addresses_table_ready($conn)) {
            $addressId = (int) ($_POST['address_id'] ?? 0);
            if ($addressId > 0) {
                try {
                    $conn->begin_transaction();
                    $rowStmt = $conn->prepare("SELECT is_default_shipping FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1");
                    $rowStmt->bind_param('ii', $addressId, $customerId);
                    $rowStmt->execute();
                    $row = $rowStmt->get_result()->fetch_assoc();
                    if ($row) {
                        $isDefault = (int) ($row['is_default_shipping'] ?? 0) === 1;
                        $del = $conn->prepare("DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?");
                        $del->bind_param('ii', $addressId, $customerId);
                        $del->execute();
                        if ($isDefault) {
                            $pick = $conn->prepare("SELECT id FROM customer_addresses WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
                            $pick->bind_param('i', $customerId);
                            $pick->execute();
                            $next = $pick->get_result()->fetch_assoc();
                            if ($next) {
                                $newDefaultId = (int) ($next['id'] ?? 0);
                                if ($newDefaultId > 0) {
                                    $set = $conn->prepare("UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ? AND customer_id = ?");
                                    $set->bind_param('ii', $newDefaultId, $customerId);
                                    $set->execute();
                                }
                            }
                        }
                    }
                    $conn->commit();
                    flash('success', 'Address deleted.');
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    flash('error', 'Unable to delete address right now.');
                }
            }
        }
        redirect('/customer/profile.php');
    } elseif ($action === 'set_default_address') {
        if (customer_addresses_table_ready($conn)) {
            $addressId = (int) ($_POST['address_id'] ?? 0);
            if ($addressId > 0) {
                try {
                    $conn->begin_transaction();
                    $reset = $conn->prepare("UPDATE customer_addresses SET is_default_shipping = 0 WHERE customer_id = ?");
                    $reset->bind_param('i', $customerId);
                    $reset->execute();
                    $set = $conn->prepare("UPDATE customer_addresses SET is_default_shipping = 1 WHERE id = ? AND customer_id = ?");
                    $set->bind_param('ii', $addressId, $customerId);
                    $set->execute();
                    $conn->commit();
                    flash('success', 'Default address updated.');
                } catch (Throwable $e) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    flash('error', 'Unable to update default address.');
                }
            }
        }
        redirect('/customer/profile.php');
    }
}

// Refresh
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();
$addressList = customer_addresses_list($conn, $customerId);

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
                <div class="surface-panel p-4">
                    <h5 class="mb-3">Saved Addresses</h5>
                    <?php if (!empty($errors['_address']) && $activeForm === 'address'): ?>
                        <div class="alert alert-danger"><?php echo e((string) $errors['_address']); ?></div>
                    <?php endif; ?>

                    <?php if (empty($addressList)): ?>
                        <p class="text-muted small">No saved addresses yet.</p>
                    <?php else: ?>
                        <div class="d-grid gap-2 mb-3">
                            <?php foreach ($addressList as $addr): ?>
                                <div class="border rounded p-2 small">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <strong><?php echo e((string) ($addr['label'] !== '' ? $addr['label'] : 'Address')); ?></strong>
                                            <?php if ((int) ($addr['is_default_shipping'] ?? 0) === 1): ?>
                                                <span class="badge bg-success ms-1">Default</span>
                                            <?php endif; ?>
                                            <div class="text-muted"><?php echo e((string) ($addr['full_name'] ?? '')); ?><?php if (!empty($addr['phone'])): ?> | <?php echo e((string) $addr['phone']); ?><?php endif; ?></div>
                                            <div><?php echo e((string) ($addr['address_line'] ?? '')); ?></div>
                                            <div><?php echo e((string) ($addr['city'] ?? '')); ?><?php if (!empty($addr['state'])): ?>, <?php echo e((string) $addr['state']); ?><?php endif; ?><?php if (!empty($addr['pincode'])): ?> - <?php echo e((string) $addr['pincode']); ?><?php endif; ?></div>
                                            <div><?php echo e((string) ($addr['country'] ?? '')); ?></div>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 mt-2">
                                        <a href="/customer/profile.php?edit_address=<?php echo (int) $addr['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <?php if ((int) ($addr['is_default_shipping'] ?? 0) !== 1): ?>
                                            <form method="POST" action="/customer/profile.php" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="set_default_address">
                                                <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Make Default</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="/customer/profile.php" class="d-inline" onsubmit="return confirm('Delete this saved address?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_address">
                                            <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h6 class="mb-3"><?php echo ((int) ($addressForm['id'] ?? 0) > 0) ? 'Edit Address' : 'Add New Address'; ?></h6>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_address">
                        <input type="hidden" name="address_id" value="<?php echo (int) ($addressForm['id'] ?? 0); ?>">
                        <div class="mb-2">
                            <label class="form-label">Label</label>
                            <input type="text" name="label" class="form-control" placeholder="Home / Office" value="<?php echo e((string) ($addressForm['label'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'full_name'); ?>" value="<?php echo e((string) ($addressForm['full_name'] ?? '')); ?>">
                            <?php if ($activeForm === 'address') echo form_error($errors, 'full_name'); ?>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Phone</label>
                            <input type="text" name="address_phone" class="form-control" value="<?php echo e((string) ($addressForm['phone'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Address *</label>
                            <textarea name="address_line" rows="2" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_line'); ?>"><?php echo e((string) ($addressForm['address_line'] ?? '')); ?></textarea>
                            <?php if ($activeForm === 'address') echo form_error($errors, 'address_line'); ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label">City *</label>
                                <input type="text" name="address_city" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_city'); ?>" value="<?php echo e((string) ($addressForm['city'] ?? '')); ?>">
                                <?php if ($activeForm === 'address') echo form_error($errors, 'address_city'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">State</label>
                                <input type="text" name="address_state" class="form-control" value="<?php echo e((string) ($addressForm['state'] ?? '')); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Pincode</label>
                                <input type="text" name="address_pincode" class="form-control" value="<?php echo e((string) ($addressForm['pincode'] ?? '')); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Country *</label>
                                <input type="text" name="address_country" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_country'); ?>" value="<?php echo e((string) ($addressForm['country'] ?? 'India')); ?>">
                                <?php if ($activeForm === 'address') echo form_error($errors, 'address_country'); ?>
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" name="is_default_shipping" id="is_default_shipping" value="1" <?php echo ((int) ($addressForm['is_default_shipping'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_default_shipping">Set as default shipping address</label>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-outline-primary">Save Address</button>
                            <?php if ((int) ($addressForm['id'] ?? 0) > 0): ?>
                                <a href="/customer/profile.php" class="btn btn-outline-secondary">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
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
                    <a href="/customer/orders.php" class="app-back-link">&larr; Back to My Orders</a>
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

