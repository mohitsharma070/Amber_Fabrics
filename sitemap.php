<?php
require_once __DIR__ . '/includes/init.php';

$paths = [
    '/',
    '/catalog',
    '/about',
    '/contact',
    '/international-buyers',
    '/faq',
    '/size-guide',
    '/privacy-policy',
    '/return-policy',
    '/shipping-policy',
    '/terms',
    '/international-orders-policy',
];

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($paths as $path): ?>
  <url>
    <loc><?php echo e(SiteContext::url($path)); ?></loc>
    <changefreq>weekly</changefreq>
    <priority><?php echo $path === '/' ? '1.0' : '0.8'; ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
