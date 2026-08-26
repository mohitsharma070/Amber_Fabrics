<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$fabric = (string) file_get_contents($root . '/fabric.php');
$checkout = (string) file_get_contents($root . '/checkout.php');
$editor = (string) file_get_contents($root . '/admin/edit-fabric.php');
$editorForm = (string) file_get_contents($root . '/admin/partials/fabric-product-form.php');
$editorScript = (string) file_get_contents($root . '/admin/partials/fabric-product-form-script.php');
$draft = (string) file_get_contents($root . '/admin/add-fabric.php');
$admin = (string) file_get_contents($root . '/js/admin.js');

$assert(
    str_contains($fabric, 'currentPricePerUnit')
        && str_contains($fabric, 'updateVariantPrice(v)')
        && str_contains($fabric, 'v.price_override'),
    'The storefront must update displayed and calculated prices for variant overrides.'
);
$assert(
    str_contains($fabric, 'function updateVariantQuantity(v)')
        && str_contains($fabric, 'selectedVariantStock(v)')
        && str_contains($fabric, 'Math.floor((stock + 0.000001) / meterLength)'),
    'The storefront must constrain quantities using the selected variant stock.'
);
$assert(
    str_contains($checkout, 'shippingRateAbortController')
        && str_contains($checkout, 'requestContext !== currentContext')
        && str_contains($checkout, 'requestId === shippingRateRequestId'),
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
