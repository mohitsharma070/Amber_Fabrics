<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

$allowedStatuses = ['new', 'qualified', 'quoted', 'won', 'lost', 'contacted'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'Invalid inquiry ID.');
    redirect('inquiries.php');
}

$returnState = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'sort' => trim((string) ($_GET['sort'] ?? 'newest')),
    'per_page' => (int) ($_GET['per_page'] ?? 15),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
];
$returnQuery = list_build_query($returnState);
$backUrl = 'inquiries.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Invalid session token. Please try again.');
        redirect("inquiry-view.php?id={$id}" . ($returnQuery !== '' ? '&' . $returnQuery : ''));
    }

    $beforeStmt = $conn->prepare("SELECT status, internal_note FROM inquiries WHERE id = ? LIMIT 1");
    $beforeStmt->bind_param('i', $id);
    $beforeStmt->execute();
    $before = $beforeStmt->get_result()->fetch_assoc();
    $actorId = !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    $actorName = (string) ($_SESSION['admin_name'] ?? 'admin');

    if (isset($_POST['status'])) {
        $newStatus = trim((string) $_POST['status']);
        if (!in_array($newStatus, $allowedStatuses, true)) {
            flash('error', 'Invalid status value.');
        } else {
            $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $id);
            $stmt->execute();
            $oldStatus = (string) ($before['status'] ?? '');
            if ($oldStatus !== $newStatus) {
                log_inquiry_activity(
                    $conn,
                    $id,
                    'status_updated',
                    $actorId,
                    $actorName,
                    "Status changed from '{$oldStatus}' to '{$newStatus}'."
                );
            }
            flash('success', 'Status updated.');
        }
    } elseif (isset($_POST['save_note'])) {
        $note = trim((string) ($_POST['internal_note'] ?? ''));
        $stmt = $conn->prepare("UPDATE inquiries SET internal_note = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $oldNote = (string) ($before['internal_note'] ?? '');
        if ($oldNote !== $note) {
            log_inquiry_activity(
                $conn,
                $id,
                'note_updated',
                $actorId,
                $actorName,
                'Internal note updated.'
            );
        }
        flash('success', 'Note saved.');
    } elseif (isset($_POST['delete'])) {
        log_inquiry_activity(
            $conn,
            $id,
            'deleted',
            $actorId,
            $actorName,
            'Inquiry deleted from admin panel.'
        );
        $stmt = $conn->prepare("DELETE FROM inquiries WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        flash('success', 'Inquiry deleted.');
        redirect($backUrl);
    }
}

$stmt = $conn->prepare("SELECT * FROM inquiries WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$inquiry = $stmt->get_result()->fetch_assoc();
if (!$inquiry) {
    flash('error', 'Inquiry not found.');
    redirect($backUrl);
}

$activityLogs = [];
try {
    $logStmt = $conn->prepare("SELECT actor_name, action, details, created_at FROM inquiry_activity_logs WHERE inquiry_id = ? ORDER BY created_at DESC LIMIT 20");
    $logStmt->bind_param('i', $id);
    $logStmt->execute();
    $activityLogs = fetch_all_assoc($logStmt->get_result());
} catch (Throwable $e) {
    error_log('[fabric-export] inquiry-view activity read failed: ' . $e->getMessage());
}
?>

<?php
$metaTitle = SiteContext::title('Inquiry Details');
$metaDescription = 'Admin page to view details of a customer inquiry for ' . SiteContext::name() . '.';
$metaKeywords = 'admin, inquiry details, ' . SiteContext::name();
include 'partials/header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Inquiry #<?php echo (int) $inquiry['id']; ?></h1>
    <a href="<?php echo e($backUrl); ?>" class="btn btn-outline-secondary">Back to List</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="surface-panel h-100">
            <h5 class="mb-3">Customer Details</h5>
            <p class="mb-1"><strong>Name:</strong> <?php echo e($inquiry['name']); ?></p>
            <p class="mb-1"><strong>Email:</strong> <a href="mailto:<?php echo e($inquiry['email']); ?>"><?php echo e($inquiry['email']); ?></a></p>
            <p class="mb-1"><strong>Country:</strong> <?php echo e($inquiry['country']); ?></p>
            <p class="mb-1"><strong>Fabric:</strong> <?php echo e($inquiry['fabric_type']); ?></p>
            <p class="mb-1"><strong>Quantity:</strong> <?php echo e($inquiry['quantity']); ?></p>
            <p class="mb-1"><strong>Meters:</strong> <?php echo e($inquiry['meters'] ?? ''); ?></p>
            <p class="mb-1"><strong>Incoterm:</strong> <?php echo e($inquiry['incoterm']); ?></p>
            <p class="mb-1"><strong>Destination:</strong> <?php echo e($inquiry['destination']); ?></p>
            <p class="mb-1"><strong>Pin Code:</strong> <?php echo e($inquiry['pincode'] ?? ''); ?></p>
            <p class="mb-1"><strong>Timeline:</strong> <?php echo e($inquiry['timeline']); ?></p>
            <p class="mb-0"><strong>Received:</strong> <?php echo e($inquiry['created_at']); ?></p>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="surface-panel h-100">
            <h5 class="mb-3">Update Inquiry</h5>
            <form method="POST" class="mb-3">
                <?php echo csrf_field(); ?>
                <label class="form-label">Status</label>
                <div class="d-flex gap-2">
                    <select name="status" class="form-select">
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $inquiry['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary" type="submit">Update</button>
                </div>
            </form>
            <form method="POST" class="mb-3">
                <?php echo csrf_field(); ?>
                <label class="form-label">Internal Note</label>
                <textarea name="internal_note" class="form-control mb-2" rows="4" placeholder="Write internal note"><?php echo e($inquiry['internal_note']); ?></textarea>
                <button name="save_note" class="btn btn-outline-secondary" type="submit">Save Note</button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this inquiry?');">
                <?php echo csrf_field(); ?>
                <button name="delete" class="btn btn-outline-danger" type="submit">Delete Inquiry</button>
            </form>
        </div>
    </div>
</div>

<div class="surface-panel mt-3">
    <h5 class="mb-2">Message</h5>
    <div><?php echo nl2br(e($inquiry['message'])); ?></div>
</div>

<div class="surface-panel mt-3">
    <h5 class="mb-3">Activity History</h5>
    <?php if (empty($activityLogs)): ?>
        <p class="text-muted mb-0">No activity logged yet.</p>
    <?php endif; ?>
    <?php foreach ($activityLogs as $log): ?>
        <div class="border rounded p-2 mb-2">
            <div class="small text-muted"><?php echo e($log['created_at']); ?> by <?php echo e($log['actor_name']); ?></div>
            <div><strong><?php echo e($log['action']); ?></strong></div>
            <?php if (!empty($log['details'])): ?>
                <div><?php echo e($log['details']); ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php include 'partials/footer.php'; ?>
