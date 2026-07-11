<?php
require_once __DIR__ . '/includes/init.php';
$siteSettings = SiteSettingsService::get();
$metaTitle = SiteContext::title('FAQ');
$heroSubtitle = (string) ($siteSettings['faq_subtitle'] ?? 'Answers for India shopping and international inquiries.');
$heroSubtitle = strtr($heroSubtitle, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
$pageBody = (string) ($siteSettings['faq_body_html'] ?? '');
$pageBody = strtr($pageBody, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="container">
        <h1>Frequently Asked Questions</h1>
        <p class="mb-0"><?php echo e($heroSubtitle); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="container surface-panel">
        <?php echo $pageBody; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
