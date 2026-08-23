<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$storefrontTemplates = [
    'about.php',
    'cart.php',
    'catalog.php',
    'checkout.php',
    'contact.php',
    'fabric.php',
    'faq.php',
    'index.php',
    'international-buyers.php',
    'international-orders-policy.php',
    'order-success.php',
    'privacy-policy.php',
    'return-policy.php',
    'shipping-policy.php',
    'size-guide.php',
    'terms.php',
    'thank-you.php',
    'customer/forgot-password.php',
    'customer/login.php',
    'customer/order-view.php',
    'customer/orders.php',
    'customer/profile.php',
    'customer/register.php',
    'customer/resend-verification.php',
    'customer/reset-password.php',
    'customer/verify-email.php',
    'guest/account-activate.php',
    'guest/order-access.php',
    'guest/order.php',
    'guest/support.php',
    'includes/helpers/product-cards.php',
    'includes/views/layouts/header.php',
    'includes/views/layouts/footer.php',
    'includes/partials/interaction-layer-v2.php',
    'payment/razorpay-create.php',
    'plugins/back-in-stock-alert/plugin.php',
    'plugins/google-analytics/plugin.php',
    'plugins/meta-pixel/plugin.php',
    'plugins/recommendations/plugin.php',
    'plugins/review-rating/plugin.php',
];

