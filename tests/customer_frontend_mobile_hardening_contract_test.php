<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$cssRuleDeclaresProperty = static function (string $css, string $selector, string $property): bool {
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (str_contains($rule[1], $selector)
            && preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:/i', $rule[2]) === 1) {
            return true;
        }
    }
    return false;
};
$hasBoundLabel = static function (string $source, string $id, ?string $name = null): bool {
    $quotedId = preg_quote($id, '/');
    $controlPattern = '/<(?:input|select|textarea)\\b(?=[^>]*\\bid="' . $quotedId . '")';
    if ($name !== null) {
        $controlPattern .= '(?=[^>]*\\bname="' . preg_quote($name, '/') . '")';
    }
    $controlPattern .= '[^>]*>/s';
    return preg_match('/<label\\b(?=[^>]*\\bfor="' . $quotedId . '")[^>]*>.*?<\\/label>/s', $source) === 1
        && preg_match($controlPattern, $source) === 1;
};

$script = $read('js/script.js');
$productDetailPath = $root . '/js/product-detail.js';
$productDetail = is_file($productDetailPath) ? (string) file_get_contents($productDetailPath) : '';
$adminScript = $read('js/admin.js');
$style = $read('css/style.css');
$adminStyle = $read('css/admin.css');
$header = $read('includes/views/layouts/header.php');
$footer = $read('includes/views/layouts/footer.php');
$fabric = $read('fabric.php');
$home = $read('index.php');
$productCards = $read('includes/helpers/product-cards.php');
$catalog = $read('catalog.php');
$catalogFilterPartial = $read('includes/partials/catalog-filter-fields.php');
$checkout = $read('checkout.php');
$checkoutScriptPath = $root . '/js/checkout.js';
$checkoutScript = is_file($checkoutScriptPath) ? (string) file_get_contents($checkoutScriptPath) : '';
$cart = $read('cart.php');
$orders = $read('customer/orders.php');
$profile = $read('customer/profile.php');
$accountService = $read('includes/services/CustomerAccountService.php');
$adminHeader = $read('admin/partials/header.php');
$adminFooter = $read('admin/partials/footer.php');
$adminLogin = $read('admin/login.php');
$adminOtp = $read('admin/verify-otp.php');
$interactionLayer = $read('includes/partials/interaction-layer.php');

foreach (['customer/orders.php', 'customer/order-view.php', 'customer/profile.php', 'guest/order.php'] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'onsubmit="return confirm'), $path . ' must not use CSP-blocked inline confirmation handlers.');
}
$assert(str_contains($script, 'function confirmationAttribute(form, submitter, name)') && str_contains($script, 'AmberUI.confirm = function') && str_contains($script, 'function degradedConfirmation(options)') && str_contains($script, 'window.confirm(') && !str_contains($script, 'window.alert('), 'Shared confirmations must prefer the application modal and retain a native degraded mode when Bootstrap is unavailable.');
$assert(str_contains($script, 'function submitConfirmedForm(form, submitter)') && str_contains($script, 'form.requestSubmit(submitter || undefined)') && str_contains($script, 'form.dataset.confirmPending') && str_contains($script, 'form.dataset.confirmed') && str_contains($script, 'form.dataset.confirmSubmitting'), 'Confirmed forms must preserve the original submitter and guard duplicate confirmation and submission.');
$assert(str_contains($interactionLayer, 'id="uiConfirmDialog"') && str_contains($interactionLayer, 'id="siteToastRegion"') && str_contains($interactionLayer, 'aria-describedby="uiConfirmDialogMessage"'), 'Shared layouts must use one accessible confirmation dialog and toast region.');
$assert(str_contains($adminFooter, "includes/partials/interaction-layer.php") && !str_contains($adminFooter, 'id="adminConfirmModal"'), 'Admin confirmation UI must use the shared interaction partial instead of duplicate modal markup.');
$assert(str_contains($script, 'AmberUI.toast = function') && str_contains($script, 'AmberUI.setButtonLoading = function') && str_contains($script, 'window.adminConfirm = function'), 'The shared UI API and admin compatibility wrapper must remain available.');
$assert(str_contains($checkout, 'data-confirm-context="checkout"') && substr_count($cart, 'data-confirm-variant="danger"') >= 3, 'Checkout and cart destructive actions must use the shared confirmation policy.');
$assert(str_contains($script, 'e.submitter') && str_contains($script, 'if (e.defaultPrevented) return;'), 'Form loading must use the actual submitter and respect prevented submissions.');
$assert(str_contains($script, 'bootstrapComponent("Offcanvas")'), 'Storefront Bootstrap behavior must be guarded when the library is unavailable.');
$assert(str_contains($script, 'if (!response.ok)') && str_contains($script, '.finally(function ()') && str_contains($script, 'window.clearTimeout(timeoutId)'), 'Shared JSON requests must validate responses and always clear timeout resources.');
$assert(substr_count($script, 'document.addEventListener("DOMContentLoaded"') === 1 && substr_count($script, 'document.addEventListener("mouseup"') === 1, 'Shared JavaScript must not restore duplicate lifecycle or slider drag listeners.');

