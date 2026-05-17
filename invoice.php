<?php
/**
 * Customer Tax Invoice — standalone printable page.
 * URL: /invoice.php?order=VT...
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/customer-auth.php';

require_customer();

$customerId  = (int) $_SESSION['customer_id'];
$orderNumber = trim((string) ($_GET['order'] ?? ''));

if ($orderNumber === '') {
    flash('error', 'Order not found.');
    redirect('/customer/orders.php');
}

// ── Fetch order (customer must own it) ──────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.customer_email,
            o.address, o.city, o.state, o.pincode, o.country, o.currency,
            o.subtotal, o.shipping_amount, o.discount_amount, o.total_amount,
            o.payment_method, o.payment_status, o.order_status, o.created_at
     FROM orders o
     WHERE o.order_number = ? AND o.customer_id = ?
     LIMIT 1"
);
$stmt->bind_param('si', $orderNumber, $customerId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    flash('error', 'Order not found.');
    redirect('/customer/orders.php');
}

// Only show invoice for actionable/paid orders (COD always allowed — payment on delivery)
$validStatuses = ['confirmed', 'processing', 'packed', 'shipped', 'delivered'];
$orderStatus   = (string) ($order['order_status'] ?? '');
$isCodOrder    = ($order['payment_method'] === 'cod');
if (!$isCodOrder && $order['payment_status'] !== 'paid' && !in_array($orderStatus, $validStatuses, true)) {
    flash('error', 'Invoice is not available for this order yet.');
    redirect('/customer/order-view.php?id=' . (int) $order['id']);
}

// ── Fetch items ──────────────────────────────────────────────────────────────
$supportsTaxSnapshot = order_items_supports_tax_snapshot($conn);
$itemSql = "SELECT oi.fabric_name_snapshot, oi.fabric_sku_snapshot, oi.size, oi.color,
                   oi.unit_type, oi.quantity, oi.quantity_meters, oi.price, oi.price_per_meter, oi.total, oi.line_total,
                   oi.bundle_quantity, oi.meter_length, oi.pack_label, oi.units_per_set";
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
$shipStmt = $conn->prepare("SELECT courier_name, tracking_id FROM shipments WHERE order_id = ? LIMIT 1");
$shipStmt->bind_param('i', $orderId);
$shipStmt->execute();
$shipment = $shipStmt->get_result()->fetch_assoc() ?: [];

// ── Financials ───────────────────────────────────────────────────────────────
$symbol        = ($order['currency'] === 'USD') ? '$' : '₹';
$subtotal      = (float) ($order['subtotal']        ?? 0);
$shippingCost  = (float) ($order['shipping_amount'] ?? 0);
$discount      = (float) ($order['discount_amount'] ?? 0);
$total         = (float) ($order['total_amount']    ?? 0);
$taxableAmount = max(0.0, $subtotal - $discount);
$gst           = order_gst_breakdown($taxableAmount, (string) ($order['country'] ?? ''));

// ── Site settings ────────────────────────────────────────────────────────────
$siteSettings  = get_site_settings();
$siteName      = (string) ($siteSettings['site_name']       ?? 'Amber Fabrics');
$siteAddress   = (string) ($siteSettings['company_address'] ?? '');
$sitePhone     = (string) ($siteSettings['company_phone']   ?? '');
$gstin         = (string) ($siteSettings['gst_number']      ?? '');
$panNumber     = (string) ($siteSettings['pan_number']      ?? '');
$hsnCode       = (string) ($siteSettings['hsn_code']        ?? '5208');
$contactEmail  = (string) ($siteSettings['contact_email']   ?? '');
$companyState  = strtolower(trim((string) ($siteSettings['company_state'] ?? '')));
$gstRate       = (float)  ($siteSettings['gst_rate']        ?? 18);

// ── Shipping address ─────────────────────────────────────────────────────────
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
<title>Invoice <?php echo e($order['order_number']); ?> | <?php echo e($siteName); ?></title>
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: "Segoe UI", Tahoma, Arial, sans-serif;
    font-size: 13px;
    color: #1f2a44;
    background: #e9edf2;
}
a { color: inherit; text-decoration: none; }

/* ── Page wrapper ── */
.invoice-wrapper {
    max-width: 820px;
    margin: 30px auto;
    background: #f8fafc;
    border: 1px solid #b9c3d2;
    border-radius: 0;
    padding: 28px 20px 24px;
}

