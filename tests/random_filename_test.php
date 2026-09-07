<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers/media.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$originalName = 'customer-supplied summer catalog.WEBP';
$names = [];
for ($i = 0; $i < 100; $i++) {
    $generated = random_filename($originalName);
    $names[] = $generated;

    $assert(
        preg_match('/^fabric_[a-f0-9]{32}\.WEBP$/', $generated) === 1,
        'Generated upload names must use a safe 128-bit hexadecimal basename and preserve the extension.'
    );
    $assert(strpos($generated, '/') === false, 'Generated upload names must not contain a slash.');
    $assert(strpos($generated, '\\') === false, 'Generated upload names must not contain a backslash.');
    $assert(
        stripos($generated, 'customer-supplied') === false,
        'Generated upload names must not embed the original user basename.'
    );
}

$assert(count(array_unique($names)) === count($names), 'Repeated generated upload names must differ.');
$assert(
    preg_match('/^fabric_[a-f0-9]{32}$/', random_filename('extensionless')) === 1,
    'Extensionless upload names must not gain a trailing dot or extension.'
);

if ($failures !== []) {
    foreach (array_unique($failures) as $failure) {
        fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
    }
    exit(1);
}

echo "random_filename_test: OK" . PHP_EOL;
