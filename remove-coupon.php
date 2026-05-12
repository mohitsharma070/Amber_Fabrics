<?php
require_once __DIR__ . '/includes/init.php';

function coupon_remove_redirect_target(string $fallback = '/cart.php'): string
{
    $target = (string) ($_POST['redirect_to'] ?? '');
    if ($target === 'checkout') {
        return '/checkout.php';
    }
    if ($target === 'cart') {
        return '/cart.php';
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect(coupon_remove_redirect_target('/cart.php'));
}

unset($_SESSION['applied_coupon_code']);
flash('success', 'Coupon removed.');
redirect(coupon_remove_redirect_target('/cart.php'));
