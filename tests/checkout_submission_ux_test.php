<?php
/** Database-free contract for checkout submission UX reliability. */
$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/checkout.php');

$checks = [
    'desktop submit button has in-form live status region for processing announcements' =>
        str_contains($checkout, 'id="place_order_btn"') && str_contains($checkout, 'id="checkout_submit_status"') && str_contains($checkout, 'aria-live="polite"'),
    'shared submission state and processing transitions exist' =>
        str_contains($checkout, 'var submissionState = { inProgress: false, restoreTimer: null };') && str_contains($checkout, 'function enterSubmissionProcessing()') && str_contains($checkout, 'function exitSubmissionProcessing(reason)'),
    'processing state applies spinner text and aria-busy and aria-disabled controls' =>
        str_contains($checkout, 'Processing order…') && str_contains($checkout, 'checkoutForm.setAttribute(\'aria-busy\', \'true\')') && str_contains($checkout, 'button.setAttribute(\'aria-disabled\', disabled ? \'true\' : \'false\')'),
    'desktop and mobile buttons are synchronized by one control sync function' =>
        str_contains($checkout, 'function syncSubmitControls()') && str_contains($checkout, 'setPlaceOrderButtonDisabled(placeOrderBtn, disabled)') && str_contains($checkout, 'setPlaceOrderButtonDisabled(mobileSubmitBtn, disabled)'),
    'submit handler blocks re-entry and pending coupon or shipping operations' =>
        str_contains($checkout, 'if (submissionState.inProgress) {') && str_contains($checkout, 'if (couponRequestInFlight) {') && str_contains($checkout, 'if (shippingQuoteState.pending || shippingQuoteState.validKey !== shippingKey(shippingSnapshot()) || !shippingQuoteTokenInput.value) {'),
    'validation failure prevents processing transition and keeps context for correction' =>
        str_contains($checkout, 'var invalidFields = validateAddressSection();') && str_contains($checkout, 'if (invalidFields.length > 0) {') && !str_contains($checkout, 'setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);\n            setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, true);'),
    'mobile trigger uses requestSubmit but respects shared in-progress guard' =>
        str_contains($checkout, 'mobileSubmitBtn.addEventListener(\'click\'') && str_contains($checkout, 'if (submissionState.inProgress) {') && str_contains($checkout, 'checkoutForm.requestSubmit();'),
    'safe restoration is present for timeout fallback and bfcache pageshow' =>
        str_contains($checkout, 'submissionState.restoreTimer = window.setTimeout') && str_contains($checkout, 'window.addEventListener(\'pageshow\'') && str_contains($checkout, 'exitSubmissionProcessing(\'\');'),
    'normal native submission remains preserved behind one-time processing gate' =>
        str_contains($checkout, 'if (!enterSubmissionProcessing()) {') && !str_contains($checkout, 'ev.preventDefault();\n            return false;'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
