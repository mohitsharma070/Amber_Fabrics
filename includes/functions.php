<?php

require_once __DIR__ . '/email-templates/index.php';
require_once __DIR__ . '/services/SiteSettingsService.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/CartService.php';
require_once __DIR__ . '/services/InventoryService.php';
require_once __DIR__ . '/services/OrderAccessService.php';
require_once __DIR__ . '/services/DeliveryEstimateService.php';
require_once __DIR__ . '/services/ProductAdminService.php';
require_once __DIR__ . '/services/ProductImportService.php';
require_once __DIR__ . '/services/ProductVariantService.php';
require_once __DIR__ . '/services/EmailService.php';
require_once __DIR__ . '/services/CronService.php';

$helperFiles = [
    'helpers/core.php',
    'helpers/observability.php',
    'helpers/catalog-cart.php',
    'helpers/inventory-orders.php',
    'helpers/media.php',
    'helpers/product-cards.php',
    'helpers/admin.php',
    'helpers/inquiries-ledger.php',
    'helpers/payments.php',
    'helpers/site-settings.php',
    'helpers/persistence.php',
    'helpers/email-tax-ui.php',
];

foreach ($helperFiles as $helperFile) {
    require_once __DIR__ . '/' . $helperFile;
}

require_once __DIR__ . '/SiteContext.php';
