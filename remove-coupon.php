<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/helpers/coupon-functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
}
if (!verify_csrf()) {
    flash('error', 'Invalid session token. Please try again.');
    redirect(coupon_redirect_target('/cart.php'));
}

preserve_checkout_state_from_coupon_request();

unset($_SESSION['applied_coupon_code']);
flash('success', 'Coupon removed.');
redirect(coupon_redirect_target('/cart.php'));
