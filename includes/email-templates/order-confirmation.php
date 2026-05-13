<?php
$subject = 'Order Confirmed - ' . (string) ($data['order_number'] ?? '');
$lines = (array) ($data['lines'] ?? []);
return ['subject' => $subject, 'body' => implode("\\r\\n", $lines)];
