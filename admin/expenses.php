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

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="mb-1">Expenses</h1>
        <p class="text-muted mb-0">Total Expenses<?php echo $month ? ' (' . e($month) . ')' : ''; ?>: <strong>Rs <?php echo number_format($totalExpenses, 2); ?></strong></p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="surface-panel p-3">
            <h5 class="mb-3"><?php echo $editExpense ? 'Edit Expense' : 'Add Expense'; ?></h5>
            <form method="POST" action="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $editExpense ? 'update' : 'create'; ?>">
                <?php if ($editExpense): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $editExpense['id']; ?>">
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select" required>
                        <?php foreach ($expenseTypes as $t): ?>
                            <option value="<?php echo e($t); ?>" <?php echo ($editExpense['type'] ?? '') === $t ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="<?php echo e((string) ($editExpense['amount'] ?? '')); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label">Expense Date</label>
                    <input type="date" name="expense_date" class="form-control" required value="<?php echo e((string) ($editExpense['expense_date'] ?? date('Y-m-d'))); ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="3"><?php echo e((string) ($editExpense['note'] ?? '')); ?></textarea>
                </div>

                <button class="btn btn-primary w-100" type="submit"><?php echo $editExpense ? 'Update Expense' : 'Add Expense'; ?></button>
                <?php if ($editExpense): ?>
                    <a href="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label">Filter by Month</label>
                <input type="month" name="month" class="form-control" value="<?php echo e($month); ?>">
            </div>
            <div class="col-md-auto align-self-end d-flex gap-2">
                <button class="btn btn-primary" type="submit">Apply</button>
                <a href="expenses.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Note</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No expenses found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($expenses as $row): ?>
                        <tr>
                            <td><?php echo e((string) $row['expense_date']); ?></td>
                            <td><?php echo e((string) $row['type']); ?></td>
                            <td>Rs <?php echo number_format((float) $row['amount'], 2); ?></td>
                            <td><?php echo e((string) $row['note']); ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="expenses.php?edit=<?php echo (int) $row['id']; ?><?php echo $month ? '&month=' . urlencode($month) : ''; ?>">Edit</a>
                                <form method="POST" action="expenses.php<?php echo $month ? '?month=' . urlencode($month) : ''; ?>" class="d-inline" onsubmit="return confirm('Delete this expense?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
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
