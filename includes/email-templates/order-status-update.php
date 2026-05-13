<?php
$orderNumber = (string) ($data['order_number'] ?? '');
$newStatus = (string) ($data['new_status'] ?? '');
$subject = 'Order Update - ' . $orderNumber . ' is now ' . ucfirst($newStatus);
$lines = (array) ($data['lines'] ?? []);
return ['subject' => $subject, 'body' => implode("\\r\\n", $lines)];
