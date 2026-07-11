<?php
/** Database-free contract for checkout mobile fixed-bar and cookie-consent layout safety. */
$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/checkout.php');
$styles = (string) file_get_contents($root . '/css/style.css');
$footer = (string) file_get_contents($root . '/includes/footer.php');
$script = (string) file_get_contents($root . '/js/script.js');

$checks = [
    'checkout mobile submit bar uses dedicated id/class and no hardcoded spacer' =>
        str_contains($checkout, 'id="checkout_mobile_submit_bar"')
        && str_contains($checkout, 'class="checkout-mobile-submit-bar d-lg-none"')
        && !str_contains($checkout, 'style="height:88px;"'),

    'checkout script measures fixed elements and publishes CSS vars' =>
        str_contains($checkout, 'function syncMobileCheckoutLayout()')
        && str_contains($checkout, 'document.documentElement.style.setProperty(\'--cookie-consent-height\'')
        && str_contains($checkout, 'document.documentElement.style.setProperty(\'--checkout-mobile-bar-height\''),

    'checkout script hides mobile bar when cookie banner is visible' =>
        str_contains($checkout, 'document.body.classList.toggle(\'checkout-mobile-bar-hidden\', cookieVisible || !isMobileViewport);')
        && str_contains($checkout, 'mobileSubmitBar.setAttribute(\'aria-hidden\', (cookieVisible || !isMobileViewport) ? \'true\' : \'false\');'),

    'cookie banner has shared class and no inline z-index' =>
        str_contains($footer, 'class="cookie-consent-banner position-fixed bottom-0 start-0 end-0 p-3')
        && !str_contains($footer, 'style="z-index:1085;"'),

    'styles define shared vars, mobile spacing, and fixed-layer z-index tokens' =>
        str_contains($styles, '--checkout-mobile-bar-height: 0px;')
        && str_contains($styles, '--cookie-consent-height: 0px;')
        && str_contains($styles, 'body.checkout-has-mobile-submit-bar {')
        && str_contains($styles, 'body.checkout-mobile-bar-hidden .checkout-mobile-submit-bar {')
        && str_contains($styles, 'scroll-margin-bottom: calc(max(var(--checkout-mobile-bar-height), var(--cookie-consent-height))'),

    'cookie consent script emits layout change events for consumers' =>
        str_contains($script, 'cookie-consent-visibility-change')
        && str_contains($script, 'function notifyConsentLayout()')
        && str_contains($script, 'document.dispatchEvent(new CustomEvent("cookie-consent-visibility-change"'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
