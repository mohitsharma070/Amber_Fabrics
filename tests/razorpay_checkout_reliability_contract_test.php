<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/payment/razorpay-create.php');
$paymentService = (string) file_get_contents($root . '/includes/services/PaymentService.php');
$config = (string) file_get_contents($root . '/config/db.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(!str_contains($checkout, '<script src="https://checkout.razorpay.com/v1/checkout.js"></script>'), 'Razorpay SDK loading must expose an explicit failure state.');
$assert(
    str_contains($checkout, 'loadRazorpaySdk')
        && str_contains($checkout, "script.onerror")
        && str_contains($checkout, "typeof window.Razorpay !== 'function'"),
    'Razorpay checkout must guard SDK download and constructor availability.'
);
$assert(
    str_contains($checkout, "window.addEventListener('pageshow'")
        && str_contains($checkout, 'isSubmitting = false')
        && str_contains($checkout, 'setPayLoadingState(false)'),
    'Razorpay checkout must recover submission state after BFCache navigation.'
);
$assert(
    str_contains($checkout, 'id="rzpPayStatus"')
        && str_contains($checkout, 'role="alert"')
        && str_contains($checkout, 'id="rzpRetrySdk"'),
    'SDK failure and retry controls must be accessible.'
);
$assert(
    str_contains($paymentService, "_cfg('RAZORPAY_TEST_BASE_URL'")
        && str_contains($paymentService, "getenv('E2E_FIXTURE_CONFIRM')")
        && str_contains($config, "'RAZORPAY_TEST_BASE_URL'"),
    'The provider stub URL must be guarded by local test configuration and explicit E2E confirmation.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "razorpay_checkout_reliability_contract_test: OK\n";
