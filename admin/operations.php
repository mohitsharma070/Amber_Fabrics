<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$role = (string) ($_SESSION['admin_role'] ?? 'viewer');
$allowedTabs = ['cron'];
if (admin_can('operations.manage', $role)) $allowedTabs = array_merge($allowedTabs, ['payments', 'refunds']);
if (admin_can('catalog.manage', $role) || admin_can('operations.manage', $role)) $allowedTabs[] = 'stock';
if (admin_can('admins.manage', $role)) $allowedTabs[] = 'audit';
$tab = strtolower(trim((string) ($_GET['tab'] ?? 'cron')));
if (!in_array($tab, $allowedTabs, true)) $tab = 'cron';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$statusFilter = substr(trim((string) ($_GET['status'] ?? '')), 0, 40);
$searchFilter = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$fromFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$toFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';
$rows = [];
$total = 0;
$columns = [];

function operations_execute(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $params[$key] = $value;
            $refs[$key] = &$params[$key];
        }
        $stmt->bind_param($types, ...$refs);
    }
    $stmt->execute();
    return $stmt;
}

$dateColumn = match ($tab) {
    'cron' => 'started_at',
    'payments' => 'pa.last_seen_at',
    'refunds' => 'rl.created_at',
    'stock' => 'sl.created_at',
    'audit' => 'aal.created_at',
    default => 'created_at',
};
$conditions = [];
$types = '';
$params = [];
if ($statusFilter !== '') {
    $conditions[] = match ($tab) {
        'payments' => 'pa.status = ?', 'refunds' => 'rl.status = ?',
        'stock' => 'sl.movement = ?', 'audit' => 'aal.status = ?', default => 'status = ?',
    };
    $types .= 's'; $params[] = $statusFilter;
}
if ($fromFilter !== '') { $conditions[] = $dateColumn . ' >= ?'; $types .= 's'; $params[] = $fromFilter . ' 00:00:00'; }
if ($toFilter !== '') { $conditions[] = $dateColumn . ' < DATE_ADD(?, INTERVAL 1 DAY)'; $types .= 's'; $params[] = $toFilter; }
if ($searchFilter !== '') {
    $conditions[] = match ($tab) {
        'payments' => '(o.order_number LIKE ? OR pa.provider LIKE ? OR pa.attempt_ref LIKE ?)',
        'refunds' => '(o.order_number LIKE ? OR rl.gateway LIKE ? OR rl.gateway_refund_id LIKE ?)',
        'stock' => '(f.name LIKE ? OR fv.sku LIKE ? OR o.order_number LIKE ?)',
        'audit' => '(a.name LIKE ? OR a.email LIKE ? OR aal.action LIKE ?)',
        default => '(summary_json LIKE ? OR status LIKE ? OR CAST(id AS CHAR) LIKE ?)',
    };
    $like = '%' . $searchFilter . '%';
    $types .= 'sss'; array_push($params, $like, $like, $like);
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

try {
    if ($tab === 'cron') {
        $count = operations_execute($conn, 'SELECT COUNT(*) FROM cron_run_history' . $where, $types, $params);
        $total = (int) ($count->get_result()->fetch_row()[0] ?? 0);
        $sql = 'SELECT id, started_at, finished_at, duration_ms, status, jobs_total, jobs_failed, jobs_degraded, critical_jobs_failed, summary_json FROM cron_run_history'
            . $where . ' ORDER BY started_at DESC LIMIT ? OFFSET ?';
        $stmt = operations_execute($conn, $sql, $types . 'ii', array_merge($params, [$perPage, $offset]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns = ['Run', 'Status', 'Jobs', 'Duration', 'Failure details'];
    } elseif ($tab === 'payments') {
        $count = operations_execute($conn, 'SELECT COUNT(*) FROM payment_attempts pa LEFT JOIN orders o ON o.id=pa.order_id' . $where, $types, $params);
        $total = (int) ($count->get_result()->fetch_row()[0] ?? 0);
        $stmt = operations_execute($conn, 'SELECT pa.id, pa.provider, pa.attempt_ref, pa.status, pa.source, pa.gateway_payment_id, pa.error_code, pa.error_message, pa.retry_count, pa.last_seen_at, o.order_number FROM payment_attempts pa LEFT JOIN orders o ON o.id=pa.order_id' . $where . ' ORDER BY pa.last_seen_at DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$perPage, $offset]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns = ['Order', 'Provider/reference', 'Status/source', 'Gateway ID', 'Error', 'Last seen'];
    } elseif ($tab === 'refunds') {
        $count = operations_execute($conn, 'SELECT COUNT(*) FROM refund_ledger rl LEFT JOIN orders o ON o.id=rl.order_id' . $where, $types, $params);
        $total = (int) ($count->get_result()->fetch_row()[0] ?? 0);
        $stmt = operations_execute($conn, 'SELECT rl.*, o.order_number FROM refund_ledger rl LEFT JOIN orders o ON o.id=rl.order_id' . $where . ' ORDER BY rl.created_at DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$perPage, $offset]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns = ['Order', 'Amount', 'Status', 'Gateway/refund ID', 'Note', 'Created'];
    } elseif ($tab === 'stock') {
        $joins = ' FROM stock_ledger sl LEFT JOIN fabrics f ON f.id=sl.fabric_id LEFT JOIN fabric_variants fv ON fv.id=sl.variant_id LEFT JOIN orders o ON o.id=sl.order_id';
        $count = operations_execute($conn, 'SELECT COUNT(*)' . $joins . $where, $types, $params);
        $total = (int) ($count->get_result()->fetch_row()[0] ?? 0);
        $stmt = operations_execute($conn, 'SELECT sl.*, f.name product_name, fv.sku variant_sku, o.order_number' . $joins . $where . ' ORDER BY sl.created_at DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$perPage, $offset]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns = ['Product/SKU', 'Order', 'Movement', 'Quantity', 'Source', 'Created'];
    } elseif ($tab === 'audit') {
        $joins = ' FROM admin_activity_logs aal JOIN admins a ON a.id=aal.admin_id';
        $count = operations_execute($conn, 'SELECT COUNT(*)' . $joins . $where, $types, $params);
        $total = (int) ($count->get_result()->fetch_row()[0] ?? 0);
        $stmt = operations_execute($conn, 'SELECT aal.*, a.name admin_name, a.email admin_email' . $joins . $where . ' ORDER BY aal.created_at DESC LIMIT ? OFFSET ?', $types . 'ii', array_merge($params, [$perPage, $offset]));
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $columns = ['Administrator', 'Action', 'Target/route', 'Status', 'Details', 'Created'];
    }
} catch (Throwable $e) {
    error_log('[operations-center] read failed: ' . $e->getMessage());
    flash('error', 'Operational data is unavailable. Apply the current migration and try again.');
}

$metaTitle = 'Operations Center | Admin';
include __DIR__ . '/partials/header.php';
$pages = max(1, (int) ceil($total / $perPage));
?>
<div class="u-mb-4"><h1 class="h3 u-mb-1">Operations Center</h1><p class="u-text-muted u-mb-0">Sanitized, read-only operational history. Provider signatures and raw payloads are never displayed.</p></div>
<ul class="admin-tabs u-mb-3"><?php foreach ($allowedTabs as $item): ?><li><a class="admin-tab <?php echo $tab === $item ? 'is-active' : ''; ?>" href="operations.php?tab=<?php echo e($item); ?>"><?php echo e(ucfirst($item)); ?></a></li><?php endforeach; ?></ul>
<form method="GET" class="l-grid l-grid--12 u-gap-2 u-mb-3"><input type="hidden" name="tab" value="<?php echo e($tab); ?>"><div class="l-col-md-quarter"><input class="ui-input" name="q" maxlength="100" placeholder="Order, product, provider or admin" aria-label="Search operations" value="<?php echo e($searchFilter); ?>"></div><div class="l-col-md-two"><input class="ui-input" name="status" maxlength="40" placeholder="Status or movement" aria-label="Filter by status or movement" value="<?php echo e($statusFilter); ?>"></div><div class="l-col-md-two"><input type="date" class="ui-input" name="from" value="<?php echo e($fromFilter); ?>" aria-label="From date"></div><div class="l-col-md-two"><input type="date" class="ui-input" name="to" value="<?php echo e($toFilter); ?>" aria-label="To date"></div><div class="l-col-auto"><button class="ui-button ui-button--outline">Filter</button></div><div class="l-col-auto"><a class="ui-button ui-button--secondary" href="operations.php?tab=<?php echo e($tab); ?>">Reset</a></div></form>
<div class="ui-table-wrap"><table class="ui-table u-align-middle"><thead><tr><?php foreach ($columns as $column): ?><th><?php echo e($column); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="<?php echo max(1, count($columns)); ?>" class="u-text-center u-text-muted u-py-4">No records found.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr>
<?php if ($tab === 'cron'): $details = json_decode((string) ($row['summary_json'] ?? '[]'), true); $details = is_array($details) ? $details : []; ?><td class="u-text-small"><?php echo e((string) $row['started_at']); ?><br><?php echo e((string) $row['finished_at']); ?></td><td><?php echo e((string) $row['status']); ?></td><td><?php echo (int) $row['jobs_total']; ?> total<br><span class="u-text-danger"><?php echo (int) $row['jobs_failed']; ?> failed</span> / <span class="u-text-warning"><?php echo (int) $row['jobs_degraded']; ?> degraded</span></td><td><?php echo (int) $row['duration_ms']; ?> ms</td><td class="u-text-small"><?php if (!$details): ?>—<?php else: ?><?php foreach ($details as $detail): ?><div><strong><?php echo e((string) ($detail['job'] ?? 'unknown')); ?></strong>: <?php echo e(CronService::sanitizeError((string) ($detail['error'] ?? $detail['status'] ?? ''))); ?><?php foreach ((array) ($detail['callbacks'] ?? []) as $callback): ?><br><span class="u-ms-2"><?php echo e((string) ($callback['callback'] ?? 'callback')); ?>: <?php echo e(CronService::sanitizeError((string) ($callback['error'] ?? $callback['status'] ?? ''))); ?></span><?php endforeach; ?></div><?php endforeach; ?><?php endif; ?></td>
<?php elseif ($tab === 'payments'): ?><td><?php echo e((string) ($row['order_number'] ?? '—')); ?></td><td><?php echo e((string) $row['provider']); ?><br><span class="u-text-small u-text-muted"><?php echo e((string) $row['attempt_ref']); ?></span></td><td><?php echo e((string) $row['status']); ?><br><span class="u-text-small"><?php echo e((string) $row['source']); ?></span></td><td><?php echo e((string) ($row['gateway_payment_id'] ?: '—')); ?></td><td class="u-text-small"><?php echo e(CronService::sanitizeError(trim((string) ($row['error_code'] ?? '') . ' ' . (string) ($row['error_message'] ?? '')))); ?></td><td><?php echo e((string) $row['last_seen_at']); ?></td>
<?php elseif ($tab === 'refunds'): ?><td><?php echo e((string) ($row['order_number'] ?? $row['order_id'])); ?></td><td><?php echo e(money((float) $row['amount'], (string) $row['currency'])); ?></td><td><?php echo e((string) $row['status']); ?></td><td><?php echo e((string) ($row['gateway'] ?? '')); ?><br><span class="u-text-small"><?php echo e((string) ($row['gateway_refund_id'] ?? '')); ?></span></td><td class="u-text-small"><?php echo e(CronService::sanitizeError((string) ($row['notes'] ?? ''))); ?></td><td><?php echo e((string) $row['created_at']); ?></td>
<?php elseif ($tab === 'stock'): ?><td><?php echo e((string) ($row['product_name'] ?? 'Product')); ?><br><span class="u-text-small"><?php echo e((string) ($row['variant_sku'] ?? '')); ?></span></td><td><?php echo e((string) ($row['order_number'] ?? '—')); ?></td><td><?php echo e((string) $row['movement']); ?> / <?php echo e((string) $row['direction']); ?></td><td><?php echo e(format_quantity_by_unit((float) $row['quantity'], (string) $row['unit_type'])); ?></td><td><?php echo e((string) ($row['source'] ?? '')); ?></td><td><?php echo e((string) $row['created_at']); ?></td>
<?php else: ?><td><?php echo e((string) $row['admin_name']); ?><br><span class="u-text-small"><?php echo e((string) $row['admin_email']); ?></span></td><td><?php echo e((string) $row['action']); ?></td><td><?php echo e((string) ($row['target_type'] ?? '')); ?> #<?php echo (int) ($row['target_id'] ?? 0); ?><br><span class="u-text-small"><?php echo e((string) ($row['route'] ?? '')); ?></span></td><td><?php echo e((string) $row['status']); ?></td><td class="u-text-small"><?php echo e(CronService::sanitizeError((string) ($row['details'] ?? ''))); ?></td><td><?php echo e((string) $row['created_at']); ?></td><?php endif; ?>
</tr><?php endforeach; ?>
</tbody></table></div>
<?php echo render_pagination($page, $pages, ['tab' => $tab, 'status' => $statusFilter, 'q' => $searchFilter, 'from' => $fromFilter, 'to' => $toFilter], 'page', $total, $perPage); ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
