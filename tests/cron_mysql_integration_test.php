<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/config/db.php';

$config = $GLOBALS['_app_config'] ?? [];
$second = new mysqli(
    (string) ($config['DB_HOST'] ?? ''),
    (string) ($config['DB_USER'] ?? ''),
    (string) ($config['DB_PASSWORD'] ?? ''),
    (string) ($config['DB_NAME'] ?? ''),
    (int) ($config['DB_PORT'] ?? 3306)
);
$second->set_charset('utf8mb4');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$lockName = 'amber-fabrics:test:cron:' . bin2hex(random_bytes(8));
$firstLock = $conn->prepare('SELECT GET_LOCK(?, 0) AS acquired');
$firstLock->bind_param('s', $lockName);
$firstLock->execute();
$assert((int) ($firstLock->get_result()->fetch_assoc()['acquired'] ?? 0) === 1, 'First connection must acquire the cron test lock.');

$secondLock = $second->prepare('SELECT GET_LOCK(?, 0) AS acquired');
$secondLock->bind_param('s', $lockName);
$secondLock->execute();
$assert((int) ($secondLock->get_result()->fetch_assoc()['acquired'] ?? -1) === 0, 'Second connection must observe named-lock contention.');

$release = $conn->prepare('SELECT RELEASE_LOCK(?) AS released');
$release->bind_param('s', $lockName);
$release->execute();
$assert((int) ($release->get_result()->fetch_assoc()['released'] ?? 0) === 1, 'Cron test lock must be released.');

$requirements = [
    ['abandoned_cart_reminders', 'delivery_attempts'],
    ['abandoned_cart_reminders', 'consecutive_failures'],
    ['back_in_stock_subscriptions', 'delivery_attempts'],
    ['back_in_stock_subscriptions', 'next_attempt_at'],
];
foreach ($requirements as [$table, $column]) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $assert((int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) === 1, $table . '.' . $column . ' must exist.');
}

$second->close();
if ($failures !== []) {
    fwrite(STDERR, "Cron MySQL integration failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Cron MySQL lock/schema integration passed.\n";
