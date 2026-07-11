<?php
require_once __DIR__ . '/includes/init.php';
$siteSettings = SiteSettingsService::get();
$metaTitle = SiteContext::title('International Orders Policy');
$heroSubtitle = (string) ($siteSettings['international_policy_subtitle'] ?? 'Terms for export and cross-border textile orders.');
$heroSubtitle = strtr($heroSubtitle, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
$policyBody = (string) ($siteSettings['international_policy_body_html'] ?? '');
$policyBody = strtr($policyBody, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>International Orders Policy</h1>
        <p class="mb-0"><?php echo e($heroSubtitle); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="container surface-panel">
        <?php echo $policyBody; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
