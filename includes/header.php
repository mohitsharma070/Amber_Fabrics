<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?php echo e(isset($metaTitle) ? $metaTitle : 'Amber Fabrics'); ?></title>

<meta name="description" content="<?php echo e(isset($metaDescription) ? $metaDescription : 'Modern home textiles for everyday living. Amber Fabrics serves Indian customers and bulk buyers.'); ?>">
<meta name="keywords" content="<?php echo e(isset($metaKeywords) ? $metaKeywords : 'home textiles, bedsheets, towels, table covers, Amber Fabrics, ecommerce'); ?>">
<meta name="author" content="Amber Fabrics">
<meta property="og:title" content="<?php echo e(isset($metaTitle) ? $metaTitle : 'Amber Fabrics'); ?>">
<meta property="og:description" content="<?php echo e(isset($metaDescription) ? $metaDescription : 'Modern home textiles for everyday living. Amber Fabrics serves Indian customers and bulk buyers.'); ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo e(isset($metaUrl) ? $metaUrl : 'https://amberfabrics.com'); ?>">
<meta property="og:image" content="<?php echo e(isset($metaImage) ? $metaImage : 'images/fabrics/default.jpg'); ?>">

<!-- Favicons: Light/Dark theme support -->
<link rel="icon" type="image/svg+xml" href="/images/favicon-light.svg" media="(prefers-color-scheme: light)">
<link rel="icon" type="image/svg+xml" href="/images/favicon-dark.svg" media="(prefers-color-scheme: dark)">
<link rel="alternate icon" type="image/png" href="/images/favicon-light.svg">

<!-- Apple Touch Icon -->
<link rel="apple-touch-icon" href="/images/favicon-light.svg">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="/css/style.css?v=20260507h">

<meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

<script src="/js/script.js?v=20260507h" defer></script>

</head>

<body>

<?php 
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$siteSettings = get_site_settings();
$siteName = $siteSettings['site_name'];
$siteLogo = (string) ($siteSettings['branding_logo'] ?? '');
$siteLogo = $siteLogo !== '' ? '/' . ltrim($siteLogo, '/') : '/images/logo-brand-light.svg';
$headerCategories = [];
try {
    $headerCatStmt = $conn->prepare("SELECT name, slug FROM categories WHERE status = 'active' ORDER BY name ASC");
    $headerCatStmt->execute();
    $headerCategories = $headerCatStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $headerCategories = [];
}
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
                <button class="nav-drawer-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNavDrawer" aria-controls="mobileNavDrawer" aria-label="Open menu">
                    <span></span><span></span><span></span>
                </button>
                <a class="navbar-brand brand-mark text-white d-flex align-items-center m-0" href="/index.php">
                    <img src="<?php echo e($siteLogo); ?>" alt="<?php echo e($siteName); ?>" class="site-logo">
                </a>
            </div>

            <div class="site-header-right">
                <div class="dropdown d-none d-lg-inline-flex">
                    <button class="header-chip dropdown-toggle header-cat-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Shop Categories
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end header-cat-menu">
                        <?php if (!empty($headerCategories)): ?>
                            <?php foreach ($headerCategories as $cat): ?>
                                <li>
                                    <a class="dropdown-item" href="/catalog.php?category=<?php echo e($cat['slug']); ?>">
                                        <?php echo e($cat['name']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><span class="dropdown-item-text text-muted small">No categories available</span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <a class="header-icon-link position-relative <?php echo $currentPage === 'cart.php' ? 'active' : ''; ?>" href="/cart.php" title="Cart" aria-label="Cart">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                    <?php if ($cartCount > 0): ?><span class="cart-badge"><?php echo $cartCount; ?></span><?php endif; ?>
                </a>
                <?php if ($isLoggedIn): ?>
                    <a class="header-icon-link d-none d-lg-inline-flex <?php echo $currentPage === 'profile.php' ? 'active' : ''; ?>" href="/customer/profile.php" title="Account" aria-label="Account">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M14 14s-1-4-6-4-6 4-6 4 1 1 6 1 6-1 6-1Z"/></svg>
                    </a>
                <?php else: ?>
                    <a class="header-chip d-none d-lg-inline-flex" href="/customer/login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="site-header-nav d-none d-lg-flex">
            <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="/index.php">Home</a>
            <a class="nav-link <?php echo in_array($currentPage, ['catalog.php','fabric.php'], true) ? 'active' : ''; ?>" href="/catalog.php">Shop</a>
            <a class="nav-link <?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" href="/about.php">About</a>
            <a class="nav-link <?php echo in_array($currentPage, ['contact.php','thank-you.php'], true) ? 'active' : ''; ?>" href="/contact.php">Contact</a>
            <?php if ($isLoggedIn): ?>
                <a class="nav-link <?php echo in_array($currentPage, ['orders.php','order-view.php'], true) ? 'active' : ''; ?>" href="/customer/orders.php">Orders</a>
            <?php else: ?>
                <a class="nav-link <?php echo $currentPage === 'register.php' ? 'active' : ''; ?>" href="/customer/register.php">Register</a>
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
            <a class="nav-link <?php echo $currentPage === 'index.php' ? 'active' : ''; ?>" href="/index.php">Home</a>
            <a class="nav-link <?php echo in_array($currentPage, ['catalog.php','fabric.php'], true) ? 'active' : ''; ?>" href="/catalog.php">Shop</a>
            <a class="nav-link <?php echo $currentPage === 'about.php' ? 'active' : ''; ?>" href="/about.php">About</a>
            <a class="nav-link <?php echo in_array($currentPage, ['contact.php','thank-you.php'], true) ? 'active' : ''; ?>" href="/contact.php">Contact</a>
        </div>
        <div class="mobile-drawer-utility">
            <a class="drawer-utility-link position-relative" href="/cart.php">Cart <?php if ($cartCount > 0): ?><span class="cart-badge"><?php echo $cartCount; ?></span><?php endif; ?></a>
            <?php if ($isLoggedIn): ?>
                <a class="drawer-utility-link" href="/customer/orders.php">My Orders</a>
                <a class="drawer-utility-link" href="/customer/profile.php">Account</a>
            <?php else: ?>
                <a class="drawer-utility-link" href="/customer/login.php">Login</a>
                <a class="drawer-utility-link" href="/customer/register.php">Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (function_exists('flash')): ?>
    <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success text-center mb-0 rounded-0"><?php echo e($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger text-center mb-0 rounded-0"><?php echo e($msg); ?></div>
    <?php endif; ?>
<?php endif; ?>