foreach ($storefrontTemplates as $path) {
    $source = $read($path);
    $assert($source !== '', $path . ' must be readable.');
    $assert(!preg_match('/bootstrap(?:\.min)?\.(?:css|js)|data-bs-|window\.bootstrap|\bbi\s+bi-/i', $source), $path . ' must not depend on Bootstrap.');
    $assert(!preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=/i', $source), $path . ' must not contain browser inline styles or event handlers.');

    if (preg_match_all('/<script\b[^>]*>/i', $source, $scriptTags)) {
        foreach ($scriptTags[0] as $scriptTag) {
            $allowed = preg_match('/\bsrc\s*=/i', $scriptTag)
                || preg_match('/\btype\s*=\s*["\']application\/ld\+json["\']/i', $scriptTag);
            $assert((bool) $allowed, $path . ' must not contain executable inline JavaScript.');
        }
    }
}

$header = $read('includes/views/layouts/header.php');
$checkout = $read('checkout.php');
$fabric = $read('fabric.php');
$payment = $read('payment/razorpay-create.php');
$commerce = $read('js/commerce.js');
$app = $read('js/app.js');
$formHelpers = $read('includes/helpers/email-tax-ui.php');
$coreHelpers = $read('includes/helpers/core.php');
$siteSettings = $read('includes/services/SiteSettingsService.php');
$storefrontCss = $read('css/storefront.css');

$assert(str_contains($header, 'data-ui-area="storefront"') && str_contains($header, 'data-ui-page='), 'The storefront layout must expose its guarded UI area and route.');
$assert(substr_count($header, "ui_asset('/css/") === 2, 'Storefront routes must load exactly two first-party CSS files.');
$assert(substr_count($header, "ui_asset('/js/") === 2, 'Storefront routes must load exactly two first-party JavaScript files.');
$assert(str_contains($checkout, 'data-checkout-config="<?php echo ui_data_json('), 'Checkout data must use the safe JSON attribute helper.');
$assert(str_contains($checkout, 'data-checkout-payable'), 'Checkout confirmation must read the displayed payable total.');
$assert(str_contains($fabric, 'data-product-config="<?php echo ui_data_json('), 'Product data must use the safe JSON attribute helper.');
$assert(str_contains($payment, 'data-payment-config="<?php echo ui_data_json('), 'Payment data must use the safe JSON attribute helper.');
$assert(str_contains($payment, 'https://checkout.razorpay.com/v1/checkout.js') && !str_contains($header, 'checkout.razorpay.com'), 'Razorpay Checkout must load only on its payment page.');
$assert(str_contains($commerce, 'fetchJson("/cookie-consent.php"') && str_contains($commerce, 'choice: choice'), 'Cookie consent must preserve the established endpoint and request field.');
$assert(!str_contains($commerce, '/marketing-consent.php'), 'The storefront must not call a non-existent marketing-consent endpoint.');
$assert(str_contains($commerce, 'window.location.reload();'), 'Granting consent must reload so consent-gated analytics markup and CSP directives can be rendered.');
$assert(str_contains($checkout, 'formaction="/checkout.php" formmethod="post"') && str_contains($checkout, 'CheckoutInput::sessionState($preparedInput)'), 'Checkout must retain a server-rendered continue-to-payment fallback.');
$assert(substr_count($checkout, 'data-checkout-continue') === 2
    && str_contains($checkout, 'checkout_continue_payment_mobile')
    && str_contains($commerce, 'continueButtons.forEach(function (button)')
    && str_contains($storefrontCss, '.checkout-continue-mobile:not(.u-hidden)'), 'Phone checkout must place its shared continue-to-payment action below the order summary.');
$assert(!preg_match('/<form\b[^>]*id="checkout_form"[^>]*\bnovalidate\b/is', $checkout), 'Checkout must retain native validation when JavaScript is unavailable.');

foreach ([
    'data-ajax-cart',
    'data-ui-cart-quantity',
    'data-ui-product',
    'data-ui-checkout',
    'data-ui-back-in-stock',
    'Razorpay',
] as $contract) {
    $assert(str_contains($commerce, $contract), 'commerce.js must retain behavior for ' . $contract . '.');
}
$assert(str_contains($app, 'requestSubmit(submitter || undefined)'), 'The shared confirmation flow must preserve submitter name and value.');
$assert(str_contains($app, 'amber:validate'), 'Shared confirmation must honor page validation before opening.');
$assert(str_contains($app, 'form.hasAttribute("data-ui-async")'), 'The shared submit coordinator must leave managed asynchronous forms to commerce.js.');
$assert(str_contains($commerce, 'event.preventDefault();') && str_contains($commerce, 'button.getAttribute("aria-busy") === "true"'), 'AJAX cart submissions must prevent native duplicate submissions.');
$assert(str_contains($formHelpers, "string \$base = 'ui-field-error'"), 'The shared form error helper must use the first-party error presentation.');
$assert(!preg_match('/form_class\([^,\r\n]+,\s*["\'][^"\']+["\']\s*\)/', $checkout), 'Checkout fields must explicitly use first-party control classes.');
$assert(str_contains($storefrontCss, '@layer storefront-refresh')
    && str_contains($storefrontCss, '.checkout-pay-option:hover')
    && str_contains($storefrontCss, '.account-nav'), 'The refreshed storefront, checkout, and customer-account visual system must remain present.');
$assert(str_contains($catalog = $read('catalog.php'), 'catalog-main-section')
    && str_contains($catalog, 'catalog-recommendations'), 'Catalog utilities and hook-rendered recommendations must stay inside the responsive catalog layout.');
$assert(str_contains($catalog, 'id="catalogFiltersDrawer"')
    && str_contains($storefrontCss, ".catalog-filters {\n      display: none;"), 'Tablet and phone catalog views must hide the desktop filter sidebar and use only the filter drawer.');
$assert(str_contains($storefrontCss, '.catalog-utility-row')
    && str_contains($storefrontCss, '.recommendations-block .catalog-products-grid')
    && str_contains($storefrontCss, 'grid-template-areas: "brand links actions"'), 'Catalog utilities, recommendations, and desktop navigation must retain their corrected layouts.');
$assert(str_contains($storefrontCss, '.catalog-active-filters')
    && str_contains($storefrontCss, '.checkout-payment-options')
    && str_contains($storefrontCss, '.saved-cart-item__actions')
    && str_contains($storefrontCss, '.about-story')
    && str_contains($storefrontCss, '.testimonial-author'), 'Migrated storefront page structures must retain explicit first-party layout definitions.');
$assert(str_contains($read('contact.php'), 'inquiry-shell--compact')
    && str_contains($read('international-buyers.php'), 'inquiry-shell')
    && str_contains($storefrontCss, '.inquiry-card'), 'Contact and international inquiry forms must remain centered in their explicit responsive layout.');
$assert(str_contains($commerce, 'initializeProductImageFallbacks()')
    && str_contains($commerce, 'Image unavailable'), 'Broken product media must degrade to a bounded accessible placeholder.');
$assert(!str_contains($read('index.php'), 'data-announcement-pause')
    && !str_contains($read('index.php'), 'data-slider-toggle')
    && !str_contains($storefrontCss, '.announce-pause')
    && !str_contains($storefrontCss, '.slider-motion-toggle'), 'Public pause controls must not crowd announcement or product content.');
$assert(str_contains($storefrontCss, '@media (max-width: 47.99rem)')
    && str_contains($storefrontCss, ".site-header__drawer-toggle {\n      display: none;"), 'Phone layouts must use the bottom navigation as their single menu trigger.');
$assert(str_contains($storefrontCss, '.hero-section--home .hero-swatch-col')
    && str_contains($storefrontCss, 'padding-block: 1.2rem 1.45rem;'), 'The phone home hero must remain compact and omit its decorative swatch panel.');
$assert(str_contains($storefrontCss, 'flex-basis: calc((100% - 0.65rem) / 2)')
    && str_contains($storefrontCss, '.recommendations-block .catalog-products-grid')
    && str_contains($storefrontCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));'), 'Phone sliders and product grids must retain two cards per row.');
$assert(str_contains($storefrontCss, ".why-grid,\n    .testimonials-grid {")
    && str_contains($storefrontCss, '.testimonial-location'), 'Why-us and buyer-testimonial cards must retain their compact two-column phone presentation.');
$assert(str_contains($coreHelpers, 'function ui_rich_text_html('), 'Stored policy HTML must pass through the legacy presentation-class normalizer.');
foreach (['shipping-policy.php', 'return-policy.php', 'privacy-policy.php', 'terms.php', 'international-orders-policy.php', 'size-guide.php'] as $path) {
    $assert(str_contains($read($path), 'ui_rich_text_html('), $path . ' must normalize historical editable presentation classes before rendering.');
}
$assert(!preg_match('/class=\\"(?:mb-4|btn\b|table-responsive|table\s+table-bordered)/', $siteSettings), 'Fresh site-setting defaults must use first-party presentation classes.');

if ($failures !== []) {
    fwrite(STDERR, "Storefront rewrite contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: storefront rewrite contracts passed\n";
