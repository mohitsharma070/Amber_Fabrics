</main>

<footer class="site-footer">
    <?php
    $footerSiteName = site_name();
    $footerContactEmail = contact_email();
    $footerSettings = SiteSettingsService::get();
    $footerDescription = (string) ($footerSettings['footer_description'] ?? SiteContext::description());
    $footerSupportTitle = (string) ($footerSettings['footer_support_title'] ?? 'Support');
    $footerSupportHours = (string) ($footerSettings['footer_support_hours'] ?? 'Mon - Sat: 9:00 AM to 7:00 PM');
    $footerSupportCta = (string) ($footerSettings['footer_support_contact_cta'] ?? 'Contact Team');
    $footerExploreTitle = (string) ($footerSettings['footer_explore_title'] ?? 'Explore');
    $footerExploreShop = (string) ($footerSettings['footer_explore_shop_cta'] ?? 'Shop Collection');
    $footerExploreCategories = (string) ($footerSettings['footer_explore_categories_cta'] ?? 'Categories');
    $footerExploreInquiry = (string) ($footerSettings['footer_explore_inquiry_cta'] ?? 'International / Bulk Inquiry');
    $footerExploreFaq = (string) ($footerSettings['footer_explore_faq_cta'] ?? 'FAQ');
    $footerPoliciesTitle = (string) ($footerSettings['footer_policies_title'] ?? 'Policies');
    $footerPolicyShipping = (string) ($footerSettings['footer_policy_shipping_cta'] ?? 'Shipping Policy');
    $footerPolicyReturn = (string) ($footerSettings['footer_policy_return_cta'] ?? 'Return Policy');
    $footerPolicyPrivacy = (string) ($footerSettings['footer_policy_privacy_cta'] ?? 'Privacy Policy');
    $footerPolicyTerms = (string) ($footerSettings['footer_policy_terms_cta'] ?? 'Terms & Conditions');
    $footerPolicySizeGuide = (string) ($footerSettings['footer_policy_size_guide_cta'] ?? 'Size & Fabric Guide');
    $footerPolicyInternational = (string) ($footerSettings['footer_policy_international_cta'] ?? 'International Orders Policy');
    $footerBottomTagline = (string) ($footerSettings['footer_bottom_tagline'] ?? 'Built for fast, reliable ecommerce growth.');
    ?>
    <div class="l-container">
        <div class="site-footer__grid">
            <section class="site-footer__column"><h2><?php echo e($footerSiteName); ?></h2><p><?php echo e($footerDescription); ?></p></section>
            <section class="site-footer__column">
                <h3><?php echo e($footerSupportTitle); ?></h3>
                <?php if ($footerContactEmail !== ''): ?><a href="mailto:<?php echo e($footerContactEmail); ?>"><?php echo e($footerContactEmail); ?></a><?php endif; ?>
                <span><?php echo e($footerSupportHours); ?></span><a href="/contact"><?php echo e($footerSupportCta); ?></a>
            </section>
            <section class="site-footer__column">
                <h3><?php echo e($footerExploreTitle); ?></h3>
                <a href="/catalog"><?php echo e($footerExploreShop); ?></a><a href="/#catSlider"><?php echo e($footerExploreCategories); ?></a><a href="/international-buyers"><?php echo e($footerExploreInquiry); ?></a><a href="/faq"><?php echo e($footerExploreFaq); ?></a>
            </section>
            <section class="site-footer__column">
                <h3><?php echo e($footerPoliciesTitle); ?></h3>
                <a href="/shipping-policy"><?php echo e($footerPolicyShipping); ?></a><a href="/return-policy"><?php echo e($footerPolicyReturn); ?></a><a href="/privacy-policy"><?php echo e($footerPolicyPrivacy); ?></a><a href="/terms"><?php echo e($footerPolicyTerms); ?></a><a href="/size-guide"><?php echo e($footerPolicySizeGuide); ?></a><a href="/international-orders-policy"><?php echo e($footerPolicyInternational); ?></a>
            </section>
        </div>
        <div class="site-footer__bottom"><p>&copy; <?php echo date('Y'); ?> <?php echo e($footerSiteName); ?>. <?php echo e($footerBottomTagline); ?></p></div>
    </div>
</footer>

<?php $showMobileBottomNav = !in_array($currentPage ?? '', ['login.php'], true); ?>
<?php if ($showMobileBottomNav): ?>
<nav class="mobile-nav" aria-label="Mobile bottom navigation">
    <a class="mobile-nav__item" <?php echo $currentPage === 'index.php' ? 'aria-current="page"' : ''; ?> href="/"><?php echo ui_icon('speedometer2'); ?><span>Home</span></a>
    <a class="mobile-nav__item" <?php echo in_array($currentPage, ['catalog.php', 'fabric.php'], true) ? 'aria-current="page"' : ''; ?> href="/catalog"><?php echo ui_icon('box'); ?><span>Shop</span></a>
    <a class="mobile-nav__item" <?php echo $currentPage === 'cart.php' ? 'aria-current="page"' : ''; ?> href="/cart" data-cart-link><?php echo ui_icon('cash-stack'); ?><?php if (($cartCount ?? 0) > 0): ?><span class="cart-count" data-cart-badge><?php echo (int) $cartCount; ?></span><?php endif; ?><span>Cart</span></a>
    <?php if ($isLoggedIn): ?><a class="mobile-nav__item" <?php echo in_array($currentPage, ['orders.php', 'order-view.php'], true) ? 'aria-current="page"' : ''; ?> href="/customer/orders"><?php echo ui_icon('receipt'); ?><span>Orders</span></a><?php else: ?><a class="mobile-nav__item" <?php echo in_array($currentPage, ['login.php', 'register.php'], true) ? 'aria-current="page"' : ''; ?> href="/customer/login"><?php echo ui_icon('person-gear'); ?><span>Account</span></a><?php endif; ?>
    <button type="button" class="mobile-nav__item" data-ui-drawer-open="siteNavDrawer" aria-controls="siteNavDrawer" aria-expanded="false"><?php echo ui_icon('sliders'); ?><span>Menu</span></button>
</nav>
<?php endif; ?>

<button type="button" class="ui-button ui-button--navy ui-button--icon go-top" data-ui-go-top aria-label="Go to top"><?php echo ui_icon('arrow-counterclockwise'); ?></button>
<?php $marketingConsentStatus = function_exists('marketing_consent_status') ? marketing_consent_status() : 'unknown'; ?>
<aside class="cookie-banner" data-ui-cookie-consent data-consent-status="<?php echo e($marketingConsentStatus); ?>" <?php echo $marketingConsentStatus === 'unknown' ? '' : 'hidden'; ?>>
    <div class="cookie-banner__panel">
        <div class="l-stack u-gap-2"><h2>Cookie Preferences</h2><p class="u-text-muted u-text-small">We use marketing cookies for Meta Pixel, Meta CAPI, Google Analytics, and UTM attribution only after your consent. Review our <a href="/privacy-policy">Privacy Policy</a>.</p></div>
        <div class="l-cluster u-nowrap"><button type="button" class="ui-button ui-button--secondary ui-button--small" data-consent-choice="reject">Reject</button><button type="button" class="ui-button ui-button--navy ui-button--small" data-consent-choice="accept">Accept</button></div>
    </div>
</aside>
<?php require dirname(__DIR__, 2) . '/partials/interaction-layer-v2.php'; ?>
<?php do_action('page.footer', ['page' => basename($_SERVER['PHP_SELF'] ?? '')]); ?>
</body>
</html>
