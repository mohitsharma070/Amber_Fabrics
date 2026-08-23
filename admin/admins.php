<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$roles = ['viewer', 'catalog_manager', 'operations_manager', 'super_admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('admins.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $adminId = (int) ($_POST['admin_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = strtolower(trim((string) ($_POST['role'] ?? 'viewer')));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    try {
        if ($name === '' || mb_strlen($name) > 255) {
            throw new RuntimeException('Enter an administrator name up to 255 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new RuntimeException('Enter a valid administrator email address.');
        }
        if (!in_array($role, $roles, true)) {
            throw new RuntimeException('Invalid administrator role.');
        }

        $conn->begin_transaction();
        if ($action === 'create') {
            $stmt = $conn->prepare('INSERT INTO admins (name, email, role, is_active) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('sssi', $name, $email, $role, $isActive);
            $stmt->execute();
            $targetId = (int) $conn->insert_id;
            log_admin_activity($conn, (int) $_SESSION['admin_id'], 'admin_created', 'admin', $targetId, 'Role: ' . $role, 'ok');
        } elseif ($action === 'update' && $adminId > 0) {
            $currentStmt = $conn->prepare('SELECT id, role, is_active FROM admins WHERE id = ? FOR UPDATE');
            $currentStmt->bind_param('i', $adminId);
            $currentStmt->execute();
            $current = $currentStmt->get_result()->fetch_assoc();
            if (!$current) {
                throw new RuntimeException('Administrator not found.');
            }

            $selfId = (int) ($_SESSION['admin_id'] ?? 0);
            if ($adminId === $selfId && $isActive !== 1) {
                throw new RuntimeException('You cannot deactivate your current administrator account.');
            }
            if ((string) $current['role'] === 'super_admin' && (int) $current['is_active'] === 1 && ($role !== 'super_admin' || $isActive !== 1)) {
                $count = (int) ($conn->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1")->fetch_row()[0] ?? 0);
                if ($count <= 1) {
                    throw new RuntimeException('At least one active super administrator is required.');
                }
            }

            $stmt = $conn->prepare('UPDATE admins SET name = ?, email = ?, role = ?, is_active = ? WHERE id = ?');
            $stmt->bind_param('sssii', $name, $email, $role, $isActive, $adminId);
            $stmt->execute();
            if ($isActive !== 1) {
                $otp = $conn->prepare('DELETE FROM admin_login_otps WHERE admin_id = ?');
                $otp->bind_param('i', $adminId);
                $otp->execute();
            }
            log_admin_activity($conn, (int) $_SESSION['admin_id'], 'admin_updated', 'admin', $adminId, 'Role: ' . $role . '; active: ' . $isActive, 'ok');
        } else {
            throw new RuntimeException('Invalid administrator action.');
        }
        $conn->commit();
        flash('success', 'Administrator saved.');
    } catch (mysqli_sql_exception $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $message = (int) $e->getCode() === 1062 ? 'That administrator email is already in use.' : 'Unable to save the administrator.';
        error_log('[admin-users] save failed: ' . $e->getMessage());
        flash('error', $message);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        flash('error', $e->getMessage() ?: 'Unable to save the administrator.');
    }
    redirect('admins.php');
}

$admins = $conn->query('SELECT id, name, email, role, is_active, last_login_at, last_login_ip, created_at FROM admins ORDER BY is_active DESC, name, id')->fetch_all(MYSQLI_ASSOC);
$metaTitle = 'Administrators | Admin';
include __DIR__ . '/partials/header.php';
?>
<div class="u-flex u-justify-between u-items-center u-mb-4"><div><h1 class="u-heading-4 u-mb-1">Administrators</h1><p class="u-text-muted u-mb-0">Manage separated-duty access. Login still requires the configured passphrase and emailed OTP.</p></div></div>
<div class="ui-card u-mb-4"><div class="ui-card__body"><h2 class="u-heading-5">Add administrator</h2>
<form method="POST" class="l-grid l-grid--12 u-gap-3"><?php echo csrf_field(); ?><input type="hidden" name="action" value="create">
<div class="l-col-md-third"><label class="ui-label">Name</label><input class="ui-input" name="name" maxlength="255" required></div>
<div class="l-col-md-third"><label class="ui-label">Email</label><input class="ui-input" type="email" name="email" maxlength="255" required></div>
<div class="l-col-md-quarter"><label class="ui-label">Role</label><select class="ui-select" name="role"><?php foreach ($roles as $option): ?><option value="<?php echo e($option); ?>"><?php echo e(ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></div>
<div class="l-col-md-one u-flex u-items-end"><label class="ui-check u-mb-2"><input class="ui-check__input" type="checkbox" name="is_active" checked> Active</label></div>
<div class="l-col-full"><button class="ui-button ui-button--primary">Add administrator</button></div></form></div></div>

<div class="ui-table-wrap"><table class="ui-table u-align-middle"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Action</th></tr></thead><tbody>
<?php foreach ($admins as $row): ?><?php $rowFormId = 'admin-update-' . (int) $row['id']; ?><tr>
<td><form id="<?php echo e($rowFormId); ?>" method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="admin_id" value="<?php echo (int) $row['id']; ?>"></form><input class="ui-input ui-input--small" name="name" form="<?php echo e($rowFormId); ?>" maxlength="255" value="<?php echo e((string) $row['name']); ?>" required></td>
<td><input class="ui-input ui-input--small" type="email" name="email" form="<?php echo e($rowFormId); ?>" maxlength="255" value="<?php echo e((string) $row['email']); ?>" required></td>
<td><select class="ui-select ui-select--small" name="role" form="<?php echo e($rowFormId); ?>"><?php foreach ($roles as $option): ?><option value="<?php echo e($option); ?>" <?php echo $option === (string) $row['role'] ? 'selected' : ''; ?>><?php echo e(ucwords(str_replace('_', ' ', $option))); ?></option><?php endforeach; ?></select></td>
<td><label class="ui-check"><input class="ui-check__input" type="checkbox" name="is_active" form="<?php echo e($rowFormId); ?>" <?php echo (int) $row['is_active'] === 1 ? 'checked' : ''; ?>> Active</label></td>
<td class="u-text-small"><?php echo e((string) ($row['last_login_at'] ?: 'Never')); ?><?php if (!empty($row['last_login_ip'])): ?><br><span class="u-text-muted"><?php echo e((string) $row['last_login_ip']); ?></span><?php endif; ?></td>
<td><button class="ui-button ui-button--small ui-button--outline" type="submit" form="<?php echo e($rowFormId); ?>">Save</button></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php include __DIR__ . '/partials/footer.php'; ?>