/* ── Header ── */
.inv-header {
    margin-bottom: 14px;
}
.inv-brand { display: none; }
.inv-brand-name { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
.inv-brand-sub { font-size: 11px; color: #666; }
.inv-title-block { text-align: center; width: 100%; }
.inv-title { font-size: 42px; font-weight: 700; color: #111c34; }
.inv-subtitle { font-size: 17px; font-weight: 600; margin-top: 2px; color: #111c34; }
.inv-meta {
    margin-top: 28px;
    font-size: 14px;
    color: #2a3652;
    line-height: 1.3;
    display: flex;
    justify-content: space-between;
    text-align: left;
}

/* ── Address grid ── */
.inv-addresses {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    border: 1px solid #b9c3d2;
    margin-bottom: 24px;
}
.inv-addr-box { border: 0; border-radius: 0; padding: 12px 14px; }
.inv-addr-box + .inv-addr-box { border-left: 1px solid #b9c3d2; }
.inv-addr-label {
    font-size: 18px; font-weight: 700; text-transform: none;
    letter-spacing: 0; color: #111c34; margin-bottom: 8px;
}
.inv-addr-name { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.inv-addr-detail { font-size: 13px; color: #2a3652; line-height: 1.35; }
.inv-addr-detail strong { color: #333; }

/* ── Items table ── */
.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 12px; border: 1px solid #808a98; }
.inv-table thead tr { background: #f8fafc; color: #1f2a44; }
.inv-table thead th { padding: 7px 8px; text-align: left; font-weight: 700; font-size: 11px; text-transform: none; letter-spacing: 0; border: 1px solid #808a98; }
.inv-table thead th:last-child { text-align: right; }
.inv-table tbody tr { border-bottom: 1px solid #808a98; }
.inv-table tbody tr:last-child { border-bottom: 1px solid #808a98; }
.inv-table tbody td { padding: 8px; vertical-align: top; border: 1px solid #808a98; }
.inv-table tbody td:last-child { text-align: right; white-space: nowrap; }
.inv-table tfoot td { padding: 7px 8px; border: 1px solid #808a98; font-size: 11px; }

/* ── Payment status ── */
.inv-payment { margin-top: 24px; display: flex; gap: 24px; font-size: 12px; }
.inv-payment-item span:first-child { color: #888; }
.inv-payment-item span:last-child { font-weight: 600; margin-left: 6px; }
.badge-paid { color: #2e7d32; }
.badge-pending { color: #c77800; }
.badge-cod { color: #555; }

/* ── Footer ── */
.inv-footer {
    margin-top: 18px; padding-top: 12px; border-top: 0;
    display: block; text-align: center;
    font-size: 14px; color: #2a3652;
}

/* ── Print button ── */
.no-print { }
.print-bar {
    text-align: center; margin-bottom: 20px;
    display: flex; gap: 10px; justify-content: center;
}
.btn-print {
    padding: 10px 28px; background: #1a1a1a; color: #fff;
    border: none; border-radius: 4px; font-size: 14px;
    cursor: pointer; font-family: inherit;
}
.btn-print:hover { background: #333; }
.btn-download {
    padding: 10px 28px; background: #1565c0; color: #fff;
    border: none; border-radius: 4px; font-size: 14px;
    cursor: pointer; font-family: inherit;
}
.btn-download:hover { background: #0d47a1; }
.btn-back {
    padding: 10px 20px; background: transparent; color: #555;
    border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
    cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block;
}
.btn-back:hover { background: #f5f5f5; }

/* ── Print styles ── */
@media print {
    @page { size: A4; margin: 15mm; }
    body { background: #fff; font-size: 12px; }
    .no-print { display: none !important; }
    .invoice-wrapper {
        max-width: 100%; margin: 0; border: none;
        border-radius: 0; padding: 0;
    }
    .inv-title { font-size: 20px; }
    .inv-subtitle { font-size: 13px; }
    .inv-meta { font-size: 12px; margin-top: 10px; }
    .inv-addr-label { font-size: 16px; }
    .inv-addr-detail { font-size: 12px; }
    .inv-table { font-size: 11px; }
    .inv-table thead th { font-size: 11px; }
    .inv-table tfoot td { font-size: 11px; }
    .inv-footer { font-size: 11px; }
    .inv-table thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-totals-row.total-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>
</head>
<body>

<div class="no-print print-bar">
    <a href="/customer/order-view.php?id=<?php echo (int) $order['id']; ?>" class="btn-back">&larr; Back to Order</a>
    <button class="btn-print" id="btn-print-invoice">&#128438; Print</button>
    <button class="btn-download" id="btn-download-invoice">&#11123; Download PDF</button>
</div>

<div class="invoice-wrapper">

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
                    $addrParts = array_filter([
                        $order['address'] ?? '',
                        $order['city']    ?? '',
                        trim(($order['state'] ?? '') . (!empty($order['pincode']) ? ' - ' . $order['pincode'] : '')),
                        $order['country'] ?? '',
                    ]);
                    $addrFull = implode(', ', $addrParts);
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
                <th style="width:30px">Sr.No</th>
                <th>Product</th>
                <th style="width:80px; text-align:right">Unit Price</th>
                <th style="width:50px; text-align:center">Qty</th>
                <th style="width:70px; text-align:right">Discount</th>
                <th style="width:90px; text-align:right">Amount</th>
                <th style="width:130px; text-align:center">Taxes</th>
                <th style="width:80px; text-align:right">Total</th>
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
                    <br><span style="color:#888;font-size:11px">SKU: <?php echo e($item['fabric_sku_snapshot']); ?></span>
                <?php endif; ?>
                <?php
                $attrs = array_filter([trim($item['size'] ?? ''), trim($item['color'] ?? '')]);
                if ($unitType === 'set') {
                    $ups = (int) ($item['units_per_set'] ?? 0);
                    if ($ups > 0) {
                        $attrs[] = ((int) round($qty)) . ' sets × ' . $ups . ' = ' . (((int) round($qty)) * $ups) . ' pieces';
                    }
                }
                ?>
                <?php if ($attrs): ?>
                    <br><span style="color:#888;font-size:11px"><?php echo e(implode(' · ', $attrs)); ?></span>
                <?php endif; ?>
            </td>
            <td style="text-align:right"><?php echo $symbol . number_format($unitPrice, 2); ?></td>
            <td style="text-align:center"><?php
                $bQtyDisp   = (int) ($item['bundle_quantity'] ?? 0);
                $bMeterDisp = (float) ($item['meter_length'] ?? 0);
                if ($unitType === 'meter' && $bQtyDisp > 0 && $bMeterDisp > 0) {
                    echo e($bQtyDisp . ' × ' . format_meter_quantity($bMeterDisp) . 'm');
                } else {
                    echo e(format_quantity_by_unit($qty, $unitType)) . e(quantity_unit_suffix($unitType));
                }
            ?></td>
            <td style="text-align:right"><?php echo $itemDiscount > 0 ? $symbol . number_format($itemDiscount, 2) : '-'; ?></td>
            <td style="text-align:right"><?php echo $symbol . number_format($itemAmount, 2); ?></td>
            <td style="text-align:center;font-size:11px">
                <?php if ($displayTaxType === 'igst'): ?>
                    IGST@<?php echo number_format($displayGstRate, 1); ?>%=<?php echo $symbol . number_format($displayIgst, 2); ?>
                <?php elseif ($displayTaxType === 'cgst_sgst'): ?>
                    CGST@<?php echo number_format($displayGstRate / 2, 1); ?>%=<?php echo $symbol . number_format($displayCgst, 2); ?><br>
                    SGST@<?php echo number_format($displayGstRate / 2, 1); ?>%=<?php echo $symbol . number_format($displaySgst, 2); ?>
                <?php else: ?>-<?php endif; ?>
            </td>
            <td style="text-align:right"><?php echo $symbol . number_format($itemTotal, 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f5f5f5; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px; border-top:2px solid #ccc;">Items Total</td>
                <td style="text-align:right; padding:7px 10px; border-top:2px solid #ccc; white-space:nowrap;"><?php echo $symbol . number_format($subtotal, 2); ?></td>
            </tr>
            <?php if ($discount > 0): ?>
            <tr style="background:#f5f5f5; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">Discount</td>
                <td style="text-align:right; padding:7px 10px; color:#c0392b; white-space:nowrap;">- <?php echo $symbol . number_format($discount, 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($taxType !== 'none' && $gstInclTotal > 0): ?>
            <tr style="background:#fafafa; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">Total Before Tax</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($baseNet, 2); ?></td>
            </tr>
            <?php if ($taxType === 'igst'): ?>
            <tr style="background:#fafafa; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">IGST (<?php echo number_format($gstRate, 1); ?>%)</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($gstInclTotal, 2); ?></td>
            </tr>
            <?php else: ?>
            <tr style="background:#fafafa; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">CGST (<?php echo number_format($gstRate / 2, 1); ?>%)</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($cgstIncl, 2); ?></td>
            </tr>
            <tr style="background:#fafafa; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">SGST (<?php echo number_format($gstRate / 2, 1); ?>%)</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($sgstIncl, 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background:#fafafa; font-size:12px; font-weight:600;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">Total Tax</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($gstInclTotal, 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($shippingCost > 0): ?>
            <tr style="background:#f5f5f5; font-size:12px;">
                <td colspan="7" style="text-align:right; padding:7px 10px;">Shipping</td>
                <td style="text-align:right; padding:7px 10px; white-space:nowrap;"><?php echo $symbol . number_format($shippingCost, 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background:#1a1a1a; color:#fff; font-weight:700; font-size:14px;">
                <td colspan="7" style="text-align:right; padding:12px 10px;">Invoice Total</td>
                <td style="text-align:right; padding:12px 10px; white-space:nowrap;">
                    <?php echo $symbol . number_format($total, 2); ?> <?php echo e($order['currency']); ?><br>
                    <small style="font-weight:400; opacity:0.75; font-size:11px;">Incl. GST <?php echo $symbol . number_format($gstInclTotal, 2); ?></small>
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
            <?php elseif ($order['payment_method'] === 'cod'): ?>
                <span class="badge-cod">Cash on Delivery</span>
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

<script nonce="<?php echo e($cspNonce); ?>">
var invoiceFileName = 'Invoice-<?php echo e($order['order_number']); ?>';
document.getElementById('btn-print-invoice').addEventListener('click', function () {
    window.print();
});
document.getElementById('btn-download-invoice').addEventListener('click', function () {
    var el = document.querySelector('.invoice-wrapper');
    var opt = {
        margin: 10,
        filename: invoiceFileName + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(el).save();
});
</script>
</body>
</html>

