<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('fabrics.php');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect('fabrics.php');
}

$stmt = $conn->prepare("SELECT image FROM fabrics WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$fabric = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("DELETE FROM fabrics WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();

if ($fabric && !empty($fabric['image'])) {
    @unlink(__DIR__ . "/../images/fabrics/{$fabric['image']}");
}

flash('success', 'Fabric deleted.');
redirect('fabrics.php');
