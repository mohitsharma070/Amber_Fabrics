<?php

declare(strict_types=1);

/**
 * Regression checks for canonical URL construction without database access.
 */

$config = [];

function _cfg(string $key, string $default = ''): string
{
    global $config;
    return (string) ($config[$key] ?? $default);
}

require_once dirname(__DIR__) . '/includes/SiteContext.php';

$config['APP_URL'] = 'https://store.example.com/';
if (SiteContext::url('/catalog.php') !== 'https://store.example.com/catalog.php') {
    fwrite(STDERR, "Configured APP_URL was not used.\n");
    exit(1);
}

$_SERVER['SERVER_NAME'] = 'attacker.example';
$config['APP_URL'] = '';
if (SiteContext::url('/catalog.php') !== 'http://localhost/catalog.php') {
    fwrite(STDERR, "URL fallback must not use the request host.\n");
    exit(1);
}

echo "Site context test passed.\n";
