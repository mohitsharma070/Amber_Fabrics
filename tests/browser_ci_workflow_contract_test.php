<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/ci.yml';
$packagePath = $root . '/package.json';
$playwrightPath = $root . '/playwright.config.js';
$composerPath = $root . '/composer.json';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$workflow = (string) file_get_contents($workflowPath);
$package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
$playwright = (string) file_get_contents($playwrightPath);
$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);

$assert(str_contains($workflow, "  php-contracts:\n") || str_contains($workflow, "  php-contracts:\r\n"), 'Existing PHP contract job must remain in CI.');
$assert(str_contains($workflow, 'composer validate --strict --no-check-publish'), 'Existing Composer validation must remain in CI.');
$assert(str_contains($workflow, "find . -path './vendor' -prune -o -name '*.php'"), 'Existing PHP syntax validation must remain in CI.');
$assert(str_contains($workflow, 'run: composer test'), 'Existing Composer contract suite must remain in CI.');
$assert(str_contains($workflow, "  mysql-integration:\n") || str_contains($workflow, "  mysql-integration:\r\n"), 'Existing MySQL integration job must remain in CI.');
$assert(str_contains($workflow, 'MYSQL_DATABASE: amber_ci_test'), 'Existing disposable MySQL database configuration must remain in CI.');
$assert(str_contains($workflow, 'AMBER_RUN_DISPOSABLE_MYSQL_TESTS: 1'), 'Existing disposable MySQL opt-in guard must remain in CI.');
$assert(str_contains($workflow, 'run: composer test:integration'), 'Existing MySQL integration suite must remain in CI.');

$composerTest = (string) ($composer['scripts']['test'] ?? '');
$assert(
    str_contains($composerTest, 'php tests/browser_ci_workflow_contract_test.php'),
    'The established PHP contract suite must protect the browser CI workflow.'
);

$browserJob = '';
if (preg_match('/^  browser-smoke:\R(?<body>(?:^(?: {4,}|\s*$).*\R?)*)/m', $workflow, $match) === 1) {
    $browserJob = "  browser-smoke:\n" . (string) ($match['body'] ?? '');
} else {
    $failures[] = 'Browser CI job "browser-smoke" is missing.';
}

if ($browserJob !== '') {
    $requiredJobFragments = [
        'runs-on: ubuntu-latest' => 'Browser CI must use an Ubuntu runner.',
        'image: mysql:8.0.46' => 'Browser CI must use the repository MySQL service version.',
        'MYSQL_DATABASE: amber_fabrics_e2e' => 'MySQL must provision the dedicated E2E database.',
        'APP_MODE: local' => 'Browser CI must force local application mode.',
        'APP_ENV: test' => 'Browser CI must identify the environment as test.',
        'DB_HOST: 127.0.0.1' => 'Browser CI database access must remain loopback-only.',
        'DB_NAME: amber_fabrics_e2e' => 'The application must use the dedicated E2E database.',
        'E2E_FIXTURE_CONFIRM: 1' => 'Browser CI must explicitly authorize deterministic fixture seeding.',
        'E2E_BASE_URL: http://127.0.0.1:8000' => 'Playwright must target the loopback PHP server.',
        'SHIPPING_COURIER_ENABLED: 0' => 'Courier integration must be disabled.',
        'SHIPPING_COURIER_AUTO_CREATE: 0' => 'Automatic courier shipment creation must be disabled.',
        'SHIPPING_COURIER_TRACKING_SYNC: 0' => 'Courier tracking synchronization must be disabled.',
        'RAZORPAY_KEY_ID: rzp_test_e2e' => 'Browser CI must use only the deterministic test key ID.',
        'RAZORPAY_KEY_SECRET: rzp_secret_e2e' => 'Browser CI must use only the deterministic test key secret.',
        "RAZORPAY_WEBHOOK_SECRET: ''" => 'Razorpay webhook secret must be empty.',
        'RAZORPAY_TEST_BASE_URL: http://127.0.0.1:8001' => 'Razorpay API initialization must target the loopback stub.',
        'GOOGLE_ANALYTICS_ENABLED: 0' => 'Google Analytics must be disabled.',
        "GOOGLE_ANALYTICS_MEASUREMENT_ID: ''" => 'Google Analytics measurement ID must be empty.',
        "META_PIXEL_ID: ''" => 'Meta Pixel must be disabled.',
        "META_CAPI_PIXEL_ID: ''" => 'Meta CAPI pixel ID must be empty.',
        "META_CAPI_ACCESS_TOKEN: ''" => 'Meta CAPI access token must be empty.',
        "COD_GUARD_WHATSAPP_PROVIDER: ''" => 'WhatsApp provider must be disabled.',
        "COD_GUARD_WHATSAPP_ACCESS_TOKEN: ''" => 'WhatsApp access token must be empty.',
        'MAIL_DRIVER: log' => 'Outbound mail must use the local log driver.',
        'uses: actions/setup-node@v4' => 'Browser CI must install Node through the standard action.',
        "node-version: '20'" => 'Browser CI must use a Node version supported by the lockfile.',
        'cache: npm' => 'Browser CI must use the standard npm dependency cache.',
        'composer install --no-interaction --prefer-dist --no-progress' => 'Browser CI must install PHP dependencies.',
        'run: npm ci' => 'Browser CI must install the locked npm dependency graph.',
        'npm run test:e2e:install -- --with-deps' => 'Browser CI must install Chromium and its runner dependencies through the existing script.',
        'php database/setup.php' => 'Browser CI must initialize schema through the repository setup command.',
        'php tests/browser_ci_workflow_contract_test.php' => 'Browser CI must enforce this workflow safety contract.',
        'php -S 127.0.0.1:8000 router.php' => 'The PHP server must bind only to loopback and use the repository router.',
        'php -S 127.0.0.1:8001 tests/e2e/razorpay-stub.php' => 'The Razorpay API stub must bind only to loopback.',
        'php -S 127.0.0.1:8002 tests/e2e/throwing-shipping-router.php' => 'The throwing courier harness must bind only to loopback.',
        'curl --fail --silent --show-error --retry-connrefused' => 'Browser CI must poll a real local HTTP response before Playwright starts.',
        'npm run test:e2e:smoke' => 'Browser CI must run the storefront smoke suite.',
        'npm run test:e2e:commerce' => 'Browser CI must run the real first-party commerce suite.',
        'npm run test:e2e:a11y' => 'Browser CI must run the axe accessibility suite.',
        'if: failure()' => 'Browser diagnostics must upload only after a failure.',
        'uses: actions/upload-artifact@v4' => 'Browser CI must use the supported artifact action.',
        'test-results/' => 'Playwright traces and screenshots must be retained on failure.',
        'playwright-report/' => 'A generated Playwright report must be retained on failure.',
        'tmp/e2e-php-server.log' => 'The safe local PHP server log must be retained on failure.',
        'tmp/e2e-razorpay-stub.log' => 'The loopback Razorpay stub log must be retained on failure.',
        'tmp/e2e-shipping-failure-server.log' => 'The courier failure harness log must be retained on failure.',
        'if-no-files-found: ignore' => 'Missing optional diagnostics must not mask the test failure.',
    ];

    foreach ($requiredJobFragments as $fragment => $message) {
        $assert(str_contains($browserJob, $fragment), $message);
    }

    foreach (['secrets.', 'tmp/local-mail.log', '.env', 'mysqldump'] as $forbiddenFragment) {
        $assert(!str_contains($browserJob, $forbiddenFragment), 'Browser CI must not reference unsafe data or credentials: ' . $forbiddenFragment);
    }
}

$scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
$assert(isset($scripts['test:e2e:smoke']), 'The storefront smoke npm script must exist.');
$assert(isset($scripts['test:e2e:a11y']), 'The axe accessibility npm script must exist.');
$assert(isset($scripts['test:e2e:commerce']), 'The real commerce npm script must exist.');
$assert(str_contains((string) ($scripts['test:e2e:smoke'] ?? ''), 'tests/e2e/storefront.spec.js'), 'The smoke script must execute the storefront browser suite.');
$assert(str_contains((string) ($scripts['test:e2e:smoke'] ?? ''), 'tests/e2e/confirmation-fallback.spec.js'), 'The smoke script must execute the Bootstrap confirmation fallback suite.');
$assert(str_contains((string) ($scripts['test:e2e:smoke'] ?? ''), 'tests/e2e/pdp-quantity-window.spec.js'), 'The smoke script must execute the PDP whole-unit quantity-window suite.');
$assert(str_contains((string) ($scripts['test:e2e:a11y'] ?? ''), 'tests/e2e/accessibility.spec.js'), 'The accessibility script must execute the axe suite.');
$assert(str_contains((string) ($scripts['test:e2e:commerce'] ?? ''), 'tests/e2e/commerce-real.spec.js'), 'The commerce script must execute real first-party purchase flows.');
$assert(str_contains((string) ($scripts['test:e2e:commerce'] ?? ''), 'tests/e2e/razorpay-loopback.spec.js'), 'The commerce script must execute guarded online-payment initialization.');
$assert(preg_match('/fullyParallel:\s*false/', $playwright) === 1, 'Playwright must keep fullyParallel disabled for shared fixtures.');
$assert(preg_match('/workers:\s*1/', $playwright) === 1, 'Playwright must keep one worker for deterministic shared fixtures.');

foreach (['storefront.spec.js', 'accessibility.spec.js', 'product-media.spec.js', 'confirmation-fallback.spec.js', 'pdp-quantity-window.spec.js', 'commerce-real.spec.js', 'razorpay-loopback.spec.js'] as $specName) {
    $spec = (string) file_get_contents($root . '/tests/e2e/' . $specName);
    $assert(str_contains($spec, 'forbiddenProviderHosts'), $specName . ' must retain forbidden provider request blocking.');
    $assert(str_contains($spec, "page.on('pageerror'"), $specName . ' must fail on uncaught first-party page errors.');
}

$accessibilitySpec = (string) file_get_contents($root . '/tests/e2e/accessibility.spec.js');
$assert(str_contains($accessibilitySpec, "violation.impact === 'serious'"), 'The Axe suite must fail on serious violations.');
$assert(str_contains($accessibilitySpec, "violation.impact === 'critical'"), 'The Axe suite must fail on critical violations.');

if ($failures !== []) {
    fwrite(STDERR, "Browser CI workflow contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Browser CI workflow contract: OK\n";
