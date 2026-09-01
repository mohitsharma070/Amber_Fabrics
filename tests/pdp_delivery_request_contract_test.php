<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$fabric = (string) file_get_contents($root . '/fabric.php');
$productDetailPath = $root . '/js/product-detail.js';
$productDetail = is_file($productDetailPath) ? (string) file_get_contents($productDetailPath) : '';

foreach (['product_id', 'variant_id', 'quantity', 'pincode', 'payment_method'] as $field) {
    $assert(str_contains($fabric, 'name="' . $field . '"'), 'PDP delivery request must retain payload field: ' . $field . '.');
}
$assert(str_contains($fabric, '<?php echo csrf_field(); ?>'), 'PDP delivery request must retain the first-party CSRF field.');

$assert(str_contains($productDetail, 'if (!response.ok)'), 'PDP delivery request must reject non-2xx responses.');
$assert(str_contains($productDetail, 'new AbortController()'), 'PDP delivery request must use AbortController.');
$assert(str_contains($productDetail, 'window.setTimeout(function ()') && str_contains($productDetail, 'controller.abort();') && str_contains($productDetail, '10000'), 'PDP delivery request must abort after the established 10-second timeout.');
$assert(str_contains($productDetail, 'signal: controller.signal'), 'PDP delivery fetch must receive the abort signal.');
$assert(str_contains($productDetail, 'window.clearTimeout(timeoutId)'), 'PDP delivery request must always clear its timeout.');
$assert(str_contains($productDetail, 'deliveryAbortController.abort()'), 'A newer PDP delivery request must cancel the previous request.');
$assert(str_contains($productDetail, 'requestId !== deliveryRequestId'), 'Stale PDP delivery responses must be ignored.');
$assert(str_contains($productDetail, '} finally {') && str_contains($productDetail, "submitButton.classList.remove('is-loading')") && str_contains($productDetail, 'submitButton.disabled = false'), 'Latest PDP delivery request must restore button loading state in finally.');
$assert(str_contains($productDetail, 'data = await response.json();') && str_contains($productDetail, 'catch (jsonError)'), 'Invalid PDP delivery JSON must enter controlled error handling.');
$assert(!str_contains($productDetail, 'output.textContent = data.message') && !str_contains($productDetail, 'output.textContent = error.message') && !str_contains($productDetail, 'output.textContent = jsonError.message'), 'PDP delivery failures must never render raw server or parser messages.');

foreach (['Live courier rate', 'Estimated shipping', 'Dispatch ', 'Delivery ', 'Shipping ₹', 'includes COD fee ₹', 'courier_name'] as $successMarker) {
    $assert(str_contains($productDetail, $successMarker), 'PDP delivery success UI must retain: ' . $successMarker . '.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "pdp_delivery_request_contract_test: OK\n";
