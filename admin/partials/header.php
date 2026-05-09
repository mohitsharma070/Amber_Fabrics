<?php
require_once __DIR__ . '/../../includes/init.php';
require_admin();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$siteSettings = get_site_settings();
$siteName = $siteSettings['site_name'];
$siteLogo = $siteSettings['branding_logo'];
$isRefundQueue = $currentPage === 'orders.php' && (string) ($_GET['refund_queue'] ?? '') === '1';
$pendingRefunds = 0;
try {
    $pendingRefunds = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE order_status = 'cancelled' AND payment_status = 'paid'")->fetch_row()[0] ?? 0);
} catch (Throwable $e) {
    $pendingRefunds = 0;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($metaTitle) ? $metaTitle : 'Amber Fabrics Admin'; ?></title>
    <meta name="description" content="<?php echo isset($metaDescription) ? $metaDescription : 'Admin panel for Amber Fabrics.'; ?>">
    <meta name="keywords" content="<?php echo isset($metaKeywords) ? $metaKeywords : 'admin, management, Amber Fabrics'; ?>">
    <meta name="author" content="Amber Fabrics">
    <meta property="og:title" content="<?php echo isset($metaTitle) ? $metaTitle : 'Amber Fabrics Admin'; ?>">
    <meta property="og:description" content="<?php echo isset($metaDescription) ? $metaDescription : 'Admin panel for Amber Fabrics.'; ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo (isset($metaUrl) ? $metaUrl : 'https://amberfabrics.com/admin'); ?>">
    <meta property="og:image" content="<?php echo (isset($metaImage) ? $metaImage : '../images/fabrics/default.jpg'); ?>">
    <!-- Favicons: Light/Dark theme support -->
    <link rel="icon" type="image/svg+xml" href="../images/favicon-light.svg" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/svg+xml" href="../images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
    <link rel="alternate icon" type="image/png" href="../images/favicon-light.svg">
    <link rel="apple-touch-icon" href="../images/favicon-light.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260506c">
</head>
<body class="admin-shell">
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand brand-mark text-white d-flex align-items-center" href="dashboard.php">
            <?php
            // Mobile-specific logo logic
            $isMobile = false;
            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
                $isMobile = strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false;
            }
            $logoLight = $isMobile ? '../images/logo-mobile.svg' : (!empty($siteLogo) ? '../' . $siteLogo : '../images/logo-brand-light.svg');
            $logoDark = $isMobile ? '../images/logo-mobile-dark.svg' : (!empty($siteLogo) ? '../' . $siteLogo : '../images/logo-brand-light.svg');
            ?>
            <picture>
                <source srcset="<?php echo $logoDark; ?>" media="(prefers-color-scheme: dark)">
                <img src="<?php echo e($logoLight); ?>" alt="<?php echo e($siteName); ?>" class="admin-logo">
            </picture>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="adminNav">
            <div class="navbar-nav">
                <a class="nav-link <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">Dashboard</a>
                <a class="nav-link <?php echo in_array($currentPage, ['fabrics.php','add-fabric.php','edit-fabric.php'], true) ? 'active' : ''; ?>" href="fabrics.php">Products</a>
                <a class="nav-link <?php echo $currentPage === 'categories.php' ? 'active' : ''; ?>" href="categories.php">Categories</a>
                <a class="nav-link <?php echo $currentPage === 'about-media.php' ? 'active' : ''; ?>" href="about-media.php">About Media</a>
                <a class="nav-link <?php echo ((in_array($currentPage, ['orders.php','order-view.php'], true) && !$isRefundQueue) ? 'active' : ''); ?>" href="orders.php">Orders</a>
                <a class="nav-link <?php echo $isRefundQueue ? 'active' : ''; ?>" href="orders.php?refund_queue=1">
                    Refunds
                    <?php if ($pendingRefunds > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $pendingRefunds; ?></span>
                    <?php endif; ?>
                </a>
                <a class="nav-link <?php echo $currentPage === 'expenses.php' ? 'active' : ''; ?>" href="expenses.php">Expenses</a>
                <a class="nav-link <?php echo $currentPage === 'coupons.php' ? 'active' : ''; ?>" href="coupons.php">Coupons</a>
                <a class="nav-link <?php echo $currentPage === 'export-inquiries.php' ? 'active' : ''; ?>" href="export-inquiries.php">Export Inquiries</a>
                <form method="POST" action="logout.php" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-link nav-link text-white d-inline p-0 align-baseline">Logout</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success text-center mb-0 rounded-0"><?php echo e($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger text-center mb-0 rounded-0"><?php echo e($msg); ?></div>
    <?php endif; ?>
<?php endif; ?>

<div class="container mt-4">
