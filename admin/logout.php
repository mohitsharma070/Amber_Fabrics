<?php
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', 'Invalid logout request.');
    redirect(!empty($_SESSION['admin_id']) ? 'dashboard.php' : 'login.php');
}

if (!empty($_SESSION['admin_id'])) {
    log_admin_activity($conn, (int) $_SESSION['admin_id'], 'admin_logout', 'session', 0, 'Admin logged out.', 'ok');
}
app_destroy_session(false);
redirect('login.php?logged_out=1');
