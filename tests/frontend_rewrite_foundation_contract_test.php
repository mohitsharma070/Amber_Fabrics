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

foreach ([
    'css/foundation.css',
    'css/storefront.css',
    'css/documents.css',
    'js/app.js',
    'js/commerce.js',
    'js/documents.js',
    'images/ui-icons.svg',
] as $path) {
    $assert(is_file($root . '/' . $path) && filesize($root . '/' . $path) > 0, $path . ' must exist and be non-empty.');
}

$core = $read('includes/helpers/core.php');
$app = $read('js/app.js');
$foundation = $read('css/foundation.css');
$sprite = $read('images/ui-icons.svg');

$assert(str_contains($core, 'function ui_data_json('), 'A centralized safe JSON attribute encoder is required.');
$assert(str_contains($core, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'), 'JSON attributes must use every JSON_HEX escaping flag.');
$assert(str_contains($core, 'ENT_QUOTES | ENT_SUBSTITUTE'), 'JSON attributes must use quote escaping and UTF-8 substitution.');
$assert(str_contains($core, 'function ui_asset(') && str_contains($core, 'filemtime('), 'A centralized cache-busted asset helper is required.');
$assert(str_contains($core, 'function ui_icon(') && str_contains($core, '/images/ui-icons.svg#icon-'), 'The UI icon helper must reference the first-party sprite.');

foreach (['AmberUI.confirm = function', 'AmberUI.toast = function', 'AmberUI.setButtonLoading = function', 'window.adminConfirm = function'] as $api) {
    $assert(str_contains($app, $api), 'Missing shared UI API: ' . $api);
}
$assert(str_contains($app, 'form.requestSubmit(submitter || undefined)'), 'Confirmed forms must preserve the original submitter through requestSubmit().');
$assert(str_contains($app, 'event.key === "Escape"') && str_contains($app, 'moveFocusWithin'), 'Shared overlays must support Escape and focus trapping.');
$assert(str_contains($app, 'if (pendingConfirm) {' ) && str_contains($app, 'return Promise.resolve(false);'), 'A second confirmation request must not replace an active dialog.');
$assert(str_contains($app, 'drawer.getAttribute("data-ui-opening") === "true"') && str_contains($app, 'drawer.setAttribute("data-ui-opening", "true")'), 'Rapid drawer-open requests must not acquire multiple scroll locks.');
$assert(str_contains($foundation, '@media (prefers-reduced-motion: reduce)'), 'The foundation must honor reduced-motion preferences.');
$assert(str_contains($foundation, 'min-block-size: 2.75rem'), 'Interactive controls must provide 44px touch targets.');
foreach (['.ui-spinner--small', '.ui-card__title', '.ui-surface-soft', '.ui-check__input', '.ui-check__label', '.ui-alert__link', '.ui-table__head--light', '.u-heading-6', '.u-p-5'] as $selector) {
    $assert(str_contains($foundation, $selector), 'The shared foundation must define used selector ' . $selector . '.');
}
$assert(substr_count($foundation, '.u-pt-2 {') === 1, 'Foundation utilities must not contain duplicate u-pt-2 declarations.');

preg_match_all('/<symbol\s+id="icon-[^"]+"/', $sprite, $iconMatches);
$assert(count($iconMatches[0]) === 38, 'The first-party sprite must contain exactly the 38 audited icons.');

if ($failures !== []) {
    fwrite(STDERR, "Frontend rewrite foundation contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: frontend rewrite foundation contracts passed\n";
