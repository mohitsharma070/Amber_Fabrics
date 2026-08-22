<?php
declare(strict_types=1);

/**
 * Load an allowlist of secrets from a host-managed file outside the
 * application root. Existing process environment variables always win.
 *
 * @param string[] $allowedKeys
 */
function app_private_env_load(array $allowedKeys, string $applicationRoot): bool
{
    $missingKeys = [];
    foreach ($allowedKeys as $key) {
        if (getenv($key) === false && !isset($_SERVER[$key])) {
            $missingKeys[] = $key;
        }
    }
    if ($missingKeys === []) {
        return false;
    }

    $configuredPath = getenv('APP_SECRETS_FILE');
    if ($configuredPath === false && isset($_SERVER['APP_SECRETS_FILE'])) {
        $configuredPath = (string) $_SERVER['APP_SECRETS_FILE'];
    }
    $configuredPath = trim((string) ($configuredPath === false ? '' : $configuredPath));

    $candidates = $configuredPath !== '' ? [$configuredPath] : [];
    if ($configuredPath === '') {
        foreach ([getenv('HOME'), $_SERVER['HOME'] ?? null] as $home) {
            $home = rtrim(trim((string) ($home === false ? '' : $home)), '/\\');
            if ($home !== '') {
                $candidates[] = $home . DIRECTORY_SEPARATOR . '.app-secrets';
            }
        }

        $normalizedRoot = str_replace('\\', '/', $applicationRoot);
        if (preg_match('#^(/home/[^/]+)(?:/|$)#', $normalizedRoot, $matches) === 1) {
            $candidates[] = $matches[1] . '/.app-secrets';
        }
    }
    $candidates = array_values(array_unique($candidates));

    $selectedPath = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $selectedPath = $candidate;
            break;
        }
    }
    if ($selectedPath === null) {
        if ($configuredPath !== '') {
            throw new RuntimeException('Configured private secret file is unavailable.');
        }
        return false;
    }

    $realFile = realpath($selectedPath);
    $realRoot = realpath($applicationRoot);
    if ($realFile === false || $realRoot === false || !is_readable($realFile)) {
        throw new RuntimeException('Private secret file is unavailable.');
    }

    $fileComparison = str_replace('\\', '/', $realFile);
    $rootComparison = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
    if (PHP_OS_FAMILY === 'Windows') {
        $fileComparison = strtolower($fileComparison);
        $rootComparison = strtolower($rootComparison);
    }
    if ($fileComparison === rtrim($rootComparison, '/') || str_starts_with($fileComparison, $rootComparison)) {
        throw new RuntimeException('Private secret file must be outside the application root.');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        $permissions = fileperms($realFile);
        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Private secret file permissions must be 0600.');
        }
    }

    $size = filesize($realFile);
    if ($size === false || $size > 16384) {
        throw new RuntimeException('Private secret file has an invalid size.');
    }

    $lines = file($realFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Private secret file could not be read.');
    }

    $allowed = array_fill_keys($allowedKeys, true);
    $loaded = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            throw new RuntimeException('Private secret file contains a malformed entry.');
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (!isset($allowed[$name])) {
            continue;
        }
        if (isset($loaded[$name])) {
            throw new RuntimeException('Private secret file contains a duplicate key: ' . $name . '.');
        }

        $value = trim($value);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (str_contains($value, "\0") || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Private secret file contains an invalid value for: ' . $name . '.');
        }
        $loaded[$name] = $value;
    }

    foreach ($missingKeys as $key) {
        if (!array_key_exists($key, $loaded)) {
            continue;
        }
        if (!putenv($key . '=' . $loaded[$key])) {
            throw new RuntimeException('Private secret could not be loaded: ' . $key . '.');
        }
        $_ENV[$key] = $loaded[$key];
        $_SERVER[$key] = $loaded[$key];
    }

    return true;
}
