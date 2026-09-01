<?php

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$checkoutSource = (string) file_get_contents(__DIR__ . '/../checkout.php');
$controlTag = static function (string $id) use ($checkoutSource): string {
    // Checkout attributes contain inline PHP whose closing delimiter includes ">".
    $pattern = '/<(?:input|select|textarea)\\b(?=[^\\r\\n]*\\bid="' . preg_quote($id, '/') . '")[^\\r\\n]*>/';
    return preg_match($pattern, $checkoutSource, $matches) === 1 ? $matches[0] : '';
};
$hasAttributes = static function (string $id, array $attributes) use ($controlTag): bool {
    $tag = $controlTag($id);
    if ($tag === '') {
        return false;
    }

    foreach ($attributes as $name => $value) {
        if (!str_contains($tag, $name . '="' . $value . '"')) {
            return false;
        }
    }

    return true;
};

$assert($hasAttributes('checkout_full_name', ['autocomplete' => 'name']), 'Full name must use autocomplete="name".');
$assert($hasAttributes('checkout_phone', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel']), 'Phone must provide tel type, autocomplete, and inputmode hints.');
$assert($hasAttributes('checkout_email', ['type' => 'email', 'autocomplete' => 'email']), 'Email must use email type and autocomplete.');
$assert($hasAttributes('checkout_address', ['autocomplete' => 'street-address']), 'Address must use street-address autocomplete.');
$assert($hasAttributes('checkout_city', ['autocomplete' => 'address-level2']), 'City must use address-level2 autocomplete.');
$assert($hasAttributes('checkout_state', ['autocomplete' => 'address-level1']), 'State must use address-level1 autocomplete.');
$assert($hasAttributes('checkout_pincode', ['autocomplete' => 'postal-code', 'inputmode' => 'numeric']), 'Pincode must provide postal-code autocomplete and numeric inputmode hints.');
$assert($hasAttributes('checkout_country', ['autocomplete' => 'country-name']), 'Country must use country-name autocomplete.');
$assert(!str_contains($controlTag('checkout_order_notes'), 'autocomplete='), 'Order notes must not declare misleading autocomplete semantics.');
$assert((bool) preg_match('/<form\\b(?=[^>]*\\bid="checkout_form")(?=[^>]*\\bnovalidate\\b)[^>]*>/s', $checkoutSource), 'Checkout form must retain novalidate.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Checkout input semantics contract passed.\n";
