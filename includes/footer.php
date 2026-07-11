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
    <div class="container">
        <div class="footer-desktop d-none d-md-grid">
            <div>
                <h5><?php echo e($footerSiteName); ?></h5>
                <p><?php echo e($footerDescription); ?></p>
            </div>
            <div>
                <h5><?php echo e($footerSupportTitle); ?></h5>
                <?php if ($footerContactEmail !== ''): ?><p>Email: <?php echo e($footerContactEmail); ?></p><?php endif; ?>
                <p><?php echo e($footerSupportHours); ?></p>
                <p><a class="text-decoration-none text-light" href="/contact.php"><?php echo e($footerSupportCta); ?></a></p>
            </div>
            <div>
                <h5><?php echo e($footerExploreTitle); ?></h5>
                <p><a class="text-decoration-none text-light" href="/catalog.php"><?php echo e($footerExploreShop); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/index.php#catSlider"><?php echo e($footerExploreCategories); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/international-buyers.php"><?php echo e($footerExploreInquiry); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/faq.php"><?php echo e($footerExploreFaq); ?></a></p>
            </div>
            <div>
                <h5><?php echo e($footerPoliciesTitle); ?></h5>
                <p><a class="text-decoration-none text-light" href="/shipping-policy.php"><?php echo e($footerPolicyShipping); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/return-policy.php"><?php echo e($footerPolicyReturn); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/privacy-policy.php"><?php echo e($footerPolicyPrivacy); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/terms.php"><?php echo e($footerPolicyTerms); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/size-guide.php"><?php echo e($footerPolicySizeGuide); ?></a></p>
                <p><a class="text-decoration-none text-light" href="/international-orders-policy.php"><?php echo e($footerPolicyInternational); ?></a></p>
            </div>
        </div>

        <div class="footer-mobile d-md-none">
            <div class="footer-brand">
                <h5><?php echo e($footerSiteName); ?></h5>
                <p><?php echo e($footerDescription); ?></p>
            </div>
            <div class="accordion footer-accordion" id="footerAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerSupport" aria-expanded="false" aria-controls="footerSupport"><?php echo e($footerSupportTitle); ?></button>
                    </h2>
                    <div id="footerSupport" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <?php if ($footerContactEmail !== ''): ?><a href="mailto:<?php echo e($footerContactEmail); ?>"><?php echo e($footerContactEmail); ?></a><?php endif; ?>
                            <a href="/contact.php"><?php echo e($footerSupportCta); ?></a>
                            <span><?php echo e($footerSupportHours); ?></span>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerExplore" aria-expanded="false" aria-controls="footerExplore"><?php echo e($footerExploreTitle); ?></button>
                    </h2>
                    <div id="footerExplore" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <a href="/catalog.php"><?php echo e($footerExploreShop); ?></a>
                            <a href="/index.php#catSlider"><?php echo e($footerExploreCategories); ?></a>
                            <a href="/international-buyers.php"><?php echo e($footerExploreInquiry); ?></a>
                            <a href="/faq.php"><?php echo e($footerExploreFaq); ?></a>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerPolicies" aria-expanded="false" aria-controls="footerPolicies"><?php echo e($footerPoliciesTitle); ?></button>
                    </h2>
                    <div id="footerPolicies" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <a href="/shipping-policy.php"><?php echo e($footerPolicyShipping); ?></a>
                            <a href="/return-policy.php"><?php echo e($footerPolicyReturn); ?></a>
                            <a href="/privacy-policy.php"><?php echo e($footerPolicyPrivacy); ?></a>
                            <a href="/terms.php"><?php echo e($footerPolicyTerms); ?></a>
                            <a href="/size-guide.php"><?php echo e($footerPolicySizeGuide); ?></a>
                            <a href="/international-orders-policy.php"><?php echo e($footerPolicyInternational); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php do_action('footer.newsletter', [
            'page' => basename($_SERVER['PHP_SELF'] ?? ''),
        ]); ?>

        <div class="footer-bottom">
            <p class="mb-0 text-center">&copy; <?php echo date('Y'); ?> <?php echo e($footerSiteName); ?>. <?php echo e($footerBottomTagline); ?></p>
        </div>
    </div>
</footer>

