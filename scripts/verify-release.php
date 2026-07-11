<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$artifact = $argv[1] ?? '';
if ($artifact === '') {
    fwrite(STDERR, "Usage: php scripts/verify-release.php <artifact.zip|artifact-directory>\n");
    exit(1);
}

if (!is_file($artifact) && !is_dir($artifact)) {
    fwrite(STDERR, "[FAIL] Artifact not found: {$artifact}\n");
    exit(1);
}

$forbiddenPatterns = [
    '.git',
    '.git/',
    '.gitignore',
    '.gitattributes',
    'tmp/',
    'tmp_sessions/',
    'docs/',
    'composer.phar',
    'config/app-config.php',
    'secure-config.php',
    '.env',
    '.env.*',
    'tests/',
    'logs/',
    'scripts/smoke-regressions.php',
    'scripts/customer-smoke.ps1',
    'scripts/backfill-image-derivatives.php',
    'database/setup.php',
    'database/schema.sql',
];

$requiredEntries = [
    'database/migrate.php',
];

$requiredPatterns = [
    'database/migrations/*.sql',
];

function zip_path_matches(string $path, string $pattern): bool
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $pattern = ltrim(str_replace('\\', '/', $pattern), '/');
    if (str_ends_with($pattern, '/')) {
        $prefix = rtrim($pattern, '/');
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
    if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
        return fnmatch($pattern, $path, FNM_PATHNAME);
    }
    return $path === $pattern || str_starts_with($path, $pattern . '/');
}

function artifact_contains(array $entries, string $pattern): bool
{
    foreach ($entries as $entry) {
        if (zip_path_matches($entry, $pattern)) {
            return true;
        }
    }
    return false;
}

$failures = [];
$artifactEntries = [];
if (is_file($artifact)) {
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "[FAIL] ZipArchive extension is required to verify zip artifacts.\n");
        exit(1);
    }
    $zip = new ZipArchive();
    if ($zip->open($artifact) !== true) {
        fwrite(STDERR, "[FAIL] Cannot open artifact: {$artifact}\n");
        exit(1);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $artifactEntries[] = ltrim(str_replace('\\', '/', $name), '/');
        foreach ($forbiddenPatterns as $pattern) {
            if (zip_path_matches($name, $pattern)) {
                $failures[] = "Forbidden entry in artifact: {$name}";
                break;
            }
        }
    }
    $zip->close();
} else {
    $root = rtrim(str_replace('\\', '/', realpath($artifact) ?: $artifact), '/');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $abs = str_replace('\\', '/', $item->getPathname());
        $name = ltrim(substr($abs, strlen($root)), '/');
        $artifactEntries[] = $name;
        foreach ($forbiddenPatterns as $pattern) {
            if (zip_path_matches($name, $pattern)) {
                $failures[] = "Forbidden entry in artifact: {$name}";
                break;
            }
        }
    }
}

foreach ($requiredEntries as $entry) {
    if (!artifact_contains($artifactEntries, $entry)) {
        $failures[] = "Required release entry missing: {$entry}";
    }
}

foreach ($requiredPatterns as $pattern) {
    if (!artifact_contains($artifactEntries, $pattern)) {
        $failures[] = "Required release files missing: {$pattern}";
    }
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    fwrite(STDERR, '[FAIL] Artifact verification failed.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "[PASS] Artifact verification passed.\n");
fwrite(STDOUT, "Checked: {$artifact}\n");
exit(0);
