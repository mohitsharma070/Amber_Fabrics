<?php

final class SiteSettingsService
{
    private static ?array $settings = null;
    private static bool $tableChecked = false;
    private static bool $tableAvailable = false;

    public static function defaults(): array
    {
        return [
            'site_name' => 'Store',
            'site_description' => 'Premium woven and blended fabrics for global brands, importers, and distributors.',
            'contact_email' => '',
            'branding_logo' => 'images/logo-brand-light.svg',
            'hero_swatch_1' => '',
            'hero_swatch_2' => '',
            'hero_swatch_3' => '',
            'hero_swatch_4' => '',
            'hero_swatch_5' => '',
            'hero_swatch_6' => '',
            'announcement_1_text' => 'Free Shipping on orders above Rs 999',
            'announcement_1_enabled' => '1',
            'announcement_2_text' => 'New arrivals added every week',
            'announcement_2_enabled' => '1',
            'announcement_3_text' => '',
            'announcement_3_enabled' => '0',
            'announcement_4_text' => '',
            'announcement_4_enabled' => '0',
            'announcement_5_text' => '',
            'announcement_5_enabled' => '0',
            'gst_rate' => '18',
            'company_address' => '',
            'company_phone' => '',
            'gst_number' => '',
            'hsn_code' => '5208',
            'pan_number' => '',
            'company_state' => '',
            'packing_unboxing_notice' => 'Please record an unboxing video while opening the parcel. This video is mandatory for raising any disputes or return requests. Thank you!',
            'packing_cod_notice' => 'Collect cash from customer on delivery. Do NOT handover parcel without payment.',
            'packing_footer_note' => 'If outer packaging/label is found tampered/damaged, do not accept the parcel. All disputes are subject to local jurisdiction only.',
            'packing_repeat_badge_label' => '',
            'packing_repeat_min_orders' => '1',
            'home_hero_badge' => 'Home Textile Startup',
            'home_hero_title' => 'Modern Home Textiles, Built for Everyday Living',
            'home_hero_desc' => 'is a growing Indian startup focused on quality Bedsheets, Towels, and Table Covers for retail and bulk buyers.',
            'home_hero_shop_cta' => 'Shop in India',
            'home_hero_inquiry_cta' => 'International / Bulk Inquiry',
            'home_categories_title' => 'Shop by Category',
            'home_categories_subtitle' => 'Discover our focused collection for modern homes and gifting',
            'home_latest_title' => 'Latest Drops',
            'home_latest_subtitle' => 'Newly launched designs across our fast-growing range',
            'home_latest_view_all_cta' => 'View All',
            'home_latest_empty_text' => 'No products added yet.',
            'home_latest_empty_cta' => 'Browse catalog',
            'home_latest_browse_cta' => 'Browse Full Collection',
            'home_why_title_prefix' => 'Why Choose',
            'home_why_subtitle' => 'Startup speed with dependable quality and fulfillment',
            'home_why_card_1_title' => 'Consistent Quality',
            'home_why_card_1_desc' => 'Every batch is checked for finish, stitching standards, and fabric consistency before dispatch.',
            'home_why_card_2_title' => 'Startup-Friendly MOQ',
            'home_why_card_2_desc' => 'Built for growing sellers and boutiques with practical order quantities and quick restocks.',
            'home_why_card_3_title' => 'Bulk & B2B Ready',
            'home_why_card_3_desc' => 'From sample discussions to larger purchase planning, our team supports smooth B2B coordination.',
            'home_why_card_4_title' => 'Fast Dispatch',
            'home_why_card_4_desc' => 'Ready stock is shipped quickly so your shelf and online listings stay active without long waits.',
            'home_b2b_eyebrow' => 'B2B Growth Partner',
            'home_b2b_title' => 'Need Reliable Home Textile Supply?',
            'home_b2b_desc' => 'Share your quantity, target price, and delivery timeline. Our team will get back with practical sourcing options.',
            'home_b2b_primary_cta' => 'Bulk / Export Inquiry',
            'home_b2b_secondary_cta' => 'Contact Our Team',
            'home_testimonials_title' => 'What Our Buyers Say',
            'home_testimonials_subtitle' => 'Feedback from early customers and growing retail partners',
            'home_testimonial_1_text' => '"The bedsheet quality is premium for the price point. Finishing was clean, packing was neat, and repeat order process was smooth."',
            'home_testimonial_1_name' => 'Priya Mehta',
            'home_testimonial_1_location' => 'Mumbai Retail Buyer',
            'home_testimonial_2_text' => '"We sourced table covers in bulk for our marketplace store. Team communication was quick, and the dispatch timeline matched what was promised."',
            'home_testimonial_2_name' => 'Chirag Arora',
            'home_testimonial_2_location' => 'D2C Seller, Delhi',
            'home_testimonial_3_text' => '"Started with a small towel order and scaled in weeks. MOQ flexibility and consistent product quality helped us grow without inventory stress."',
            'home_testimonial_3_name' => 'Ayesha Khan',
            'home_testimonial_3_location' => 'Boutique Owner, Bengaluru',
            'footer_description' => 'Premium woven and blended fabrics for global brands, importers, and distributors.',
            'footer_support_title' => 'Support',
            'footer_support_hours' => 'Mon - Sat: 9:00 AM to 7:00 PM',
            'footer_support_contact_cta' => 'Contact Team',
            'footer_explore_title' => 'Explore',
            'footer_explore_shop_cta' => 'Shop Collection',
            'footer_explore_categories_cta' => 'Categories',
            'footer_explore_inquiry_cta' => 'International / Bulk Inquiry',
            'footer_explore_faq_cta' => 'FAQ',
            'footer_policies_title' => 'Policies',
            'footer_policy_shipping_cta' => 'Shipping Policy',
            'footer_policy_return_cta' => 'Return Policy',
            'footer_policy_privacy_cta' => 'Privacy Policy',
            'footer_policy_terms_cta' => 'Terms & Conditions',
            'footer_policy_size_guide_cta' => 'Size & Fabric Guide',
            'footer_policy_international_cta' => 'International Orders Policy',
            'footer_bottom_tagline' => 'Built for fast, reliable ecommerce growth.',
            'shipping_policy_subtitle' => 'Shipping details for India ecommerce orders and international inquiries.',
            'shipping_policy_body_html' => '<h3>Order Processing</h3><p>Orders are processed Monday to Saturday, excluding public holidays. Most prepaid and COD orders are dispatched within 24 to 48 working hours after confirmation.</p><h3>India Delivery Timelines</h3><p>Typical delivery timeline for India orders is 4 to 7 working days after dispatch. Metro locations may be faster, while remote regions can take longer depending on courier network coverage.</p><h3>Shipping Charges</h3><p>Shipping charges are calculated at checkout and displayed before payment. Current checkout rules apply as shown on the checkout page, including free-shipping thresholds where applicable.</p><h3>Address and Delivery Attempts</h3><p>Customers must provide a complete and accurate shipping address with a reachable phone number. If delivery fails due to incorrect details or repeated non-availability, re-dispatch may attract additional shipping cost.</p><h3>Delays Beyond Our Control</h3><p>Dispatch and delivery timelines are estimates and may be affected by weather, transport strikes, high-demand periods, or government restrictions. We are not liable for courier delays beyond reasonable control.</p><h3>Order Tracking</h3><p>Tracking details are shared once the shipment is booked. If you do not receive tracking details within 72 working hours of order confirmation, contact our support team.</p><h3>International Orders</h3><p>International shipping is quote-based and is not processed via direct checkout. Freight mode, lead time, duties, taxes, and documentation are finalized during quote confirmation.</p><p>Please submit your requirement through <a href=\"/international-buyers.php\">Request International Quote</a> and review the <a href=\"/international-orders-policy.php\">International Orders Policy</a>.</p>',
            'return_policy_subtitle' => 'Simple and transparent policy for Indian ecommerce orders.',
            'return_policy_body_html' => '<h3>Return / Exchange Request Window</h3><p>Requests must be raised within 48 hours of delivery for India ecommerce orders. Claims raised after this window may not be accepted.</p><h3>Eligible Cases</h3><p>Returns or exchanges are considered for verified cases of wrong item delivered, transit damage, or major manufacturing defect.</p><h3>Mandatory Evidence</h3><p>Customers must share clear parcel opening photos/videos, product photos, and order details to support the claim. Claims without adequate evidence may be declined.</p><h3>Non-Returnable Cases</h3><p>Products are not eligible if they are used, washed, altered, cut, stitched, or missing tags/original packaging.</p><p>Minor shade variation, artisanal print irregularity, weave texture variation, and slight measurement tolerance are not considered defects.</p><h3>Handcrafted Product Note</h3><p>Many products are handcrafted or handblock-based. Small variations are inherent to the process and part of product authenticity.</p><h3>Exchange and Refund Flow</h3><p>After approval, exchange is processed subject to stock availability. If replacement is unavailable, eligible refunds are processed to the original payment source.</p><h3>Refund Timelines</h3><p>Approved refunds are initiated after quality validation and pickup completion, where applicable. Bank settlement may take 5 to 10 business days depending on payment method.</p><h3>International / Bulk Orders</h3><p>International and bulk orders follow quote-approved commercial terms. Return, replacement, and damage claims are governed by the finalized quote/proforma terms.</p>',
            'privacy_policy_subtitle' => 'How {{site_name}} collects and uses your information.',
            'privacy_policy_body_html' => '<h3>Information We Collect</h3><p>We collect information you provide directly, including name, email, phone/WhatsApp, shipping and billing details, order records, account details, and inquiry submissions.</p><h3>How We Use Information</h3><p>Data is used to process orders, provide customer support, communicate order status, respond to inquiries, prevent fraud, maintain security, and improve our products and website experience.</p><h3>Payments and Security</h3><p>Online payments are processed through authorized payment partners. We do not store full card data on our servers. Sensitive transactions are handled using secure gateway infrastructure.</p><h3>Cookies and Analytics</h3><p>We may use cookies and similar technologies for session management, cart continuity, login status, and basic analytics. You can control cookies through your browser settings, but some features may stop working.</p><p class=\"mb-4\"><button type=\"button\" class=\"btn btn-sm btn-outline-dark\" data-open-cookie-consent>Cookie Preferences</button></p><h3>Information Sharing</h3><p>We share data only with trusted service providers such as payment processors, shipping partners, technology vendors, and legal authorities where required by law.</p><h3>Marketing Communication</h3><p>We may send transactional communication and occasional promotional updates. You can opt out of marketing messages at any time without affecting order-related communication.</p><h3>Data Retention</h3><p>We retain personal data only as long as needed for business, legal, tax, accounting, and compliance obligations.</p><h3>Your Rights</h3><p>You may request correction or deletion of your personal information, subject to legal and operational retention requirements.</p><h3>International / Bulk Inquiry Data</h3><p>Information submitted for international or bulk inquiries is used for quote preparation, compliance checks, logistics planning, and sales communication only.</p><h3>Contact</h3><p>For privacy requests, contact us at {{contact_email}}.</p>',
            'terms_policy_subtitle' => 'Terms governing purchases and inquiries on {{site_name}}.',
            'terms_policy_body_html' => '<h3>Acceptance of Terms</h3><p>By accessing or purchasing through this website, you agree to these Terms &amp; Conditions and all related policies published on this site.</p><h3>Account and Customer Information</h3><p>You are responsible for providing accurate details for order processing and communication. We may suspend or cancel orders with incomplete, suspicious, or unverifiable information.</p><h3>Product Representation</h3><p>We aim to present products as accurately as possible. Actual shade, print placement, and texture may vary due to photography, display settings, and artisanal production methods.</p><h3>Handcrafted Variation</h3><p>Slight irregularities in handmade/handblock products are natural and are not considered defects.</p><h3>Pricing, Stock, and Order Acceptance</h3><p>Prices and availability can change without prior notice. Order confirmation does not guarantee fulfillment if stock discrepancy, pricing error, compliance issue, or payment risk is identified.</p><h3>Payments</h3><p>Orders may be paid through available methods at checkout, including COD and online payment where applicable. Fraud checks and payment verification may be performed before dispatch.</p><h3>Shipping and Delivery</h3><p>Shipping timelines are estimates and subject to logistics constraints. Please review our <a href=\"/shipping-policy.php\">Shipping Policy</a> for complete details.</p><h3>Returns and Exchanges</h3><p>Returns and exchanges are governed by our <a href=\"/return-policy.php\">Return &amp; Exchange Policy</a>.</p><h3>International Orders</h3><p>International orders are processed only through the quote flow and are governed by the <a href=\"/international-orders-policy.php\">International Orders Policy</a>.</p><h3>Intellectual Property</h3><p>All text, images, designs, and branding on this website are owned by or licensed to {{site_name}} and may not be reused without permission.</p><h3>Limitation of Liability</h3><p>To the maximum extent permitted by law, our liability is limited to the amount paid for the affected order item.</p><h3>Policy Updates</h3><p>We may update these terms from time to time. The latest version published on this page applies to future use of the website.</p>',
            'international_policy_subtitle' => 'Terms for export and cross-border textile orders.',
            'international_policy_body_html' => '<h3>Quote-First Process</h3><p>International orders are processed only through our quote workflow. Direct checkout is not available for cross-border shipments.</p><h3>What To Share In Inquiry</h3><p>Please include product details, quantity, destination country, preferred timeline, and any technical/compliance requirements when requesting a quote.</p><h3>Pricing and Currency</h3><p>International prices are shared in the quote/proforma and may vary by quantity, fabric specification, packaging, destination, and freight mode.</p><h3>Freight, Duties, and Taxes</h3><p>Import duties, destination taxes, customs clearance fees, and local handling charges are the buyer\'s responsibility unless explicitly stated otherwise in writing.</p><h3>Lead Time and Dispatch</h3><p>Production and dispatch timelines are confirmed at quote/proforma stage. Timelines are estimates and may vary based on order complexity and logistics conditions.</p><h3>Quality, Claims, and Documentation</h3><p>Any quality or transit claim must be raised with complete evidence within the period defined in the commercial agreement. Supporting documents may include parcel images, opening video, and shipment details.</p><h3>Returns and Replacements</h3><p>International returns/replacements are governed by approved quote/proforma terms and are not covered by domestic ecommerce return rules.</p><h3>How To Place an International Order</h3><p>Submit your request via <a href=\"/international-buyers.php\">Request International Quote</a>. Our team will share pricing, terms, and next steps.</p>',
            'faq_subtitle' => 'Answers for India shopping and international inquiries.',
            'faq_body_html' => '<h3>1. How long does India delivery take?</h3><p>Most India orders are delivered within 4 to 7 working days after dispatch, depending on destination.</p><h3>2. What are the shipping charges?</h3><p>Shipping is calculated at checkout. Any free-shipping threshold or surcharge is shown before you place the order.</p><h3>3. Can I return or exchange products?</h3><p>Yes, for eligible cases such as wrong item, transit damage, or major defect, within the policy window. Please refer to our Return &amp; Exchange Policy.</p><h3>4. Why does my product look slightly different from photos?</h3><p>Handcrafted and handblock processes naturally create slight variations in shade, texture, and motif placement.</p><h3>5. Do you store card details?</h3><p>No. Payments are processed by secure gateway partners. We do not store full card data on our servers.</p><h3>6. How do I choose size and fabric?</h3><p>Use our Size &amp; Fabric Guide for measurement reference, fabric selection advice, and care guidelines.</p><h3>7. Do you accept international orders?</h3><p>Yes, international orders are handled through a quote process only. Submit your requirement on the International Quote form.</p><h3>8. Can I place bulk orders for business?</h3><p>Yes, we support domestic and export bulk orders with quantity-based commercial terms.</p><h3>9. How can I contact support?</h3><p>You can email {{contact_email}} or use our contact and inquiry forms.</p>',
            'size_guide_subtitle' => 'Quick reference for sizing, fabric selection, and textile care.',
            'size_guide_body_html' => '<h3>How to Measure</h3><p>Use a measuring tape and measure bust, waist, and hip in inches. Compare with the product size chart before ordering.</p><h3>General Women Size Reference (inches)</h3><div class=\"table-responsive\"><table class=\"table table-bordered align-middle\"><thead><tr><th>Size</th><th>Bust</th><th>Waist</th><th>Hip</th></tr></thead><tbody><tr><td>S</td><td>34</td><td>28</td><td>36</td></tr><tr><td>M</td><td>36</td><td>30</td><td>38</td></tr><tr><td>L</td><td>38</td><td>32</td><td>40</td></tr><tr><td>XL</td><td>40</td><td>34</td><td>42</td></tr><tr><td>XXL</td><td>42</td><td>36</td><td>44</td></tr></tbody></table></div><h3>Fabric Selection Guide</h3><p><strong>Cotton:</strong> breathable, soft, and ideal for daily wear and warm weather.</p><p><strong>Cotton Blend:</strong> better wrinkle resistance and easy care with good comfort.</p><p><strong>Printed/Handblock Fabric:</strong> artisanal character with natural variation in motif and shade.</p><p><strong>Upholstery/Home Textile Fabric:</strong> choose based on use-case, abrasion needs, and wash care instructions.</p><h3>Care Guidance</h3><p>Always follow product-specific wash instructions. For first wash, separate dark and light shades. Avoid harsh detergents and direct sun drying for dyed/printed fabrics.</p><h3>Important Notes</h3><p>Fit may vary by style, cut, and fabric drape. For handcrafted textiles, slight variation in weave and finish is normal.</p><p>If you are between sizes, choose the larger size for comfort.</p><h3>Bulk/International Sizing</h3><p>For international or bulk programs, custom size specs can be shared in the quote process.</p>',
        ];
    }

