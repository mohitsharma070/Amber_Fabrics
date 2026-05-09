<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', 'Invalid logout request.');
    redirect('/customer/profile.php');
}

// Save cart to DB before destroying session
if (!empty($_SESSION['customer_id']) && !empty($_SESSION['cart'])) {
    cart_save_to_db($conn, (int) $_SESSION['customer_id'], $_SESSION['cart']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('/customer/login.php');
