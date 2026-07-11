<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$couponErrors = [];
$couponOld = [
    'code'             => '',
    'discount_type'    => 'flat',
    'discount_value'   => '',
    'min_order_amount' => '0',
    'max_discount'     => '',
    'start_date'       => '',
    'end_date'         => '',
    'usage_limit'      => '0',
    'status'           => 'active',
];

/**
 * Validate and normalize coupon form payload.
 */
function coupon_form_payload(array $src): array
{
    $code = strtoupper(trim((string) ($src['code'] ?? '')));
    $discountType = trim((string) ($src['discount_type'] ?? 'flat'));
    $discountValue = (float) ($src['discount_value'] ?? 0);
    $minOrderAmount = (float) ($src['min_order_amount'] ?? 0);
    $maxDiscountRaw = trim((string) ($src['max_discount'] ?? ''));
    $maxDiscount = $maxDiscountRaw === '' ? null : (float) $maxDiscountRaw;
    $startDateRaw = trim((string) ($src['start_date'] ?? ''));
    $endDateRaw = trim((string) ($src['end_date'] ?? ''));
    $usageLimit = max(0, (int) ($src['usage_limit'] ?? 0));
    $status = trim((string) ($src['status'] ?? 'active'));

    $startDate = $startDateRaw === '' ? null : $startDateRaw;
    $endDate = $endDateRaw === '' ? null : $endDateRaw;

    $errors = [];
    if ($code === '') { $errors['code'] = 'Coupon code is required.'; }
    if (!in_array($discountType, ['flat', 'percent'], true)) { $errors['discount_type'] = 'Invalid discount type.'; }
    if ($discountValue <= 0) { $errors['discount_value'] = 'Discount value must be greater than 0.'; }
    if ($discountType === 'percent' && $discountValue > 100) { $errors['discount_value'] = 'Percent discount cannot exceed 100.'; }
    if (!in_array($status, ['active', 'inactive'], true)) { $errors['status'] = 'Invalid status.'; }
    if ($startDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) { $errors['start_date'] = 'Invalid start date.'; }
    if ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) { $errors['end_date'] = 'Invalid end date.'; }
    if ($startDate !== null && $endDate !== null && $endDate < $startDate) { $errors['end_date'] = 'End date must be after start date.'; }

    return [
        'data' => [
            'code' => $code,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'min_order_amount' => $minOrderAmount,
            'max_discount' => $maxDiscount,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'usage_limit' => $usageLimit,
            'status' => $status,
        ],
        'errors' => $errors,
        'old' => [
            'code' => $code,
            'discount_type' => $discountType,
            'discount_value' => (string) ($src['discount_value'] ?? ''),
            'min_order_amount' => (string) ($src['min_order_amount'] ?? '0'),
            'max_discount' => $maxDiscountRaw,
            'start_date' => $startDateRaw,
            'end_date' => $endDateRaw,
            'usage_limit' => (string) $usageLimit,
            'status' => $status,
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('coupons.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $parsed = coupon_form_payload($_POST);
        $couponErrors = $parsed['errors'];
        $couponOld = $parsed['old'];
        $data = $parsed['data'];

        if (empty($couponErrors)) {
            $stmt = $conn->prepare(
                "INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, max_discount, start_date, end_date, usage_limit, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssdddssis',
                $data['code'],
                $data['discount_type'],
                $data['discount_value'],
                $data['min_order_amount'],
                $data['max_discount'],
                $data['start_date'],
                $data['end_date'],
                $data['usage_limit'],
                $data['status']
            );

            try {
                $stmt->execute();
                flash('success', 'Coupon created successfully.');
            } catch (Throwable $e) {
                flash('error', 'Could not create coupon. Code may already exist.');
            }
            redirect('coupons.php');
        }
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $parsed = coupon_form_payload($_POST);
        $couponErrors = $parsed['errors'];
        $couponOld = $parsed['old'];
        $data = $parsed['data'];
        if ($id <= 0) {
            $couponErrors['code'] = 'Invalid coupon.';
        }

        if (empty($couponErrors)) {
            $stmt = $conn->prepare(
                "UPDATE coupons
                 SET code = ?, discount_type = ?, discount_value = ?, min_order_amount = ?, max_discount = ?, start_date = ?, end_date = ?, usage_limit = ?, status = ?
                 WHERE id = ?"
            );
            $stmt->bind_param(
                'ssdddssisi',
                $data['code'],
                $data['discount_type'],
                $data['discount_value'],
                $data['min_order_amount'],
                $data['max_discount'],
                $data['start_date'],
                $data['end_date'],
                $data['usage_limit'],
                $data['status'],
                $id
            );
            try {
                $stmt->execute();
                flash('success', 'Coupon updated successfully.');
            } catch (Throwable $e) {
                flash('error', 'Could not update coupon. Code may already exist.');
            }
            redirect('coupons.php');
        }
    }

    if ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? 'inactive'));
        if ($id > 0 && in_array($newStatus, ['active', 'inactive'], true)) {
            $stmt = $conn->prepare("UPDATE coupons SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $id);
            $stmt->execute();
            flash('success', 'Coupon status updated.');
        }
        redirect('coupons.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM coupons WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            flash('success', 'Coupon deleted.');
        }
        redirect('coupons.php');
    }
}

