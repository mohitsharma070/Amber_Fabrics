<?php
require_once __DIR__.'/../includes/init.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!verify_csrf()){http_response_code(403);exit('Forbidden');}
$orderId=(int)($_POST['order_id']??0);$order=OrderAccessService::order($conn,$orderId);if(!$order){http_response_code(403);exit('Forbidden');}
if(!in_array((string)$order['payment_status'],['pending','failed'],true)||$order['payment_method']!=='razorpay'||strtotime((string)$order['created_at'])<time()-1800){flash('error','This payment is no longer eligible for retry.');redirect('/guest/order?id='.$orderId);}
InventoryService::reserve_order_inventory($conn,$orderId);$stmt=$conn->prepare("UPDATE orders SET payment_status='pending',updated_at=NOW() WHERE id=?");$stmt->bind_param('i',$orderId);$stmt->execute();
$_SESSION['pending_order_id']=$orderId;$_SESSION['pending_order_number']=$order['order_number'];$_SESSION['pending_coupon_id']=0;redirect('/payment/razorpay-create.php');
