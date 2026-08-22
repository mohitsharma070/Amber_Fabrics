<?php
/**
 * Admin Tax Invoice — standalone printable page.
 * URL: /admin/invoice.php?order=VT...
 */
require_once __DIR__ . '/../includes/init.php';
require_admin();

$orderNumber = trim((string) ($_GET['order'] ?? ''));

if ($orderNumber === '') {
    flash('error', 'Order not found.');
    redirect('orders.php');
}

// ── Fetch order (admin sees all orders) ─────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
            o.address, o.city, o.state, o.pincode, o.country, o.currency,
            o.subtotal, o.shipping_amount, o.discount_amount, o.total_amount,
            o.payment_method, o.payment_status, o.order_status, o.created_at
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

if (strtolower((string) ($order['payment_status'] ?? 'pending')) !== 'paid') {
    flash('error', 'Invoice is available only after payment is marked paid.');
    redirect('order-view.php?id=' . (int) ($order['id'] ?? 0));
}

// ── Fetch items ──────────────────────────────────────────────────────────────
$supportsTaxSnapshot = order_items_supports_tax_snapshot($conn);
$itemSql = "SELECT oi.fabric_name_snapshot, oi.fabric_sku_snapshot, oi.size, oi.color,
                   oi.unit_type, oi.quantity, oi.quantity_meters, oi.price, oi.price_per_meter, oi.total, oi.line_total,
                   oi.bundle_quantity, oi.meter_length";
if ($supportsTaxSnapshot) {
    $itemSql .= ", oi.taxable_amount, oi.discount_amount, oi.gst_rate_snapshot, oi.gst_amount, oi.cgst_amount, oi.sgst_amount, oi.igst_amount, oi.tax_type, oi.hsn_code_snapshot";
}
$itemSql .= " FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id ASC";
$itemStmt = $conn->prepare($itemSql);
$orderId = (int) $order['id'];
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch shipment (courier + AWB) ────────────────────────────────────────────
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

// ── Financials ───────────────────────────────────────────────────────────────
$symbol        = ($order['currency'] === 'USD') ? '$' : '₹';
$subtotal      = (float) ($order['subtotal']        ?? 0);
$shippingCost  = (float) ($order['shipping_amount'] ?? 0);
$discount      = (float) ($order['discount_amount'] ?? 0);
$total         = (float) ($order['total_amount']    ?? 0);
$currency      = (string) ($order['currency'] ?? 'INR');
$taxableAmount = max(0.0, $subtotal - $discount);
$gst           = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));

// ── Site settings ────────────────────────────────────────────────────────────
$siteSettings  = SiteSettingsService::get();
$siteName      = site_name();
$siteAddress   = (string) ($siteSettings['company_address'] ?? '');
$sitePhone     = (string) ($siteSettings['company_phone']   ?? '');
$gstin         = (string) ($siteSettings['gst_number']      ?? '');
$panNumber     = (string) ($siteSettings['pan_number']      ?? '');
$hsnCode       = (string) ($siteSettings['hsn_code']        ?? '5208');
$contactEmail  = contact_email();
$companyState  = strtolower(trim((string) ($siteSettings['company_state'] ?? '')));
$gstRate       = (float)  ($siteSettings['gst_rate']        ?? 18);

// ── Buyer state (flat columns for admin) ──────────────────────────────────────
$buyerState    = strtolower(trim((string) ($order['state'] ?? '')));
$isIndia       = strcasecmp(trim((string) ($order['country'] ?? '')), 'india') === 0;

