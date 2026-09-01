<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$fabric = (string) file_get_contents($root . '/fabric.php');
$productDetailPath = $root . '/js/product-detail.js';
$productDetail = is_file($productDetailPath) ? (string) file_get_contents($productDetailPath) : '';
$backInStock = (string) file_get_contents($root . '/plugins/back-in-stock-alert/plugin.php');
$checkout = (string) file_get_contents($root . '/checkout.php');
$checkoutScriptPath = $root . '/js/checkout.js';
$checkoutScript = is_file($checkoutScriptPath) ? (string) file_get_contents($checkoutScriptPath) : '';
$editor = (string) file_get_contents($root . '/admin/edit-fabric.php');
$editorForm = (string) file_get_contents($root . '/admin/partials/fabric-product-form.php');
$editorScript = (string) file_get_contents($root . '/admin/partials/fabric-product-form-script.php');
$draft = (string) file_get_contents($root . '/admin/add-fabric.php');
$admin = (string) file_get_contents($root . '/js/admin.js');
$style = (string) file_get_contents($root . '/css/style.css');
$adminHeader = (string) file_get_contents($root . '/admin/partials/header.php');

$assert(
    substr_count($style, '--accent: #c77d2f;') === 1,
    'The design token block must define --accent exactly once.'
);
$assert(
    !preg_match('/(?m)^\.card:hover\s*\{/', $style)
        && !preg_match('/(?m)^\.card:hover\s+\.fabric-thumb\s*\{/', $style)
        && str_contains($style, '.product-click-card:hover {')
        && str_contains($style, '.product-click-card:hover .fabric-thumb {'),
    'Hover transforms and product image scaling must be scoped to genuinely interactive product cards.'
);
$assert(
    str_contains($style, '@media (prefers-reduced-motion: reduce)')
        && str_contains($style, 'transform: none !important;'),
    'Reduced-motion rules must continue to disable card and image movement.'
);
$assert(
    str_contains($adminHeader, '<html lang="en">')
        && !str_contains($adminHeader, 'HTTP_USER_AGENT'),
    'The admin shell must declare English and must not vary its markup by user agent.'
);
$assert(
    str_contains($adminHeader, '../images/logo-mobile.svg')
        && str_contains($adminHeader, '../images/logo-mobile-dark.svg')
        && str_contains($adminHeader, 'media="(max-width: 767.98px) and (prefers-color-scheme: dark)"')
        && str_contains($adminHeader, 'media="(max-width: 767.98px)"')
        && str_contains($adminHeader, 'media="(prefers-color-scheme: dark)"'),
    'Admin branding must select mobile and dark logo assets with responsive picture sources.'
);

