<?php require_once 'includes/init.php'; ?>
<?php
$metaTitle = 'About Us | Amber Fabrics';
$metaDescription = 'Learn about Amber Fabrics, a modern home textile ecommerce brand.';
$metaKeywords = 'about Amber Fabrics, home textiles brand, ecommerce';

$aboutMediaItems = [];
try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS about_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            media_type ENUM('image','video') NOT NULL DEFAULT 'image',
            file_name VARCHAR(255) NOT NULL,
            poster_image VARCHAR(255) DEFAULT NULL,
            alt_text VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_about_media_active_sort (is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $stmt = $conn->prepare(
        "SELECT media_type, file_name, poster_image, alt_text
         FROM about_media
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC
         LIMIT 6"
    );
    $stmt->execute();
    $aboutMediaItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    $aboutMediaItems = [];
}

include 'includes/header.php'; ?>

<section class="about-hero">
    <div class="container">
        <div class="about-hero-inner animate-in">
            <p class="about-kicker mb-2">Trusted Textile Partner</p>
            <h1 class="mb-3">Built for Reliable Quality, Fast Fulfillment, and Scalable Growth</h1>
            <p class="about-hero-desc">Amber Fabrics helps growing brands and buyers source premium home textiles with practical MOQ, quality-first processes, and dependable dispatch.</p>
            <div class="about-hero-actions">
                <a href="/catalog.php" class="btn btn-light btn-lg">Shop Collection</a>
                <a href="/international-buyers.php" class="btn btn-outline-light btn-lg">Bulk Inquiry</a>
            </div>
            <div class="about-trust-points">
                <span>Quality-Checked Batches</span>
                <span>Startup-Friendly MOQ</span>
                <span>B2B & Export Ready</span>
            </div>
        </div>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="surface-panel about-story animate-in">
            <div class="about-story-head">
                <h2 class="mb-2">Our Story</h2>
                <p class="mb-0 text-muted">A modern textile brand combining traditional fabric sensibility with ecommerce speed.</p>
            </div>
            <div class="about-story-body">
                <p>We started Amber Fabrics to solve a common buyer challenge: finding reliable textile quality at practical order quantities without slow communication or delayed dispatch.</p>
                <p class="mb-0">From daily-use bedsheets and towels to scalable bulk programs, our team focuses on consistency, transparent coordination, and long-term buyer trust.</p>
            </div>
        </div>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="section-head text-center mb-4">
            <h2 class="mb-2">Inside Amber Fabrics</h2>
            <p class="text-muted mb-0">A quick look at our fabric quality, production mood, and dispatch-ready process.</p>
        </div>
        <div class="about-media-grid">
            <?php if (!empty($aboutMediaItems)): ?>
                <?php foreach ($aboutMediaItems as $media): ?>
                    <article class="about-media-card animate-in">
                        <?php if (($media['media_type'] ?? 'image') === 'video'): ?>
                            <?php
                                $videoExt = strtolower(pathinfo((string) ($media['file_name'] ?? ''), PATHINFO_EXTENSION));
                                $videoMime = 'video/mp4';
                                if ($videoExt === 'webm') { $videoMime = 'video/webm'; }
                                if ($videoExt === 'ogg') { $videoMime = 'video/ogg'; }
                            ?>
                            <video controls muted playsinline preload="metadata" <?php echo !empty($media['poster_image']) ? 'poster="/images/about/' . e($media['poster_image']) . '"' : ''; ?>>
                                <source src="/images/about/<?php echo e($media['file_name']); ?>" type="<?php echo e($videoMime); ?>">
                                Your browser does not support the video tag.
                            </video>
                        <?php else: ?>
                            <img src="/images/about/<?php echo e($media['file_name']); ?>" alt="<?php echo e($media['alt_text'] ?: 'About media image'); ?>" loading="lazy" width="1200" height="900">
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <article class="about-media-card animate-in">
                    <img src="/images/fabrics/cotton1.jpg" alt="Cotton fabric quality detail" loading="lazy" width="1200" height="900">
                </article>
                <article class="about-media-card animate-in">
                    <video controls muted playsinline preload="metadata" poster="/images/fabrics/linen1.jpg">
                        <source src="/images/fabrics/fabric_69fb179af2e314.32963938.mp4" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </article>
                <article class="about-media-card animate-in">
                    <img src="/images/fabrics/linen1.jpg" alt="Neatly arranged textile stock for dispatch" loading="lazy" width="1200" height="900">
                </article>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="about-metrics-grid">
            <article class="about-metric-card animate-in">
                <h3>Quality Process</h3>
                <p class="mb-0">Structured checks for fabric feel, finish, and consistency before dispatch.</p>
            </article>
            <article class="about-metric-card animate-in">
                <h3>MOQ Friendly</h3>
                <p class="mb-0">Flexible order quantities designed for growing ecommerce sellers.</p>
            </article>
            <article class="about-metric-card animate-in">
                <h3>Fast Dispatch</h3>
                <p class="mb-0">Ready stock and production planning aligned to reduce lead-time friction.</p>
            </article>
            <article class="about-metric-card animate-in">
                <h3>B2B Ready</h3>
                <p class="mb-0">International and bulk support for repeat purchase programs.</p>
            </article>
        </div>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="section-head text-center mb-4">
            <h2 class="mb-2">Capabilities for Growing Buyers</h2>
            <p class="text-muted mb-0">Operational support built for both D2C growth and wholesale scale.</p>
        </div>
        <div class="about-capability-grid">
            <article class="about-capability-card animate-in">
                <h3>Sampling Support</h3>
                <p class="mb-0">Quick sample coordination for approvals and pilot launches.</p>
            </article>
            <article class="about-capability-card animate-in">
                <h3>Scale Capacity</h3>
                <p class="mb-0">Structured production plans for repeat and seasonal demand.</p>
            </article>
            <article class="about-capability-card animate-in">
                <h3>International Programs</h3>
                <p class="mb-0">Dedicated support for export-focused and overseas buyers.</p>
            </article>
            <article class="about-capability-card animate-in">
                <h3>Account Coordination</h3>
                <p class="mb-0">Clear communication on MOQ, lead times, and shipment readiness.</p>
            </article>
        </div>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6 animate-in">
                <div class="surface-panel about-cert-panel h-100">
                    <h2 class="mb-3">Compliance & Certifications</h2>
                    <div class="about-cert-list">
                        <div class="about-cert-card">ISO Certified Manufacturing</div>
                        <div class="about-cert-card">OEKO-TEX Aligned Processes</div>
                        <div class="about-cert-card">GOTS-Compatible Material Programs</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 animate-in">
                <div class="surface-panel about-confidence h-100">
                    <h2 class="mb-3">Why Buyers Stay With Us</h2>
                    <div class="about-quote-list">
                        <blockquote class="about-quote">“Quality is consistent, and dispatch timelines are predictable for our storefront planning.”</blockquote>
                        <blockquote class="about-quote">“MOQ flexibility helped us test and scale without inventory stress.”</blockquote>
                        <blockquote class="about-quote">“Communication is clear from sampling to final shipment.”</blockquote>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="about-final-cta">
    <div class="container">
        <div class="about-final-cta-inner animate-in">
            <h2 class="mb-2">Ready to Explore the Collection?</h2>
            <p class="mb-0">Browse our latest range or connect with us for bulk and international requirements.</p>
            <div class="about-final-actions">
                <a href="/catalog.php" class="btn btn-light btn-lg">Shop Collection</a>
                <a href="/contact.php" class="btn btn-outline-light btn-lg">Talk to Our Team</a>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
