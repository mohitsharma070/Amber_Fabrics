<?php
$metaTitle = SiteContext::title('Admin Logout');
$metaDescription = 'Admin logout page for ' . SiteContext::name() . '. End your session securely.';
$metaKeywords = 'admin, logout, secure, ' . SiteContext::name();
require_once __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('error', 'Invalid logout request.');
    redirect('dashboard.php');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
redirect('login.php');
