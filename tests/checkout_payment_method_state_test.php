<?php
/** Database-free contract for payment-method state and accessible tab behavior. */
$root = dirname(__DIR__);
$checkout = (string) file_get_contents($root . '/checkout.php');
$placeOrder = (string) file_get_contents($root . '/place-order.php');
$razorpay = (string) file_get_contents($root . '/payment/razorpay-create.php');

$checks = [
    'single authoritative client state stores payment and online method' =>
        str_contains($checkout, 'var paymentState = {') && str_contains($checkout, 'paymentMethod: codRadio.checked ? \'cod\' : \'razorpay\'') && str_contains($checkout, 'selectedOnlineMethod:') && str_contains($checkout, 'lastValidOnlineMethod:'),
    'initial COD state keeps hidden online method synchronized to a valid value' =>
        str_contains($checkout, 'activateOnlineMethod(paymentState.selectedOnlineMethod);') && str_contains($checkout, 'syncOnlineMethodInput();') && !str_contains($checkout, "onlineMethodInput.value = ''"),
    'COD to Razorpay explicitly defaults invalid or missing online method to UPI' =>
        str_contains($checkout, 'function ensureValidOnlineMethodForRazorpay()') && str_contains($checkout, ": 'upi';") && str_contains($checkout, 'if (paymentState.paymentMethod === \'razorpay\') {'),
    'Razorpay to COD and back restores the prior valid online selection' =>
        str_contains($checkout, 'paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;') && str_contains($checkout, 'if (selected === \'razorpay\') {') && str_contains($checkout, 'activateOnlineMethod(paymentState.selectedOnlineMethod);'),
    'UPI Card and EMI tabs update one hidden online_method field consistently' =>
        str_contains($checkout, "var onlineMethods = ['upi', 'card', 'emi']") && str_contains($checkout, 'onlineMethodInput.value = paymentState.selectedOnlineMethod') && str_contains($checkout, 'activateOnlineMethod(method, true);'),
    'payment controls expose accessible relationship and semantic visibility states' =>
        str_contains($checkout, 'role="radiogroup"') && str_contains($checkout, 'aria-controls="cod-panel"') && str_contains($checkout, 'role="tablist"') && str_contains($checkout, 'role="tabpanel"') && str_contains($checkout, "panel.setAttribute('aria-hidden'") && str_contains($checkout, "razorpayPanel.setAttribute('aria-hidden'"),
    'keyboard navigation supports arrows home and end for online method tabs' =>
        str_contains($checkout, "['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End']") && str_contains($checkout, 'buttons[index].getAttribute(\'data-online-method\')'),
    'payment summary includes online subtype and payment-method change refreshes shipping quote' =>
        str_contains($checkout, "'Online Payment (Razorpay · '") && str_contains($checkout, 'function handlePaymentMethodChange()') && str_contains($checkout, 'maybeFetchLiveRate();'),
    'shipping quote uses authoritative payment state in request snapshot' =>
        str_contains($checkout, 'payment_method: paymentState.paymentMethod') && str_contains($checkout, 'var paymentMethod = paymentState.paymentMethod'),
    'submitted Razorpay method and gateway preference default safely to UPI' =>
        str_contains($placeOrder, '$paymentMethod' . " === 'razorpay' && " . '$onlineMethod' . " === ''") && str_contains($razorpay, "?? '')) ?: 'upi'"),
];
$failed = [];
foreach ($checks as $name => $passed) {
    fwrite(STDOUT, ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
