<?php
require_once __DIR__ . '/../../includes/coupon-functions.php';

add_action('order.after_create', 'cod_guard_after_order_create', 10);
add_action('admin.order_view.sidebar', 'cod_guard_render_admin_panel', 10);
add_filter('admin.order_action.handled', 'cod_guard_handle_admin_action', 10);
add_action('cron.tick', 'cod_guard_auto_cancel_expired', 10);

function cod_guard_settings(): array
{
    return [
        'whatsapp_threshold' => (float) plugin_setting('cod-guard', 'whatsapp_threshold', 999),
        'call_threshold' => (float) plugin_setting('cod-guard', 'call_threshold', 2000),
        'confirmation_hours' => max(1, (int) plugin_setting('cod-guard', 'confirmation_hours', 24)),
    ];
}

function cod_guard_table_ready(mysqli $conn): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cod_confirmations'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return ((int) ($row['total'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[cod-guard] table check failed: ' . $e->getMessage());
        return false;
    }
}

function cod_guard_plan_for_amount(float $amount): array
{
    $settings = cod_guard_settings();
    if ($amount > $settings['call_threshold']) {
        return ['channel' => 'call', 'status' => 'pending', 'action' => 'call_confirmation'];
    }
    if ($amount > $settings['whatsapp_threshold']) {
        return ['channel' => 'whatsapp', 'status' => 'pending', 'action' => 'whatsapp_confirmation'];
    }
    return ['channel' => 'auto', 'status' => 'confirmed', 'action' => 'auto_confirmed'];
}

