<?php
require_once __DIR__ . '/includes/init.php';

function coupon_remove_redirect_target(string $fallback = '/cart.php'): string
{
    $target = (string) ($_POST['redirect_to'] ?? '');
    $addressId = (int) ($_POST['shipping_address_id'] ?? 0);
    if ($target === 'checkout') {
        return $addressId > 0 ? ('/checkout.php?address_id=' . $addressId) : '/checkout.php';
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
