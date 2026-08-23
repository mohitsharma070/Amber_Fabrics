<?php
require_once __DIR__ . '/includes/init.php';
$siteSettings = SiteSettingsService::get();
$metaTitle = SiteContext::title('Privacy Policy');
$heroSubtitle = (string) ($siteSettings['privacy_policy_subtitle'] ?? ('How ' . SiteContext::name() . ' collects and uses your information.'));
$heroSubtitle = strtr($heroSubtitle, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
$policyBody = (string) ($siteSettings['privacy_policy_body_html'] ?? '');
$policyBody = strtr($policyBody, [
    '{{site_name}}' => SiteContext::name(),
    '{{contact_email}}' => SiteContext::contactEmail(),
]);
$policyBody = ui_rich_text_html($policyBody);
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1>Privacy Policy</h1>
        <p class="u-mb-0"><?php echo e($heroSubtitle); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="l-container surface-panel">
        <?php echo $policyBody; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
