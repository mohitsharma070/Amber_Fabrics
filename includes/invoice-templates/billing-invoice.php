<?php
/** @var array $invoice */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo e((string) ($invoice['order_number'] ?? '')); ?> | Amber Fabrics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1">Billing Invoice</h1>
            <div class="small text-muted">Invoice #: <?php echo e((string) ($invoice['order_number'] ?? '')); ?></div>
            <div class="small text-muted">Date: <?php echo e(date('d M Y, h:i A', strtotime((string) ($invoice['invoice_date'] ?? 'now')))); ?></div>
        </div>
        <div class="no-print">
            <button class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h6 class="mb-2">Bill To</h6>
            <div class="small"><?php echo e((string) ($invoice['customer_name'] ?? '')); ?></div>
            <?php if (!empty($invoice['customer_email'])): ?><div class="small text-muted"><?php echo e((string) $invoice['customer_email']); ?></div><?php endif; ?>
            <?php if (!empty($invoice['customer_phone'])): ?><div class="small text-muted"><?php echo e((string) $invoice['customer_phone']); ?></div><?php endif; ?>
            <?php foreach ((array) ($invoice['billing_address_lines'] ?? []) as $line): ?>
                <div class="small text-muted"><?php echo e((string) $line); ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ((array) ($invoice['items'] ?? []) as $item): ?>
                            <tr>
                                <td>
                                    <div><?php echo e((string) ($item['name'] ?? 'Product')); ?></div>
                                    <?php if (!empty($item['sku'])): ?><div class="small text-muted">SKU: <?php echo e((string) $item['sku']); ?></div><?php endif; ?>
                                </td>
                                <td><?php echo e(format_quantity_by_unit((float) ($item['quantity'] ?? 0), (string) ($item['unit_type'] ?? 'meter'))); ?><?php echo quantity_unit_suffix((string) ($item['unit_type'] ?? 'meter')); ?></td>
                                <td class="text-end"><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($item['unit_price'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($item['line_total'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="ms-auto" style="max-width: 320px;">
                <div class="d-flex justify-content-between small"><span>Subtotal</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['subtotal'] ?? 0), 2); ?></span></div>
                <div class="d-flex justify-content-between small"><span>Shipping</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['shipping'] ?? 0), 2); ?></span></div>
                <div class="d-flex justify-content-between small"><span>Discount</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['discount'] ?? 0), 2); ?></span></div>
                <?php if (!empty($invoice['gst']['enabled'])): ?>
                <div class="d-flex justify-content-between small"><span>GST @<?php echo number_format((float) ($invoice['gst']['rate'] ?? 0), 0); ?>% (included)</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['gst']['gst_amount'] ?? 0), 2); ?></span></div>
                <div class="d-flex justify-content-between small"><span>CGST</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['gst']['cgst_amount'] ?? 0), 2); ?></span></div>
                <div class="d-flex justify-content-between small"><span>SGST</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['gst']['sgst_amount'] ?? 0), 2); ?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between fw-bold mt-2 pt-2 border-top"><span>Total</span><span><?php echo e((string) ($invoice['symbol'] ?? 'Rs ')); ?><?php echo number_format((float) ($invoice['total'] ?? 0), 2); ?> <?php echo e((string) ($invoice['currency'] ?? 'INR')); ?></span></div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