$coupons = $conn->query(
    "SELECT id, code, discount_type, discount_value, min_order_amount, max_discount,
            start_date, end_date, usage_limit, used_count, status, created_at
     FROM coupons
     ORDER BY created_at DESC"
)->fetch_all(MYSQLI_ASSOC);

$metaTitle = 'Coupons | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Coupons</h1>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="surface-panel p-3">
            <h5 class="mb-3">Create Coupon</h5>
            <?php if (!empty($couponErrors)): ?>
                <div class="alert alert-warning py-2 small">Please fix the errors below.</div>
            <?php endif; ?>
            <form method="POST" action="coupons.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <div class="mb-2">
                    <label class="form-label">Code</label>
                    <input class="<?php echo form_class($couponErrors, 'code'); ?>" name="code" required placeholder="SAVE100" value="<?php echo e($couponOld['code']); ?>">
                    <?php echo form_error($couponErrors, 'code'); ?>
                </div>
                <div class="mb-2">
                    <label class="form-label">Discount Type</label>
                    <select class="<?php echo form_class($couponErrors, 'discount_type', 'form-select'); ?>" name="discount_type">
                        <option value="flat" <?php echo $couponOld['discount_type'] === 'flat' ? 'selected' : ''; ?>>Flat</option>
                        <option value="percent" <?php echo $couponOld['discount_type'] === 'percent' ? 'selected' : ''; ?>>Percent</option>
                    </select>
                    <?php echo form_error($couponErrors, 'discount_type'); ?>
                </div>
                <div class="mb-2">
                    <label class="form-label">Discount Value</label>
                    <input class="<?php echo form_class($couponErrors, 'discount_value'); ?>" type="number" step="0.01" min="0" name="discount_value" required value="<?php echo e($couponOld['discount_value']); ?>">
                    <?php echo form_error($couponErrors, 'discount_value'); ?>
                </div>
                <div class="mb-2">
                    <label class="form-label">Min Order Amount</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="min_order_amount" value="<?php echo e($couponOld['min_order_amount']); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label">Max Discount</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="max_discount" placeholder="Optional" value="<?php echo e($couponOld['max_discount']); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label">Start Date</label>
                    <input class="form-control" type="date" name="start_date" value="<?php echo e($couponOld['start_date']); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label">End Date</label>
                    <input class="form-control" type="date" name="end_date" value="<?php echo e($couponOld['end_date']); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label">Usage Limit (0 = unlimited)</label>
                    <input class="form-control" type="number" min="0" name="usage_limit" value="<?php echo e($couponOld['usage_limit']); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select class="<?php echo form_class($couponErrors, 'status', 'form-select'); ?>" name="status">
                        <option value="active" <?php echo $couponOld['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $couponOld['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <?php echo form_error($couponErrors, 'status'); ?>
                </div>
                <button class="btn btn-primary w-100" type="submit">Create Coupon</button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="table-responsive">
            <table class="table table-striped align-middle admin-card-table">
                <thead class="table-dark">
                    <tr>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Value</th>
                        <th>Min Order</th>
                        <th>Usage</th>
                        <th>Date Window</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($coupons)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No coupons created yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($coupons as $coupon): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($coupon['code']); ?></td>
                            <td><?php echo ucfirst(e($coupon['discount_type'])); ?></td>
                            <td>
                                <?php if ($coupon['discount_type'] === 'percent'): ?>
                                    <?php echo number_format((float) $coupon['discount_value'], 2); ?>%
                                <?php else: ?>
                                    <?php echo e(money((float) $coupon['discount_value'])); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e(money((float) $coupon['min_order_amount'])); ?></td>
                            <td><?php echo (int) $coupon['used_count']; ?> / <?php echo (int) $coupon['usage_limit'] === 0 ? 'Unlimited' : (int) $coupon['usage_limit']; ?></td>
                            <td>
                                <?php echo e($coupon['start_date'] ?: '-'); ?> to <?php echo e($coupon['end_date'] ?: '-'); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $coupon['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo ucfirst(e($coupon['status'])); ?>
                                </span>
                            </td>
                            <td class="text-nowrap admin-row-actions" data-label="Action">
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                <form method="POST" action="coupons.php" class="m-0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?php echo (int) $coupon['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $coupon['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><?php echo $coupon['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                                <button class="btn btn-sm btn-outline-secondary"
                                        type="button"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editCouponModal"
                                        data-id="<?php echo (int) $coupon['id']; ?>"
                                        data-code="<?php echo e($coupon['code']); ?>"
                                        data-discount-type="<?php echo e($coupon['discount_type']); ?>"
                                        data-discount-value="<?php echo e((string) $coupon['discount_value']); ?>"
                                        data-min-order-amount="<?php echo e((string) $coupon['min_order_amount']); ?>"
                                        data-max-discount="<?php echo e((string) ($coupon['max_discount'] ?? '')); ?>"
                                        data-start-date="<?php echo e((string) ($coupon['start_date'] ?? '')); ?>"
                                        data-end-date="<?php echo e((string) ($coupon['end_date'] ?? '')); ?>"
                                        data-usage-limit="<?php echo e((string) $coupon['usage_limit']); ?>"
                                        data-status="<?php echo e($coupon['status']); ?>">
                                    Edit
                                </button>
                                <form method="POST" action="coupons.php" class="m-0" data-confirm-modal data-confirm-title="Delete Coupon" data-confirm-message="Delete this coupon?" data-confirm-ok="Delete">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $coupon['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="editCouponModal" tabindex="-1" aria-labelledby="editCouponModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCouponModalLabel">Edit Coupon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="coupons.php">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editCouponId" value="">

                    <div class="mb-2">
                        <label class="form-label">Code</label>
                        <input class="form-control" name="code" id="editCouponCode" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Discount Type</label>
                        <select class="form-select" name="discount_type" id="editCouponDiscountType">
                            <option value="flat">Flat</option>
                            <option value="percent">Percent</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Discount Value</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="discount_value" id="editCouponDiscountValue" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Min Order Amount</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="min_order_amount" id="editCouponMinOrderAmount">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Max Discount</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="max_discount" id="editCouponMaxDiscount">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Start Date</label>
                        <input class="form-control" type="date" name="start_date" id="editCouponStartDate">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">End Date</label>
                        <input class="form-control" type="date" name="end_date" id="editCouponEndDate">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Usage Limit (0 = unlimited)</label>
                        <input class="form-control" type="number" min="0" name="usage_limit" id="editCouponUsageLimit">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="editCouponStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo e($cspNonce ?? ''); ?>">
(function () {
    function prefillCouponFromButton(btn) {
        if (!btn) return;
        document.getElementById('editCouponId').value = btn.getAttribute('data-id') || '';
        document.getElementById('editCouponCode').value = btn.getAttribute('data-code') || '';
        document.getElementById('editCouponDiscountType').value = btn.getAttribute('data-discount-type') || 'flat';
        document.getElementById('editCouponDiscountValue').value = btn.getAttribute('data-discount-value') || '';
        document.getElementById('editCouponMinOrderAmount').value = btn.getAttribute('data-min-order-amount') || '0';
        document.getElementById('editCouponMaxDiscount').value = btn.getAttribute('data-max-discount') || '';
        document.getElementById('editCouponStartDate').value = btn.getAttribute('data-start-date') || '';
        document.getElementById('editCouponEndDate').value = btn.getAttribute('data-end-date') || '';
        document.getElementById('editCouponUsageLimit').value = btn.getAttribute('data-usage-limit') || '0';
        document.getElementById('editCouponStatus').value = btn.getAttribute('data-status') || 'active';
    }

    var modalEl = document.getElementById('editCouponModal');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        prefillCouponFromButton(btn);
    });
})();
</script>

<?php include 'partials/footer.php'; ?>