$assert(str_contains($fabric, "SiteContext::url('/images/fabrics/'") && !str_contains($fabric, "var src = 'images/fabrics/'") && !str_contains($fabric, 'data-src="images/fabrics/'), 'Product media and Open Graph URLs must be safe on clean product routes.');
$assert(
    str_contains($home, 'product_card_build_home_context($row, $variantRows)')
        && str_contains($productCards, '$representativeVariantPrice')
        && str_contains($productCards, "['price_override']")
        && str_contains($productCards, 'product_card_unit_suffix($unitType)'),
    'Homepage pricing must account for representative variant overrides and selling units through the shared card-state helper.'
);
$assert(str_contains($home, 'ProductAdminService::publicPath($row)'), 'Homepage product links must use the clean public route generator.');
$assert(str_contains($home, '<a href="<?php echo e($productUrl); ?>" class="fabric-thumb-link" aria-label="View <?php echo e($row[\'name\']); ?>">') && str_contains($home, '<a href="<?php echo e($productUrl); ?>" class="fabric-card-title-link">'), 'Homepage Latest Drops cards must expose native image and title links through ProductAdminService::publicPath($row).');
$catalogDesktopControls = [
    'catalog_category' => 'category',
    'catalog_min_price' => 'min_price',
    'catalog_max_price' => 'max_price',
    'catalog_in_stock' => 'in_stock',
    'catalog_material' => 'material',
    'catalog_color' => 'color',
    'catalog_size' => 'size',
    'catalog_dispatch' => 'dispatch',
    'catalog_sort' => 'sort',
    'catalog_per_page' => 'per_page',
];
$catalogMobileControls = [
    'catalog_mobile_category' => 'category',
    'catalog_mobile_min_price' => 'min_price',
    'catalog_mobile_max_price' => 'max_price',
    'catalog_mobile_in_stock' => 'in_stock',
    'catalog_mobile_material' => 'material',
    'catalog_mobile_color' => 'color',
    'catalog_mobile_size' => 'size',
    'catalog_mobile_dispatch' => 'dispatch',
    'catalog_mobile_sort' => 'sort',
    'catalog_mobile_per_page' => 'per_page',
];
foreach ($catalogDesktopControls as $id => $name) {
    $assert(
        str_contains($catalog, "'name' => '" . $name . "'")
            && str_contains($catalog, "\$catalogFilterIdPrefix = 'catalog_'")
            && str_contains($catalogFilterPartial, 'for="<?php echo e($catalogFilterId($field)); ?>"')
            && str_contains($catalogFilterPartial, 'id="<?php echo e($catalogFilterId($field)); ?>"'),
        'Desktop catalog control must retain a unique generated ID and bound label: ' . $id . '.'
    );
}
foreach ($catalogMobileControls as $id => $name) {
    $assert(
        str_contains($catalog, "'name' => '" . $name . "'")
            && str_contains($catalog, "\$catalogFilterIdPrefix = 'catalog_mobile_'")
            && str_contains($catalogFilterPartial, 'for="<?php echo e($catalogFilterId($field)); ?>"')
            && str_contains($catalogFilterPartial, 'id="<?php echo e($catalogFilterId($field)); ?>"'),
        'Mobile catalog control must retain a unique generated ID and bound label: ' . $id . '.'
    );
}
foreach (['saved_address_select' => null, 'checkout_full_name' => 'full_name', 'checkout_phone' => 'phone', 'checkout_email' => 'email', 'checkout_address' => 'address', 'checkout_city' => 'city', 'checkout_state' => 'state', 'checkout_pincode' => 'pincode', 'checkout_country' => 'country', 'checkout_order_notes' => 'order_notes'] as $id => $name) {
    $assert($hasBoundLabel($checkout, $id, $name), 'Checkout control must have a bound label: ' . $id . '.');
}
$assert($hasBoundLabel($fabric, 'product_quantity', 'bundle_quantity') && $hasBoundLabel($fabric, 'product_quantity', 'quantity'), 'Product quantity controls must retain the visible quantity label across unit types.');
$assert(str_contains($fabric, 'aria-label="Delivery pincode"') && str_contains($fabric, 'aria-label="Payment method"'), 'Product delivery controls must expose accessible names without relying on placeholders.');
$assert(str_contains($cart, 'aria-label="Quantity for <?php echo e($item[\'name\']); ?>"'), 'Each cart quantity control must expose its product-specific accessible name.');

