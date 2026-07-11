<?php
/**
 * High-confidence source credential scan used by local release builds and CI.
 * It reports only file names, line numbers, and rule names; never secret values.
 */

function release_security_scan(string $root): array
{
    $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
    $excludedDirectories = [
        '.git', 'vendor', 'node_modules', 'dist', 'tmp', 'tmp_sessions',
        'artifacts', 'images',
    ];
    $allowedExtensions = [
        'php', 'md', 'json', 'js', 'sql', 'xml', 'yml', 'yaml', 'ini',
        'conf', 'config', 'txt',
    ];
    $specialFileNames = ['.htaccess', '.env', '.env.example'];
    $rules = [
        'private-key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'gmail-app-password' => '/Password\s*=\s*[' . "'" . '"][a-z]{4}(?:\s+[a-z]{4}){3}[' . "'" . '"]/i',
        'razorpay-live-key' => '/\brzp_live_(?!x{6,})[A-Za-z0-9]{8,}\b/i',
        'aws-access-key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'slack-token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
    ];

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $item) use ($excludedDirectories): bool {
            return !$item->isDir() || !in_array($item->getFilename(), $excludedDirectories, true);
        }
    );
    $iterator = new RecursiveIteratorIterator($filter);
    $violations = [];

    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->getSize() > 2 * 1024 * 1024) {
            continue;
        }
        $name = $item->getFilename();
        $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($root))), '/');
        if ($relative === 'config/app-config.php' || $relative === 'secure-config.php' || str_starts_with($relative, '.env')) {
            continue;
        }
        $extension = strtolower($item->getExtension());
        if (!in_array($extension, $allowedExtensions, true) && !in_array($name, $specialFileNames, true)) {
            continue;
        }

        $contents = file_get_contents($item->getPathname());
        if (!is_string($contents)) {
            continue;
        }
        $lines = preg_split('/\R/', $contents) ?: [];
        foreach ($lines as $index => $line) {
            foreach ($rules as $rule => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $violations[] = sprintf('%s:%d [%s]', $relative, $index + 1, $rule);
                }
            }
        }
    }

    return array_values(array_unique($violations));
}

function release_security_print_result(string $root): int
{
    $violations = release_security_scan($root);
    if ($violations === []) {
        fwrite(STDOUT, "[PASS] Source credential scan passed.\n");
        return 0;
    }

    foreach ($violations as $violation) {
        fwrite(STDERR, '[FAIL] Potential embedded credential: ' . $violation . PHP_EOL);
    }
    fwrite(STDERR, "[FAIL] Source credential scan failed. Secret values were not printed.\n");
    return 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("Forbidden\n");
    }
    exit(release_security_print_result(dirname(__DIR__)));
}
