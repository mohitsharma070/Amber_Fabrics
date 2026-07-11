<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

function coupon_remove_redirect_target(string $fallback = '/cart.php'): string
{
    return (string) ($_POST['redirect_to'] ?? '') === 'checkout' ? '/checkout.php' : $fallback;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/cart.php'); }
if (!verify_csrf()) {
    if (coupon_wants_json()) api_json(['ok' => false, 'valid' => false, 'message' => 'Invalid session token. Please refresh and try again.', 'coupon_code' => '', 'discount_amount' => null, 'subtotal' => null, 'shipping' => null, 'cod_fee' => null, 'final_total' => null, 'shipping_quote_token' => ''], 403);
    flash('error', 'Invalid session token. Please try again.'); redirect(coupon_remove_redirect_target());
}
coupon_store_checkout_draft($_POST);
unset($_SESSION['applied_coupon_code']);
$totals = coupon_checkout_totals($conn);
$payload = [
    'ok' => true, 'valid' => true, 'message' => 'Coupon removed.', 'coupon_code' => '',
    'discount_amount' => $totals['discount_amount'], 'subtotal' => $totals['subtotal'],
    'shipping' => $totals['shipping'], 'cod_fee' => $totals['cod_fee'],
    'final_total' => $totals['final_total'], 'shipping_quote_token' => $totals['shipping_quote_token'], 'quote_for' => $totals['quote_for'],
];
if (coupon_wants_json()) api_json($payload);
flash('success', 'Coupon removed.'); redirect(coupon_remove_redirect_target());
