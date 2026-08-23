<?php

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    return is_string($contents) ? $contents : '';
};

$templates = [
    'admin/login.php',
    'admin/verify-otp.php',
    'admin/dashboard.php',
    'admin/coupons.php',
    'admin/edit-fabric.php',
    'admin/settings.php',
    'admin/shipping-rates.php',
    'admin/partials/header.php',
    'admin/partials/footer.php',
    'admin/partials/fabric-product-form.php',
];

foreach ($templates as $path) {
    $source = $read($path);
    $assert($source !== '', $path . ' must be readable.');
    $assert(!preg_match('/bootstrap(?:\.min)?\.(?:css|js)|data-bs-|window\.bootstrap|\bbi\s+bi-/i', $source), $path . ' must not depend on Bootstrap.');
    $assert(!preg_match('/<style\b|\sstyle\s*=|\son[a-z]+\s*=/i', $source), $path . ' must not contain browser inline styles or event handlers.');
    if (preg_match_all('/<script\b[^>]*>/i', $source, $matches)) {
        foreach ($matches[0] as $tag) {
            $assert((bool) preg_match('/\bsrc\s*=/i', $tag), $path . ' must not contain executable inline JavaScript.');
        }
    }
}

$header = $read('admin/partials/header.php');
$admin = $read('js/admin.js');
$dashboard = $read('admin/dashboard.php');
$editor = $read('admin/edit-fabric.php');
$courierReturns = $read('plugins/shipping-courier/modules/returns.php');
$supportTickets = $read('plugins/support-tickets/plugin.php');
$adminCss = $read('css/admin.css');

$assert(str_contains($header, 'data-ui-area="admin"') && str_contains($header, 'data-ui-page='), 'The admin layout must expose the guarded UI area and route.');
$assert(substr_count($header, "ui_asset('/css/") === 2, 'Admin routes must load exactly two first-party CSS files.');
$assert(substr_count($header, "ui_asset('/js/") === 2, 'Admin routes must load exactly two first-party JavaScript files.');
$assert(str_contains($admin, 'AmberUI.openDialog = openDialog') && str_contains($admin, 'data-ui-dialog-open'), 'admin.js must provide the first-party dialog behavior.');
$assert(str_contains($admin, 'requestJson(') && str_contains($admin, 'initVariants()'), 'admin.js must own the product editor asynchronous behavior.');
$assert(str_contains($dashboard, 'data-admin-chart="<?php echo ui_data_json('), 'Dashboard chart data must use the safe JSON attribute helper.');
$assert(str_contains($editor, 'data-admin-product-media') && str_contains($editor, 'data-admin-variants'), 'The product editor must expose escaped configuration through data attributes.');
$assert(!is_file($root . '/admin/partials/fabric-product-form-script.php'), 'The duplicated inline product editor script must be removed.');
$assert(!str_contains($courierReturns, 'class="small text-muted mt-2"')
    && !str_contains($courierReturns, 'class="mt-2"')
    && !str_contains($courierReturns, 'class="btn btn-sm'), 'Courier return actions must use first-party admin classes.');
$assert(!str_contains($supportTickets, 'class="alert alert-warning"'), 'Support migration warnings must use the shared alert component.');
$assert(str_contains($adminCss, '@layer admin-refresh')
    && str_contains($adminCss, '.dashboard-kpi-card::before')
    && str_contains($adminCss, '.admin-card-table tr'), 'The refreshed admin shell, dashboard, and responsive table system must remain present.');
$assert(str_contains($adminCss, '.dashboard-kpi-grid')
    && str_contains($adminCss, '.dashboard-mini-list')
    && str_contains($adminCss, '.return-breakdown-mobile-item'), 'Admin dashboard and return summary structures must retain explicit first-party layouts.');
$assert(str_contains($adminCss, '@media (max-width: 95rem)')
    && str_contains($adminCss, '.admin-nav.is-open')
    && str_contains($adminCss, 'grid-template-columns: repeat(2, 12rem) auto')
    && str_contains($adminCss, 'display: grid !important')
    && str_contains($adminCss, '.dashboard-header .admin-filter-actions'), 'Admin navigation and dashboard filters must retain their collision-free desktop and mobile layouts.');

if ($failures !== []) {
    fwrite(STDERR, "Admin frontend rewrite contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: admin frontend rewrite contracts passed\n";
