<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only run from CLI." . PHP_EOL;
    exit(1);
}

$options = getopt('', ['dir::', 'dry-run', 'force', 'help']);
if (isset($options['help'])) {
    echo "Backfill image derivatives (thumbnails + responsive WebP)" . PHP_EOL;
    echo "Usage:" . PHP_EOL;
    echo "  php scripts/backfill-image-derivatives.php [--dir=images/fabrics] [--dry-run] [--force]" . PHP_EOL;
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  --dir      Relative directory from project root. Default: images/fabrics" . PHP_EOL;
    echo "  --dry-run  Show files that would be processed, without writing files" . PHP_EOL;
    echo "  --force    Regenerate derivatives even when a thumbnail already exists" . PHP_EOL;
    exit(0);
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve project root." . PHP_EOL);
    exit(1);
}

$targetRelative = (string) ($options['dir'] ?? 'images/fabrics');
$targetRelative = trim(str_replace('\\', '/', $targetRelative), '/');
if ($targetRelative === '') {
    $targetRelative = 'images/fabrics';
}

$targetAbsolute = realpath($projectRoot . DIRECTORY_SEPARATOR . $targetRelative);
if ($targetAbsolute === false || !is_dir($targetAbsolute)) {
    fwrite(STDERR, "Directory not found: {$targetRelative}" . PHP_EOL);
    exit(1);
}

if (strpos(str_replace('\\', '/', $targetAbsolute), str_replace('\\', '/', $projectRoot)) !== 0) {
    fwrite(STDERR, "Unsafe directory path." . PHP_EOL);
    exit(1);
}

$configFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app-config.php';
$allConfig = is_file($configFile) ? require $configFile : [];
if (is_array($allConfig)) {
    $activeConfig = $allConfig['local'] ?? $allConfig['production'] ?? [];
    if (is_array($activeConfig)) {
        $GLOBALS['_app_config'] = $activeConfig;
    }
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';

$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

function is_supported_original_image(string $name): bool
{
    if ($name === '' || $name[0] === '.') {
        return false;
    }
    if (preg_match('/-(thumb|\d+w)\.(?:webp|jpe?g|png)$/i', $name)) {
        return false;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
}

function derivative_thumb_exists(string $absoluteDir, string $filename): bool
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $thumbWebp = $absoluteDir . DIRECTORY_SEPARATOR . $base . '-thumb.webp';
    if (is_file($thumbWebp)) {
        return true;
    }

    $thumbFallback = $absoluteDir . DIRECTORY_SEPARATOR . $base . '-thumb.' . $ext;
    return is_file($thumbFallback);
}

$entries = scandir($targetAbsolute);
if ($entries === false) {
    fwrite(STDERR, "Failed to scan directory: {$targetRelative}" . PHP_EOL);
    exit(1);
}

$totalCandidates = 0;
$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($entries as $entry) {
    if (!is_supported_original_image($entry)) {
        continue;
    }

    $absoluteFile = $targetAbsolute . DIRECTORY_SEPARATOR . $entry;
    if (!is_file($absoluteFile)) {
        continue;
    }

    $totalCandidates++;

    if (!$force && derivative_thumb_exists($targetAbsolute, $entry)) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo "[DRY-RUN] would process: {$targetRelative}/{$entry}" . PHP_EOL;
        $processed++;
        continue;
    }

    try {
        image_pipeline_generate_derivatives($absoluteFile);
        echo "[OK] {$targetRelative}/{$entry}" . PHP_EOL;
        $processed++;
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "[FAIL] {$targetRelative}/{$entry} :: " . $e->getMessage() . PHP_EOL);
    }
}

echo PHP_EOL;
echo "Summary" . PHP_EOL;
echo "  Candidates: {$totalCandidates}" . PHP_EOL;
echo "  Processed:  {$processed}" . PHP_EOL;
echo "  Skipped:    {$skipped}" . PHP_EOL;
echo "  Errors:     {$errors}" . PHP_EOL;

exit($errors > 0 ? 1 : 0);
