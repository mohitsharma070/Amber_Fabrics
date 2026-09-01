<?php
require_once __DIR__ . '/../../includes/init.php';
require_admin();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$siteSettings = SiteSettingsService::get();
$siteName = $siteSettings['site_name'];
$siteLogo = $siteSettings['branding_logo'];
$isRefundQueue = $currentPage === 'orders.php' && (string) ($_GET['refund_queue'] ?? '') === '1';
$isCatalogNav = in_array($currentPage, ['fabrics.php', 'add-fabric.php', 'edit-fabric.php', 'product-import.php', 'categories.php', 'about-media.php'], true);
$isOrdersNav = in_array($currentPage, ['orders.php', 'order-view.php', 'returns.php'], true) || $isRefundQueue;
$isCustomersNav = $currentPage === 'customers.php';
$isMarketingNav = in_array($currentPage, ['coupons.php', 'reviews.php', 'export-inquiries.php'], true);
$isOperationsNav = in_array($currentPage, ['shipping-rates.php', 'expenses.php'], true);
$isSettingsNav = $currentPage === 'settings.php';
$currentRole = (string) ($_SESSION['admin_role'] ?? 'viewer');
$adminCanMutateCurrentPage = admin_can(admin_route_capability($currentPage, 'POST'), $currentRole);
$adminCanManageSettings = admin_can('settings.manage', $currentRole);
$adminCanManageAdmins = admin_can('admins.manage', $currentRole);
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

if (!function_exists('admin_nav_safe_url')) {
    function admin_nav_safe_url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (is_string($scheme) && $scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('admin_nav_safe_icon')) {
    function admin_nav_safe_icon(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '' || !preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $icon)) {
            return '';
        }
        return $icon;
    }
}

if (!function_exists('admin_nav_plugin_items')) {
    function admin_nav_plugin_items(mysqli $conn, string $currentPage): array
    {
        $items = apply_filters('admin.nav.items', [], [
            'conn' => $conn,
            'current_page' => $currentPage,
            'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
            'admin_name' => (string) ($_SESSION['admin_name'] ?? 'admin'),
        ]);
        if (!is_array($items)) {
            return [];
        }

        $safeItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $url = admin_nav_safe_url((string) ($item['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $safeItems[] = [
                'label' => substr($label, 0, 80),
                'url' => $url,
                'icon' => admin_nav_safe_icon((string) ($item['icon'] ?? '')),
                'active' => !empty($item['active']),
            ];
        }

        return $safeItems;
    }
}

$pluginNavItems = admin_nav_plugin_items($conn, $currentPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(isset($metaTitle) ? $metaTitle : ($siteName . ' Admin')); ?></title>
    <meta name="description" content="<?php echo e(isset($metaDescription) ? $metaDescription : ('Admin panel for ' . $siteName . '.')); ?>">
    <meta name="keywords" content="<?php echo e(isset($metaKeywords) ? $metaKeywords : ('admin, management, ' . $siteName)); ?>">
    <meta name="author" content="<?php echo e($siteName); ?>">
    <meta property="og:title" content="<?php echo e(isset($metaTitle) ? $metaTitle : ($siteName . ' Admin')); ?>">
    <meta property="og:description" content="<?php echo e(isset($metaDescription) ? $metaDescription : ('Admin panel for ' . $siteName . '.')); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo e(isset($metaUrl) ? $metaUrl : SiteContext::url('/admin')); ?>">
    <meta property="og:image" content="<?php echo e(isset($metaImage) ? $metaImage : SiteContext::url('/images/fabrics/default.jpg')); ?>">
    <!-- Favicons: Light/Dark theme support -->
    <link rel="icon" type="image/svg+xml" href="../images/favicon-light.svg" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/svg+xml" href="../images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
    <link rel="alternate icon" type="image/png" href="../images/favicon-light.svg">
    <link rel="apple-touch-icon" href="../images/favicon-light.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=20260822a">
    <link rel="stylesheet" href="../css/admin.css?v=20260822a">
</head>
<body class="admin-shell<?php echo $adminCanMutateCurrentPage ? '' : ' admin-read-only'; ?>" data-admin-can-mutate="<?php echo $adminCanMutateCurrentPage ? '1' : '0'; ?>">
<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand brand-mark text-white d-flex align-items-center" href="dashboard.php">
            <?php $desktopLogo = !empty($siteLogo) ? '../' . ltrim((string) $siteLogo, '/') : '../images/logo-brand-light.svg'; ?>
            <picture>
                <source srcset="<?php echo e('../images/logo-mobile-dark.svg'); ?>" media="(max-width: 767.98px) and (prefers-color-scheme: dark)">
                <source srcset="<?php echo e('../images/logo-mobile.svg'); ?>" media="(max-width: 767.98px)">
                <source srcset="<?php echo e($desktopLogo); ?>" media="(prefers-color-scheme: dark)">
                <img src="<?php echo e($desktopLogo); ?>" alt="<?php echo e($siteName); ?>" class="admin-logo">
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

                <a class="nav-link <?php echo $currentPage === 'operations.php' ? 'active' : ''; ?>" href="operations.php"><i class="bi bi-activity me-2" aria-hidden="true"></i>Operations Center</a>

                <?php if ($adminCanManageAdmins): ?>
                    <a class="nav-link <?php echo $currentPage === 'admins.php' ? 'active' : ''; ?>" href="admins.php"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>Administrators</a>
                <?php endif; ?>

                <?php if ($adminCanManageSettings): ?>
                    <a class="nav-link <?php echo $isSettingsNav ? 'active' : ''; ?>" href="settings.php"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Settings</a>
                <?php endif; ?>

                <?php foreach ($pluginNavItems as $pluginNavItem): ?>
                    <a class="nav-link <?php echo !empty($pluginNavItem['active']) ? 'active' : ''; ?>" href="<?php echo e((string) $pluginNavItem['url']); ?>">
                        <?php if (!empty($pluginNavItem['icon'])): ?><i class="<?php echo e((string) $pluginNavItem['icon']); ?> me-2" aria-hidden="true"></i><?php endif; ?><?php echo e((string) $pluginNavItem['label']); ?>
                    </a>
                <?php endforeach; ?>

                <form method="POST" action="logout.php" class="d-inline admin-logout-form" aria-label="Admin logout">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="btn btn-link nav-link text-white" title="Log out of admin"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Log out</button>
                </form>
            </div>
        </div>
    </div>
</nav>

<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success text-center mb-0 rounded-0" role="status"><?php echo e($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger text-center mb-0 rounded-0" role="alert"><?php echo e($msg); ?></div>
    <?php endif; ?>
<?php endif; ?>

<div class="container mt-4">
