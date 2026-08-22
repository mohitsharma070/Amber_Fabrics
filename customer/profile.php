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

<section class="page-hero"><div class="l-container"><h1>Account Settings</h1></div></section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-gap-4">
            <div class="l-col-md-half">
                <?php if ($errors && $activeForm === 'info'): ?>
                    <div class="ui-alert ui-alert--error"><?php echo e((string) ($errors['_profile'] ?? 'Please fix the errors below.')); ?></div>
                <?php endif; ?>

                <div class="surface-panel u-p-4 u-mb-4">
                    <h5 class="u-mb-3">Profile Information</h5>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_info">
                        <div class="u-mb-3">
                            <label class="ui-label">Full Name</label>
                            <input type="text" name="name" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'name', 'ui-input'); ?>" required value="<?php echo e($cust['name']); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'name', 'ui-field-error'); ?>
                        </div>
                        <div class="u-mb-3">
                            <label class="ui-label">Email <small class="u-text-muted">(read only)</small></label>
                            <input type="email" class="ui-input" value="<?php echo e($cust['email']); ?>" disabled>
                        </div>
                        <div class="u-mb-3">
                            <label class="ui-label">Phone</label>
                            <input type="tel" name="phone" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'phone', 'ui-input'); ?>" value="<?php echo e($cust['phone'] ?? ''); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'phone', 'ui-field-error'); ?>
                        </div>
                        <div class="u-mb-4">
                            <label class="ui-label">Country</label>
                            <input type="text" name="country" class="<?php echo form_class($activeForm === 'info' ? $errors : [], 'country', 'ui-input'); ?>" value="<?php echo e($cust['country'] ?? ''); ?>">
                            <?php if ($activeForm === 'info') echo form_error($errors, 'country', 'ui-field-error'); ?>
                        </div>
                        <button type="submit" class="ui-button ui-button--primary">Save Changes</button>
                    </form>
                </div>
                <div class="surface-panel u-p-4">
                    <h5 class="u-mb-3">Saved Addresses</h5>
                    <?php if (!empty($errors['_address']) && $activeForm === 'address'): ?>
                        <div class="ui-alert ui-alert--error"><?php echo e((string) $errors['_address']); ?></div>
                    <?php endif; ?>

                    <?php if (empty($addressList)): ?>
                        <p class="u-text-muted u-text-small">No saved addresses yet.</p>
                    <?php else: ?>
                        <div class="u-grid u-gap-2 u-mb-3">
                            <?php foreach ($addressList as $addr): ?>
                                <div class="u-border u-rounded u-p-2 u-text-small">
                                    <div class="u-flex u-justify-between u-items-start u-gap-2">
                                        <div>
                                            <strong><?php echo e((string) ($addr['label'] !== '' ? $addr['label'] : 'Address')); ?></strong>
                                            <?php if ((int) ($addr['is_default_shipping'] ?? 0) === 1): ?>
                                                <span class="ui-badge ui-badge--success u-ms-1">Default</span>
                                            <?php endif; ?>
                                            <div class="u-text-muted"><?php echo e((string) ($addr['full_name'] ?? '')); ?><?php if (!empty($addr['phone'])): ?> | <?php echo e((string) $addr['phone']); ?><?php endif; ?></div>
                                            <div><?php echo e((string) ($addr['address_line'] ?? '')); ?></div>
                                            <div><?php echo e((string) ($addr['city'] ?? '')); ?><?php if (!empty($addr['state'])): ?>, <?php echo e((string) $addr['state']); ?><?php endif; ?><?php if (!empty($addr['pincode'])): ?> - <?php echo e((string) $addr['pincode']); ?><?php endif; ?></div>
                                            <div><?php echo e((string) ($addr['country'] ?? '')); ?></div>
                                        </div>
                                    </div>
                                    <div class="u-flex u-gap-2 u-mt-2">
                                        <a href="/customer/profile?edit_address=<?php echo (int) $addr['id']; ?>" class="ui-button ui-button--small ui-button--outline">Edit</a>
                                        <?php if ((int) ($addr['is_default_shipping'] ?? 0) !== 1): ?>
                                            <form method="POST" action="/customer/profile.php" class="u-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="set_default_address">
                                                <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                                <button type="submit" class="ui-button ui-button--small ui-button--outline">Make Default</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="/customer/profile.php" class="u-inline" data-confirm="Delete this saved address?" data-confirm-title="Delete Saved Address?" data-confirm-ok="Delete Address" data-confirm-cancel="Keep Address" data-confirm-variant="danger">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_address">
                                            <input type="hidden" name="address_id" value="<?php echo (int) $addr['id']; ?>">
                                            <button type="submit" class="ui-button ui-button--small ui-button--danger-outline">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h6 class="u-mb-3"><?php echo ((int) ($addressForm['id'] ?? 0) > 0) ? 'Edit Address' : 'Add New Address'; ?></h6>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_address">
                        <input type="hidden" name="address_id" value="<?php echo (int) ($addressForm['id'] ?? 0); ?>">
                        <div class="u-mb-2">
                            <label class="ui-label">Label</label>
                            <input type="text" name="label" class="ui-input" placeholder="Home / Office" value="<?php echo e((string) ($addressForm['label'] ?? '')); ?>">
                        </div>
                        <div class="u-mb-2">
                            <label class="ui-label">Full Name *</label>
                            <input type="text" name="full_name" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'full_name', 'ui-input'); ?>" value="<?php echo e((string) ($addressForm['full_name'] ?? '')); ?>">
                            <?php if ($activeForm === 'address') echo form_error($errors, 'full_name', 'ui-field-error'); ?>
                        </div>
                        <div class="u-mb-2">
                            <label class="ui-label">Phone</label>
                            <input type="text" name="address_phone" class="ui-input" value="<?php echo e((string) ($addressForm['phone'] ?? '')); ?>">
                        </div>
                        <div class="u-mb-2">
                            <label class="ui-label">Address *</label>
                            <textarea name="address_line" rows="2" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_line', 'ui-input'); ?>"><?php echo e((string) ($addressForm['address_line'] ?? '')); ?></textarea>
                            <?php if ($activeForm === 'address') echo form_error($errors, 'address_line', 'ui-field-error'); ?>
                        </div>
                        <div class="l-grid l-grid--12 u-gap-2">
                            <div class="l-col-sm-half">
                                <label class="ui-label">City *</label>
                                <input type="text" name="address_city" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_city', 'ui-input'); ?>" value="<?php echo e((string) ($addressForm['city'] ?? '')); ?>">
                                <?php if ($activeForm === 'address') echo form_error($errors, 'address_city', 'ui-field-error'); ?>
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">State</label>
                                <input type="text" name="address_state" class="ui-input" value="<?php echo e((string) ($addressForm['state'] ?? '')); ?>">
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">Pincode</label>
                                <input type="text" name="address_pincode" class="ui-input" value="<?php echo e((string) ($addressForm['pincode'] ?? '')); ?>">
                            </div>
                            <div class="l-col-sm-half">
                                <label class="ui-label">Country *</label>
                                <input type="text" name="address_country" class="<?php echo form_class($activeForm === 'address' ? $errors : [], 'address_country', 'ui-input'); ?>" value="<?php echo e((string) ($addressForm['country'] ?? 'India')); ?>">
                                <?php if ($activeForm === 'address') echo form_error($errors, 'address_country', 'ui-field-error'); ?>
                            </div>
                        </div>
                        <div class="ui-check u-mt-3">
                            <input class="ui-check__input" type="checkbox" name="is_default_shipping" id="is_default_shipping" value="1" <?php echo ((int) ($addressForm['is_default_shipping'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            <label class="ui-check__label" for="is_default_shipping">Set as default shipping address</label>
                        </div>
                        <div class="u-flex u-gap-2 u-mt-3">
                            <button type="submit" class="ui-button ui-button--outline">Save Address</button>
                            <?php if ((int) ($addressForm['id'] ?? 0) > 0): ?>
                                <a href="/customer/profile" class="ui-button ui-button--secondary">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="l-col-md-half">
                <?php if ($errors && $activeForm === 'password'): ?>
                    <div class="ui-alert ui-alert--error">Please fix the errors below.</div>
                <?php endif; ?>
                <div class="surface-panel u-p-4">
                    <h5 class="u-mb-3">Change Password</h5>
                    <form method="POST" action="/customer/profile.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="u-mb-3">
                            <label class="ui-label">Current Password</label>
                            <input type="password" name="current_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'current_password', 'ui-input'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'current_password', 'ui-field-error'); ?>
                        </div>
                        <div class="u-mb-3">
                            <label class="ui-label">New Password <small class="u-text-muted">(min. 10 chars, upper/lowercase and number)</small></label>
                            <input type="password" name="new_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'new_password', 'ui-input'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'new_password', 'ui-field-error'); ?>
                        </div>
                        <div class="u-mb-4">
                            <label class="ui-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="<?php echo form_class($activeForm === 'password' ? $errors : [], 'confirm_password', 'ui-input'); ?>" required>
                            <?php if ($activeForm === 'password') echo form_error($errors, 'confirm_password', 'ui-field-error'); ?>
                        </div>
                        <button type="submit" class="ui-button ui-button--outline">Update Password</button>
                    </form>
                </div>

                <div class="u-mt-3 u-text-center">
                    <a href="/customer/orders" class="app-back-link">&larr; Back to My Orders</a>
                </div>
                <div class="u-mt-2 u-text-center">
                    <form method="POST" action="/customer/logout.php" class="u-inline" aria-label="Customer logout">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="ui-button ui-button--small ui-button--danger-outline">Log out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

