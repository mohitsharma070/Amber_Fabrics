<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$root = dirname(__DIR__);
$delete = in_array('--delete', $argv, true);

$raw = shell_exec('git ls-files');
if (!is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "[FAIL] Unable to read tracked files via git ls-files.\n");
    exit(1);
}

$tracked = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));

$forbiddenPatterns = [
    '#^node_modules/#',
    '#^artifacts/#',
    '#^tmp/#',
    '#^tmp_sessions/#',
    '#^Documentation-for-Unified-Outbound-API\.pdf$#',
    '#^scripts/debug-.*\.php$#',
    '#^scripts/verify-courier-db\.php$#',
];

$violations = [];
foreach ($tracked as $path) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $path) === 1) {
            $violations[] = $path;
            break;
        }
    }
}

if (empty($violations)) {
    fwrite(STDOUT, "[PASS] Production allowlist check passed.\n");
    exit(0);
}

fwrite(STDERR, "[FAIL] Found non-production tracked files:\n");
foreach ($violations as $path) {
    fwrite(STDERR, " - {$path}\n");
}

if ($delete) {
    $args = array_map('escapeshellarg', $violations);
    $cmd = 'git rm -r -f --ignore-unmatch ' . implode(' ', $args);
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "[FAIL] Cleanup command failed.\n");
        exit(2);
    }
    fwrite(STDOUT, "[PASS] Removed non-production tracked files listed above.\n");
    exit(0);
}

fwrite(STDOUT, "Run with --delete to remove them automatically.\n");
exit(2);