<?php
$showMobileBottomNav = !in_array($currentPage ?? '', ['checkout.php', 'login.php'], true);
?>
<?php if ($showMobileBottomNav): ?>
<nav class="mobile-bottom-nav d-md-none" aria-label="Mobile bottom navigation">
    <a class="mobile-bottom-nav__item <?php echo $currentPage === 'index.php' ? 'is-active' : ''; ?>" href="/index.php" aria-label="Home">
        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8.354 1.146a.5.5 0 0 0-.708 0l-6 6A.5.5 0 0 0 2 8h1v6a1 1 0 0 0 1 1h3.5a.5.5 0 0 0 .5-.5V11h1v3.5a.5.5 0 0 0 .5.5H13a1 1 0 0 0 1-1V8h1a.5.5 0 0 0 .354-.854z"/></svg>
        <span>Home</span>
    </a>
    <a class="mobile-bottom-nav__item <?php echo in_array($currentPage, ['catalog.php', 'fabric.php'], true) ? 'is-active' : ''; ?>" href="/catalog.php" aria-label="Shop">
        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M2.97 1.35A1 1 0 0 1 3.73 1h8.54a1 1 0 0 1 .76.35l2.609 3.044A1.5 1.5 0 0 1 16 5.37v.255a2.375 2.375 0 0 1-4.25 1.458A2.371 2.371 0 0 1 9.875 8 2.37 2.37 0 0 1 8 7.083 2.37 2.37 0 0 1 6.125 8a2.37 2.37 0 0 1-1.875-.917A2.375 2.375 0 0 1 0 5.625V5.37a1.5 1.5 0 0 1 .361-.976zM1.5 8.5A.5.5 0 0 1 2 8h1a.5.5 0 0 1 .5.5V14h8V8.5A.5.5 0 0 1 12 8h1a.5.5 0 0 1 .5.5V15a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z"/></svg>
        <span>Shop</span>
    </a>
    <a class="mobile-bottom-nav__item position-relative <?php echo $currentPage === 'cart.php' ? 'is-active' : ''; ?>" href="/cart.php" aria-label="Cart">
        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5zM3.102 4l1.313 7h8.17l1.313-7H3.102zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
        <?php if (($cartCount ?? 0) > 0): ?><span class="cart-badge"><?php echo (int) $cartCount; ?></span><?php endif; ?>
        <span>Cart</span>
    </a>
    <?php if ($isLoggedIn): ?>
        <a class="mobile-bottom-nav__item <?php echo in_array($currentPage, ['orders.php', 'order-view.php'], true) ? 'is-active' : ''; ?>" href="/customer/orders.php" aria-label="Orders">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M0 1.5A.5.5 0 0 1 .5 1H1v14h14V1h.5a.5.5 0 0 1 .5.5v13a.5.5 0 0 1-.5.5H.5a.5.5 0 0 1-.5-.5v-13A.5.5 0 0 1 0 1.5"/><path d="M2 2h12v11H2zm2.5 2a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 2a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1zm0 2a.5.5 0 0 0 0 1h4a.5.5 0 0 0 0-1z"/></svg>
            <span>Orders</span>
        </a>
    <?php else: ?>
        <a class="mobile-bottom-nav__item <?php echo in_array($currentPage, ['login.php', 'register.php'], true) ? 'is-active' : ''; ?>" href="/customer/login.php" aria-label="Account">
            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M14 14s-1-4-6-4-6 4-6 4 1 1 6 1 6-1 6-1Z"/></svg>
            <span>Account</span>
        </a>
    <?php endif; ?>
    <button type="button" class="mobile-bottom-nav__item mobile-bottom-nav__menu-btn" data-mobile-bottom-menu aria-label="Open menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/></svg>
        <span>Menu</span>
    </button>
</nav>
<?php endif; ?>

<button
    type="button"
    class="go-top-btn"
    id="goTopBtn"
    aria-label="Go to top"
    title="Go to top"
>
    <span aria-hidden="true">&uarr;</span>
</button>

<?php $marketingConsentStatus = function_exists('marketing_consent_status') ? marketing_consent_status() : 'unknown'; ?>
<div
    id="cookieConsentBanner"
    data-consent-status="<?php echo e($marketingConsentStatus); ?>"
    class="cookie-consent-banner position-fixed bottom-0 start-0 end-0 p-3 <?php echo $marketingConsentStatus === 'unknown' ? '' : 'd-none'; ?>"
>
    <div class="container">
        <div class="card border-0 shadow">
            <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                <div class="flex-grow-1">
                    <h6 class="mb-1">Cookie Preferences</h6>
                    <p class="mb-0 small text-muted">
                        We use marketing cookies for Meta Pixel, Meta CAPI, Google Analytics, and UTM attribution only after your consent.
                        You can review details in our <a href="/privacy-policy.php">Privacy Policy</a>.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-consent-choice="reject">Reject</button>
                    <button type="button" class="btn btn-dark btn-sm" data-consent-choice="accept">Accept</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php do_action('page.footer', [
    'page' => basename($_SERVER['PHP_SELF'] ?? ''),
]); ?>
</body>
</html>
