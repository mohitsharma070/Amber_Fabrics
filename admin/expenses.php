<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$expenseTypes = ['Marketing','Packaging','Shipping','Product Purchase','Website','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid token. Please try again.');
        redirect('expenses.php');
    }

    $action = trim((string) ($_POST['action'] ?? 'create'));

    if ($action === 'create') {
        $type = trim((string) ($_POST['type'] ?? 'Other'));
        $amount = (float) ($_POST['amount'] ?? 0);
        $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!in_array($type, $expenseTypes, true) || $amount <= 0 || $expenseDate === '') {
            flash('error', 'Please provide valid expense details.');
            redirect('expenses.php');
        }

        $stmt = $conn->prepare("INSERT INTO expenses (type, amount, expense_date, note) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sdss', $type, $amount, $expenseDate, $note);
        $stmt->execute();

        flash('success', 'Expense added successfully.');
        redirect('expenses.php');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $type = trim((string) ($_POST['type'] ?? 'Other'));
        $amount = (float) ($_POST['amount'] ?? 0);
        $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($id <= 0 || !in_array($type, $expenseTypes, true) || $amount <= 0 || $expenseDate === '') {
            flash('error', 'Invalid expense update request.');
            redirect('expenses.php');
        }

        $stmt = $conn->prepare("UPDATE expenses SET type = ?, amount = ?, expense_date = ?, note = ? WHERE id = ?");
        $stmt->bind_param('sdssi', $type, $amount, $expenseDate, $note, $id);
        $stmt->execute();

        flash('success', 'Expense updated successfully.');
        redirect('expenses.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            flash('success', 'Expense deleted.');
        }
        redirect('expenses.php');
    }
}

$month = trim((string) ($_GET['month'] ?? ''));
$whereSql = '';
$types = '';
$params = [];

if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
    $whereSql = "WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?";
    $types = 's';
    $params[] = $month;
}

$totalSql = "SELECT COALESCE(SUM(amount),0) AS total FROM expenses {$whereSql}";
$totalStmt = $conn->prepare($totalSql);
if (!empty($params)) {
    $totalStmt->bind_param($types, ...$params);
}
$totalStmt->execute();
$totalExpenses = (float) ($totalStmt->get_result()->fetch_assoc()['total'] ?? 0);

$listSql = "SELECT id, type, amount, expense_date, note, created_at FROM expenses {$whereSql} ORDER BY expense_date DESC, id DESC";
$listStmt = $conn->prepare($listSql);
if (!empty($params)) {
    $listStmt->bind_param($types, ...$params);
}
$listStmt->execute();
$expenses = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$editId = (int) ($_GET['edit'] ?? 0);
$editExpense = null;
if ($editId > 0) {
    foreach ($expenses as $row) {
        if ((int) $row['id'] === $editId) {
            $editExpense = $row;
            break;
        }
    }
}

$metaTitle = 'Expenses | Admin';
include 'partials/header.php';
?>

<div class="admin-page-header u-flex u-justify-between u-items-center u-mb-3">
    <div>
        <h1 class="u-mb-1">Expenses</h1>
        <p class="u-text-muted u-mb-0">Total Expenses<?php echo $month ? ' (' . e($month) . ')' : ''; ?>: <strong><?php echo e(money($totalExpenses)); ?></strong></p>
    </div>
</div>

<div class="l-grid l-grid--12 u-gap-4">
    <div class="l-col-lg-third">
        <div class="surface-panel u-p-3">
            <h5 class="u-mb-3"><?php echo $editExpense ? 'Edit Expense' : 'Add Expense'; ?></h5>
            <form method="POST" action="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $editExpense ? 'update' : 'create'; ?>">
                <?php if ($editExpense): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $editExpense['id']; ?>">
                <?php endif; ?>

                <div class="u-mb-2">
                    <label for="type" class="ui-label">Type</label>
                    <select id="type" name="type" class="ui-select" required>
                        <?php foreach ($expenseTypes as $t): ?>
                            <option value="<?php echo e($t); ?>" <?php echo ($editExpense['type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="u-mb-2">
                    <label for="amount" class="ui-label">Amount</label>
                    <input id="amount" type="number" step="0.01" min="0.01" name="amount" class="ui-input" required value="<?php echo e((string) ($editExpense['amount'] ?? '')); ?>">
                </div>
                <div class="u-mb-2">
                    <label for="expense_date" class="ui-label">Expense Date</label>
                    <input id="expense_date" type="date" name="expense_date" class="ui-input" required value="<?php echo e((string) ($editExpense['expense_date'] ?? date('Y-m-d'))); ?>">
                </div>
                <div class="u-mb-3">
                    <label for="note" class="ui-label">Note</label>
                    <textarea id="note" name="note" class="ui-input" rows="3"><?php echo e((string) ($editExpense['note'] ?? '')); ?></textarea>
                </div>

                <button class="ui-button ui-button--primary u-w-full" type="submit"><?php echo $editExpense ? 'Update Expense' : 'Add Expense'; ?></button>
                <?php if ($editExpense): ?>
                    <a href="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>" class="ui-button ui-button--secondary u-w-full u-mt-2">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="l-col-lg-eight">
        <form method="GET" class="l-grid l-grid--12 u-gap-2 u-mb-3 admin-filter-form">
            <div class="l-col-md-third">
                <label for="month" class="ui-label">Filter by Month</label>
                <input id="month" type="month" name="month" class="ui-input" value="<?php echo e($month); ?>">
            </div>
            <div class="l-col-auto u-flex u-items-end u-gap-2 admin-filter-actions">
                <button class="ui-button ui-button--primary" type="submit"><?php echo ui_icon('funnel'); ?>Apply</button>
                <a href="expenses.php" class="ui-button ui-button--secondary"><?php echo ui_icon('arrow-counterclockwise'); ?>Reset</a>
            </div>
        </form>

        <div class="ui-table-wrap">
            <table class="ui-table ui-table--striped u-align-middle admin-card-table">
                <thead class="ui-table__head--dark">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Note</th>
                        <th class="u-text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="5" class="u-text-center u-text-muted">No expenses found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($expenses as $row): ?>
                        <tr>
                            <td><?php echo e((string) $row['expense_date']); ?></td>
                            <td><?php echo e((string) $row['type']); ?></td>
                            <td><?php echo e(money((float) $row['amount'])); ?></td>
                            <td><?php echo e((string) $row['note']); ?></td>
                            <td class="u-text-end admin-row-actions">
                                <a class="ui-button ui-button--small ui-button--outline" href="expenses.php?edit=<?php echo (int) $row['id']; ?><?php echo $month ? '&month=' . urlencode($month) : ''; ?>"><?php echo ui_icon('pencil'); ?>Edit</a>
                                <form method="POST" action="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>" class="u-inline" data-confirm-modal data-confirm-title="Delete Expense" data-confirm-message="Delete this expense entry?" data-confirm-ok="Delete" data-confirm-variant="danger">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="ui-button ui-button--small ui-button--danger-outline"><?php echo ui_icon('trash'); ?>Delete</button>
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
