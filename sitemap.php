<?php
require_once __DIR__ . '/includes/init.php';

$paths = [
    '/index.php',
    '/catalog.php',
    '/about.php',
    '/contact.php',
    '/international-buyers.php',
    '/faq.php',
    '/size-guide.php',
    '/privacy-policy.php',
    '/return-policy.php',
    '/shipping-policy.php',
    '/terms.php',
    '/international-orders-policy.php',
];

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($paths as $path): ?>
  <url>
    <loc><?php echo e(SiteContext::url($path)); ?></loc>
    <changefreq>weekly</changefreq>
    <priority><?php echo $path === '/index.php' ? '1.0' : '0.8'; ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
