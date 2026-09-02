<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectedStyleVersion = '20260901a';
$expectedScriptVersion = '20260902a';
$expectedAdminScriptVersion = '20260825a';
$consumers = [
    'includes/views/layouts/header.php',
    'admin/partials/header.php',
    'admin/login.php',
    'admin/verify-otp.php',
];
$versions = [];
foreach ($consumers as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $matches = [];
    $count = preg_match_all('/style\.css\?v=([^"\']+)/', $source, $matches);
    $assert($count === 1, $relativePath . ' must reference style.css exactly once with a cache version.');
    if ($count === 1) {
        $versions[$relativePath] = $matches[1][0];
    }
}

$assert($versions !== [] && count(array_unique($versions)) === 1, 'Every first-party style.css consumer must use one consistent cache version.');
$assert(($versions[$consumers[0]] ?? '') === $expectedStyleVersion, 'The current stylesheet cache version must be deliberately updated to ' . $expectedStyleVersion . '.');
$assert(!str_contains(implode("\n", array_map(
    static fn (string $path): string => (string) file_get_contents($root . '/' . $path),
    $consumers
)), 'style.css?v=20260822a'), 'The historical stylesheet cache version must not remain in first-party consumers.');

$scriptConsumers = [
    'includes/views/layouts/header.php',
    'admin/partials/footer.php',
    'admin/login.php',
    'admin/verify-otp.php',
];
$scriptVersions = [];
foreach ($scriptConsumers as $relativePath) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $matches = [];
    $count = preg_match_all('/script\.js\?v=([^"\']+)/', $source, $matches);
    $assert($count === 1, $relativePath . ' must reference script.js exactly once with a cache version.');
    if ($count === 1) {
        $scriptVersions[$relativePath] = $matches[1][0];
    }
}
$assert($scriptVersions !== [] && count(array_unique($scriptVersions)) === 1, 'Every shared script.js consumer must use one consistent cache version.');
$assert(($scriptVersions[$scriptConsumers[0]] ?? '') === $expectedScriptVersion, 'The shared interaction script cache version must be updated to ' . $expectedScriptVersion . '.');

$adminFooter = (string) file_get_contents($root . '/admin/partials/footer.php');
$assert(
    substr_count($adminFooter, 'admin.js?v=') === 1
        && str_contains($adminFooter, 'admin.js?v=' . $expectedAdminScriptVersion),
    'The admin JavaScript cache version must match its latest first-party asset update.'
);

$faviconConsumers = [
    'includes/views/layouts/header.php' => '/images/',
    'admin/partials/header.php' => '../images/',
];
foreach ($faviconConsumers as $relativePath => $assetPrefix) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    $assert(
        str_contains($source, 'type="image/svg+xml" href="' . $assetPrefix . 'favicon-light.svg" media="(prefers-color-scheme: light)"')
            && str_contains($source, 'type="image/svg+xml" href="' . $assetPrefix . 'favicon-dark.svg" media="(prefers-color-scheme: dark)"')
            && str_contains($source, 'rel="alternate icon" type="image/svg+xml" href="' . $assetPrefix . 'favicon-light.svg"')
            && !str_contains($source, 'type="image/png" href="' . $assetPrefix . 'favicon-light.svg"'),
        $relativePath . ' must declare SVG favicon files using their actual SVG MIME type while retaining light/dark behavior.'
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "asset_version_contract_test: OK\n";