$assert(substr_count($header . $footer, 'data-cart-link') >= 3 && str_contains($script, "querySelectorAll('[data-cart-link]')"), 'Every cart navigation surface must participate in AJAX badge synchronization.');
$assert(str_contains($profile, '$cust = array_merge($cust, $profileValues)') && str_contains($accountService, "'name' => trim") && str_contains($accountService, "'country' => trim"), 'Invalid profile submissions must retain sanitized submitted values.');
$assert(str_contains($orders, 'function order_customer_status_messages') && substr_count($orders, "['_customer_status_messages']") >= 3, 'Mobile cards and desktop rows must render one shared order-status message model.');

$assert(str_contains($checkout, 'id="mobile_place_order_btn"') && str_contains($checkout, 'form="checkout_form"') && str_contains($checkout, 'id="mobile_summary_total"'), 'Mobile checkout must submit the authoritative checkout form after the order summary.');
$assert(!str_contains($checkoutScript, 'mobileSubmitBtn.addEventListener'), 'Mobile checkout must not create a second JavaScript submission path.');
$assert(str_contains($cart, 'saved-cart-item__actions') && str_contains($fabric, 'product-purchase-controls'), 'Cart and product purchase controls must use dedicated responsive components.');
$assert(!str_contains($fabric, '<style') && str_contains($style, '.product-media-main') && str_contains($style, 'height: 300px;'), 'Product responsive media styling must live in the shared stylesheet.');
$assert(str_contains($fabric, 'page-hero product-detail-hero') && str_contains($fabric, 'class="app-back-link"') && !str_contains($fabric, 'class="text-white opacity-75 small"'), 'Product detail navigation must remain visible against the light storefront background.');
$assert(str_contains($fabric, 'class="card product-buy-box') && !str_contains($fabric, 'style="max-width:420px;"') && str_contains($style, '.product-buy-box') && !$cssRuleDeclaresProperty($style, '.product-buy-box', 'max-width'), 'The product buy box must use the available responsive column width instead of an inline or stylesheet cap.');
$assert($cssRuleDeclaresProperty('.product-buy-box { max-width: 420px; }', '.product-buy-box', 'max-width'), 'The product buy-box width-cap regression guard must detect a capped CSS rule.');
$assert(str_contains($fabric, 'Select cut length') && str_contains($fabric, 'Number of cuts') && str_contains($productDetail, 'qty === 1 ? \' cut\' : \' cuts\''), 'Meter products must distinguish cut length from the number of cuts in their purchase controls and live summary.');
$assert(str_contains($fabric, '$stockUnitLabel = $displayStock === 1.0') && str_contains($fabric, "e(\$stockUnitLabel)"), 'Product availability must use a singular unit label when exactly one unit remains.');
$assert((bool) preg_match('/<section class="section-block">\s*<div class="container">\s*<div class="surface-panel text-center">\s*<h5 class="mb-2">Need International Shipping/s', $catalog), 'The catalog inquiry section must retain normal top spacing after recommendations.');

