<?php

require_once __DIR__ . '/email-templates/index.php';
require_once __DIR__ . '/domain/OrderLifecycle.php';
require_once __DIR__ . '/domain/OnlinePaymentMethod.php';
require_once __DIR__ . '/domain/CheckoutInput.php';
require_once __DIR__ . '/presentation/CommercePresenter.php';
require_once __DIR__ . '/security/ExternalUrlPolicy.php';
require_once __DIR__ . '/security/UploadPolicy.php';
require_once __DIR__ . '/integrations/HttpClientPolicy.php';
require_once __DIR__ . '/integrations/JsonHttpClient.php';
require_once __DIR__ . '/services/SiteSettingsService.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/ProductReadService.php';
require_once __DIR__ . '/services/CustomerReadService.php';
require_once __DIR__ . '/services/CartService.php';
require_once __DIR__ . '/services/InventoryService.php';
require_once __DIR__ . '/services/OrderItemSnapshotService.php';
require_once __DIR__ . '/services/CheckoutPricingService.php';
require_once __DIR__ . '/services/OrderPersistenceService.php';
require_once __DIR__ . '/services/CouponService.php';
require_once __DIR__ . '/services/OrderReadService.php';
require_once __DIR__ . '/services/CustomerAuthenticationService.php';
require_once __DIR__ . '/services/CustomerSessionMergeService.php';
require_once __DIR__ . '/services/CustomerAddressService.php';
require_once __DIR__ . '/services/CustomerAccountService.php';
require_once __DIR__ . '/services/CheckoutReadService.php';
require_once __DIR__ . '/services/CategoryAdminService.php';
require_once __DIR__ . '/services/OrderAccessService.php';
require_once __DIR__ . '/services/DeliveryEstimateService.php';
require_once __DIR__ . '/services/ProductAdminService.php';
require_once __DIR__ . '/services/ProductImportService.php';
require_once __DIR__ . '/services/ProductVariantService.php';
require_once __DIR__ . '/services/EmailService.php';
require_once __DIR__ . '/services/CronService.php';
require_once __DIR__ . '/services/AdminOtpService.php';
require_once __DIR__ . '/services/OutboxService.php';

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

require_once __DIR__ . '/presentation/SiteContext.php';
