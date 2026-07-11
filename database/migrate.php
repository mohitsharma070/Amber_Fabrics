<?php
/**
 * CLI migration runner.
 *
 * Usage:
 *   APP_MODE=local php database/migrate.php
 *   APP_MODE=production php database/migrate.php
 *   APP_MODE=production php database/migrate.php --baseline
 *   APP_MODE=production php database/migrate.php --baseline-before=2026-06-01-first-new-migration.sql
 *   APP_MODE=production php database/migrate.php --only=2026-06-01-example.sql
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "DB connection not available.\n");
    exit(2);
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(191) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

function migration_run_sql(mysqli $conn, string $sql, string $name): void
{
    if (!migration_has_executable_sql($sql)) {
        return;
    }

    if (!$conn->multi_query($sql)) {
        throw new RuntimeException($name . ': ' . $conn->error);
    }

    do {
        $result = $conn->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        throw new RuntimeException($name . ': ' . $conn->error);
    }
}

function migration_has_executable_sql(string $sql): bool
{
    $withoutBlockComments = preg_replace('/\/\*.*?\*\//s', '', $sql);
    if (!is_string($withoutBlockComments)) {
        return trim($sql) !== '';
    }

    $lines = preg_split('/\R/', $withoutBlockComments);
    if (!is_array($lines)) {
        return trim($withoutBlockComments) !== '';
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        return true;
    }
    return false;
}

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$baselineAll = in_array('--baseline', $argv, true);
$baselineBefore = '';
$only = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--baseline-before=') === 0) {
        $baselineBefore = substr($arg, strlen('--baseline-before='));
    }
    if (strpos($arg, '--only=') === 0) {
        $only = substr($arg, strlen('--only='));
    }
}

$migrationNames = array_map('basename', $files);
if ($only !== '' && !in_array($only, $migrationNames, true)) {
    fwrite(STDERR, "Requested migration not found: {$only}\n");
    exit(2);
}
if ($baselineBefore !== '' && !in_array($baselineBefore, $migrationNames, true)) {
    fwrite(STDERR, "Baseline boundary migration not found: {$baselineBefore}\n");
    exit(2);
}
if ($baselineAll && $only !== '') {
    fwrite(STDERR, "--baseline cannot be combined with --only.\n");
    exit(2);
}

$applied = 0;
$baselined = 0;
foreach ($files as $file) {
    $name = basename($file);
    if ($only !== '' && $name !== $only) {
        continue;
    }

    $checksum = hash_file('sha256', $file);
    if ($checksum === false) {
        fwrite(STDERR, "Unable to checksum {$name}\n");
        exit(2);
    }

    $check = $conn->prepare("SELECT checksum FROM schema_migrations WHERE migration = ? LIMIT 1");
    $check->bind_param('s', $name);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if ($row) {
        if (!hash_equals((string) $row['checksum'], $checksum)) {
            fwrite(STDERR, "Checksum changed for already-applied migration: {$name}\n");
            exit(1);
        }
        echo "skip {$name}\n";
        continue;
    }

    if ($baselineAll || ($baselineBefore !== '' && strcmp($name, $baselineBefore) < 0)) {
        $insert = $conn->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
        $insert->bind_param('ss', $name, $checksum);
        $insert->execute();
        $baselined++;
        echo "baseline {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Unable to read {$name}\n");
        exit(2);
    }

    try {
        migration_run_sql($conn, $sql, $name);
        $insert = $conn->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
        $insert->bind_param('ss', $name, $checksum);
        $insert->execute();
        $applied++;
        echo "apply {$name}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "OK: {$applied} migration(s) applied, {$baselined} migration(s) baselined\n";
exit(0);
