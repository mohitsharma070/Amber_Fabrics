<?php
require_once __DIR__ . '/includes/init.php';
$siteSettings = SiteSettingsService::get();
$metaTitle = SiteContext::title('FAQ');
$heroSubtitle = (string) ($siteSettings['faq_subtitle'] ?? 'Answers for India shopping and international inquiries.');
$heroSubtitle = strtr($heroSubtitle, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
$pageBody = (string) ($siteSettings['faq_body_html'] ?? '');
$pageBody = strtr($pageBody, ['{{site_name}}' => SiteContext::name(), '{{contact_email}}' => SiteContext::contactEmail()]);
// Echoed unescaped at the bottom of this file, exactly like the six policy pages.
// This page was the one that never sanitized; substitution happens first so the
// allowlist also sees whatever the placeholders expanded into.
$pageBody = ui_rich_text_html($pageBody);
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
    <div class="l-container">
        <h1>Frequently Asked Questions</h1>
        <p class="u-mb-0"><?php echo e($heroSubtitle); ?></p>
    </div>
</section>

<section class="section-block">
    <div class="l-container surface-panel">
        <?php echo $pageBody; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
