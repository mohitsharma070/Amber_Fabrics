<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

<?php
$siteSettingsForHead = SiteSettingsService::get();
$siteNameForHead = site_name();
$siteDescriptionForHead = SiteContext::description();
$siteUrlForHead = app_url();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$uiPage = preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($currentPage, PATHINFO_FILENAME))) ?: 'page';
?>
<title><?php echo e(isset($metaTitle) ? $metaTitle : $siteNameForHead); ?></title>
<meta name="description" content="<?php echo e(isset($metaDescription) ? $metaDescription : $siteDescriptionForHead); ?>">
<meta name="keywords" content="<?php echo e(isset($metaKeywords) ? $metaKeywords : ('home textiles, ecommerce, ' . $siteNameForHead)); ?>">
<meta name="author" content="<?php echo e($siteNameForHead); ?>">
<meta property="og:title" content="<?php echo e(isset($metaTitle) ? $metaTitle : $siteNameForHead); ?>">
<meta property="og:description" content="<?php echo e(isset($metaDescription) ? $metaDescription : $siteDescriptionForHead); ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo e(isset($metaUrl) ? $metaUrl : $siteUrlForHead); ?>">
<meta property="og:image" content="<?php echo e(isset($metaImage) ? $metaImage : '/images/fabrics/default.jpg'); ?>">
<link rel="icon" type="image/svg+xml" href="/images/favicon-light.svg" media="(prefers-color-scheme: light)">
<link rel="icon" type="image/svg+xml" href="/images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
<link rel="alternate icon" type="image/svg+xml" href="/images/favicon-light.svg">
<link rel="apple-touch-icon" href="/images/favicon-light.svg">
<link rel="stylesheet" href="<?php echo e(ui_asset('/css/foundation.css')); ?>">
<link rel="stylesheet" href="<?php echo e(ui_asset('/css/storefront.css')); ?>">
<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
<script src="<?php echo e(ui_asset('/js/app.js')); ?>" defer></script>
<script src="<?php echo e(ui_asset('/js/commerce.js')); ?>" defer></script>
<?php do_action('page.head', ['page' => $currentPage, 'title' => isset($metaTitle) ? (string) $metaTitle : $siteNameForHead]); ?>
</head>

