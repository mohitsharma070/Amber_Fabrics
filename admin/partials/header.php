<?php
require_once __DIR__ . '/../../includes/init.php';
require_admin();

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$uiPage = pathinfo($currentPage, PATHINFO_FILENAME) ?: 'admin';
$siteSettings = SiteSettingsService::get();
$siteName = (string) $siteSettings['site_name'];
$siteLogo = trim((string) ($siteSettings['branding_logo'] ?? ''));
$siteLogo = $siteLogo !== '' ? '/' . ltrim($siteLogo, '/') : '/images/logo-brand-light.svg';
$isRefundQueue = $currentPage === 'orders.php' && (string) ($_GET['refund_queue'] ?? '') === '1';
$isCatalogNav = in_array($currentPage, ['fabrics.php', 'add-fabric.php', 'edit-fabric.php', 'product-import.php', 'categories.php', 'about-media.php'], true);
$isOrdersNav = in_array($currentPage, ['orders.php', 'order-view.php', 'returns.php'], true) || $isRefundQueue;
$isCustomersNav = in_array($currentPage, ['customers.php', 'customer-view.php'], true);
$isMarketingNav = in_array($currentPage, ['coupons.php', 'reviews.php', 'export-inquiries.php', 'inquiries.php', 'inquiry-view.php'], true);
$isOperationsNav = in_array($currentPage, ['shipping-rates.php', 'expenses.php', 'bigship-service.php'], true);
$isSettingsNav = $currentPage === 'settings.php';
$currentRole = (string) ($_SESSION['admin_role'] ?? 'viewer');
$adminCanMutateCurrentPage = admin_can(admin_route_capability($currentPage, 'POST'), $currentRole);
$adminCanManageSettings = admin_can('settings.manage', $currentRole);
$adminCanManageAdmins = admin_can('admins.manage', $currentRole);
// Order, customer and return reads expose customer PII and need a distinct
// capability, so hide those entries from roles that would only get a 403.
$adminCanViewPii = admin_can('admin.view.pii', $currentRole);
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
    if ((int) ($reviewTableStmt->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
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
        return is_string($scheme) && $scheme !== '' && !in_array(strtolower($scheme), ['http', 'https'], true) ? '' : $url;
    }
}

