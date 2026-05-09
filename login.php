<?php
require_once __DIR__ . '/includes/init.php';

// Backward-compatible login route.
// Default: customer login, use ?type=admin for admin login.
$type = strtolower(trim((string) ($_GET['type'] ?? 'customer')));

if ($type === 'admin') {
    redirect('/admin/login.php');
}

redirect('/customer/login.php');
