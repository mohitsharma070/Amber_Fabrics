<?php
require_once __DIR__ . '/../includes/init.php';
if(!OrderAccessService::enabled()){http_response_code(404);exit('Not found');}
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('error', 'Invalid session.'); redirect('/guest/order-access'); }
    $number = strtoupper(trim((string) ($_POST['order_number'] ?? '')));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $ipKey = 'guest_order_link_ip_' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $identifierKey = 'guest_order_link_identifier_' . hash('sha256', $number . '|' . $email);
    // Scope hashes contain no raw PII. Disabling implicit client binding makes
    // these true independent limits: one per IP and one per order/email pair.
    $ipAllowed = public_form_rate_limit_allow($ipKey, 10, 900, false);
    $identifierAllowed = public_form_rate_limit_allow($identifierKey, 5, 900, false);
    if ($ipAllowed && $identifierAllowed && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT id FROM orders WHERE order_number=? AND LOWER(TRIM(customer_email))=? LIMIT 1");
        $stmt->bind_param('ss', $number, $email); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
        if ($row) { EmailService::send_guest_manage_link($conn, (int) $row['id']); }
    }
    $sent = true;
}
$metaTitle=SiteContext::title('Manage Guest Order'); include __DIR__.'/../includes/header.php'; ?>
<section class="page-hero"><div class="container"><h1>Manage Your Order</h1><p class="mb-0">Get a secure link by email.</p></div></section>
<section class="section-block"><div class="container"><div class="surface-panel p-4 mx-auto" style="max-width:560px"><?php if($sent): ?><div class="alert alert-success">If those details match an order, a secure link has been sent.</div><?php endif; ?><form method="post"><?php echo csrf_field(); ?><div class="mb-3"><label class="form-label">Order number</label><input class="form-control" name="order_number" required></div><div class="mb-3"><label class="form-label">Order email</label><input class="form-control" type="email" name="email" required></div><button class="btn btn-primary w-100">Email Secure Link</button></form></div></div></section><?php include __DIR__.'/../includes/footer.php'; ?>
