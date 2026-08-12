<?php
$orderNumber = (string) ($data['order_number'] ?? '');
$newStatus = (string) ($data['new_status'] ?? '');
$subject = 'Order Update - ' . $orderNumber . ' is now ' . ucfirst($newStatus);
$lines=(array)($data['lines']??[]);$text=implode("\r\n",$lines);
return ['subject'=>$subject,'text_body'=>$text,'html_body'=>'<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#17263d"><h2>'.htmlspecialchars(site_name(),ENT_QUOTES,'UTF-8').'</h2><div style="line-height:1.6">'.nl2br(htmlspecialchars($text,ENT_QUOTES,'UTF-8')).'</div></div>'];