<body data-ui-area="storefront" data-ui-page="<?php echo e($uiPage); ?>">
<a class="ui-skip-link" href="#main-content">Skip to main content</a>
<?php
$siteSettings = SiteSettingsService::get();
$siteName = $siteSettings['site_name'];
$siteLogo = (string) ($siteSettings['branding_logo'] ?? '');
$siteLogo = $siteLogo !== '' ? '/' . ltrim($siteLogo, '/') : '/images/logo-brand-light.svg';
$headerCategories = storefront_categories_fetch($conn);
$cartCount = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
$isLoggedIn = function_exists('is_customer_logged_in') && is_customer_logged_in();
?>
<header class="site-header">
    <nav class="l-container" aria-label="Primary">
        <div class="site-header__main">
            <a class="site-header__brand" href="/"><img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="site-header__logo"></a>
            <div class="site-header__actions">
                <button class="site-header__icon site-header__drawer-toggle" type="button" data-ui-drawer-open="siteNavDrawer" aria-controls="siteNavDrawer" aria-expanded="false" aria-label="Open navigation menu"><?php echo ui_icon('sliders'); ?></button>
                <div class="site-header__menu site-header__desktop-action">
                    <button class="site-header__chip" type="button" data-ui-menu-toggle="categoryMenu" aria-controls="categoryMenu" aria-expanded="false">Shop Categories</button>
                    <ul class="ui-menu" id="categoryMenu" data-ui-menu hidden>
                        <?php if ($headerCategories !== []): ?>
                            <?php foreach ($headerCategories as $cat): ?><li><a href="/catalog?category=<?php echo e($cat['slug']); ?>"><?php echo e($cat['name']); ?></a></li><?php endforeach; ?>
                        <?php else: ?><li><span class="u-text-muted u-text-small">No categories available</span></li><?php endif; ?>
                    </ul>
                </div>
                <a class="site-header__icon site-header__desktop-action" href="/cart" aria-label="Cart" data-cart-link><?php echo ui_icon('box'); ?><?php if ($cartCount > 0): ?><span class="cart-count" data-cart-badge><?php echo $cartCount; ?></span><?php endif; ?></a>
                <?php if ($isLoggedIn): ?>
                    <a class="site-header__icon site-header__desktop-action" href="/customer/profile" aria-label="Account"><?php echo ui_icon('person-gear'); ?></a>
                    <form method="POST" action="/customer/logout.php" class="site-header__desktop-action"><?php echo csrf_field(); ?><button type="submit" class="site-header__icon" aria-label="Log out"><?php echo ui_icon('box-arrow-right'); ?></button></form>
                <?php else: ?><a class="site-header__chip site-header__desktop-action" href="/customer/login">Login</a><?php endif; ?>
            </div>
        </div>
        <div class="site-header__links">
            <a class="site-header__link" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?> href="/">Home</a>
            <a class="site-header__link" <?php echo in_array($currentPage, ['catalog.php', 'fabric.php'], true) ? 'aria-current="page"' : ''; ?> href="/catalog">Shop</a>
            <a class="site-header__link" <?php echo $currentPage === 'about.php' ? 'aria-current="page"' : ''; ?> href="/about">About</a>
            <a class="site-header__link" <?php echo in_array($currentPage, ['contact.php', 'thank-you.php'], true) ? 'aria-current="page"' : ''; ?> href="/contact">Contact</a>
            <?php if ($isLoggedIn): ?><a class="site-header__link" <?php echo in_array($currentPage, ['orders.php', 'order-view.php'], true) ? 'aria-current="page"' : ''; ?> href="/customer/orders">Orders</a><?php else: ?><a class="site-header__link" <?php echo $currentPage === 'register.php' ? 'aria-current="page"' : ''; ?> href="/customer/register">Register</a><?php endif; ?>
        </div>
    </nav>
</header>

<aside class="ui-drawer site-drawer" id="siteNavDrawer" data-ui-drawer aria-hidden="true" tabindex="-1">
    <div class="ui-drawer__header"><h2><?php echo e($siteName); ?></h2><button type="button" class="ui-button ui-button--secondary ui-button--icon" data-ui-drawer-close aria-label="Close navigation"><?php echo ui_icon('x-lg'); ?></button></div>
    <div class="ui-drawer__body site-drawer__links">
        <a <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?> href="/">Home</a>
        <a <?php echo in_array($currentPage, ['catalog.php', 'fabric.php'], true) ? 'aria-current="page"' : ''; ?> href="/catalog">Shop</a>
        <a <?php echo $currentPage === 'about.php' ? 'aria-current="page"' : ''; ?> href="/about">About</a>
        <a <?php echo in_array($currentPage, ['contact.php', 'thank-you.php'], true) ? 'aria-current="page"' : ''; ?> href="/contact">Contact</a>
        <hr>
        <a href="/cart" data-cart-link>Cart <?php if ($cartCount > 0): ?><span class="cart-count" data-cart-badge><?php echo $cartCount; ?></span><?php endif; ?></a>
        <?php if ($isLoggedIn): ?>
            <a href="/customer/orders">My Orders</a><a href="/customer/profile">Account</a>
            <form method="POST" action="/customer/logout.php"><?php echo csrf_field(); ?><button type="submit">Log out</button></form>
        <?php else: ?><a href="/customer/login">Login</a><a href="/customer/register">Register</a><?php endif; ?>
    </div>
</aside>

<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?><div class="ui-alert ui-alert--success site-flash" role="status"><?php echo e($msg); ?></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="ui-alert ui-alert--error site-flash" role="alert"><?php echo e($msg); ?></div><?php endif; ?>
    <?php if ($msg = flash('warning')): ?><div class="ui-alert ui-alert--warning site-flash" role="status"><?php echo e($msg); ?></div><?php endif; ?>
<?php endif; ?>
<main id="main-content" tabindex="-1">