// ── Tax type: IGST (inter-state) vs CGST+SGST (intra-state) vs none ─────────
// IGST      = seller state ≠ buyer state (both India, both known)
// CGST+SGST = same state, OR either state unknown but India order
// None      = international order, or zero-rate
if (!$isIndia || $gstRate <= 0) {
    $taxType = 'none';
} elseif ($companyState !== '' && $buyerState !== '' && $companyState !== $buyerState) {
    $taxType = 'igst';
} else {
    $taxType = 'cgst_sgst';
}
$gstTotal = !empty($gst['enabled']) ? (float) $gst['gst_amount'] : 0.0;
if ($supportsTaxSnapshot && !empty($items)) {
    $firstTaxType = (string) ($items[0]['tax_type'] ?? '');
    if (in_array($firstTaxType, ['none', 'cgst_sgst', 'igst'], true)) {
        $taxType = $firstTaxType;
    }
    $firstRate = (float) ($items[0]['gst_rate_snapshot'] ?? 0.0);
    if ($firstRate >= 0) {
        $gstRate = $firstRate;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?php echo e($order['order_number']); ?> | <?php echo e($siteName); ?> Admin</title>
<link rel="stylesheet" href="../css/foundation.css?v=<?php echo e(asset_version('foundation.css')); ?>">
<link rel="stylesheet" href="../css/documents.css?v=<?php echo e(asset_version('documents.css')); ?>">
<script defer src="../js/app.js?v=<?php echo e(asset_version('app.js')); ?>"></script>
<script defer src="../js/documents.js?v=<?php echo e(asset_version('documents.js')); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
</head>
<body data-ui-area="document" data-ui-page="invoice">

<div class="no-print print-bar document-toolbar">
    <a href="order-view.php?id=<?php echo (int) $order['id']; ?>" class="ui-button ui-button--secondary">&larr; Back to Order</a>
    <button class="ui-button ui-button--secondary" type="button" data-document-print>Print</button>
    <button class="ui-button ui-button--primary" type="button" data-document-pdf="invoice-document" data-document-filename="Invoice-<?php echo e($order['order_number']); ?>.pdf">Download PDF</button>
</div>

<div class="invoice-wrapper" id="invoice-document" data-document-sheet>

    <!-- Header -->
    <div class="inv-header">
        <div class="inv-brand">
            <div class="inv-brand-name"><?php echo e($siteName); ?></div>
            <?php if ($siteAddress !== ''): ?>
                <div class="inv-brand-sub"><?php echo nl2br(e($siteAddress)); ?></div>
            <?php endif; ?>
            <?php if ($sitePhone !== ''): ?>
                <div class="inv-brand-sub">&#9742; <?php echo e($sitePhone); ?></div>
            <?php endif; ?>
            <?php if ($contactEmail !== ''): ?>
                <div class="inv-brand-sub"><?php echo e($contactEmail); ?></div>
            <?php endif; ?>
            <?php if ($gstin !== ''): ?>
                <div class="inv-brand-sub"><strong>GSTIN:</strong> <?php echo e($gstin); ?></div>
            <?php endif; ?>
        </div>
        <div class="inv-title-block">
            <div class="inv-title">Tax Invoice</div>
            <div class="inv-subtitle">(Original for Recipient)</div>
            <div class="inv-meta">
                <div>Invoice No: <?php echo e($order['order_number']); ?></div>
                <div>Invoice Date: <?php echo date('d-m-Y', strtotime($order['created_at'])); ?></div>
            </div>
        </div>
    </div>

    <!-- Addresses -->
    <div class="inv-addresses">
        <div class="inv-addr-box">
            <div class="inv-addr-label">Sold By</div>
            <div class="inv-addr-detail">
                Company Name: <strong><?php echo e($siteName); ?></strong><br>
                <?php if ($siteAddress !== ''): ?>Seller Address: <?php echo nl2br(e($siteAddress)); ?><br><?php endif; ?>
                <?php if ($sitePhone !== ''): ?>Ph No: <?php echo e($sitePhone); ?><br><?php endif; ?>
                <?php if ($contactEmail !== ''): ?><?php echo e($contactEmail); ?><br><?php endif; ?>
                <?php if ($gstin !== ''): ?>GSTIN: <?php echo e($gstin); ?><br><?php endif; ?>
                <?php if ($panNumber !== ''): ?>PAN: <?php echo e($panNumber); ?><br><?php endif; ?>
                <?php if (!empty($shipment['courier_name'])): ?>Delivery Partner: <?php echo e($shipment['courier_name']); ?><br><?php endif; ?>
                <?php if (!empty($shipment['tracking_id'])): ?>AWB: <?php echo e($shipment['tracking_id']); ?><?php endif; ?>
            </div>
        </div>
        <div class="inv-addr-box">
            <div class="inv-addr-label">Bill To</div>
            <div class="inv-addr-detail">
                Customer Name: <strong><?php echo e($order['customer_name']); ?></strong><br>
                <?php
                    $addrFull = trim(
                        ($order['address'] ?? '') .
                        (!empty($order['city'])    ? ', ' . $order['city']    : '') .
                        (!empty($order['state'])   ? ', ' . $order['state']   : '') .
                        (!empty($order['pincode']) ? ' - ' . $order['pincode'] : '') .
                        (!empty($order['country']) ? ', ' . $order['country'] : '')
                    );
                    if ($addrFull !== ''): ?>
                    Customer Address: <?php echo e($addrFull); ?><br>
                <?php endif; ?>
                <?php if (!empty($order['customer_phone'])): ?>Mobile No: <?php echo e($order['customer_phone']); ?><br><?php endif; ?>
                <?php if ($buyerState !== ''): ?>Place of Supply: <?php echo e($order['state']); ?><br><?php endif; ?>
                Order Via: <?php echo e($siteName); ?>
            </div>
        </div>
    </div>

    <?php
    $taxableNet   = max(0.0, $subtotal - $discount);
    $gstInclTotal = ($taxType !== 'none' && $gstRate > 0) ? round($taxableNet * $gstRate / (100 + $gstRate), 2) : 0.0;
    $baseNet      = round($taxableNet - $gstInclTotal, 2);
    $cgstIncl     = round($gstInclTotal / 2, 2);
    $sgstIncl     = round($gstInclTotal - $cgstIncl, 2);
    ?>
    <!-- Items Table -->
    <table class="inv-table">
        <thead>
            <tr>
                <th>Sr.No</th>
                <th>Product</th>
                <th class="document-number">Unit Price</th>
                <th class="document-center">Qty</th>
                <th class="document-number">Discount</th>
                <th class="document-number">Amount</th>
                <th class="document-center">Taxes</th>
                <th class="document-number">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $tQty = 0; $tDiscount = 0.0; $tAmount = 0.0; $tTax = 0.0; $tTotal = 0.0;
        foreach ($items as $i => $item):
            $unitType  = in_array((string) ($item['unit_type'] ?? ''), ['meter','piece','set'], true)
                ? (string) $item['unit_type'] : 'meter';
            // For meter items use quantity_meters (total meters); for piece/set use quantity
            $qty       = ($unitType === 'meter')
                ? (float) ($item['quantity_meters'] ?? $item['quantity'] ?? 0)
                : ((float) ($item['quantity'] ?? 0) > 0 ? (float) $item['quantity'] : (float) ($item['quantity_meters'] ?? 0));
            $unitPrice = ((float) ($item['price']         ?? 0)) > 0
                ? (float) $item['price']         : (float) ($item['price_per_meter'] ?? 0);
            $lineTotal = ((float) ($item['total']         ?? 0)) > 0
                ? (float) $item['total']         : (float) ($item['line_total']      ?? 0);
            // Proportional item discount from order-level discount
            $itemDiscount = ($subtotal > 0 && $discount > 0) ? round(($lineTotal / $subtotal) * $discount, 2) : 0.0;
            // Cap item discount to line total
            $itemDiscount = min($itemDiscount, $lineTotal);
            $itemAmount   = max(0.0, round($lineTotal - $itemDiscount, 2));
            // Back-calculate GST included in price: gst = amount * rate / (100 + rate)
            $itemTax      = ($taxType !== 'none' && $gstRate > 0 && $itemAmount > 0) ? round($itemAmount * $gstRate / (100 + $gstRate), 2) : 0.0;
            $itemTotal    = $itemAmount; // price already includes tax — no addition
            $displayTaxType = $taxType;
            $displayGstRate = $gstRate;
            $displayCgst = round($itemTax / 2, 2);
            $displaySgst = round($itemTax - $displayCgst, 2);
            $displayIgst = $itemTax;
            if ($supportsTaxSnapshot) {
                $itemDiscount = (float) ($item['discount_amount'] ?? $itemDiscount);
                $itemAmount = (float) ($item['taxable_amount'] ?? $itemAmount);
                $itemTax = (float) ($item['gst_amount'] ?? $itemTax);
                $itemTotal = $itemAmount;
                $displayTaxType = (string) ($item['tax_type'] ?? $displayTaxType);
                $displayGstRate = (float) ($item['gst_rate_snapshot'] ?? $displayGstRate);
                $displayCgst = (float) ($item['cgst_amount'] ?? $displayCgst);
                $displaySgst = (float) ($item['sgst_amount'] ?? $displaySgst);
                $displayIgst = (float) ($item['igst_amount'] ?? $displayIgst);
            }
            $tQty += $qty; $tDiscount += $itemDiscount; $tAmount += $itemAmount; $tTax += $itemTax; $tTotal += $itemTotal;
        ?>
        <tr>
            <td><?php echo $i + 1; ?></td>
            <td>
                <strong><?php echo e($item['fabric_name_snapshot']); ?></strong>
                <?php if (!empty($item['fabric_sku_snapshot'])): ?>
                    <br><span class="document-muted">SKU: <?php echo e($item['fabric_sku_snapshot']); ?></span>
                <?php endif; ?>
                <?php $attrs = array_filter([trim($item['size'] ?? ''), trim($item['color'] ?? '')]); ?>
                <?php if ($attrs): ?>
                    <br><span class="document-muted"><?php echo e(implode(' · ', $attrs)); ?></span>
                <?php endif; ?>
            </td>
            <td class="document-number"><?php echo e(money($unitPrice, $currency)); ?></td>
            <td class="document-center"><?php
                $bQtyDisp   = (int) ($item['bundle_quantity'] ?? 0);
                $bMeterDisp = (float) ($item['meter_length'] ?? 0);
                if ($unitType === 'meter' && $bQtyDisp > 0 && $bMeterDisp > 0) {
                    echo e($bQtyDisp . ' × ' . format_meter_quantity($bMeterDisp) . 'm');
                } else {
                    echo e(format_quantity_by_unit($qty, $unitType)) . e(CommercePresenter::quantityUnitSuffix($unitType));
                }
            ?></td>
            <td class="document-number"><?php echo $itemDiscount > 0 ? e(money($itemDiscount, $currency)) : '-'; ?></td>
            <td class="document-number"><?php echo e(money($itemAmount, $currency)); ?></td>
            <td class="document-center document-muted">
                <?php if ($displayTaxType === 'igst'): ?>
                    IGST@<?php echo number_format($displayGstRate, 1); ?>%=<?php echo e(money($displayIgst, $currency)); ?>
                <?php elseif ($displayTaxType === 'cgst_sgst'): ?>
                    CGST@<?php echo number_format($displayGstRate / 2, 1); ?>%=<?php echo e(money($displayCgst, $currency)); ?><br>
                    SGST@<?php echo number_format($displayGstRate / 2, 1); ?>%=<?php echo e(money($displaySgst, $currency)); ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td class="document-number"><?php echo e(money($itemTotal, $currency)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="document-total-row">
                <td colspan="7">Items Total</td>
                <td><?php echo e(money($subtotal, $currency)); ?></td>
            </tr>
            <?php if ($discount > 0): ?>
            <tr class="document-total-row">
                <td colspan="7">Discount</td>
                <td>- <?php echo e(money($discount, $currency)); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($taxType !== 'none' && $gstInclTotal > 0): ?>
            <tr class="document-total-row">
                <td colspan="7">Total Before Tax</td>
                <td><?php echo e(money($baseNet, $currency)); ?></td>
            </tr>
            <?php if ($taxType === 'igst'): ?>
            <tr class="document-total-row">
                <td colspan="7">IGST (<?php echo number_format($gstRate, 1); ?>%)</td>
                <td><?php echo e(money($gstInclTotal, $currency)); ?></td>
            </tr>
            <?php else: ?>
            <tr class="document-total-row">
                <td colspan="7">CGST (<?php echo number_format($gstRate / 2, 1); ?>%)</td>
                <td><?php echo e(money($cgstIncl, $currency)); ?></td>
            </tr>
            <tr class="document-total-row">
                <td colspan="7">SGST (<?php echo number_format($gstRate / 2, 1); ?>%)</td>
                <td><?php echo e(money($sgstIncl, $currency)); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="document-total-row">
                <td colspan="7">Total Tax</td>
                <td><?php echo e(money($gstInclTotal, $currency)); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($shippingCost > 0): ?>
            <tr class="document-total-row">
                <td colspan="7">Shipping</td>
                <td><?php echo e(money($shippingCost, $currency)); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="document-grand-total">
                <td colspan="7">Invoice Total</td>
                <td>
                    <?php echo e(money($total, $currency, true)); ?><br>
                    <small>Incl. GST <?php echo e(money($gstInclTotal, $currency)); ?></small>
                </td>
            </tr>
        </tfoot>
    </table>

    <!-- Payment info -->
    <div class="inv-payment">
        <div class="inv-payment-item">
            <span>Payment Method:</span>
            <span><?php echo e(ucwords(str_replace('_', ' ', $order['payment_method']))); ?></span>
        </div>
        <div class="inv-payment-item">
            <span>Payment Status:</span>
            <?php if ($order['payment_status'] === 'paid'): ?>
                <span class="badge-paid">&#10003; Paid</span>
            <?php else: ?>
                <span class="badge-pending"><?php echo e(ucfirst($order['payment_status'])); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="inv-footer">
        <span>This is a computer generated document and does not requires signature</span>
    </div>

</div><!-- /.invoice-wrapper -->

</body>
</html>

