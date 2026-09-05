<?php

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// 1. Verify the default remains "New arrivals" and normal config path exposes it
$config = require __DIR__ . '/../config/plugins.php';
$assert(
    isset($config['settings']['recommendations']['title_new_arrivals']) && 
    $config['settings']['recommendations']['title_new_arrivals'] === 'New arrivals',
    'Default title_new_arrivals must be "New arrivals"'
);

// 2. Verify explicit RECOMMENDATIONS_TITLE_NEW_ARRIVALS override is respected
if (!function_exists('_cfg')) {
    function _cfg(string $key, $default = '') {
        if ($key === 'RECOMMENDATIONS_TITLE_NEW_ARRIVALS') {
            return 'Latest Additions';
        }
        return $default;
    }
}

// require is cached, so we must reload it by reading the file or unsetting it?
// Actually require returns the array each time since it returns an array directly, but require only executes once!
// Use include to evaluate it again.
$configWithOverride = include __DIR__ . '/../config/plugins.php';
$assert(
    isset($configWithOverride['settings']['recommendations']['title_new_arrivals']) && 
    $configWithOverride['settings']['recommendations']['title_new_arrivals'] === 'Latest Additions',
    'Explicit RECOMMENDATIONS_TITLE_NEW_ARRIVALS override must be respected'
);

if (!empty($failures)) {
    fwrite(STDERR, "Recommendation config contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Recommendation config contract test passed.\n";
exit(0);

