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

$app = $read('js/app.js');
$commerce = $read('js/commerce.js');
$foundation = $read('css/foundation.css');
$storefront = $read('css/storefront.css');
$admin = $read('js/admin.js');
$adminStyle = $read('css/admin.css');
$header = $read('includes/views/layouts/header.php');
$footer = $read('includes/views/layouts/footer.php');
$interaction = $read('includes/partials/interaction-layer-v2.php');
$fabric = $read('fabric.php');
$home = $read('index.php');
$checkout = $read('checkout.php');
$cart = $read('cart.php');
$profile = $read('customer/profile.php');
$accountService = $read('includes/services/CustomerAccountService.php');
$adminHeader = $read('admin/partials/header.php');

foreach (['customer/orders.php', 'customer/order-view.php', 'customer/profile.php', 'guest/order.php'] as $path) {
    $source = $read($path);
    $assert(!preg_match('/\son[a-z]+\s*=|window\.confirm\s*\(/i', $source), $path . ' must not use inline browser confirmation handlers.');
}

foreach (['AmberUI.confirm = function', 'AmberUI.toast = function', 'AmberUI.setButtonLoading = function', 'window.adminConfirm = function'] as $api) {
    $assert(str_contains($app, $api), 'Shared application UI API missing: ' . $api);
}
$assert(str_contains($app, 'form.requestSubmit(submitter || undefined)') && str_contains($app, 'submittingForms'), 'Confirmed forms must preserve the submitter and block duplicate submission.');
$assert(str_contains($app, 'event.key === "Escape"') && str_contains($app, 'moveFocusWithin'), 'Shared dialogs must support Escape and keyboard focus trapping.');
$assert(str_contains($interaction, 'data-ui-confirm-dialog') && str_contains($interaction, 'data-ui-toast-region'), 'Shared layouts must include one accessible confirmation dialog and toast region.');
$assert(str_contains($commerce, 'data-ajax-cart') && str_contains($commerce, 'data-ui-checkout') && str_contains($commerce, 'Razorpay'), 'Commerce JavaScript must retain cart, checkout, and payment behavior.');
$assert(str_contains($checkout, 'data-checkout-config="<?php echo ui_data_json(') && str_contains($checkout, 'data-checkout-payable'), 'Checkout data and payable total must use safe attributes.');
$assert(str_contains($cart, 'data-confirm-variant="danger"'), 'Cart destructive actions must retain confirmation metadata.');

$assert(str_contains($header, 'data-ui-area="storefront"') && str_contains($header, "ui_asset('/css/foundation.css')") && str_contains($header, "ui_asset('/js/commerce.js')"), 'Storefront layout must load the first-party responsive system.');
$assert(str_contains($adminHeader, 'data-ui-area="admin"') && str_contains($adminHeader, "ui_asset('/css/admin.css')"), 'Admin layout must remain isolated from storefront assets.');
$assert(str_contains($foundation, '@media (prefers-reduced-motion: reduce)') && str_contains($foundation, 'min-block-size: 2.75rem'), 'Foundation controls must honor reduced motion and 44px touch targets.');
$assert(str_contains($storefront, '@media (max-width: 22rem)') || str_contains($storefront, '@media (max-width: 359'), 'Storefront must retain a narrow-mobile layout rule.');
$assert(str_contains($adminStyle, '[data-ui-area="admin"]') && str_contains($admin, 'dataset.adminTableReady'), 'Admin-only presentation and table enhancement must remain dedicated.');

$assert(str_contains($fabric, "SiteContext::url('/images/fabrics/'") && !str_contains($fabric, '<style'), 'Product media paths must be safe and product templates must not contain inline styles.');
$assert(str_contains($home, 'price_override') && str_contains($home, 'ProductAdminService::publicPath($row)'), 'Homepage pricing and product links must retain their product-aware behavior.');
$assert(str_contains($profile, '$cust = array_merge($cust, $profileValues)') && str_contains($accountService, "'country' => trim"), 'Invalid profile submissions must retain sanitized entered values.');
$assert(!preg_match('/bootstrap|data-bs-|\bbi\s+bi-/i', $app . $commerce . $foundation . $storefront . $admin . $adminStyle), 'First-party frontend assets must not depend on Bootstrap.');

if ($failures !== []) {
    fwrite(STDERR, "Customer frontend mobile hardening contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "customer_frontend_mobile_hardening_contract_test: OK\n";
