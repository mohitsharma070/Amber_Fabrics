<?php

$failures = [];
$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
$router = (string) file_get_contents(__DIR__ . '/../router.php');

foreach ([
    'RewriteRule ^admin/([A-Za-z0-9-]+)\\.php$ /admin/$1 [R=301,L,NE]' => 'Admin GET canonical redirect is missing.',
    'RewriteRule ^admin/([A-Za-z0-9-]+)\\.php$ /admin/$1 [R=307,L,NE]' => 'Admin method-preserving redirect is missing.',
    'RewriteRule ^admin/([A-Za-z0-9-]+)$ admin/$1.php [L,QSA]' => 'Admin clean-route rewrite is missing.',
] as $rule => $message) {
    if (!str_contains($htaccess, $rule)) {
        $failures[] = $message;
    }
}

if (!str_contains($router, "preg_match('#^admin/([A-Za-z0-9-]+)$#'")) {
    $failures[] = 'Local router does not support clean admin routes.';
}
if (!str_contains($router, "preg_match('#^admin/([A-Za-z0-9-]+)\\.php$#'")
    || !str_contains($router, 'http_response_code($isReadRequest ? 301 : 307)')) {
    $failures[] = 'Local router does not redirect legacy admin PHP URLs.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Clean URL contract passed.' . PHP_EOL;