$assert(
    str_contains($productDetail, 'currentPricePerUnit')
        && str_contains($productDetail, 'updateVariantPrice(v)')
        && str_contains($productDetail, 'v.price_override'),
    'The storefront must update displayed and calculated prices for variant overrides.'
);
$assert(
    str_contains($productDetail, 'function updateVariantQuantity(v)')
        && str_contains($productDetail, 'selectedVariantStock(v)')
        && str_contains($productDetail, 'Math.floor((stock + 0.000001) / meterLength)'),
    'The storefront must constrain quantities using the selected variant stock.'
);
$assert(
    str_contains($fabric, '<script type="application/json" id="product-detail-data"')
        && str_contains($fabric, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT')
        && str_contains($fabric, '<script src="/js/product-detail.js?v=20260831a" defer></script>')
        && !str_contains($fabric, 'window.FABRIC_VARIANTS')
        && !str_contains($fabric, 'window.productMediaController'),
    'Product detail behavior must use the page-scoped JSON contract and dedicated versioned asset without global bootstrap state.'
);
$assert(
    preg_match('/<script\b(?![^>]*\bsrc=)(?![^>]*\btype="application\/json")[^>]*>.*?<\/script>/si', $fabric) !== 1,
    'Product detail executable JavaScript must not remain inline after extraction.'
);
$assert(
    strpos($fabric, 'id="product-detail-data"') < strpos($fabric, "do_action('product.details.after'")
        && str_contains($backInStock, "document.getElementById('product-detail-data')")
        && str_contains($backInStock, 'JSON.parse(productDataElement.textContent')
        && !str_contains($backInStock, 'window.FABRIC_VARIANTS'),
    'Product-detail plugins must consume the page-scoped data contract without depending on legacy globals.'
);
$assert(
    str_contains($checkoutScript, 'shippingRateAbortController')
        && str_contains($checkoutScript, 'requestContext !== currentContext')
        && str_contains($checkoutScript, 'requestId === shippingRateRequestId'),
    'Checkout must cancel and reject stale shipping quote responses.'
);
$assert(
    str_contains($editor, 'form="product-editor-form" data-submit-intent="publish"')
        && str_contains($editor, 'Save &amp; Publish')
        && !str_contains($editor, "action('publish')")
        && str_contains($editorForm, 'id="product_submit_intent"'),
    'Publishing must submit the current editor form instead of publishing stale saved data.'
);
$assert(
    str_contains($editorForm, 'data-initial-editor-section')
        && str_contains($editorScript, "addEventListener('invalid'")
        && str_contains($editorScript, 'setSection(section)'),
    'Product validation must reveal the tab containing the first invalid field.'
);
$assert(
    str_contains($editor, '$publishChecks = (array)')
        && str_contains($editor, '$errors = array_merge($errors, $publishChecks);')
        && str_contains($editorForm, 'data-initial-editor-target')
        && str_contains($editorScript, "errorTarget === 'media'")
        && str_contains($editorScript, "errorTarget === 'variants'"),
    'Publish-readiness errors must retain their field keys and reveal media or variants when applicable.'
);
$assert(
    str_contains($editor, "!empty(\$errors['save'])")
        && str_contains($editor, "e((string)\$errors['save'])"),
    'Global editor save failures must be visible to the administrator.'
);
$assert(
    str_contains($draft, 'name="unit_type" required')
        && !str_contains($draft, '<input type="hidden" name="unit_type" value="piece">'),
    'Draft creation must allow the administrator to choose the selling unit.'
);
$assert(
    str_contains($editorForm, '<select id="unit_type" name="unit_type"')
        && !str_contains($editorForm, '<input type="hidden" name="unit_type"')
        && str_contains($editorForm, 'name="meter_options"')
        && str_contains($admin, 'function syncUnitType()')
        && str_contains($admin, 'holder.hidden = !isMeter')
        && str_contains($editor, '$requestedStatus = $unitTypeChanged ? \'draft\' : $status;'),
    'Existing products must allow safe selling-unit changes and meter length configuration.'
);
$assert(
    str_contains($draft, 'ProductAdminService::normalizeDraftUnitType')
        && str_contains($draft, 'data-default-unit-type=')
        && str_contains($admin, 'function initCategoryUnitType(form)')
        && str_contains($admin, 'unitType.value = requiredUnit')
        && str_contains($editor, 'ProductAdminService::normalizeDraftUnitType'),
    'Category selling-unit metadata must drive browser and backend unit enforcement.'
);
$assert(
    !str_contains($editor, "\$size = (string) (\$fabric['size'] ?? '');")
        && !str_contains($editor, "\$color = (string) (\$fabric['color'] ?? '');"),
    'Submitted size and colour must not be overwritten with stale database values.'
);
$assert(
    str_contains($editor, "v.color || '\\u2014'")
        && str_contains($editor, "v.size  || '\\u2014'"),
    'Empty variant values must render an em dash instead of a literal HTML entity.'
);
$assert(
    !str_contains($editor, 'loadVariants()'),
    'Variant mutations must not call the removed loadVariants function after a successful request.'
);

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "frontend_integrity_regression_test: OK\n";
