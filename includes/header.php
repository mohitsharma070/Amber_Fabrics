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
?>
<title><?php echo e(isset($metaTitle) ? $metaTitle : $siteNameForHead); ?></title>

<meta name="description" content="<?php echo e(isset($metaDescription) ? $metaDescription : $siteDescriptionForHead); ?>">
<meta name="keywords" content="<?php echo e(isset($metaKeywords) ? $metaKeywords : ('home textiles, ecommerce, ' . $siteNameForHead)); ?>">
<meta name="author" content="<?php echo e($siteNameForHead); ?>">
<meta property="og:title" content="<?php echo e(isset($metaTitle) ? $metaTitle : $siteNameForHead); ?>">
<meta property="og:description" content="<?php echo e(isset($metaDescription) ? $metaDescription : $siteDescriptionForHead); ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo e(isset($metaUrl) ? $metaUrl : $siteUrlForHead); ?>">
<meta property="og:image" content="<?php echo e(isset($metaImage) ? $metaImage : 'images/fabrics/default.jpg'); ?>">

<!-- Favicons: Light/Dark theme support -->
<link rel="icon" type="image/svg+xml" href="/images/favicon-light.svg" media="(prefers-color-scheme: light)">
<link rel="icon" type="image/svg+xml" href="/images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
<link rel="alternate icon" type="image/png" href="/images/favicon-light.svg">

<!-- Apple Touch Icon -->
<link rel="apple-touch-icon" href="/images/favicon-light.svg">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="/css/style.css?v=20260809g">

<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

<script src="/js/script.js?v=20260809c" defer></script>

<?php do_action('page.head', [
    'page' => basename($_SERVER['PHP_SELF'] ?? ''),
    'title' => isset($metaTitle) ? (string) $metaTitle : $siteNameForHead,
]); ?>

</head>

<body>

