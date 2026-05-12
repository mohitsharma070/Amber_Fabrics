<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/customer-auth.php';

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

$metaTitle = 'Verify Email | Amber Fabrics';
include __DIR__ . '/../includes/header.php';
?>

<section class="page-hero"><div class="container"><h1>Email Verification</h1></div></section>

<section class="section-block">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4 text-center">
                <?php if ($invalid): ?>
                    <div class="alert alert-danger">This verification link is invalid or has expired.</div>
                    <p><a href="/customer/register.php">Register again</a> or <a href="/customer/login.php">log in</a>.</p>
                <?php elseif ($already): ?>
                    <div class="alert alert-info">Your email is already verified.</div>
                    <a href="/customer/login.php" class="btn btn-primary">Log In</a>
                <?php else: ?>
                    <div class="alert alert-success">Your email has been verified successfully!</div>
                    <a href="/customer/login.php" class="btn btn-primary">Log In Now</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