    public static function ensureTable(mysqli $conn): bool
    {
        if (self::$tableChecked) {
            return self::$tableAvailable;
        }

        self::$tableChecked = true;

        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'site_settings'"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            self::$tableAvailable = ((int) ($row['total'] ?? 0)) > 0;
            if (!self::$tableAvailable) {
                error_log('[site-settings] site_settings table missing. Run: php database/setup.php');
            }
        } catch (Throwable $e) {
            self::$tableAvailable = false;
            error_log('[site-settings] site_settings table check failed: ' . $e->getMessage());
        }

        return self::$tableAvailable;
    }

    public static function loadFromDb(mysqli $conn): array
    {
        if (!self::ensureTable($conn)) {
            return [];
        }

        $rows = $conn->query("SELECT setting_key, setting_value FROM site_settings");
        $settings = [];
        while ($row = $rows->fetch_assoc()) {
            $key = (string) ($row['setting_key'] ?? '');
            if ($key !== '') {
                $settings[$key] = (string) ($row['setting_value'] ?? '');
            }
        }

        return $settings;
    }

    public static function saveToDb(mysqli $conn, array $settings): void
    {
        if (!self::ensureTable($conn)) {
            return;
        }

        $stmt = $conn->prepare(
            "INSERT INTO site_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($settings as $key => $value) {
            $settingKey = (string) $key;
            $settingValue = is_scalar($value) ? (string) $value : '';
            $stmt->bind_param('ss', $settingKey, $settingValue);
            $stmt->execute();
        }

        self::$settings = null;
    }

    public static function get(?mysqli $conn = null): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $settings = self::defaults();
        $settingsFile = dirname(__DIR__, 2) . '/config/site-settings.json';

        if (file_exists($settingsFile)) {
            $json = @file_get_contents($settingsFile);
            if ($json !== false) {
                $loaded = @json_decode($json, true);
                if (is_array($loaded)) {
                    $settings = array_merge($settings, $loaded);
                }
            }
        }

        $conn = $conn ?: (($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null);
        if ($conn instanceof mysqli) {
            try {
                $dbSettings = self::loadFromDb($conn);
                if (!empty($dbSettings)) {
                    $settings = array_merge($settings, $dbSettings);
                }
            } catch (Throwable $e) {
                error_log('[site-settings] load from db failed: ' . $e->getMessage());
            }
        }

        self::$settings = $settings;
        return self::$settings;
    }
}
