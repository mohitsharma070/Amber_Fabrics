<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$script = $read('js/script.js');
$adminScript = $read('js/admin.js');
$style = $read('css/style.css');
$adminStyle = $read('css/admin.css');
$header = $read('includes/header.php');
$footer = $read('includes/footer.php');
$fabric = $read('fabric.php');
$home = $read('index.php');
$catalog = $read('catalog.php');
$checkout = $read('checkout.php');
$cart = $read('cart.php');
$orders = $read('customer/orders.php');
$profile = $read('customer/profile.php');
$adminHeader = $read('admin/partials/header.php');
$adminFooter = $read('admin/partials/footer.php');
$adminLogin = $read('admin/login.php');
$adminOtp = $read('admin/verify-otp.php');

foreach (['customer/orders.php', 'customer/order-view.php', 'customer/profile.php', 'guest/order.php'] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'onsubmit="return confirm'), $path . ' must not use CSP-blocked inline confirmation handlers.');
}
$assert(str_contains($script, 'submitter.getAttribute("data-confirm")') && str_contains($script, 'form.getAttribute("data-confirm")') && str_contains($script, 'window.confirm(message)'), 'External JavaScript must provide delegated form and submitter confirmation handling.');
$assert(str_contains($script, 'e.submitter') && str_contains($script, 'if (e.defaultPrevented) return;'), 'Form loading must use the actual submitter and respect prevented submissions.');
$assert(str_contains($script, 'bootstrapComponent("Offcanvas")'), 'Storefront Bootstrap behavior must be guarded when the library is unavailable.');
$assert(str_contains($script, 'if (!response.ok)') && str_contains($script, '.finally(function ()') && str_contains($script, 'window.clearTimeout(timeoutId)'), 'Shared JSON requests must validate responses and always clear timeout resources.');
$assert(substr_count($script, 'document.addEventListener("DOMContentLoaded"') === 1 && substr_count($script, 'document.addEventListener("mouseup"') === 1, 'Shared JavaScript must not restore duplicate lifecycle or slider drag listeners.');

$assert(str_contains($fabric, "SiteContext::url('/images/fabrics/'") && !str_contains($fabric, "var src = 'images/fabrics/'") && !str_contains($fabric, 'data-src="images/fabrics/'), 'Product media and Open Graph URLs must be safe on clean product routes.');
$assert(str_contains($home, 'price_override') && str_contains($home, '$representativeVariantPrice') && str_contains($home, '$cardUnitSuffix'), 'Homepage pricing must account for representative variant overrides and selling units.');
$assert(str_contains($home, 'ProductAdminService::publicPath($row)'), 'Homepage product links must use the clean public route generator.');

$assert(substr_count($header . $footer, 'data-cart-link') >= 3 && str_contains($script, "querySelectorAll('[data-cart-link]')"), 'Every cart navigation surface must participate in AJAX badge synchronization.');
$assert(str_contains($profile, "\$cust['name'] = \$name") && str_contains($profile, "\$cust['country'] = \$country"), 'Invalid profile submissions must retain sanitized submitted values.');
$assert(str_contains($orders, 'function order_customer_status_messages') && substr_count($orders, "['_customer_status_messages']") >= 3, 'Mobile cards and desktop rows must render one shared order-status message model.');

$assert(str_contains($checkout, 'id="mobile_place_order_btn"') && str_contains($checkout, 'form="checkout_form"') && str_contains($checkout, 'id="mobile_summary_total"'), 'Mobile checkout must submit the authoritative checkout form after the order summary.');
$assert(!str_contains($checkout, 'mobileSubmitBtn.addEventListener'), 'Mobile checkout must not create a second JavaScript submission path.');
$assert(str_contains($cart, 'saved-cart-item__actions') && str_contains($fabric, 'product-purchase-controls'), 'Cart and product purchase controls must use dedicated responsive components.');
$assert(!str_contains($fabric, '<style') && str_contains($style, '.product-media-main') && str_contains($style, 'height: 300px;'), 'Product responsive media styling must live in the shared stylesheet.');
$assert((bool) preg_match('/<section class="section-block">\s*<div class="container">\s*<div class="surface-panel text-center">\s*<h5 class="mb-2">Need International Shipping/s', $catalog), 'The catalog inquiry section must retain normal top spacing after recommendations.');

