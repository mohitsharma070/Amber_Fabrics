<?php
require_once __DIR__ . '/includes/init.php';
$siteSettings = SiteSettingsService::get();
$metaTitle = SiteContext::title('Return Policy');
$heroSubtitle = (string) ($siteSettings['return_policy_subtitle'] ?? 'Simple and transparent policy for Indian ecommerce orders.');
$heroSubtitle = strtr($heroSubtitle, [
    '{{site_name}}' => SiteContext::name(),
    '{{contact_email}}' => SiteContext::contactEmail(),
]);
$policyBody = (string) ($siteSettings['return_policy_body_html'] ?? '');
$policyBody = strtr($policyBody, [
    '{{site_name}}' => SiteContext::name(),
    '{{contact_email}}' => SiteContext::contactEmail(),
]);
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1>Refund Return Policy</h1>
        <p class="u-mb-0"><?php echo e($heroSubtitle); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="l-container surface-panel">
        <div class="ui-alert ui-alert--info">Refund return requests are accepted for eligible cases within <?php echo return_request_window_days(); ?> calendar days of confirmed delivery. Product exchanges are not offered.</div>
        <?php echo $policyBody; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
