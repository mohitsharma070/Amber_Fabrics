<?php require_once 'includes/init.php'; ?>
<?php
$metaTitle = SiteContext::title('Thank You');
$metaDescription = 'Thank you for contacting ' . SiteContext::name() . '. We appreciate your inquiry and will respond soon.';
$metaKeywords = 'thank you, inquiry, ' . SiteContext::name();
include 'includes/header.php'; ?>

<section class="section-block">
    <div class="l-container">
        <div class="surface-panel u-text-center animate-in">
            <h1 class="u-mb-3">Thank You</h1>
            <p class="u-mb-4">Your inquiry has been received. Our team will contact you shortly with the next steps.</p>
            <div class="u-flex u-justify-center u-gap-2 u-wrap">
                <a href="catalog.php" class="ui-button ui-button--primary">Browse Fabrics</a>
                <a href="index.php" class="ui-button ui-button--outline">Back to Home</a>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
