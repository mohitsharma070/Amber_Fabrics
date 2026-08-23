<?php
/**
 * Admin Packing Slip — standalone printable page (no prices).
 * URL: /admin/packing-slip.php?order=VT...
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_admin();

$orderNumber = trim((string) ($_GET['order'] ?? ''));

if ($orderNumber === '') {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

// ── Fetch order ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT o.id, o.customer_id, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
            o.address, o.city, o.state, o.pincode, o.country,
            o.order_status, o.payment_method, o.payment_status, o.created_at
     FROM orders o
     WHERE o.order_number = ?
     LIMIT 1"
);
$stmt->bind_param('s', $orderNumber);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

// ── Fetch items (no pricing) ─────────────────────────────────────────────────
$itemStmt = $conn->prepare(
    "SELECT oi.fabric_name_snapshot, oi.fabric_sku_snapshot, oi.size, oi.color,
            oi.unit_type, oi.quantity, oi.quantity_meters
     FROM order_items oi
     WHERE oi.order_id = ?
     ORDER BY oi.id ASC"
);
$orderId = (int) $order['id'];
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch shipment ───────────────────────────────────────────────────────────
$shipStmt = $conn->prepare(
    "SELECT courier_name,
            COALESCE(NULLIF(tracking_id, ''), NULLIF(awb_code, ''), '') AS tracking_id
     FROM shipments
     WHERE order_id = ?
     LIMIT 1"
);
$shipStmt->bind_param('i', $orderId);
$shipStmt->execute();
$shipment = $shipStmt->get_result()->fetch_assoc() ?: [];

// ── Site settings ────────────────────────────────────────────────────────────
$siteSettings = SiteSettingsService::get();
$siteName     = SiteContext::name();
$siteAddress  = (string) ($siteSettings['company_address'] ?? '');
$sitePhone    = (string) ($siteSettings['company_phone']   ?? '');
$gstin        = (string) ($siteSettings['gst_number']      ?? '');
$companyState        = (string) ($siteSettings['company_state']          ?? '');
$unboxingNotice      = (string) ($siteSettings['packing_unboxing_notice']   ?? '');
$codNotice           = (string) ($siteSettings['packing_cod_notice']          ?? '');
$packingFooterNote   = (string) ($siteSettings['packing_footer_note']         ?? '');
$repeatBadgeLabel    = (string) ($siteSettings['packing_repeat_badge_label']  ?? '');
$repeatMinOrders     = max(1, (int) ($siteSettings['packing_repeat_min_orders'] ?? 1));

// ── Item / SKU counts ────────────────────────────────────────────────────────
$totalSkus = count($items);
$totalQty  = 0;
foreach ($items as $item) {
    $qty = ((float) ($item['quantity'] ?? 0)) > 0
        ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0);
    $totalQty += (int) ceil($qty);
}

// ── Repeat customer check ────────────────────────────────────────────────────
$isRepeatCustomer = false;
if (!empty($order['customer_id'])) {
    $cid     = (int) $order['customer_id'];
    $repStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND id != ?");
    $repStmt->bind_param('ii', $cid, $orderId);
    $repStmt->execute();
    $isRepeatCustomer = (int) $repStmt->get_result()->fetch_row()[0] >= $repeatMinOrders;
}
$isCod = strtolower($order['payment_method']) === 'cod';

// ── Barcode generator ────────────────────────────────────────────────────────
$barcodeGen = new Picqer\Barcode\BarcodeGeneratorSVG();
$awbBarcodeSvg = !empty($shipment['tracking_id'])
    ? $barcodeGen->getBarcode($shipment['tracking_id'], $barcodeGen::TYPE_CODE_128, 2, 70)
    : '';
$orderBarcodeSvg = $barcodeGen->getBarcode($order['order_number'], $barcodeGen::TYPE_CODE_128, 1.5, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Packing Slip <?php echo e($order['order_number']); ?></title>
<link rel="stylesheet" href="<?php echo e(ui_asset('/css/foundation.css')); ?>">
<link rel="stylesheet" href="<?php echo e(ui_asset('/css/documents.css')); ?>">
<script defer src="<?php echo e(ui_asset('/js/app.js')); ?>"></script>
<script defer src="<?php echo e(ui_asset('/js/documents.js')); ?>"></script>
</head>
<body data-ui-area="document" data-ui-page="packing-slip">

<div class="no-print print-bar document-toolbar">
    <a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="ui-button ui-button--secondary">&larr; Back to Order</a>
    <button class="ui-button ui-button--primary" type="button" data-document-print>Print packing slip</button>
</div>

<div class="slip-wrapper" data-document-sheet>

    <!-- To: + AWB -->
    <div class="slip-to-section">
        <div class="slip-to-left">
            <div class="slip-to-label">To:</div>
            <div class="slip-to-name"><?php echo e($order['customer_name']); ?></div>
            <div class="slip-to-address">
                <?php if (!empty($order['address'])): ?><?php echo e($order['address']); ?><br><?php endif; ?>
                <?php
                    $cityState = trim(
                        e($order['city'] ?? '') .
                        (!empty($order['state']) ? ', ' . e($order['state']) : '')
                    );
                    if ($cityState !== ''): ?>
                    <?php echo $cityState; ?><br>
                <?php endif; ?>
                <?php if (!empty($order['pincode'])): ?><span class="slip-to-pincode"><?php echo e($order['pincode']); ?></span><br><?php endif; ?>
                <?php if (!empty($order['customer_phone'])): ?>Ph: <?php echo e($order['customer_phone']); ?><?php endif; ?>
            </div>
        </div>
        <?php if (!empty($shipment['tracking_id'])): ?>
        <div class="slip-to-right">
            <div class="slip-awb-label">AWB: <?php echo e($shipment['tracking_id']); ?></div>
            <div class="slip-awb-barcode">
                <?php echo $awbBarcodeSvg; ?>
            </div>
            <?php if (!empty($shipment['courier_name'])): ?>
            <div class="slip-awb-routing">Routing Code: <?php echo e($shipment['courier_name']); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Repeat Customer badge -->
    <?php if ($isRepeatCustomer && $repeatBadgeLabel !== ''): ?>
    <div class="slip-repeat-badge">&#9733; <?php echo e($repeatBadgeLabel); ?> &#9733;</div>
    <?php endif; ?>

    <!-- Payment type + Courier -->
    <div class="slip-payment-bar">
        <span><?php echo $isCod ? '&#9888; CASH ON DELIVERY (COD)' : '&#10003; PREPAID'; ?></span>
        <span><?php echo !empty($shipment['courier_name']) ? e($shipment['courier_name']) : ''; ?></span>
    </div>

    <!-- Unboxing notice -->
    <?php if ($unboxingNotice !== ''): ?>
    <div class="slip-unboxing-notice">
        <strong>Important:</strong> <?php echo nl2br(e($unboxingNotice)); ?>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="slip-stats">
        <span>Number of SKUs: <?php echo $totalSkus; ?></span>
        <span>Total Quantity: <?php echo $totalQty; ?></span>
        <span>Order Id: <?php echo e($order['order_number']); ?></span>
    </div>

    <!-- Body: From + Items -->
    <div class="slip-body">

        <!-- From -->
        <div class="slip-from-row">
            <div class="slip-from">
                <div class="slip-from-label">From:</div>
                <div class="slip-from-name"><?php echo e($siteName); ?></div>
                <?php if ($siteAddress !== ''): ?><?php echo nl2br(e($siteAddress)); ?><br><?php endif; ?>
                <?php if ($sitePhone !== ''): ?>Ph: <?php echo e($sitePhone); ?><br><?php endif; ?>
                <?php if ($gstin !== ''): ?>GST: <?php echo e($gstin); ?><?php endif; ?>
            </div>
            <div class="slip-order-barcode">
                <?php echo $orderBarcodeSvg; ?>
            </div>
        </div>

        <!-- Items Table -->
        <table class="slip-table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th >Product Code</th>
                    <th >SKU ID</th>
                    <th class="document-center">Qty</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
            <?php
                $unitType = in_array((string) ($item['unit_type'] ?? ''), ['meter','piece','set'], true)
                    ? (string) $item['unit_type'] : 'meter';
                $qty      = ((float) ($item['quantity'] ?? 0)) > 0
                    ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0);
                $attrs    = array_filter([trim($item['size'] ?? ''), trim($item['color'] ?? '')]);
                $sku      = e($item['fabric_sku_snapshot'] ?? '-');
            ?>
            <tr>
                <td>
                    <strong><?php echo e($item['fabric_name_snapshot']); ?></strong>
                    <?php if ($attrs): ?>
                        <br><span class="document-muted"><?php echo e(implode(' / ', $attrs)); ?></span>
                    <?php endif; ?>
                </td>
                <td class="document-muted"><?php echo $sku; ?></td>
                <td class="document-muted"><?php echo $sku; ?></td>
                <td><?php echo e(format_quantity_by_unit($qty, $unitType)); ?><?php echo e(CommercePresenter::quantityUnitSuffix($unitType)); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="document-number">
                        Total SKUs: <?php echo $totalSkus; ?> &nbsp;|&nbsp; Total Quantity:
                    </td>
                    <td class="document-center"><?php echo $totalQty; ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Packed by + timestamp -->
        <div class="slip-footer-meta">
            <div>
                <div class="slip-packed-by-label">Packed By</div>
                <div class="slip-packed-by-line"></div>
            </div>
            <div class="slip-print-time">
                Printed: <?php echo date('d M Y, h:i A'); ?><br>
                Order Status: <strong><?php echo e(ucfirst($order['order_status'])); ?></strong>
            </div>
        </div>

    </div><!-- /.slip-body -->

    <!-- Footer note -->
    <?php if ($packingFooterNote !== ''): ?>
    <div class="slip-footer-note">
        <strong>NOTE:</strong> <?php echo nl2br(e($packingFooterNote)); ?>
        <?php if ($companyState !== ''): ?>
        All disputes are subject to <?php echo e(ucwords(strtolower($companyState))); ?> jurisdiction only.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Powered By -->
    <div class="slip-powered-by">Powered By: <?php echo e($siteName); ?></div>

</div><!-- /.slip-wrapper -->

</body>
</html>