<?php 
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$siteSettings = SiteSettingsService::get();
$siteName = $siteSettings['site_name'];
$siteLogo = (string) ($siteSettings['branding_logo'] ?? '');
$siteLogo = $siteLogo !== '' ? '/' . ltrim($siteLogo, '/') : '/images/logo-brand-light.svg';
$headerCategories = storefront_categories_fetch($conn);
$cartCount = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cartCount = count($_SESSION['cart']);
}
$isLoggedIn = function_exists('is_customer_logged_in') && is_customer_logged_in();
?>
<nav class="site-navbar" aria-label="Primary">
    <div class="container">
        <div class="site-header-main">
            <div class="site-header-left">
                <a class="navbar-brand brand-mark text-white d-flex align-items-center m-0" href="/">
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="site-logo">
                </a>
            </div>

            <div class="site-header-right">
                <button type="button" class="nav-drawer-btn d-none d-md-inline-flex d-lg-none" data-mobile-nav-menu aria-controls="mobileNavDrawer" aria-expanded="false" aria-label="Open navigation menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="dropdown d-none d-lg-inline-flex">
                    <button class="header-chip dropdown-toggle header-cat-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Shop Categories
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end header-cat-menu">
                        <?php if (!empty($headerCategories)): ?>
                            <?php foreach ($headerCategories as $cat): ?>
                                <li>
                                    <a class="dropdown-item" href="/catalog?category=<?php echo e($cat['slug']); ?>">
                                        <?php echo e($cat['name']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><span class="dropdown-item-text text-muted small">No categories available</span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <a class="header-icon-link position-relative d-none d-lg-inline-flex <?php echo $currentPage === 'cart.php' ? 'active' : ''; ?>" href="/cart" title="Cart" aria-label="Cart">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                    <?php if ($cartCount > 0): ?><span class="cart-badge"><?php echo $cartCount; ?></span><?php endif; ?>
                </a>
                <?php if ($isLoggedIn): ?>
                    <a class="header-icon-link d-none d-lg-inline-flex <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" href="/customer/profile" title="Account" aria-label="Account">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M14 14s-1-4-6-4-6 4-6 4 1 1 6 1 6-1 6-1Z"/></svg>
                    </a>
                    <form method="POST" action="/customer/logout.php" class="d-none d-lg-inline-flex">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="header-icon-link" title="Log out" aria-label="Log out">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 0 .5.5h2a.5.5 0 0 0 .5-.5v-9a.5.5 0 0 0-.5-.5h-2a.5.5 0 0 0 0 1H12v8h-1.5a.5.5 0 0 0-.5.5"/><path fill-rule="evenodd" d="M4.146 8.354a.5.5 0 0 1 0-.708l2-2a.5.5 0 1 1 .708.708L5.707 7.5H10.5a.5.5 0 0 1 0 1H5.707l1.147 1.146a.5.5 0 0 1-.708.708zM1.5 1A1.5 1.5 0 0 0 0 2.5v11A1.5 1.5 0 0 0 1.5 15h6A1.5 1.5 0 0 0 9 13.5v-1a.5.5 0 0 0-1 0v1a.5.5 0 0 1-.5.5h-6a.5.5 0 0 1-.5-.5v-11a.5.5 0 0 1 .5-.5h6a.5.5 0 0 1 .5.5v1a.5.5 0 0 0 1 0v-1A1.5 1.5 0 0 0 7.5 1z"/></svg>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="header-chip d-none d-lg-inline-flex" href="/customer/login">Login</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="site-header-nav d-none d-lg-flex">
            <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="/">Home</a>
            <a class="nav-link <?php echo in_array($currentPage, ['catalog.php','fabric.php'], true) ? 'active' : ''; ?>" href="/catalog">Shop</a>
            <a class="nav-link <?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" href="/about">About</a>
            <a class="nav-link <?php echo in_array($currentPage, ['contact.php','thank-you.php'], true) ? 'active' : ''; ?>" href="/contact">Contact</a>
            <?php if ($isLoggedIn): ?>
                <a class="nav-link <?php echo in_array($currentPage, ['orders.php','order-view.php'], true) ? 'active' : ''; ?>" href="/customer/orders">Orders</a>
            <?php else: ?>
                <a class="nav-link <?php echo $currentPage === 'register.php' ? 'active' : ''; ?>" href="/customer/register">Register</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start site-nav-drawer" tabindex="-1" id="mobileNavDrawer" aria-labelledby="mobileNavDrawerLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="mobileNavDrawerLabel"><?php echo e($siteName); ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="mobile-drawer-links">
            <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="/">Home</a>
            <a class="nav-link <?php echo in_array($currentPage, ['catalog.php','fabric.php'], true) ? 'active' : ''; ?>" href="/catalog">Shop</a>
            <a class="nav-link <?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" href="/about">About</a>
            <a class="nav-link <?php echo in_array($currentPage, ['contact.php','thank-you.php'], true) ? 'active' : ''; ?>" href="/contact">Contact</a>
        </div>
        <div class="mobile-drawer-utility">
            <a class="drawer-utility-link position-relative" href="/cart">Cart <?php if ($cartCount > 0): ?><span class="cart-badge"><?php echo $cartCount; ?></span><?php endif; ?></a>
            <?php if ($isLoggedIn): ?>
                <a class="drawer-utility-link" href="/customer/orders">My Orders</a>
                <a class="drawer-utility-link" href="/customer/profile">Account</a>
                <form method="POST" action="/customer/logout.php">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="drawer-utility-link border-0 w-100 text-start">Log out</button>
                </form>
            <?php else: ?>
                <a class="drawer-utility-link" href="/customer/login">Login</a>
                <a class="drawer-utility-link" href="/customer/register">Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success text-center mb-0 rounded-0" role="status"><?php echo e($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger text-center mb-0 rounded-0" role="alert"><?php echo e($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('warning')): ?>
        <div class="alert alert-warning text-center mb-0 rounded-0" role="status"><?php echo e($msg); ?></div>
    <?php endif; ?>
<?php endif; ?>
