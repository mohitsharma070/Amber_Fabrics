<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root, $assert): string {
    $value = @file_get_contents($root . '/' . $path);
    $assert($value !== false, $path . ' must exist.');
    return $value === false ? '' : $value;
};

$checkout = $read('checkout.php');
$checkoutScript = $read('js/checkout.js');

$dataPosition = strpos($checkout, 'id="checkout-data"');
$assetPosition = strpos($checkout, '<script src="/js/checkout.js?v=20260901a"></script>');
$footerPosition = strpos($checkout, "include __DIR__ . '/includes/footer.php'");
$assert(
    $dataPosition !== false
        && $assetPosition !== false
        && $footerPosition !== false
        && $dataPosition < $assetPosition
        && $assetPosition < $footerPosition,
    'Checkout must emit its JSON state before the page-only versioned asset at the existing pre-footer position.'
);
$assert(
    str_contains($checkout, '<script type="application/json" id="checkout-data"')
        && str_contains($checkout, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'),
    'Checkout must serialize minimal server state through a safely encoded application/json block.'
);
$assert(
    preg_match('/<script\b(?![^>]*\bsrc=)(?![^>]*\btype="application\/json")[^>]*>.*?<\/script>/si', $checkout) !== 1,
    'Checkout executable JavaScript must not remain inline after extraction.'
);
$assert(
    !str_contains($checkout, '<script src="/js/checkout.js?v=20260901a" defer>'),
    'Checkout validation must initialize at the current pre-footer position before the deferred shared confirmation handler.'
);
$assert(
    str_contains($checkoutScript, 'function initCheckout()')
        && str_contains($checkoutScript, 'checkoutForm.dataset.checkoutReady')
        && str_contains($checkoutScript, 'checkoutForm.dataset.checkoutReady = "true"'),
    'Checkout JavaScript must use one guarded, idempotent initializer.'
);
$assert(
    str_contains($checkoutScript, 'document.querySelector(\'meta[name="csrf-token"]\')')
        && !str_contains($checkout, "json_encode(csrf_token())"),
    'Checkout requests must reuse the existing CSRF meta contract instead of serializing another token.'
);

$assert(
    str_contains($checkoutScript, 'function applySavedAddressOption(optionEl)')
        && str_contains($checkoutScript, "optionEl.getAttribute('data-full-name')")
        && str_contains($checkoutScript, 'shippingAddressIdInput.value = selectedId'),
    'Saved-address selection must continue populating delivery fields and shipping_address_id.'
);
$assert(
    str_contains($checkoutScript, 'shippingRateAbortController.abort()')
        && str_contains($checkoutScript, 'requestId !== shippingRateRequestId')
        && str_contains($checkoutScript, 'requestContext !== currentContext'),
    'Checkout must abort prior shipping requests and ignore stale responses.'
);
$liveRateStart = strpos($checkoutScript, 'async function maybeFetchLiveRate(');
$liveRateEnd = strpos($checkoutScript, 'function scheduleLiveRate(', $liveRateStart === false ? 0 : $liveRateStart);
$liveRateFunction = ($liveRateStart !== false && $liveRateEnd !== false)
    ? substr($checkoutScript, $liveRateStart, $liveRateEnd - $liveRateStart)
    : '';
$timeoutStart = strpos($liveRateFunction, 'window.setTimeout(function ()');
$timeoutEnd = strpos($liveRateFunction, '}, SHIPPING_RATE_TIMEOUT_MS);', $timeoutStart === false ? 0 : $timeoutStart);
$timeoutBlock = ($timeoutStart !== false && $timeoutEnd !== false)
    ? substr($liveRateFunction, $timeoutStart, $timeoutEnd - $timeoutStart)
    : '';
$finallyPosition = strpos($liveRateFunction, '} finally {');
$clearTimeoutPosition = strpos($liveRateFunction, 'window.clearTimeout(timeoutId)', $finallyPosition === false ? 0 : $finallyPosition);
$finallyRequestGuardPosition = strpos($liveRateFunction, 'if (requestId === shippingRateRequestId)', $finallyPosition === false ? 0 : $finallyPosition);
$staleGuardPosition = strpos($liveRateFunction, 'if (requestId !== shippingRateRequestId || requestContext !== currentContext)');
$quoteTokenStorePosition = strpos($liveRateFunction, 'shippingQuoteTokenInput.value = String(data.quote_token)');
$checkoutUnlockPosition = strpos($liveRateFunction, 'setCheckoutUnlocked(true)');
$assert(
    str_contains($checkoutScript, 'var SHIPPING_RATE_TIMEOUT_MS = 10000;')
        && preg_match('/timeoutId\s*=\s*window\.setTimeout\(function \(\)/', $liveRateFunction) === 1
        && str_contains($liveRateFunction, 'var res = await Promise.race([shippingRequest, timeoutPromise]);'),
    'Checkout shipping fetch must enforce the established 10-second first-party timeout, including the no-AbortController fallback.'
);
$assert(
    str_contains($timeoutBlock, 'timedOut = true;')
        && str_contains($timeoutBlock, 'controller.abort()')
        && !str_contains($timeoutBlock, 'shippingRateAbortController.abort()'),
    'Each shipping timeout must abort only its own request controller so an older timer cannot abort a replacement request.'
);
$assert(
    $finallyPosition !== false
        && $clearTimeoutPosition !== false
        && $finallyRequestGuardPosition !== false
        && $finallyPosition < $clearTimeoutPosition
        && $clearTimeoutPosition < $finallyRequestGuardPosition
        && str_contains($liveRateFunction, 'setDeliveryRequestPending(false)'),
    'Shipping timeout resources must always be cleared while only the current request may restore pending controls.'
);
$assert(
    str_contains($liveRateFunction, 'if (timedOut && requestId === shippingRateRequestId)')
        && str_contains($liveRateFunction, "shippingQuoteTokenInput.value = ''")
        && str_contains($liveRateFunction, 'setCheckoutUnlocked(false)')
        && str_contains($liveRateFunction, 'Shipping calculation timed out. Please try again.'),
    'A current timed-out quote must clear its token, keep payment/review locked, and show generic retry guidance.'
);
$assert(
    $staleGuardPosition !== false
        && $quoteTokenStorePosition !== false
        && $checkoutUnlockPosition !== false
        && $staleGuardPosition < $quoteTokenStorePosition
        && $staleGuardPosition < $checkoutUnlockPosition,
    'A stale shipping response must be rejected before it can store a quote token or unlock checkout.'
);
$assert(
    !str_contains($liveRateFunction, 'error.message')
        && !str_contains($liveRateFunction, 'error.stack'),
    'Checkout shipping failures must not render raw client, provider, or parser error details.'
);
$assert(
    str_contains($checkoutScript, 'shippingQuoteTokenInput.value = String(data.quote_token)')
        && str_contains($checkoutScript, "shippingQuoteTokenInput.value = ''"),
    'Shipping quote refreshes must clear stale tokens and store only the accepted response token.'
);
$assert(
    str_contains($checkoutScript, 'function activateOnlineMethod(method)')
        && str_contains($checkoutScript, "btn.setAttribute('aria-pressed', selected ? 'true' : 'false')")
        && str_contains($checkoutScript, 'razorpayRadio.checked = true')
        && str_contains($checkoutScript, 'refreshQuoteForPaymentChange()'),
    'Payment and online-method switching must preserve selected UI state and refresh its quote context.'
);
$assert(
    str_contains($checkoutScript, 'checkoutCurrentTotal >= codWhatsappThreshold')
        && str_contains($checkoutScript, 'codWhatsappConsent.required = required')
        && str_contains($checkoutScript, "codWhatsappConsent.setAttribute('aria-required', required ? 'true' : 'false')"),
    'COD WhatsApp consent must retain its amount-driven required and aria-required state.'
);
$assert(
    str_contains($checkoutScript, "totalEl.textContent = toMoney(total)")
        && str_contains($checkoutScript, "mobileTotalEl.textContent = toMoney(total)")
        && str_contains($checkoutScript, '} finally {')
        && str_contains($checkoutScript, 'setDeliveryRequestPending(false)'),
    'Accepted quotes must update desktop/mobile totals and always restore request loading state.'
);
$assert(
    str_contains($checkoutScript, 'function preserveCheckoutState(form)')
        && str_contains($checkoutScript, 'shipping_address_id: shippingAddressIdInput')
        && str_contains($checkoutScript, 'online_method: onlineMethodInput')
        && str_contains($checkoutScript, 'setCouponStateField(form, name, state[name])'),
    'Coupon submissions must continue preserving address and payment selection state.'
);
$assert(
    !str_contains($checkoutScript, 'mobileSubmitBtn.addEventListener')
        && str_contains($checkout, 'id="mobile_place_order_btn"')
        && str_contains($checkout, 'form="checkout_form"'),
    'The mobile Place Order button must keep using the authoritative checkout form without a second submit path.'
);

foreach ([
    'full_name', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'country', 'order_notes',
    'payment_method', 'online_method', 'shipping_address_id', 'shipping_quote_token', 'order_nonce',
] as $fieldName) {
    $assert(str_contains($checkout, 'name="' . $fieldName . '"'), 'Checkout field contract must retain ' . $fieldName . '.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "checkout_javascript_extraction_contract_test: OK\n";