if (!function_exists('admin_nav_safe_icon')) {
    function admin_nav_safe_icon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        return preg_match('/^[a-z0-9-]{1,40}$/', $icon) ? $icon : '';
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
    <title><?php echo e(isset($metaTitle) ? (string) $metaTitle : ($siteName . ' Admin')); ?></title>
    <meta name="description" content="<?php echo e(isset($metaDescription) ? (string) $metaDescription : ('Admin panel for ' . $siteName . '.')); ?>">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/svg+xml" href="/images/favicon-light.svg" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/svg+xml" href="/images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
    <link rel="stylesheet" href="<?php echo e(ui_asset('/css/foundation.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(ui_asset('/css/admin.css')); ?>">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <script src="<?php echo e(ui_asset('/js/app.js')); ?>" defer></script>
    <script src="<?php echo e(ui_asset('/js/admin.js')); ?>" defer></script>
</head>
<body class="admin-shell<?php echo $adminCanMutateCurrentPage ? '' : ' admin-read-only'; ?>" data-ui-area="admin" data-ui-page="<?php echo e($uiPage); ?>" data-admin-can-mutate="<?php echo $adminCanMutateCurrentPage ? '1' : '0'; ?>">
<a class="ui-skip-link" href="#admin-main">Skip to main content</a>
<header class="admin-header">
    <div class="l-container admin-header__inner">
        <a class="admin-brand" href="dashboard.php"><img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="admin-logo"></a>
        <button class="admin-nav-toggle" type="button" data-admin-nav-toggle aria-controls="adminNav" aria-expanded="false" aria-label="Toggle admin navigation"><?php echo ui_icon('sliders'); ?></button>
        <nav class="admin-nav" id="adminNav" data-admin-nav aria-label="Administration">
            <a class="admin-nav__link<?php echo $currentPage === 'dashboard.php' ? ' is-active' : ''; ?>" href="dashboard.php"><?php echo ui_icon('speedometer2'); ?><span>Dashboard</span></a>
            <div class="admin-nav__group">
                <button class="admin-nav__link<?php echo $isCatalogNav ? ' is-active' : ''; ?>" type="button" data-ui-menu-toggle="adminCatalogMenu" aria-controls="adminCatalogMenu" aria-expanded="false"><?php echo ui_icon('box-seam'); ?><span>Catalog</span></button>
                <ul class="ui-menu admin-nav__menu" id="adminCatalogMenu" data-ui-menu hidden>
                    <li><a class="<?php echo in_array($currentPage, ['fabrics.php', 'add-fabric.php', 'edit-fabric.php'], true) ? 'is-active' : ''; ?>" href="fabrics.php"><?php echo ui_icon('box'); ?>Products</a></li>
                    <li><a class="<?php echo $currentPage === 'categories.php' ? 'is-active' : ''; ?>" href="categories.php"><?php echo ui_icon('tags'); ?>Categories</a></li>
                    <li><a class="<?php echo $currentPage === 'about-media.php' ? 'is-active' : ''; ?>" href="about-media.php"><?php echo ui_icon('images'); ?>About Media</a></li>
                </ul>
            </div>
            <?php if ($adminCanViewPii): ?>
            <div class="admin-nav__group">
                <button class="admin-nav__link<?php echo $isOrdersNav ? ' is-active' : ''; ?>" type="button" data-ui-menu-toggle="adminOrdersMenu" aria-controls="adminOrdersMenu" aria-expanded="false"><?php echo ui_icon('receipt'); ?><span>Orders</span></button>
                <ul class="ui-menu admin-nav__menu" id="adminOrdersMenu" data-ui-menu hidden>
                    <li><a class="<?php echo in_array($currentPage, ['orders.php', 'order-view.php'], true) && !$isRefundQueue ? 'is-active' : ''; ?>" href="orders.php"><?php echo ui_icon('receipt-cutoff'); ?>Orders</a></li>
                    <li><a class="<?php echo $currentPage === 'returns.php' ? 'is-active' : ''; ?>" href="returns.php"><?php echo ui_icon('arrow-counterclockwise'); ?>Returns</a></li>
                    <li><a class="<?php echo $isRefundQueue ? 'is-active' : ''; ?>" href="orders.php?refund_queue=1"><?php echo ui_icon('cash-stack'); ?>Refund Queue<?php if ($pendingRefunds > 0): ?><span class="ui-badge ui-badge--error"><?php echo $pendingRefunds; ?></span><?php endif; ?></a></li>
                </ul>
            </div>
            <a class="admin-nav__link<?php echo $isCustomersNav ? ' is-active' : ''; ?>" href="customers.php"><?php echo ui_icon('people'); ?><span>Customers</span></a>
            <?php endif; ?>
            <div class="admin-nav__group">
                <button class="admin-nav__link<?php echo $isMarketingNav ? ' is-active' : ''; ?>" type="button" data-ui-menu-toggle="adminMarketingMenu" aria-controls="adminMarketingMenu" aria-expanded="false"><?php echo ui_icon('megaphone'); ?><span>Marketing</span></button>
                <ul class="ui-menu admin-nav__menu" id="adminMarketingMenu" data-ui-menu hidden>
                    <li><a class="<?php echo $currentPage === 'coupons.php' ? 'is-active' : ''; ?>" href="coupons.php"><?php echo ui_icon('ticket-perforated'); ?>Coupons</a></li>
                    <li><a class="<?php echo $currentPage === 'reviews.php' ? 'is-active' : ''; ?>" href="reviews.php"><?php echo ui_icon('chat-square-text'); ?>Reviews<?php if ($pendingReviews > 0): ?><span class="ui-badge ui-badge--warning"><?php echo $pendingReviews; ?></span><?php endif; ?></a></li>
                    <li><a class="<?php echo $currentPage === 'export-inquiries.php' ? 'is-active' : ''; ?>" href="export-inquiries.php"><?php echo ui_icon('globe2'); ?>Export Inquiries</a></li>
                </ul>
            </div>
            <div class="admin-nav__group">
                <button class="admin-nav__link<?php echo $isOperationsNav ? ' is-active' : ''; ?>" type="button" data-ui-menu-toggle="adminOperationsMenu" aria-controls="adminOperationsMenu" aria-expanded="false"><?php echo ui_icon('gear-wide-connected'); ?><span>Operations</span></button>
                <ul class="ui-menu admin-nav__menu" id="adminOperationsMenu" data-ui-menu hidden>
                    <li><a class="<?php echo $currentPage === 'shipping-rates.php' ? 'is-active' : ''; ?>" href="shipping-rates.php"><?php echo ui_icon('truck'); ?>Shipping Rates</a></li>
                    <li><a class="<?php echo $currentPage === 'expenses.php' ? 'is-active' : ''; ?>" href="expenses.php"><?php echo ui_icon('wallet2'); ?>Expenses</a></li>
                </ul>
            </div>
            <a class="admin-nav__link<?php echo $currentPage === 'operations.php' ? ' is-active' : ''; ?>" href="operations.php"><?php echo ui_icon('activity'); ?><span>Operations Center</span></a>
            <?php if ($adminCanManageAdmins): ?><a class="admin-nav__link<?php echo $currentPage === 'admins.php' ? ' is-active' : ''; ?>" href="admins.php"><?php echo ui_icon('person-gear'); ?><span>Administrators</span></a><?php endif; ?>
            <?php if ($adminCanManageSettings): ?><a class="admin-nav__link<?php echo $isSettingsNav ? ' is-active' : ''; ?>" href="settings.php"><?php echo ui_icon('sliders'); ?><span>Settings</span></a><?php endif; ?>
            <?php foreach ($pluginNavItems as $pluginNavItem): ?>
                <a class="admin-nav__link<?php echo !empty($pluginNavItem['active']) ? ' is-active' : ''; ?>" href="<?php echo e((string) $pluginNavItem['url']); ?>"><?php if ($pluginNavItem['icon'] !== ''): ?><?php echo ui_icon((string) $pluginNavItem['icon']); ?><?php endif; ?><span><?php echo e((string) $pluginNavItem['label']); ?></span></a>
            <?php endforeach; ?>
            <form method="POST" action="logout.php" class="admin-logout-form" aria-label="Admin logout"><?php echo csrf_field(); ?><button type="submit" class="admin-nav__link" title="Log out of admin"><?php echo ui_icon('box-arrow-right'); ?><span>Log out</span></button></form>
        </nav>
    </div>
</header>
<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?><div class="ui-alert ui-alert--success admin-flash" role="status"><?php echo e($msg); ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="ui-alert ui-alert--error admin-flash" role="alert"><?php echo e($msg); ?></div><?php endif; ?>
<?php endif; ?>
<main id="admin-main" class="admin-main" tabindex="-1"><div class="l-container">
