<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/security/customer-auth.php';

$token   = trim($_GET['token'] ?? '');
$invalid = false;
$already = false;

if ($token === '' || strlen($token) < 32) {
    $invalid = true;
} else {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT id, email_verified
         FROM customers
         WHERE email_verify_token = ?
           AND email_verify_expires > UTC_TIMESTAMP()
         LIMIT 1"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();

    if (!$customer) {
        $invalid = true;
    } elseif ((int) $customer['email_verified'] === 1) {
        $already = true;
    } else {
        $upd = $conn->prepare(
            "UPDATE customers
             SET email_verified = 1,
                 email_verify_token = NULL,
                 email_verify_expires = NULL
             WHERE id = ?"
        );
        $upd->bind_param('i', $customer['id']);
        $upd->execute();
    }
}

$metaTitle = SiteContext::title('Verify Email');
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="l-container"><h1>Email Verification</h1></div></section>

<section class="section-block">
    <div class="l-container">
        <div class="l-grid l-grid--12 u-justify-center">
            <div class="l-col-md-half l-col-lg-third u-text-center">
                <?php if ($invalid): ?>
                    <div class="ui-alert ui-alert--error">This verification link is invalid or has expired.</div>
                    <p><a href="/customer/register">Register again</a> or <a href="/customer/login">log in</a>.</p>
                <?php elseif ($already): ?>
                    <div class="ui-alert ui-alert--info">Your email is already verified.</div>
                    <a href="/customer/login" class="ui-button ui-button--primary">Log In</a>
                <?php else: ?>
                    <div class="ui-alert ui-alert--success">Your email has been verified successfully!</div>
                    <a href="/customer/login" class="ui-button ui-button--primary">Log In Now</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