function cod_guard_after_order_create(array $context): void
{
    $conn = $context['conn'] ?? null;
    if (!$conn instanceof mysqli || !cod_guard_table_ready($conn)) {
        return;
    }

    $paymentMethod = strtolower((string) ($context['payment_method'] ?? ''));
    if ($paymentMethod !== 'cod') {
        return;
    }

    $orderId = (int) ($context['order_id'] ?? 0);
    $totalAmount = (float) ($context['total_amount'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $plan = cod_guard_plan_for_amount($totalAmount);
    $settings = cod_guard_settings();
    $deadline = date('Y-m-d H:i:s', time() + ($settings['confirmation_hours'] * 3600));

    $stmt = $conn->prepare(
        "INSERT INTO cod_confirmations
            (order_id, channel, status, deadline_at, notes, confirmed_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            channel = VALUES(channel),
            status = VALUES(status),
            deadline_at = VALUES(deadline_at),
            notes = VALUES(notes),
            confirmed_at = VALUES(confirmed_at),
            updated_at = NOW()"
    );
    $notes = $plan['channel'] === 'auto'
        ? 'Auto-confirmed because COD order amount is within low-risk threshold.'
        : 'Awaiting ' . $plan['channel'] . ' confirmation before dispatch.';
    $confirmedAt = $plan['status'] === 'confirmed' ? date('Y-m-d H:i:s') : null;
    $stmt->bind_param('isssss', $orderId, $plan['channel'], $plan['status'], $deadline, $notes, $confirmedAt);
    $stmt->execute();

    if ($plan['status'] === 'confirmed') {
        $upd = $conn->prepare("UPDATE orders SET order_status = 'confirmed', status = 'confirmed', updated_at = NOW() WHERE id = ? AND order_status = 'pending'");
        $upd->bind_param('i', $orderId);
        $upd->execute();
    }

    if (function_exists('log_order_activity')) {
        log_order_activity($conn, $orderId, 'cod_guard_' . $plan['action'], 'system', 0, 'cod-guard', $notes);
    }
}

function cod_guard_get_confirmation(mysqli $conn, int $orderId): ?array
{
    if ($orderId <= 0 || !cod_guard_table_ready($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM cod_confirmations WHERE order_id = ? LIMIT 1");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function cod_guard_label(array $row): string
{
    $channel = ucfirst((string) ($row['channel'] ?? ''));
    $status = ucfirst(str_replace('_', ' ', (string) ($row['status'] ?? '')));
    return trim($channel . ' / ' . $status, ' /');
}

function cod_guard_render_admin_panel(array $context): void
{
    $conn = $context['conn'] ?? null;
    $order = $context['order'] ?? [];
    if (!$conn instanceof mysqli || strtolower((string) ($order['payment_method'] ?? '')) !== 'cod') {
        return;
    }

    $orderId = (int) ($order['id'] ?? 0);
    $row = cod_guard_get_confirmation($conn, $orderId);
    if (!$row) {
        return;
    }

    $status = strtolower((string) ($row['status'] ?? ''));
    $channel = strtolower((string) ($row['channel'] ?? ''));
    ?>
    <div class="card mb-4 border-warning">
        <div class="card-body">
            <h6 class="card-title">COD Guard</h6>
            <div class="small text-muted mb-2">
                <div>Status: <strong><?php echo e(cod_guard_label($row)); ?></strong></div>
                <div>Deadline: <strong><?php echo e((string) ($row['deadline_at'] ?? '-')); ?></strong></div>
                <div>Attempts: <strong><?php echo (int) ($row['attempts'] ?? 0); ?></strong></div>
            </div>

            <?php if ($status === 'pending' && in_array($channel, ['whatsapp', 'call'], true)): ?>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="d-grid gap-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_mark_confirmed">
                    <button class="btn btn-sm btn-success" type="submit">Mark COD Confirmed</button>
                </form>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="d-grid gap-2 mt-2" onsubmit="return confirm('Cancel this unconfirmed COD order?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_mark_cancelled">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel COD Order</button>
                </form>
                <form method="POST" action="order-view.php?id=<?php echo $orderId; ?>" class="mt-2">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cod_guard_log_attempt">
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Log Attempt</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function cod_guard_release_coupon(mysqli $conn, int $orderId): void
{
    release_coupon_usage_for_order($conn, $orderId);
}

function cod_guard_cancel_order(mysqli $conn, int $orderId, string $reason, string $actorType = 'system', int $actorId = 0, string $actorName = 'cod-guard', string $confirmationStatus = 'cancelled'): void
{
    $confirmationStatus = $confirmationStatus === 'auto_cancelled' ? 'auto_cancelled' : 'cancelled';
    $upd = $conn->prepare(
        "UPDATE orders
         SET order_status = 'cancelled',
             status = 'cancelled',
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE id = ? AND payment_method = 'cod' AND order_status = 'pending'"
    );
    $upd->bind_param('ssi', $reason, $reason, $orderId);
    $upd->execute();
    if ((int) $upd->affected_rows <= 0) {
        return;
    }

    restore_order_inventory($conn, $orderId);
    cod_guard_release_coupon($conn, $orderId);

    $cod = $conn->prepare(
        "UPDATE cod_confirmations
         SET status = ?,
             cancelled_at = NOW(),
             notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE CONCAT(notes, '\n', ?) END,
             updated_at = NOW()
         WHERE order_id = ?"
    );
    $cod->bind_param('sssi', $confirmationStatus, $reason, $reason, $orderId);
    $cod->execute();

    log_order_activity($conn, $orderId, 'cod_guard_cancelled', $actorType, $actorId, $actorName, $reason);
}

function cod_guard_handle_admin_action($handled, array $context)
{
    if ($handled) {
        return true;
    }
    $conn = $context['conn'] ?? null;
    $action = (string) ($context['action'] ?? '');
    $orderId = (int) ($context['order_id'] ?? 0);
    if (!$conn instanceof mysqli || $orderId <= 0 || !in_array($action, ['cod_guard_mark_confirmed', 'cod_guard_mark_cancelled', 'cod_guard_log_attempt'], true)) {
        return false;
    }

    try {
        $conn->begin_transaction();
        $row = cod_guard_get_confirmation($conn, $orderId);
        if (!$row || strtolower((string) ($row['status'] ?? '')) !== 'pending') {
            throw new RuntimeException('COD confirmation is not pending.');
        }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $adminName = (string) ($_SESSION['admin_name'] ?? 'admin');

        if ($action === 'cod_guard_mark_confirmed') {
            $upd = $conn->prepare("UPDATE orders SET order_status = 'confirmed', status = 'confirmed', updated_at = NOW() WHERE id = ? AND payment_method = 'cod' AND order_status = 'pending'");
            $upd->bind_param('i', $orderId);
            $upd->execute();

            $cod = $conn->prepare("UPDATE cod_confirmations SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW() WHERE order_id = ?");
            $cod->bind_param('i', $orderId);
            $cod->execute();
            log_order_activity($conn, $orderId, 'cod_guard_confirmed', 'admin', $adminId, $adminName, 'COD order manually confirmed.');
            flash('success', 'COD order confirmed.');
        } elseif ($action === 'cod_guard_mark_cancelled') {
            cod_guard_cancel_order($conn, $orderId, 'COD order cancelled after failed confirmation.', 'admin', $adminId, $adminName);
            flash('success', 'COD order cancelled and stock restored.');
        } else {
            $upd = $conn->prepare("UPDATE cod_confirmations SET attempts = attempts + 1, updated_at = NOW() WHERE order_id = ?");
            $upd->bind_param('i', $orderId);
            $upd->execute();
            log_order_activity($conn, $orderId, 'cod_guard_attempt_logged', 'admin', $adminId, $adminName, 'Confirmation attempt logged.');
            flash('success', 'COD confirmation attempt logged.');
        }

        $conn->commit();
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackException) {
        }
        flash('error', $e->getMessage() !== '' ? $e->getMessage() : 'Unable to update COD confirmation.');
    }

    return true;
}

function cod_guard_auto_cancel_expired(array $context): void
{
    $conn = $context['conn'] ?? ($GLOBALS['conn'] ?? null);
    if (!$conn instanceof mysqli || !cod_guard_table_ready($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        "SELECT cc.order_id
         FROM cod_confirmations cc
         JOIN orders o ON o.id = cc.order_id
         WHERE cc.status = 'pending'
           AND cc.deadline_at IS NOT NULL
           AND cc.deadline_at < NOW()
           AND o.payment_method = 'cod'
           AND o.order_status = 'pending'
         LIMIT 50"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        try {
            $conn->begin_transaction();
            cod_guard_cancel_order($conn, $orderId, 'Auto-cancelled because COD confirmation deadline expired.', 'system', 0, 'cod-guard', 'auto_cancelled');
            $conn->commit();
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackException) {
            }
            error_log('[cod-guard] auto cancel failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }
}
