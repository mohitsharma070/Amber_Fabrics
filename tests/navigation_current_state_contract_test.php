<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$header = (string) file_get_contents($root . '/includes/views/layouts/header.php');
$footer = (string) file_get_contents($root . '/includes/views/layouts/footer.php');
$navigation = $header . $footer;

$assert(str_contains($header, '$ariaCurrent = static function'), 'Header must define a server-rendered current-state formatter.');
$assert(str_contains($header, "return \$isCurrent ? ' aria-current=\"page\"' : '';"), 'Current-state formatter must emit aria-current only for current destinations.');
$assert(str_contains($header, '$isShopPage') && str_contains($header, "['catalog.php','fabric.php']"), 'Shop current-state grouping must include catalog and product pages.');
$assert(str_contains($header, '$isContactPage') && str_contains($header, "['contact.php','thank-you.php']"), 'Contact current-state grouping must preserve contact and thank-you pages.');
$assert(str_contains($header, '$isOrdersPage') && str_contains($header, "['orders.php','order-view.php']"), 'Orders current-state grouping must include order detail pages.');

$requiredReferences = [
    '$ariaCurrent($isHomePage)' => 'Home links must use the server-rendered current state.',
    '$ariaCurrent($isShopPage)' => 'Shop links must use the server-rendered current state.',
    '$ariaCurrent($isAboutPage)' => 'About links must use the server-rendered current state.',
    '$ariaCurrent($isContactPage)' => 'Contact links must use the server-rendered current state.',
    '$ariaCurrent($isCartPage)' => 'Cart links must use the server-rendered current state.',
    '$ariaCurrent($isOrdersPage)' => 'Orders links must use the server-rendered current state.',
    '$ariaCurrent($isProfilePage)' => 'Account links must use the server-rendered current state.',
    '$ariaCurrent($isRegisterPage)' => 'Register links must use the server-rendered current state.',
];
foreach ($requiredReferences as $reference => $message) {
    $assert(substr_count($navigation, $reference) >= 1, $message);
}
$assert(substr_count($header, '$ariaCurrent($isLoginPage)') >= 2, 'Login destinations must expose their server-rendered current state.');
$assert(str_contains($footer, '$ariaCurrent($isAccountBottomPage)'), 'Mobile bottom account destination must expose its current state.');
$assert(substr_count($navigation, 'aria-current="page"') === 1, 'The aria-current value must be defined once and rendered dynamically.');
$assert(!str_contains($header, 'header-cat-toggle" aria-current'), 'Category menu trigger must not receive aria-current.');
$assert(!str_contains($header, 'action="/customer/logout.php" aria-current'), 'Logout form must not receive aria-current.');
$assert(!str_contains($footer, 'mobile-bottom-nav__menu-btn') || !str_contains($footer, 'mobile-bottom-nav__menu-btn aria-current'), 'Mobile menu button must not receive aria-current.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "navigation_current_state_contract_test: OK\n";
