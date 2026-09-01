<?php
declare(strict_types=1);

require_once __DIR__ . '/fixture-policy.php';

$failures = [];
$assertSame = static function (mixed $actual, mixed $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message;
    }
};

$assertSame(
    e2e_fixture_policy_errors('production', '1', 'amber_fabrics_e2e'),
    ['E2E fixtures require APP_MODE=local.'],
    'Production mode must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '', 'amber_fabrics_e2e'),
    ['E2E fixtures require E2E_FIXTURE_CONFIRM=1.'],
    'Missing explicit fixture confirmation must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'amber_fabrics'),
    ['E2E fixtures require a database name ending in _test or _e2e.'],
    'An ordinary database name must be rejected.'
);
$assertSame(
    e2e_fixture_policy_errors('local', '1', 'amber_fabrics_test'),
    [],
    'A confirmed local _test database must be accepted.'
);
$assertSame(
    e2e_fixture_policy_errors('LOCAL', '1', 'AMBER_FABRICS_E2E'),
    [],
    'Policy matching should be case-insensitive.'
);

if ($failures !== []) {
    fwrite(STDERR, "E2E fixture policy failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "E2E fixture policy: OK\n";
