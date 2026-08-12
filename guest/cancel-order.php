<?php
require_once __DIR__.'/../includes/init.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf()){http_response_code(403);exit('Forbidden');}
$orderId=(int)($_POST['order_id']??0);if(!OrderAccessService::canAccess($orderId)){http_response_code(403);exit('Forbidden');}
try{InventoryService::customer_cancel_order($conn,$orderId,0);flash('success','Order cancelled successfully.');}catch(Throwable $e){flash('error',$e->getMessage());}
redirect('/guest/order?id='.$orderId);
