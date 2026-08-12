<?php
require_once __DIR__.'/../includes/init.php';
$row=OrderAccessService::consume($conn,trim((string)($_GET['token']??'')),'manage');
if(!$row){flash('error','This secure link is invalid or expired. Request a new one.');redirect('/guest/order-access');}
OrderAccessService::grant((int)$row['order_id']);redirect('/guest/order?id='.(int)$row['order_id']);
