<?php
/** Database-free contract for checkout's custom novalidate accessibility layer. */
$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/checkout.php');
$placeOrder = (string) file_get_contents($root . '/place-order.php');
$core = (string) file_get_contents($root . '/includes/helpers/core.php');

$checks = [
    'blank required delivery fields have specific client and server messages' =>
        str_contains($checkout, "'Full name is required.'") && str_contains($checkout, "'Phone is required.'") && str_contains($checkout, "'Address is required.'") && str_contains($placeOrder, "'State is required.'"),
    'email phone and Indian pincode use shared backend-compatible constraints' =>
        str_contains($checkout, 'checkoutValidationRules') && str_contains($core, 'checkout_validation_constraints') && str_contains($placeOrder, "['phone_pattern']") && str_contains($placeOrder, "['pincode_pattern']"),
    'weak and mismatched account passwords are validated against the shared policy' =>
        str_contains($checkout, 'password_min_length') && str_contains($checkout, 'password_uppercase_pattern') && str_contains($checkout, 'Passwords do not match.') && str_contains($core, 'password_strength_error'),
    'disabling account creation clears passwords, required state and errors' =>
        str_contains($checkout, 'createAccountPassword.value = \'\'') && str_contains($checkout, 'createAccountPassword.required = enabled') && str_contains($checkout, "setFieldError(createAccountPassword, '')"),
    'every validated field has linked inline error semantics' =>
        str_contains($checkout, 'aria-describedby="checkout_full_name_error"') && str_contains($checkout, 'aria-describedby="checkout_create_account_password_error"') && str_contains($checkout, "input.setAttribute('aria-invalid'") && str_contains($checkout, 'checkout_validation_summary'),
    'keyboard submission focuses the first invalid control without collapsing sections' =>
        str_contains($checkout, 'focusFirstError();') && !str_contains($checkout, 'setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, false);\n                setSectionCollapsed(sectionPayment'),
    'correcting an invalid field clears its inline error' =>
        str_contains($checkout, "field.getAttribute('aria-invalid') === 'true'") && str_contains($checkout, 'validateAddressSection();'),
    'appropriate keyboard and autofill input metadata is present' =>
        str_contains($checkout, 'inputmode="tel"') && str_contains($checkout, 'inputmode="email"') && str_contains($checkout, 'inputmode="numeric"') && str_contains($checkout, 'autocomplete="postal-code"'),
];
$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
