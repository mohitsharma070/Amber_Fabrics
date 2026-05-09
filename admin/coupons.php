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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('coupons.php');
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $discountType = trim((string) ($_POST['discount_type'] ?? 'flat'));
        $discountValue = (float) ($_POST['discount_value'] ?? 0);
        $minOrderAmount = (float) ($_POST['min_order_amount'] ?? 0);
        $maxDiscountRaw = trim((string) ($_POST['max_discount'] ?? ''));
        $maxDiscount = $maxDiscountRaw === '' ? null : (float) $maxDiscountRaw;
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $usageLimit = max(0, (int) ($_POST['usage_limit'] ?? 0));
        $status = trim((string) ($_POST['status'] ?? 'active'));

        $couponOld = [
            'code'             => $code,
            'discount_type'    => $discountType,
            'discount_value'   => (string) ($_POST['discount_value'] ?? ''),
            'min_order_amount' => (string) ($_POST['min_order_amount'] ?? '0'),
            'max_discount'     => $maxDiscountRaw,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'usage_limit'      => (string) $usageLimit,
            'status'           => $status,
        ];

        if ($code === '') { $couponErrors['code'] = 'Coupon code is required.'; }
        if (!in_array($discountType, ['flat', 'percent'], true)) { $couponErrors['discount_type'] = 'Invalid discount type.'; }
        if ($discountValue <= 0) { $couponErrors['discount_value'] = 'Discount value must be greater than 0.'; }
        if ($discountType === 'percent' && $discountValue > 100) { $couponErrors['discount_value'] = 'Percent discount cannot exceed 100.'; }
        if (!in_array($status, ['active', 'inactive'], true)) { $couponErrors['status'] = 'Invalid status.'; }

        if (empty($couponErrors)) {
            $stmt = $conn->prepare(
                "INSERT INTO coupons (code, discount_type, discount_value, min_order_amount, max_discount, start_date, end_date, usage_limit, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'ssdddssis',
                $code,
                $discountType,
                $discountValue,
                $minOrderAmount,
                $maxDiscount,
                $startDate,
                $endDate,
                $usageLimit,
                $status
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

<div class="d-flex justify-content-between align-items-center mb-3">
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
            <table class="table table-striped align-middle">
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
                                    Rs <?php echo number_format((float) $coupon['discount_value'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td>Rs <?php echo number_format((float) $coupon['min_order_amount'], 2); ?></td>
                            <td><?php echo (int) $coupon['used_count']; ?> / <?php echo (int) $coupon['usage_limit'] === 0 ? 'Unlimited' : (int) $coupon['usage_limit']; ?></td>
                            <td>
                                <?php echo e($coupon['start_date'] ?: '-'); ?> to <?php echo e($coupon['end_date'] ?: '-'); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $coupon['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo ucfirst(e($coupon['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="coupons.php" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?php echo (int) $coupon['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $coupon['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><?php echo $coupon['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
