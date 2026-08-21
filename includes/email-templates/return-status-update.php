<?php
$siteName = site_name();
$returnNumber = trim((string) ($data['return_number'] ?? ''));
$status = strtoupper(str_replace('_', ' ', trim((string) ($data['status'] ?? 'updated'))));
$subject = 'Return ' . $returnNumber . ' status update - ' . $siteName;
$lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
$text = implode("\r\n", array_map('strval', $lines));
$html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#17263d"><h2>'
    . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h2><p>Return <strong>'
    . htmlspecialchars($returnNumber, ENT_QUOTES, 'UTF-8') . '</strong> is now <strong>'
    . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</strong>.</p><div style="line-height:1.6">'
    . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div></div>';
return ['subject' => $subject, 'text_body' => $text, 'html_body' => $html];
