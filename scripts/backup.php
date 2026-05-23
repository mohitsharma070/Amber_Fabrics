<?php
/**
 * CLI-only database backup script.
 *
 * Usage:
 *   php scripts/backup.php
 *   BACKUP_DIR=/mnt/backups php scripts/backup.php
 *
 * Recommended crontab (daily at 02:00):
 *   0 2 * * * php /var/www/html/scripts/backup.php >> /var/log/amber-backup.log 2>&1
 *
 * Environment variables (all optional):
 *   BACKUP_DIR   - Directory to write .sql.gz files (default: one level above web root /backups/)
 *   BACKUP_KEEP_DAYS - How many days of backups to retain (default: 30)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: this script may only be run from the command line.\n");
}

// Bootstrap config (loads DB credentials via _cfg()).
// Force local mode to prevent CLI from accidentally targeting production config
// when no APP_MODE is set; the operator should set APP_MODE=production explicitly.
require __DIR__ . '/../config/db.php';

// ── Configuration ──────────────────────────────────────────────────────────────
$backupDir  = rtrim((string) (getenv('BACKUP_DIR') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups'), DIRECTORY_SEPARATOR);
$keepDays   = max(1, (int) (getenv('BACKUP_KEEP_DAYS') ?: 30));

$dbHost = _cfg('DB_HOST', '127.0.0.1');
$dbPort = _cfg('DB_PORT', '3306');
$dbUser = _cfg('DB_USER', '');
$dbPass = _cfg('DB_PASSWORD', '');
$dbName = _cfg('DB_NAME', '');

if ($dbUser === '' || $dbName === '') {
    fwrite(STDERR, "[backup] ERROR: DB_USER or DB_NAME is not configured.\n");
    exit(1);
}

// ── Ensure backup directory exists ────────────────────────────────────────────
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "[backup] ERROR: Could not create backup directory: {$backupDir}\n");
        exit(1);
    }
}

// ── Verify mysqldump is available ─────────────────────────────────────────────
$mysqldumpBin = trim((string) shell_exec('command -v mysqldump 2>/dev/null || where mysqldump 2>NUL'));
if ($mysqldumpBin === '') {
    fwrite(STDERR, "[backup] ERROR: mysqldump not found in PATH.\n");
    exit(1);
}

// ── Build backup filename and run dump ────────────────────────────────────────
$timestamp  = date('Y-m-d_His');
$filename   = "amber_backup_{$timestamp}.sql.gz";
$targetPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

// Build mysqldump command — password is passed via env to avoid shell history exposure.
$portArg    = $dbPort !== '' && $dbPort !== '3306' ? " --port=" . (int) $dbPort : '';
$command    = sprintf(
    'MYSQL_PWD=%s mysqldump --single-transaction --quick --skip-lock-tables'
    . ' -h %s%s -u %s %s | gzip > %s',
    escapeshellarg($dbPass),
    escapeshellarg($dbHost),
    $portArg,
    escapeshellarg($dbUser),
    escapeshellarg($dbName),
    escapeshellarg($targetPath)
);

$returnCode = 0;
passthru($command, $returnCode);

if ($returnCode !== 0 || !is_file($targetPath) || filesize($targetPath) < 100) {
    fwrite(STDERR, "[backup] ERROR: mysqldump failed (exit code {$returnCode}).\n");
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
    exit(1);
}

$sizeMb = round(filesize($targetPath) / 1048576, 2);
echo "[backup] OK: {$filename} ({$sizeMb} MB)\n";
error_log("[amber] backup created: {$filename} ({$sizeMb} MB)");

// ── Rotate old backups ────────────────────────────────────────────────────────
$cutoff = time() - ($keepDays * 86400);
$deleted = 0;
foreach (glob($backupDir . DIRECTORY_SEPARATOR . 'amber_backup_*.sql.gz') ?: [] as $old) {
    if (is_file($old) && filemtime($old) < $cutoff) {
        if (@unlink($old)) {
            $deleted++;
        }
    }
}
if ($deleted > 0) {
    echo "[backup] Rotated {$deleted} backup(s) older than {$keepDays} days.\n";
    error_log("[amber] backup rotation: removed {$deleted} file(s)");
}

exit(0);
