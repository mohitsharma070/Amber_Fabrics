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
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1a1a1a; background: #f5f5f5; }

/* ── Wrapper ── */
.slip-wrapper { max-width: 680px; margin: 30px auto; background: #fff; border: 1px solid #bbb; }

/* ── To section ── */
.slip-to-section {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 16px 20px; border-bottom: 1px solid #ccc; gap: 20px;
}
.slip-to-left { flex: 1; }
.slip-to-label { font-size: 12px; color: #555; margin-bottom: 2px; }
.slip-to-name { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.slip-to-address { font-size: 12px; color: #333; line-height: 1.8; }
.slip-to-pincode { font-size: 14px; font-weight: 700; }
.slip-to-right { text-align: right; min-width: 200px; }
.slip-awb-label { font-size: 11px; color: #555; margin-bottom: 2px; font-weight: 600; }
.slip-awb-barcode svg { display: block; max-width: 100%; height: 70px; }
.slip-awb-routing { font-size: 11px; color: #444; margin-top: 3px; }

/* ── Repeat customer badge ── */
.slip-repeat-badge {
    text-align: center; font-size: 13px; font-weight: 700; letter-spacing: 1px;
    padding: 7px 20px; border-bottom: 1px solid #ccc; border-top: 1px solid #ccc;
    background: #fff;
}

/* ── Payment bar ── */
.slip-payment-bar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 20px; border-bottom: 1px solid #ccc;
    background: #f0f0f0; font-size: 13px; font-weight: 700; text-transform: uppercase;
}

/* ── COD notice ── */
.slip-cod-notice {
    padding: 8px 20px; font-size: 12px; font-weight: 600;
    color: #7c4a00; background: #fff3cd; border-bottom: 1px solid #ffc107;
}

/* ── Unboxing notice ── */
.slip-unboxing-notice {
    padding: 10px 20px; font-size: 11px; color: #333;
    background: #fffbf0; border-bottom: 1px solid #e8d870;
    line-height: 1.5;
}

/* ── Stats row ── */
.slip-stats {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 20px; font-size: 12px; font-weight: 600;
    border-bottom: 2px solid #1a1a1a; background: #fafafa;
}

/* ── Body ── */
.slip-body { padding: 16px 20px; }

/* ── From section ── */
.slip-from-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
.slip-from { font-size: 12px; color: #333; line-height: 1.7; flex: 1; }
.slip-from-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
.slip-from-name { font-weight: 700; font-size: 13px; }

/* ── Items table ── */
.slip-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 12px; }
.slip-table thead tr { background: #1a1a1a; color: #fff; }
.slip-table thead th { padding: 9px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.slip-table thead th:last-child { text-align: center; }
.slip-table tbody tr { border-bottom: 1px solid #eee; }
.slip-table tbody tr:last-child { border-bottom: 2px solid #ccc; }
.slip-table tbody td { padding: 9px 10px; vertical-align: top; }
.slip-table tbody td:last-child { text-align: center; }
.slip-table tfoot td { padding: 8px 10px; font-weight: 600; background: #f5f5f5; font-size: 12px; }

/* ── Packed by ── */
.slip-footer-meta {
    display: flex; justify-content: space-between; align-items: flex-end;
    margin-top: 16px; padding-top: 12px; border-top: 1px solid #ddd;
}
.slip-packed-by-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #aaa; margin-bottom: 4px; }
.slip-packed-by-line { border-top: 1px solid #888; width: 180px; margin-top: 28px; }
.slip-print-time { font-size: 11px; color: #888; text-align: right; line-height: 1.7; }

/* ── Footer note & powered by ── */
.slip-footer-note {
    padding: 10px 20px; font-size: 11px; color: #555;
    border-top: 1px solid #ccc; background: #f9f9f9; line-height: 1.6;
}
.slip-powered-by {
    text-align: center; font-size: 11px; color: #aaa;
    padding: 7px; border-top: 1px solid #eee;
}

/* ── Print bar ── */
.print-bar { text-align: center; margin-bottom: 20px; display: flex; gap: 10px; justify-content: center; }
.btn-print { padding: 9px 24px; background: #1a1a1a; color: #fff; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; font-family: inherit; }
.btn-print:hover { background: #333; }
.btn-back { padding: 9px 18px; background: transparent; color: #555; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; text-decoration: none; display: inline-block; }
.btn-back:hover { background: #f5f5f5; }

/* ── Print ── */
@media print {
    @page { size: A4; margin: 10mm; }
    body { background: #fff; }
    .no-print { display: none !important; }
    .slip-wrapper { max-width: 100%; margin: 0; border: 1px solid #aaa; }
    .slip-table thead tr,
    .slip-payment-bar,
    .slip-repeat-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .slip-awb-barcode svg,
    .slip-order-barcode svg { display: block; }
}
</style>
</head>
<body>

<div class="no-print print-bar">
    <a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="btn-back">&larr; Back to Order</a>
    <button class="btn-print" id="btn-print-slip">&#128438; Print Packing Slip</button>
</div>

<div class="slip-wrapper">

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
            <div class="slip-order-barcode" style="text-align:right; flex-shrink:0;">
                <?php echo $orderBarcodeSvg; ?>
            </div>
        </div>

        <!-- Items Table -->
        <table class="slip-table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th style="width:115px">Product Code</th>
                    <th style="width:115px">SKU ID</th>
                    <th style="width:48px; text-align:center">Qty</th>
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
                        <br><span style="color:#888;font-size:11px"><?php echo e(implode(' / ', $attrs)); ?></span>
                    <?php endif; ?>
                </td>
                <td style="color:#555"><?php echo $sku; ?></td>
                <td style="color:#555"><?php echo $sku; ?></td>
                <td><?php echo e(format_quantity_by_unit($qty, $unitType)); ?><?php echo e(InventoryService::quantity_unit_suffix($unitType)); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">
                        Total SKUs: <?php echo $totalSkus; ?> &nbsp;|&nbsp; Total Quantity:
                    </td>
                    <td style="text-align:center;"><?php echo $totalQty; ?></td>
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

<script nonce="<?php echo e($cspNonce); ?>">
document.getElementById('btn-print-slip').addEventListener('click', function () {
    window.print();
});
</script>
</body>
</html>
