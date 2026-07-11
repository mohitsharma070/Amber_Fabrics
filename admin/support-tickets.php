<?php
require_once __DIR__ . '/../includes/init.php';
require_admin();

if (!function_exists('support_tickets_render_admin_page')) {
    $metaTitle = 'Support Tickets | Admin';
    include __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-warning">Support tickets are not enabled.</div>';
    include __DIR__ . '/partials/footer.php';
    exit;
}

support_tickets_render_admin_page($conn);
