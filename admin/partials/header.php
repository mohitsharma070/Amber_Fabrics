<?php
require_once __DIR__ . '/../../includes/init.php';
require_admin();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$siteSettings = get_site_settings();
$siteName = $siteSettings['site_name'];
$siteLogo = $siteSettings['branding_logo'];
$isRefundQueue = $currentPage === 'orders.php' && (string) ($_GET['refund_queue'] ?? '') === '1';
$isCatalogNav = in_array($currentPage, ['fabrics.php', 'add-fabric.php', 'edit-fabric.php', 'categories.php', 'about-media.php'], true);
$isOrdersNav = in_array($currentPage, ['orders.php', 'order-view.php', 'returns.php'], true) || $isRefundQueue;
$isCustomersNav = $currentPage === 'customers.php';
$isMarketingNav = in_array($currentPage, ['coupons.php', 'reviews.php', 'export-inquiries.php'], true);
$isOperationsNav = in_array($currentPage, ['shipping-rates.php', 'expenses.php'], true);
$isSettingsNav = $currentPage === 'settings.php';
$pendingRefunds = 0;
$pendingReviews = 0;
try {
    $pendingRefunds = (int) ($conn->query("SELECT COUNT(*) FROM orders WHERE order_status = 'cancelled' AND payment_status = 'paid'")->fetch_row()[0] ?? 0);
} catch (Throwable $e) {
    $pendingRefunds = 0;
}
try {
    $reviewTableStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'product_reviews'"
    );
    $reviewTableStmt->execute();
    $reviewTableReady = ((int) (($reviewTableStmt->get_result()->fetch_assoc()['total'] ?? 0)) > 0);
    if ($reviewTableReady) {
        $pendingReviews = (int) ($conn->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'pending'")->fetch_row()[0] ?? 0);
    }
} catch (Throwable $e) {
    $pendingReviews = 0;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260516d">
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
        <button class="navbar-toggler admin-nav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon admin-nav-open-icon"></span>
            <i class="bi bi-x-lg admin-nav-close-icon" aria-hidden="true"></i>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="adminNav">
            <div class="navbar-nav admin-nav-grid">
                <a class="nav-link <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php"><i class="bi bi-speedometer2 me-2" aria-hidden="true"></i>Dashboard</a>

                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $isCatalogNav ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-box-seam me-2" aria-hidden="true"></i>Catalog
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo in_array($currentPage, ['fabrics.php', 'add-fabric.php', 'edit-fabric.php'], true) ? 'active' : ''; ?>" href="fabrics.php"><i class="bi bi-box me-2" aria-hidden="true"></i>Products</a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'categories.php' ? 'active' : ''; ?>" href="categories.php"><i class="bi bi-tags me-2" aria-hidden="true"></i>Categories</a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'about-media.php' ? 'active' : ''; ?>" href="about-media.php"><i class="bi bi-images me-2" aria-hidden="true"></i>About Media</a></li>
                    </ul>
                </div>

                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $isOrdersNav ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-receipt me-2" aria-hidden="true"></i>Orders
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo ((in_array($currentPage, ['orders.php', 'order-view.php'], true) && !$isRefundQueue) ? 'active' : ''); ?>" href="orders.php"><i class="bi bi-receipt-cutoff me-2" aria-hidden="true"></i>Orders</a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'returns.php' ? 'active' : ''; ?>" href="returns.php"><i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i>Returns</a></li>
                        <li><a class="dropdown-item <?php echo $isRefundQueue ? 'active' : ''; ?>" href="orders.php?refund_queue=1"><i class="bi bi-cash-stack me-2" aria-hidden="true"></i>Refund Queue<?php if ($pendingRefunds > 0): ?><span class="badge bg-danger ms-2"><?php echo $pendingRefunds; ?></span><?php endif; ?></a></li>
                    </ul>
                </div>

                <a class="nav-link <?php echo $isCustomersNav ? 'active' : ''; ?>" href="customers.php"><i class="bi bi-people me-2" aria-hidden="true"></i>Customers</a>

                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $isMarketingNav ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-megaphone me-2" aria-hidden="true"></i>Marketing
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo $currentPage === 'coupons.php' ? 'active' : ''; ?>" href="coupons.php"><i class="bi bi-ticket-perforated me-2" aria-hidden="true"></i>Coupons</a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'reviews.php' ? 'active' : ''; ?>" href="reviews.php"><i class="bi bi-chat-square-text me-2" aria-hidden="true"></i>Reviews<?php if ($pendingReviews > 0): ?><span class="badge bg-warning text-dark ms-2"><?php echo $pendingReviews; ?></span><?php endif; ?></a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'export-inquiries.php' ? 'active' : ''; ?>" href="export-inquiries.php"><i class="bi bi-globe2 me-2" aria-hidden="true"></i>Export Inquiries</a></li>
                    </ul>
                </div>

                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo $isOperationsNav ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear-wide-connected me-2" aria-hidden="true"></i>Operations
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo $currentPage === 'shipping-rates.php' ? 'active' : ''; ?>" href="shipping-rates.php"><i class="bi bi-truck me-2" aria-hidden="true"></i>Shipping Rates</a></li>
                        <li><a class="dropdown-item <?php echo $currentPage === 'expenses.php' ? 'active' : ''; ?>" href="expenses.php"><i class="bi bi-wallet2 me-2" aria-hidden="true"></i>Expenses</a></li>
                    </ul>
                </div>

                <a class="nav-link <?php echo $isSettingsNav ? 'active' : ''; ?>" href="settings.php"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Settings</a>

                <form method="POST" action="logout.php" class="d-inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-link nav-link text-white d-inline p-0 align-baseline"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Logout</button>
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
