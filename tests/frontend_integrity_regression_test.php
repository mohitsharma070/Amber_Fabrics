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

$commerce = $read('js/commerce.js');
$admin = $read('js/admin.js');
$editor = $read('admin/edit-fabric.php');
$editorForm = $read('admin/partials/fabric-product-form.php');
$draft = $read('admin/add-fabric.php');

$assert(
    str_contains($commerce, 'function updatePrice(variant)')
        && str_contains($commerce, 'variant.price_override')
        && str_contains($commerce, 'currentPrice ='),
    'The storefront must update displayed and calculated prices for variant overrides.'
);
$assert(
    str_contains($commerce, 'function updateQuantityAvailability(variant)')
        && str_contains($commerce, 'availableStock(variant)')
        && str_contains($commerce, 'Math.floor((stock + 0.000001) / selectedLength)'),
    'The storefront must constrain quantities using the selected variant stock.'
);
$assert(
    str_contains($commerce, 'abortController = new AbortController()')
        && str_contains($commerce, 'currentContext !== context')
        && str_contains($commerce, 'serial !== requestSerial'),
    'Checkout must cancel and reject stale shipping quote responses.'
);
$assert(
    str_contains($editor, 'form="product-editor-form" data-submit-intent="publish"')
        && str_contains($editor, 'Save &amp; Publish')
        && str_contains($editorForm, 'id="product_submit_intent"'),
    'Publishing must submit the current editor form instead of publishing stale saved data.'
);
$assert(
    str_contains($editorForm, 'data-initial-editor-section')
        && str_contains($admin, 'addEventListener("invalid"')
        && str_contains($admin, 'showSection(holder.dataset.editorSection)'),
    'Product validation must reveal the tab containing the first invalid field.'
);
$assert(
    str_contains($editor, "!empty(\$errors['save'])")
        && str_contains($editor, "e((string)\$errors['save'])"),
    'Global editor save failures must be visible to the administrator.'
);
$assert(
    str_contains($draft, '<select name="unit_type" required')
        && !str_contains($draft, '<input type="hidden" name="unit_type" value="piece">'),
    'Draft creation must allow the administrator to choose the selling unit.'
);
$assert(
    !str_contains($editor, "\$size = (string) (\$fabric['size'] ?? '');")
        && !str_contains($editor, "\$color = (string) (\$fabric['color'] ?? '');"),
    'Submitted size and colour must not be overwritten with stale database values.'
);
$assert(
    str_contains($admin, 'variant.color || "—"')
        && str_contains($admin, 'variant.size || "—"'),
    'Empty variant values must render an em dash instead of a literal HTML entity.'
);
$assert(
    !str_contains($admin, 'loadVariants()') && str_contains($admin, 'reloadRows()'),
    'Variant mutations must reload the current table through the first-party controller.'
);

if ($failures !== []) {
    fwrite(STDERR, "Frontend integrity regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "frontend_integrity_regression_test: OK\n";
