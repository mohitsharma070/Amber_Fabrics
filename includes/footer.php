<footer class="site-footer">
    <div class="container">
        <div class="footer-desktop d-none d-md-grid">
            <div>
                <h5>Amber Fabrics</h5>
                <p>Modern home textile startup focused on premium Bedsheets, Towels, and Table Covers for retail and bulk buyers.</p>
            </div>
            <div>
                <h5>Support</h5>
                <p>Email: amberfabricstextiles@gmail.com</p>
                <p>Mon - Sat: 9:00 AM to 7:00 PM</p>
                <p><a class="text-decoration-none text-light" href="/contact.php">Contact Team</a></p>
            </div>
            <div>
                <h5>Explore</h5>
                <p><a class="text-decoration-none text-light" href="/catalog.php">Shop Collection</a></p>
                <p><a class="text-decoration-none text-light" href="/index.php#catSlider">Categories</a></p>
                <p><a class="text-decoration-none text-light" href="/international-buyers.php">International / Bulk Inquiry</a></p>
                <p><a class="text-decoration-none text-light" href="/faq.php">FAQ</a></p>
            </div>
            <div>
                <h5>Policies</h5>
                <p><a class="text-decoration-none text-light" href="/shipping-policy.php">Shipping Policy</a></p>
                <p><a class="text-decoration-none text-light" href="/return-policy.php">Return Policy</a></p>
                <p><a class="text-decoration-none text-light" href="/privacy-policy.php">Privacy Policy</a></p>
                <p><a class="text-decoration-none text-light" href="/terms.php">Terms & Conditions</a></p>
                <p><a class="text-decoration-none text-light" href="/size-guide.php">Size & Fabric Guide</a></p>
                <p><a class="text-decoration-none text-light" href="/international-orders-policy.php">International Orders Policy</a></p>
            </div>
        </div>

        <div class="footer-mobile d-md-none">
            <div class="footer-brand">
                <h5>Amber Fabrics</h5>
                <p>Modern home textile startup for quality products and faster fulfillment.</p>
            </div>
            <div class="accordion footer-accordion" id="footerAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerSupport" aria-expanded="false" aria-controls="footerSupport">Support</button>
                    </h2>
                    <div id="footerSupport" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <a href="mailto:amberfabricstextiles@gmail.com">amberfabricstextiles@gmail.com</a>
                            <a href="/contact.php">Contact Team</a>
                            <span>Mon - Sat: 9:00 AM to 7:00 PM</span>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerExplore" aria-expanded="false" aria-controls="footerExplore">Explore</button>
                    </h2>
                    <div id="footerExplore" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <a href="/catalog.php">Shop Collection</a>
                            <a href="/index.php#catSlider">Categories</a>
                            <a href="/international-buyers.php">International / Bulk Inquiry</a>
                            <a href="/faq.php">FAQ</a>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#footerPolicies" aria-expanded="false" aria-controls="footerPolicies">Policies</button>
                    </h2>
                    <div id="footerPolicies" class="accordion-collapse collapse" data-bs-parent="#footerAccordion">
                        <div class="accordion-body">
                            <a href="/shipping-policy.php">Shipping Policy</a>
                            <a href="/return-policy.php">Return Policy</a>
                            <a href="/privacy-policy.php">Privacy Policy</a>
                            <a href="/terms.php">Terms & Conditions</a>
                            <a href="/size-guide.php">Size & Fabric Guide</a>
                            <a href="/international-orders-policy.php">International Orders Policy</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <p class="mb-0 text-center">&copy; <?php echo date('Y'); ?> Amber Fabrics. Built for fast, reliable ecommerce growth.</p>
        </div>
    </div>
</footer>

<button
    type="button"
    class="go-top-btn"
    id="goTopBtn"
    aria-label="Go to top"
    title="Go to top"
>
    <span aria-hidden="true">&uarr;</span>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php do_action('page.footer', [
    'page' => basename($_SERVER['PHP_SELF'] ?? ''),
]); ?>
</body>
</html>