$assert(str_contains($header, 'class="skip-link"') && str_contains($header, '<main id="main-content" tabindex="-1">') && str_starts_with($footer, '</main>'), 'Shared storefront chrome must expose a skip link and one main landmark.');
$assert(str_contains($script, 'function initSkipLink()') && str_contains($script, 'destination.focus({ preventScroll: true })') && str_contains($script, 'destination.scrollIntoView({ behavior: "auto", block: "start" })'), 'The skip link must focus and align the main landmark without centering the full-page element.');
$assert(str_contains($fabric, 'class="row g-3 g-md-5"'), 'Product details must use a viewport-safe gutter below the desktop breakpoint.');
$assert(str_contains($fabric, 'aria-current=') && str_contains($fabric, 'aria-pressed=') && str_contains($checkout, 'aria-pressed="true"'), 'Product media, variant controls, and payment controls must expose selected state.');
$assert(str_contains($script, 'clone.matches("a, button, input, select, textarea, [tabindex]")') && str_contains($script, 'control.setAttribute("tabindex", "-1")') && str_contains($script, 'prefers-reduced-motion: reduce') && str_contains($home, 'data-slider-toggle'), 'Slider clones and autoplay must be keyboard and reduced-motion safe.');
$assert(str_contains($script, 'hoverPaused') && str_contains($script, 'focusPaused') && str_contains($script, 'touchPaused'), 'Autoplay must remain paused while pointer, keyboard focus, or touch interaction is active.');
$assert(!str_contains($home, 'aria-live="polite"') && str_contains($home, 'id="announcePause"'), 'Rotating announcements must not be a live region and must provide a pause control.');
$assert(str_contains($script, "toast.setAttribute('role', 'status')") && str_contains($style, '.site-toast') && str_contains($style, '.cookie-consent-banner.has-mobile-nav'), 'Toast and consent UI must account for mobile navigation and accessibility.');
$assert(str_contains($style, '@media (max-width: 359.98px)') && str_contains($style, '.home-products-grid'), 'Product grids must retain two columns at 360px and switch below that width.');

$assert(!str_contains($style, '.admin-shell') && !str_contains($script, 'admin-shell'), 'Storefront assets must not contain admin-shell behavior or presentation.');
$assert(str_contains($adminStyle, '.admin-shell') && str_contains($adminScript, 'admin-card-table'), 'Admin-only presentation and behavior must live in dedicated assets.');
$assert(str_contains($adminHeader, 'css/admin.css') && str_contains($adminFooter, 'js/admin.js'), 'Admin templates must load the dedicated assets.');
$assert(str_contains($adminScript, 'dataset.adminTableReady') && str_contains($adminScript, 'nav.addEventListener("click"'), 'Admin table enhancement and navigation handling must be idempotent and delegated.');

foreach (['.surface-panel .d-flex.gap-2', '.auth-card', '.btn-accent', '.category-grid', '.feature-grid', '.payment-method-option', '.site-header-search'] as $staleSelector) {
    $assert(!str_contains($style, $staleSelector), 'Unused or overly broad selector must stay removed: ' . $staleSelector . '.');
}
$assert(!str_contains($adminStyle, '.admin-login-wrap') && !str_contains($adminStyle, '.stat-card'), 'Unused admin selectors must stay removed.');
$adminConfirmSources = '';
foreach (['admin/product-import.php', 'admin/inquiry-view.php', 'admin/categories.php', 'admin/about-media.php', 'admin/settings.php', 'plugins/cod-guard/plugin.php'] as $path) {
    $adminConfirmSources .= $read($path);
}
$assert(!str_contains($adminConfirmSources, 'onclick="return confirm') && !str_contains($adminConfirmSources, 'onsubmit="return confirm') && substr_count($adminConfirmSources, 'data-confirm=') >= 6, 'Admin confirmations must use CSP-safe delegated hooks.');
$assert(!str_contains($script . $adminScript . $style . $adminStyle, "\xC3\xA2") && !str_contains($script . $adminScript . $style . $adminStyle, "\xEF\xBF\xBD"), 'First-party assets must not contain mojibake or replacement characters.');
$assetTemplates = $header . $adminHeader . $adminFooter . $adminLogin . $adminOtp;
$assert(!str_contains($assetTemplates, 'v=20260815a') && substr_count($assetTemplates, 'v=20260815b') >= 8 && substr_count($assetTemplates, 'script.js?v=20260815c') === 2, 'Storefront and admin templates must use the current first-party asset versions.');
$assetBytes = strlen($style) + strlen($adminStyle) + strlen($script) + strlen($adminScript);
$assert($assetBytes <= 108023, 'Combined first-party assets must not exceed the pre-rewrite raw-byte baseline.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "customer_frontend_mobile_hardening_contract_test: OK\n";
