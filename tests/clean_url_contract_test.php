<?php

$failures = [];
$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
$router = (string) file_get_contents(__DIR__ . '/../router.php');

foreach ([
    'RewriteRule ^admin/([A-Za-z0-9-]+)\\.php$ /admin/$1 [R=301,L,NE]' => 'Admin GET canonical redirect is missing.',
    'RewriteRule ^admin/([A-Za-z0-9-]+)$ admin/$1.php [L,QSA]' => 'Admin clean-route rewrite is missing.',
] as $rule => $message) {
    if (!str_contains($htaccess, $rule)) {
        $failures[] = $message;
    }
}

if (str_contains($htaccess, '[R=307,L,NE]')) {
    $failures[] = 'Admin POST requests must not be redirected.';
}

if (!str_contains($router, "preg_match('#^admin/([A-Za-z0-9-]+)$#'")) {
    $failures[] = 'Local router does not support clean admin routes.';
}
if (!str_contains($router, "\$isReadRequest && preg_match('#^admin/([A-Za-z0-9-]+)\\.php$#'")
    || str_contains($router, 'http_response_code($isReadRequest ? 301 : 307)')) {
    $failures[] = 'Local router must redirect only read requests for legacy admin PHP URLs.';
}

$couponPage = (string) file_get_contents(__DIR__ . '/../admin/coupons.php');
if (substr_count($couponPage, 'action="/admin/coupons"') !== 4
    || str_contains($couponPage, 'action="coupons.php"')) {
    $failures[] = 'Coupon forms must submit directly to the canonical admin route.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Clean URL contract passed.' . PHP_EOL;
