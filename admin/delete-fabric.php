<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('fabrics.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    flash('error', 'Invalid fabric selected.');
    redirect('fabrics.php');
}

if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('fabrics.php');
}

try {
    $conn->begin_transaction();
    $check = $conn->prepare("SELECT id FROM fabrics WHERE id = ? LIMIT 1 FOR UPDATE");
    $check->bind_param('i', $id);
    $check->execute();
    $fabric = $check->get_result()->fetch_assoc();
    if (!$fabric) {
        throw new RuntimeException('Fabric not found.');
    }

    $archiveFabric = $conn->prepare("UPDATE fabrics SET status = 'inactive', is_available = 0 WHERE id = ?");
    $archiveFabric->bind_param('i', $id);
    $archiveFabric->execute();

    $archiveVariants = $conn->prepare("UPDATE fabric_variants SET is_active = 0 WHERE fabric_id = ?");
    $archiveVariants->bind_param('i', $id);
    $archiveVariants->execute();

    $conn->commit();
    flash('success', 'Fabric archived and hidden from storefront.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackException) {
        // ignore rollback errors
    }
    flash('error', 'Unable to archive fabric right now.');
}
redirect('fabrics.php');
