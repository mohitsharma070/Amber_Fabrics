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

$version = '5.3.3';
$cssUrl = '/css/bootstrap-' . $version . '.min.css';
$bundleUrl = '/js/bootstrap.bundle-' . $version . '.min.js';
$cssPath = $root . $cssUrl;
$bundlePath = $root . $bundleUrl;

$cssConsumers = [
    'includes/views/layouts/header.php',
    'admin/partials/header.php',
    'admin/login.php',
    'admin/verify-otp.php',
    'plugins/back-in-stock-alert/plugin.php',
];
$bundleConsumers = [
    'includes/views/layouts/footer.php',
    'admin/partials/footer.php',
    'admin/login.php',
    'admin/verify-otp.php',
];

foreach ($cssConsumers as $path) {
    $source = $read($path);
    $assert(substr_count($source, $cssUrl) === 1, $path . ' must load the pinned first-party Bootstrap CSS exactly once.');
}
foreach ($bundleConsumers as $path) {
    $source = $read($path);
    $assert(substr_count($source, $bundleUrl) === 1, $path . ' must load the pinned first-party Bootstrap bundle exactly once.');
}

$productionSources = '';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relativePath, 'tests/') || str_starts_with($relativePath, 'vendor/')) {
        continue;
    }
    $productionSources .= (string) file_get_contents($file->getPathname());
}

$assert(!str_contains($productionSources, 'cdn.jsdelivr.net/npm/bootstrap@'), 'Production PHP must not load Bootstrap from jsDelivr.');
$assert(substr_count($productionSources, $cssUrl) === count($cssConsumers), 'Only the intended production surfaces may load the first-party Bootstrap CSS.');
$assert(substr_count($productionSources, $bundleUrl) === count($bundleConsumers), 'Only the intended production surfaces may load the first-party Bootstrap bundle.');

$assert(is_file($cssPath), 'The pinned first-party Bootstrap CSS asset must exist.');
$assert(is_file($bundlePath), 'The pinned first-party Bootstrap bundle asset must exist.');
if (is_file($cssPath)) {
    $assert(hash_file('sha256', $cssPath) === '3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8', 'Bootstrap CSS must match the exact 5.3.3 npm distribution.');
    $assert(str_contains((string) file_get_contents($cssPath), 'Bootstrap  v5.3.3'), 'Bootstrap CSS must identify version 5.3.3.');
}
if (is_file($bundlePath)) {
    $assert(hash_file('sha256', $bundlePath) === '0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c', 'Bootstrap bundle must match the exact 5.3.3 npm distribution including Popper.');
    $assert(str_contains((string) file_get_contents($bundlePath), 'Bootstrap v5.3.3'), 'Bootstrap bundle must identify version 5.3.3.');
}

$package = json_decode($read('package.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = json_decode($read('package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$assert(($package['devDependencies']['bootstrap'] ?? null) === $version, 'package.json must pin Bootstrap exactly to 5.3.3.');
$assert(($lock['packages']['']['devDependencies']['bootstrap'] ?? null) === $version, 'package-lock.json must pin the root Bootstrap requirement exactly to 5.3.3.');
$assert(($lock['packages']['node_modules/bootstrap']['version'] ?? null) === $version, 'The locked Bootstrap package must remain at 5.3.3.');

$init = $read('includes/init.php');
$assert(str_contains($init, "'default-src' => [\"'self'\"]"), 'CSP default-src must remain self-only.');
$assert(!str_contains($init, "'unsafe-eval'"), 'CSP must not allow unsafe-eval.');
$assert(!preg_match("/'script-src'\\s*=>\\s*\\[[^\\]]*'unsafe-inline'/s", $init), 'CSP script-src must not allow unsafe-inline.');
$assert(!preg_match("/'connect-src'\\s*=>\\s*\\[[^\\]]*cdn\\.jsdelivr\\.net/s", $init), 'Self-hosting Bootstrap must remove the unnecessary jsDelivr connect-src permission.');
foreach (['style-src', 'script-src', 'font-src'] as $directive) {
    $assert(preg_match("/'" . preg_quote($directive, '/') . "'\\s*=>\\s*\\[[^\\]]*cdn\\.jsdelivr\\.net/s", $init) === 1, 'jsDelivr must remain narrowly allowed by ' . $directive . ' for existing non-Bootstrap CDN assets.');
}

foreach (['tests/e2e/storefront.spec.js', 'tests/e2e/accessibility.spec.js'] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'cdn.jsdelivr.net/npm/bootstrap@'), $path . ' must not intercept production Bootstrap CDN URLs.');
    $assert(!str_contains($source, "require.resolve('bootstrap/"), $path . ' must exercise the same first-party Bootstrap assets as production.');
    $assert(str_contains($source, 'forbiddenProviderHosts'), $path . ' must retain unrelated provider network blocking.');
}

if ($failures !== []) {
    fwrite(STDERR, "Bootstrap delivery contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Bootstrap delivery contract: OK\n";
