<?php
require_once __DIR__.'/../includes/init.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf()){http_response_code(403);exit('Forbidden');}
$orderId=(int)($_POST['order_id']??0);if(!OrderAccessService::canAccess($orderId)){http_response_code(403);exit('Forbidden');}
try {
    InventoryService::customer_cancel_order($conn, $orderId, 0);
    flash('success', 'Order cancelled successfully.');
} catch (Throwable $e) {
    $safeMessages = ['Invalid order request.', 'Order not found.', 'This order can no longer be cancelled.'];
    $message = in_array($e->getMessage(), $safeMessages, true)
        ? $e->getMessage()
        : 'Unable to cancel order right now.';
    if ($message === 'Unable to cancel order right now.') {
        app_log('error', 'guest_order_cancel_failed', [
            'order_id' => $orderId,
            'exception_type' => get_class($e),
        ]);
    }
    flash('error', $message);
}
redirect('/guest/order?id='.$orderId);
