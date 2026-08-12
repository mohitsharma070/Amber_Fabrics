<?php
$subject = 'Order Confirmed - ' . (string) ($data['order_number'] ?? '');
$lines=(array)($data['lines']??[]);$text=implode("\r\n",$lines);$html=nl2br(htmlspecialchars($text,ENT_QUOTES,'UTF-8'));
return ['subject'=>$subject,'text_body'=>$text,'html_body'=>'<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#17263d"><h2>'.htmlspecialchars(site_name(),ENT_QUOTES,'UTF-8').'</h2><div style="line-height:1.6">'.$html.'</div><p><a style="display:inline-block;background:#0f766e;color:white;padding:12px 20px;text-decoration:none;border-radius:6px" href="'.htmlspecialchars((string)($data['manage_url']??app_url('/guest/order-access')),ENT_QUOTES,'UTF-8').'">Manage Order</a></p></div>'];
