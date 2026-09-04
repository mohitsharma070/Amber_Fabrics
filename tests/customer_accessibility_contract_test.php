<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$labelContracts = [
    'contact.php' => ['contact_name', 'contact_email', 'contact_country', 'contact_phone', 'contact_message'],
    'customer/register.php' => ['register_name', 'register_email', 'register_phone', 'register_country', 'register_password', 'register_confirm_password'],
    'customer/forgot-password.php' => ['forgot_email'],
    'customer/resend-verification.php' => ['resend_email'],
    'customer/reset-password.php' => ['reset_password', 'reset_confirm_password'],
    'customer/profile.php' => ['profile_name', 'profile_email', 'profile_phone', 'profile_country', 'address_label', 'address_full_name', 'address_phone', 'address_line', 'address_city', 'address_state', 'address_pincode', 'address_country', 'is_default_shipping', 'current_password', 'new_password', 'confirm_new_password'],
    'guest/order-access.php' => ['guest_order_number', 'guest_order_email'],
    'guest/support.php' => ['guest_support_subject', 'guest_support_message'],
    'guest/account-activate.php' => ['activation_password', 'activation_confirm_password'],
    'customer/order-view.php' => ['return_image_1', 'return_image_2'],
];

foreach ($labelContracts as $path => $ids) {
    $source = $read($path);
    foreach ($ids as $id) {
        $assert(str_contains($source, 'id="' . $id . '"'), $path . ' must retain control id ' . $id . '.');
        $assert(str_contains($source, 'for="' . $id . '"'), $path . ' must provide an explicit label for ' . $id . '.');
    }
}

foreach (['guest/order.php', 'customer/order-view.php'] as $path) {
    $source = $read($path);
    foreach (['aria-label="Return quantity', 'aria-label="Return reason"', 'aria-label="Optional return note"'] as $needle) {
        $assert(str_contains($source, $needle), $path . ' must provide a stable accessible return-control name for ' . $needle . '.');
    }
}

$supportPlugin = $read('plugins/support-tickets/plugin.php');
$assert(str_contains($supportPlugin, 'aria-label="Support subject"'), 'Customer support subject must have a stable accessible name.');
$assert(str_contains($supportPlugin, 'aria-label="Support message"'), 'Customer support message must have a stable accessible name.');

if ($failures !== []) {
    fwrite(STDERR, "Customer accessibility contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Customer accessibility contract: OK\n";