$assert(str_contains($header, 'class="skip-link"') && str_contains($header, '<main id="main-content" tabindex="-1">') && str_starts_with($footer, '</main>'), 'Shared storefront chrome must expose a skip link and one main landmark.');
$assert(str_contains($script, 'function initSkipLink()') && str_contains($script, 'destination.focus({ preventScroll: true })') && str_contains($script, 'destination.scrollIntoView({ behavior: "auto", block: "start" })'), 'The skip link must focus and align the main landmark without centering the full-page element.');
$assert(str_contains($fabric, 'class="row g-3 g-md-5"'), 'Product details must use a viewport-safe gutter below the desktop breakpoint.');
$assert(str_contains($fabric, 'aria-current=') && str_contains($fabric, 'aria-pressed=') && str_contains($checkout, 'aria-pressed="true"'), 'Product media, variant controls, and payment controls must expose selected state.');
$assert(str_contains($script, 'clone.matches("a, button, input, select, textarea, [tabindex]")') && str_contains($script, 'control.setAttribute("tabindex", "-1")') && str_contains($script, 'prefers-reduced-motion: reduce') && str_contains($home, 'data-slider-toggle'), 'Slider clones and autoplay must be keyboard and reduced-motion safe.');
$assert(str_contains($script, 'hoverPaused') && str_contains($script, 'focusPaused') && str_contains($script, 'touchPaused'), 'Autoplay must remain paused while pointer, keyboard focus, or touch interaction is active.');
$assert(!str_contains($home, 'aria-live="polite"') && str_contains($home, 'id="announcePause"'), 'Rotating announcements must not be a live region and must provide a pause control.');
$assert(str_contains($script, 'toast.setAttribute("role", type === "error" ? "alert" : "status")') && str_contains($script, 'positionToastRegion()') && str_contains($style, '.site-toast-region') && str_contains($style, '.cookie-consent-banner.has-mobile-nav'), 'Toast severity and positioning must account for accessibility, mobile navigation, and consent UI.');
$assert(str_contains($style, '@media (max-width: 359.98px)') && str_contains($style, '.home-products-grid'), 'Product grids must retain two columns at 360px and switch below that width.');
$assert(str_contains($style, '.btn-icon') && str_contains($style, '.btn-danger') && str_contains($style, '.ui-dialog[data-variant="danger"]') && !str_contains($style, '.btn-ripple'), 'Buttons and dialogs must expose consistent semantic variants without decorative ripple effects.');

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
$assert(!str_contains($script . $checkoutScript . $adminScript . $style . $adminStyle, "\xC3\xA2") && !str_contains($script . $checkoutScript . $adminScript . $style . $adminStyle, "\xEF\xBF\xBD"), 'First-party assets must not contain mojibake or replacement characters.');
$assetTemplates = $header . $adminHeader . $adminFooter . $adminLogin . $adminOtp;
$assert(substr_count($assetTemplates, 'admin.css?v=') === 3 && substr_count($assetTemplates, 'script.js?v=') === 4 && substr_count($assetTemplates, 'admin.js?v=') === 1, 'Storefront, admin, and standalone authentication templates must include their first-party admin and JavaScript assets.');
$assert(substr_count($fabric, 'src="/js/product-detail.js?v=20260901b"') === 1 && !str_contains($header . $footer, 'product-detail.js'), 'The versioned product-detail asset must load only from the product page.');
$assert(substr_count($checkout, 'src="/js/checkout.js?v=20260901a"') === 1 && !str_contains($header . $footer, 'checkout.js'), 'The versioned checkout asset must load only from checkout.');
$canonicalAsset = static fn (string $source): string => str_replace("\r\n", "\n", $source);
$assetBytes = strlen($canonicalAsset($style)) + strlen($canonicalAsset($adminStyle)) + strlen($canonicalAsset($script)) + strlen($canonicalAsset($adminScript));
$assert($assetBytes <= 127000, 'Combined first-party interaction assets must remain within the reviewed raw-byte budget.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "customer_frontend_mobile_hardening_contract_test: OK\n";
