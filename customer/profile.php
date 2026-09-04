<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

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

$cust = CustomerAccountService::profile($conn, $customerId) ?: [];
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
        $profileValues = CustomerAccountService::profileValues($_POST);
        $cust = array_merge($cust, $profileValues);
        $errors = CustomerAccountService::validateProfile($profileValues);
        if (empty($errors)) {
            try {
                CustomerAccountService::updateProfile($conn, $customerId, $profileValues);
                $_SESSION['customer_name'] = (string) $profileValues['name'];
                flash('success', 'Profile updated.');
                redirect('/customer/profile.php');
            } catch (Throwable $e) {
                error_log('[customer-profile] Profile update failed: ' . $e->getMessage());
                $errors['_profile'] = 'Unable to update your profile right now.';
            }
        }
    } elseif ($action === 'change_password') {
        $activeForm = 'password';
        $current = $_POST['current_password']  ?? '';
        $newPass = $_POST['new_password']       ?? '';
        $confirm = $_POST['confirm_password']   ?? '';

        $result = CustomerAccountService::changePassword($conn, $customerId, $current, $newPass, $confirm);
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        if ($errors === []) {
            $_SESSION['customer_auth_version'] = (int) $result['auth_version'];
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
            redirect('/customer/profile.php');
        }
    } elseif ($action === 'save_address') {
        $activeForm = 'address';
        if (!customer_addresses_table_ready($conn)) {
            $errors['_address'] = 'Address book is not available right now.';
        } else {
            $addressForm = CustomerAddressService::formValues($_POST);
            $errors = array_merge($errors, CustomerAddressService::validate($addressForm));

            if (empty($errors)) {
                try {
                    CustomerAddressService::save($conn, $customerId, $addressForm);
                    flash('success', 'Address saved.');
                    redirect('/customer/profile.php');
                } catch (Throwable $e) {
                    app_log('error', 'customer_address_save_failed', ['exception_type' => get_class($e), 'customer_id' => $customerId]);
                    $errors['_address'] = 'Unable to save address right now.';
                }
            }
        }
    } elseif ($action === 'delete_address') {
        if (customer_addresses_table_ready($conn)) {
            $addressId = (int) ($_POST['address_id'] ?? 0);
            if ($addressId > 0) {
                try {
                    CustomerAddressService::delete($conn, $customerId, $addressId);
                    flash('success', 'Address deleted.');
                } catch (Throwable $e) {
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
                    CustomerAddressService::setDefault($conn, $customerId, $addressId);
                    flash('success', 'Default address updated.');
                } catch (Throwable $e) {
                    flash('error', 'Unable to update default address.');
                }
            }
        }
        redirect('/customer/profile.php');
    }
}

// Refresh
$cust = CustomerAccountService::profile($conn, $customerId) ?: [];
$addressList = customer_addresses_list($conn, $customerId);

$metaTitle = SiteContext::title('My Profile');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="container"><h1>Account Settings</h1></div></section>

<section class="section-block">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6">
                <?php if ($errors && $activeForm === 'info'): ?>
                    <div class="alert alert-danger"><?php echo e((string) ($errors['_profile'] ?? 'Please fix the errors below.')); ?></div>
                <?php endif; ?>

                <div class="surface-panel p-4 mb-4">
                    <h5 class="mb-3">Profile Information</h5>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_info">
                        <div class="mb-3">
                            <label class="form-label" for="profile_name">Full Name</label>
                            <input id="profile_name" type="text" name="name" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'name'); ?>" required value="<?php echo e($cust['name']); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'name'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="profile_email">Email <small class="text-muted">(read only)</small></label>
                            <input id="profile_email" type="email" class="form-control" value="<?php echo e($cust['email']); ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="profile_phone">Phone</label>
                            <input id="profile_phone" type="tel" name="phone" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'phone'); ?>" value="<?php echo e($cust['phone'] ?? ''); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'phone'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="profile_country">Country</label>
                            <input id="profile_country" type="text" name="country" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'country'); ?>" value="<?php echo e($cust['country'] ?? ''); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'country'); ?>
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
                                        <a href="/customer/profile?edit_address=<?php echo (int) $addr['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <?php if ((int) ($addr['is_default_shipping'] ?? 0) !== 1): ?>
                                            <form method="POST" action="/customer/profile.php" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="set_default_address">
                                                <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">Make Default</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="/customer/profile.php" class="d-inline" data-confirm="Delete this saved address?" data-confirm-title="Delete Saved Address?" data-confirm-ok="Delete Address" data-confirm-cancel="Keep Address" data-confirm-variant="danger">
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
                            <label class="form-label" for="address_label">Label</label>
                            <input id="address_label" type="text" name="label" class="form-control" placeholder="Home / Office" value="<?php echo e((string) ($addressForm['label'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="address_full_name">Full Name *</label>
                            <input id="address_full_name" type="text" name="full_name" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'full_name'); ?>" value="<?php echo e((string) ($addressForm['full_name'] ?? '')); ?>">
                            <?php if ($activeForm === 'address') echo form_error($errors, 'full_name'); ?>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="address_phone">Phone</label>
                            <input id="address_phone" type="text" name="address_phone" class="form-control" value="<?php echo e((string) ($addressForm['phone'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="address_line">Address *</label>
                            <textarea id="address_line" name="address_line" rows="2" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_line'); ?>"><?php echo e((string) ($addressForm['address_line'] ?? '')); ?></textarea>
                            <?php if ($activeForm === 'address') echo form_error($errors, 'address_line'); ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label" for="address_city">City *</label>
                                <input id="address_city" type="text" name="address_city" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_city'); ?>" value="<?php echo e((string) ($addressForm['city'] ?? '')); ?>">
                                <?php if ($activeForm === 'address') echo form_error($errors, 'address_city'); ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="address_state">State</label>
                                <input id="address_state" type="text" name="address_state" class="form-control" value="<?php echo e((string) ($addressForm['state'] ?? '')); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="address_pincode">Pincode</label>
                                <input id="address_pincode" type="text" name="address_pincode" class="form-control" value="<?php echo e((string) ($addressForm['pincode'] ?? '')); ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="address_country">Country *</label>
                                <input id="address_country" type="text" name="address_country" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_country'); ?>" value="<?php echo e((string) ($addressForm['country'] ?? 'India')); ?>">
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
                                <a href="/customer/profile" class="btn btn-outline-secondary">Cancel Edit</a>
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
                            <label class="form-label" for="current_password">Current Password</label>
                            <input id="current_password" type="password" name="current_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'current_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'current_password'); ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New Password <small class="text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                            <input id="new_password" type="password" name="new_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'new_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'new_password'); ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="confirm_new_password">Confirm New Password</label>
                            <input id="confirm_new_password" type="password" name="confirm_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'confirm_password'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'confirm_password'); ?>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Update Password</button>
                    </form>
                </div>

                <div class="mt-3 text-center">
                    <a href="/customer/orders" class="app-back-link">&larr; Back to My Orders</a>
                </div>
                <div class="mt-2 text-center">
                    <form method="POST" action="/customer/logout.php" class="d-inline" aria-label="Customer logout">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Log out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

