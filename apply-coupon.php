<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/coupon-functions.php';

function coupon_redirect_target(string $fallback = '/cart.php'): string
{
    return (string) ($_POST['redirect_to'] ?? '') === 'checkout' ? '/checkout.php' : $fallback;
}
function apply_coupon_finish(bool $valid, string $message, string $code = ''): void
{
    $totals = coupon_checkout_totals($GLOBALS['conn'], (string) ($_SESSION['applied_coupon_code'] ?? ''));
    $payload = [
        'ok' => $valid, 'valid' => $valid, 'message' => $message,
        'coupon_code' => $totals['coupon_code'] ?: $code,
        'discount_amount' => $totals['discount_amount'], 'subtotal' => $totals['subtotal'],
        'shipping' => $totals['shipping'], 'cod_fee' => $totals['cod_fee'],
        'final_total' => $totals['final_total'], 'shipping_quote_token' => $totals['shipping_quote_token'], 'quote_for' => $totals['quote_for'],
    ];
    if (coupon_wants_json()) api_json($payload, $valid ? 200 : 422);
    flash($valid ? 'success' : 'error', $message);
    redirect(coupon_redirect_target());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/cart.php'); }
if (!verify_csrf()) {
    if (coupon_wants_json()) api_json(['ok' => false, 'valid' => false, 'message' => 'Invalid session token. Please refresh and try again.', 'coupon_code' => '', 'discount_amount' => null, 'subtotal' => null, 'shipping' => null, 'cod_fee' => null, 'final_total' => null, 'shipping_quote_token' => ''], 403);
    flash('error', 'Invalid session token. Please try again.'); redirect(coupon_redirect_target());
}
coupon_store_checkout_draft($_POST);
if (!public_form_rate_limit_allow('coupon_apply', 15, 300)) apply_coupon_finish(false, 'Too many coupon attempts. Please wait a few minutes and try again.');
$code = normalize_coupon_code((string) ($_POST['coupon_code'] ?? ''));
if ($code === '') apply_coupon_finish(false, 'Please enter a coupon code.');
$totals = coupon_checkout_totals($conn, $code);
if ($totals['cart_empty']) { unset($_SESSION['applied_coupon_code']); apply_coupon_finish(false, 'Add items to cart before applying a coupon.', $code); }
$info = $totals['coupon_info'];
if (!$info['valid']) { unset($_SESSION['applied_coupon_code']); apply_coupon_finish(false, $info['message'] ?: 'Coupon is not valid.', $code); }
$_SESSION['applied_coupon_code'] = $info['code'];
apply_coupon_finish(true, 'Coupon applied: ' . $info['code'], $info['code']);
