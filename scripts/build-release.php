<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/release-security.php';

$root = dirname(__DIR__);
$distDir = $root . DIRECTORY_SEPARATOR . 'dist';
$releaseId = date('Ymd-His');
$stagingDir = $distDir . DIRECTORY_SEPARATOR . 'release-' . $releaseId;
$zipPath = $distDir . DIRECTORY_SEPARATOR . 'release-' . $releaseId . '.zip';
$ignoreFile = $root . DIRECTORY_SEPARATOR . '.deployignore';

if (!is_dir($distDir) && !mkdir($distDir, 0775, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Failed to create dist directory.\n");
    exit(1);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function cleanup_old_releases(string $distDir): void
{
    if (!is_dir($distDir)) {
        return;
    }
    $entries = scandir($distDir);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/^release-\d{8}-\d{6}(\.zip)?$/', $entry)) {
            continue;
        }
        $target = $distDir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) {
            rrmdir($target);
        } elseif (is_file($target)) {
            @unlink($target);
        }
    }
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function read_ignore_patterns(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $patterns = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $patterns[] = normalize_path($line);
    }
    return $patterns;
}

function path_matches_pattern(string $relativePath, string $pattern): bool
{
    $relativePath = ltrim(normalize_path($relativePath), '/');
    $pattern = ltrim(normalize_path($pattern), '/');
    $patternDir = rtrim($pattern, '/');

    if (str_ends_with($pattern, '/')) {
        return $relativePath === $patternDir || str_starts_with($relativePath, $patternDir . '/');
    }

    if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
        return fnmatch($pattern, $relativePath, FNM_PATHNAME);
    }

    return $relativePath === $pattern || str_starts_with($relativePath, $pattern . '/');
}

function should_exclude(string $relativePath, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (path_matches_pattern($relativePath, $pattern)) {
            return true;
        }
    }
    return false;
}

function assert_htaccess_has_no_secrets(string $rootDir): void
{
    $path = $rootDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($path)) {
        return;
    }
    $contents = (string) file_get_contents($path);
    $dangerPatterns = [
        '/^\s*SetEnv\s+DB_PASSWORD\b/im',
        '/^\s*SetEnv\s+RAZORPAY_KEY_SECRET\b/im',
        '/^\s*SetEnv\s+RAZORPAY_WEBHOOK_SECRET\b/im',
        '/^\s*SetEnv\s+SMTP_PASSWORD\b/im',
        '/^\s*SetEnv\s+META_CAPI_ACCESS_TOKEN\b/im',
    ];
    foreach ($dangerPatterns as $pattern) {
        if (preg_match($pattern, $contents) === 1) {
            throw new RuntimeException('.htaccess contains secret-bearing SetEnv directives. Move secrets to environment variables or secure-config.php outside web root.');
        }
    }
}

function staged_path(string $stagingDir, string $relativePath): string
{
    return $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function assert_release_migration_files(string $stagingDir): void
{
    $requiredFiles = [
        'database/migrate.php',
    ];
    foreach ($requiredFiles as $relativePath) {
        if (!is_file(staged_path($stagingDir, $relativePath))) {
            throw new RuntimeException("Release artifact is missing required file: {$relativePath}");
        }
    }

    $migrationFiles = glob(staged_path($stagingDir, 'database/migrations') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    if (count($migrationFiles) === 0) {
        throw new RuntimeException('Release artifact must include at least one database/migrations/*.sql file.');
    }

    $forbiddenFiles = [
        'database/setup.php',
        'database/schema.sql',
    ];
    foreach ($forbiddenFiles as $relativePath) {
        if (file_exists(staged_path($stagingDir, $relativePath))) {
            throw new RuntimeException("Release artifact contains setup-only database file: {$relativePath}");
        }
    }
}

$sourceSecurityViolations = release_security_scan($root);
if ($sourceSecurityViolations !== []) {
    foreach ($sourceSecurityViolations as $violation) {
        fwrite(STDERR, '[FAIL] Potential embedded credential: ' . $violation . PHP_EOL);
    }
    fwrite(STDERR, "[FAIL] Source credential scan failed. Secret values were not printed." . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "[PASS] Source credential scan passed." . PHP_EOL);

try {
    assert_htaccess_has_no_secrets($root);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

cleanup_old_releases($distDir);
$patterns = read_ignore_patterns($ignoreFile);
rrmdir($stagingDir);
if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
    fwrite(STDERR, "Failed to create staging directory.\n");
    exit(1);
}

$copied = 0;
$excluded = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $item) {
    $absolute = $item->getPathname();
    $relative = normalize_path(substr($absolute, strlen($root) + 1));

    if (str_starts_with($relative, 'dist/')) {
        continue;
    }

    if (should_exclude($relative, $patterns)) {
        $excluded++;
        if ($item->isDir()) {
            $it->next();
        }
        continue;
    }

    $target = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if ($item->isDir()) {
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            fwrite(STDERR, "Failed to create directory in staging: {$relative}\n");
            exit(1);
        }
        continue;
    }

    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        fwrite(STDERR, "Failed to create parent directory in staging: {$relative}\n");
        exit(1);
    }
    if (!copy($absolute, $target)) {
        fwrite(STDERR, "Failed to copy file: {$relative}\n");
        exit(1);
    }
    $copied++;
}

try {
    assert_release_migration_files($stagingDir);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    rrmdir($stagingDir);
    exit(1);
}

$artifactType = 'directory';
$artifactPath = $stagingDir;
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "[FAIL] Could not create archive {$zipPath}\n");
        exit(1);
    }

    $stageIt = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($stageIt as $item) {
        $itemPath = $item->getPathname();
        $local = normalize_path(substr($itemPath, strlen($stagingDir) + 1));
        if ($item->isDir()) {
            $zip->addEmptyDir($local);
        } else {
            $zip->addFile($itemPath, $local);
        }
    }
    $zip->close();
    $artifactType = 'zip';
    $artifactPath = $zipPath;
    rrmdir($stagingDir);
}

fwrite(STDOUT, "[PASS] Release artifact created.\n");
fwrite(STDOUT, "Artifact type: {$artifactType}\n");
fwrite(STDOUT, "Artifact: {$artifactPath}\n");
fwrite(STDOUT, "Staging: {$stagingDir}\n");
fwrite(STDOUT, "Files copied: {$copied}\n");
fwrite(STDOUT, "Entries excluded by pattern: {$excluded}\n");
fwrite(STDOUT, "Next: php scripts/verify-release.php \"{$artifactPath}\"\n");
exit(0);
