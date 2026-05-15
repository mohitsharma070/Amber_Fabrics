<?php
require_once __DIR__ . '/includes/init.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('catalog.php');
}

$stmt = $conn->prepare("SELECT * FROM fabrics WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    header('HTTP/1.1 404 Not Found');
    $metaTitle = 'Product Not Found | Amber Fabrics';
    include 'includes/header.php';
    echo '<div class="container py-5 text-center"><a href="/catalog.php">&larr; Back to Shop</a></div>';
    include 'includes/footer.php';
    exit;
}

$regularPrice = (float) (($product['price'] !== null && $product['price'] !== '') ? $product['price'] : ($product['price_inr'] ?? 0));
$salePrice = (float) ($product['sale_price'] ?? 0);
$unitType = in_array((string) ($product['unit_type'] ?? ''), ['meter', 'piece', 'set'], true)
    ? (string) $product['unit_type']
    : 'meter';
$isWholeUnit = $unitType === 'piece' || $unitType === 'set';
// qty_step: use admin-configured value if > 0; else default per unit
$qtyStepDb = (float) ($product['qty_step'] ?? 0);
if ($qtyStepDb > 0) {
    $unitStep = rtrim(rtrim(number_format($qtyStepDb, 4), '0'), '.');
} else {
    $unitStep = $isWholeUnit ? '1' : '0.01';
}
$unitLabel = $unitType === 'piece' ? 'pieces' : ($unitType === 'set' ? 'sets' : 'meters');
$unitSingleLabel = $unitType === 'piece' ? 'piece' : ($unitType === 'set' ? 'set' : 'meter');
$displayStock = $isWholeUnit ? (float) ($product['stock'] ?? 0) : (float) ($product['stock_meters'] ?? 0);
$inStock = !empty($product['is_available']) && $displayStock > 0;
$galleryImages = array_values(array_filter([
    (string) ($product['image'] ?? ''),
    (string) ($product['image2'] ?? ''),
    (string) ($product['image3'] ?? ''),
    (string) ($product['image4'] ?? ''),
]));
$videoFile = (string) ($product['video'] ?? '');
$sizeOptions = [];
if (!empty($product['size'])) {
    $parts = preg_split('/[,\|\/]+/', (string) $product['size']);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $clean = trim((string) $part);
            if ($clean !== '') {
                $sizeOptions[] = $clean;
            }
        }
    }
    $sizeOptions = array_values(array_unique($sizeOptions));
}
$defaultSize = !empty($sizeOptions) ? (string) $sizeOptions[0] : '';

$meterOptions = [];
if ($unitType === 'meter' && !empty($product['meter_options'])) {
    $parts = preg_split('/[,\|]+/', (string) $product['meter_options']);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $val = trim((string) $part);
            if ($val !== '' && is_numeric($val) && (float) $val > 0) {
                $meterOptions[] = (float) $val;
            }
        }
    }
    $meterOptions = array_values(array_unique($meterOptions));
    sort($meterOptions);
}

$quantityOptions = [];
if ($unitType === 'meter') {
    // Meter quick options are fully controlled by admin via meter_options.
    $quantityOptions = $meterOptions;
    if (empty($quantityOptions)) {
        // Safe fallback when admin has not set options yet.
        $quantityOptions = [max(1.0, (float) ($product['min_order_meters'] ?? 1))];
    }
    if ($displayStock > 0) {
        $quantityOptions = array_values(array_filter($quantityOptions, static function ($q) use ($displayStock) {
            return (float) $q <= (float) $displayStock;
        }));
        if (empty($quantityOptions)) {
            $quantityOptions = [(float) $displayStock];
        }
    }
} else {
    $qtyStart = 1.0;
    $qtyStep = 1.0;
    $qtyLimit = $displayStock > 0 ? min($displayStock, 20.0) : 20.0;
    if ($qtyLimit < $qtyStart) {
        $qtyLimit = $qtyStart;
    }
    for ($q = $qtyStart; $q <= $qtyLimit + 0.0001; $q += $qtyStep) {
        $normalized = (float) round($q);
        if ($normalized > 0) {
            $quantityOptions[] = $normalized;
        }
    }
}
$quantityOptions = array_values(array_unique($quantityOptions));
sort($quantityOptions);

$metaTitle = e($product['name']) . ' | Amber Fabrics';
$metaDescription = !empty($product['description'])
    ? e(mb_strimwidth((string) $product['description'], 0, 155, '...'))
    : 'Product details from Amber Fabrics.';
$metaImage = !empty($product['image']) ? 'images/fabrics/' . e($product['image']) : '';
include 'includes/header.php';
do_action('product.view', [
    'conn' => $conn,
    'product_id' => (int) $product['id'],
    'customer_id' => (int) ($_SESSION['customer_id'] ?? 0),
]);
?>
<style nonce="<?php echo $cspNonce; ?>">
@media (max-width: 767.98px) {
    .product-media-main {
        height: 300px !important;
    }
    .product-media-thumb {
        width: 64px !important;
        height: 64px !important;
    }
}
</style>

<section class="page-hero py-4">
    <div class="container">
        <a href="catalog.php" class="text-white opacity-75 small">&larr; Back to Shop</a>
    </div>
</section>

<section class="section-block">
    <div class="container">
        <div class="row g-5">
            <div class="col-md-5">
                <?php if (!empty($galleryImages)): ?>
                    <div class="product-media-main mb-3 rounded shadow-sm overflow-hidden" style="height:420px;background:#f5f5f5;">
                        <img id="product-main-image"
                             src="images/fabrics/<?php echo e($galleryImages[0]); ?>"
                             alt="<?php echo e($product['name']); ?>"
                             class="w-100 h-100"
                             style="object-fit:cover;">
                        <?php if ($videoFile !== ''): ?>
                            <video id="product-main-video" class="w-100 h-100 d-none" style="object-fit:cover;" controls preload="metadata">
                                <source src="images/fabrics/<?php echo e($videoFile); ?>">
                            </video>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($galleryImages as $index => $img): ?>
                            <button type="button"
                                    class="btn p-0 border rounded media-thumb product-media-thumb <?php echo $index === 0 ? 'border-primary' : 'border-light'; ?>"
                                    data-type="image"
                                    data-src="images/fabrics/<?php echo e($img); ?>"
                                    style="width:76px;height:76px;overflow:hidden;">
                                <img src="images/fabrics/<?php echo e($img); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                            </button>
                        <?php endforeach; ?>
                        <?php if ($videoFile !== ''): ?>
                            <button type="button"
                                    class="btn p-0 border rounded media-thumb product-media-thumb border-light position-relative"
                                    data-type="video"
                                    data-src="images/fabrics/<?php echo e($videoFile); ?>"
                                    style="width:76px;height:76px;overflow:hidden;">
                                <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-dark text-white fw-semibold">Video</div>
                            </button>
                        <?php endif; ?>
                    </div>
                    <script nonce="<?php echo $cspNonce; ?>">
                    (function () {
                        var mainImage = document.getElementById('product-main-image');
                        var mainVideo = document.getElementById('product-main-video');
                        var thumbs = document.querySelectorAll('.media-thumb');
                        if (!mainImage || !thumbs.length) return;
                        thumbs.forEach(function (thumb) {
                            thumb.addEventListener('click', function () {
                                thumbs.forEach(function (t) {
                                    t.classList.remove('border-primary');
                                    t.classList.add('border-light');
                                });
                                thumb.classList.remove('border-light');
                                thumb.classList.add('border-primary');

                                var type = thumb.getAttribute('data-type');
                                var src = thumb.getAttribute('data-src') || '';
                                if (type === 'video' && mainVideo) {
                                    mainImage.classList.add('d-none');
                                    mainVideo.classList.remove('d-none');
                                    var source = mainVideo.querySelector('source');
                                    if (source && source.getAttribute('src') !== src) {
                                        source.setAttribute('src', src);
                                        mainVideo.load();
                                    }
                                } else {
                                    if (mainVideo) {
                                        mainVideo.pause();
                                        mainVideo.classList.add('d-none');
                                    }
                                    mainImage.classList.remove('d-none');
                                    mainImage.setAttribute('src', src);
                                }
                            });
                        });
                    })();
                    </script>
                <?php else: ?>
                    <div class="bg-light rounded d-flex align-items-center justify-content-center" style="min-height:320px;">
                        <span class="text-muted">No image</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-7">
                <h1 class="mb-1"><?php echo e($product['name']); ?></h1>
                <?php if (!empty($product['category'])): ?>
                    <p class="text-muted mb-2"><?php echo e($product['category']); ?></p>
                <?php endif; ?>

                <div class="mb-3">
                    <?php if ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice): ?>
                        <span class="fs-4 fw-bold text-primary">₹<?php echo number_format($salePrice, 2); ?> / <?php echo e($unitSingleLabel); ?></span>
                        <span class="ms-3 text-muted"><del>₹<?php echo number_format($regularPrice, 2); ?> / <?php echo e($unitSingleLabel); ?></del></span>
                    <?php elseif ($regularPrice > 0): ?>
                        <span class="fs-4 fw-bold text-primary">₹<?php echo number_format($regularPrice, 2); ?> / <?php echo e($unitSingleLabel); ?></span>
                    <?php else: ?>
                        <span class="text-muted">Price on request</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php if (!empty($product['color'])): ?>
                        <span class="badge-soft">Color: <?php echo e($product['color']); ?></span>
                    <?php endif; ?>
                    <span class="badge <?php echo $inStock ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $inStock ? 'Stock Status: In Stock (' . format_quantity_by_unit($displayStock, $unitType) . ' ' . e($unitLabel) . ')' : 'Stock Status: Out of Stock'; ?>
                    </span>
                </div>

                <?php if (!empty($sizeOptions)): ?>
                <div class="mb-3">
                    <h6 class="fw-semibold mb-2">Available Sizes</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($sizeOptions as $idx => $opt): ?>
                            <button type="button"
                                    class="btn btn-sm size-option-btn <?php echo $idx === 0 ? 'btn-dark' : 'btn-outline-dark'; ?>"
                                    data-size="<?php echo e($opt); ?>">
                                <?php echo e($opt); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($meterOptions)): ?>
                <div class="mb-3">
                    <h6 class="fw-semibold mb-2">Select Meters</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($meterOptions as $idx => $mval): ?>
                            <button type="button"
                                    class="btn btn-sm meter-option-btn <?php echo $idx === 0 ? 'btn-primary' : 'btn-outline-primary'; ?>"
                                    data-meters="<?php echo e($mval); ?>">
                                <?php echo e($mval); ?>m
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card p-3 mb-3" style="max-width:420px;">
                    <label class="form-label fw-semibold">
                        <?php echo $unitType === 'meter' ? 'Quantity (pieces)' : 'Quantity (' . e($unitLabel) . ')'; ?>
                    </label>
                    <form method="POST" action="/add-to-cart.php">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                        <?php if ($unitType === 'meter'): ?>
                            <?php $defaultMeterLength = !empty($meterOptions) ? (float) $meterOptions[0] : max(1.0, (float) ($product['min_order_meters'] ?? 1)); ?>
                            <input type="hidden" name="meter_length" id="selected_meter_length" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>">
                            <input type="hidden" name="quantity" id="meter_total_quantity" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength, 2), '0'), '.')); ?>">
                        <?php endif; ?>
                        <?php if (!empty($sizeOptions)): ?>
                            <input type="hidden" name="selected_size" id="selected_size_add" value="<?php echo e($defaultSize); ?>">
                        <?php endif; ?>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if ($unitType === 'meter'): ?>
                                <button type="button" id="qty_dec" class="btn btn-outline-secondary" aria-label="Decrease quantity">-</button>
                                <input type="number"
                                       id="product_quantity"
                                       name="bundle_quantity"
                                       class="form-control"
                                       style="width:120px;"
                                       min="1"
                                       step="1"
                                       value="1"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                <button type="button" id="qty_inc" class="btn btn-outline-secondary" aria-label="Increase quantity">+</button>
                            <?php else: ?>
                                <button type="button" id="qty_dec" class="btn btn-outline-secondary" aria-label="Decrease quantity">-</button>
                                <select
                                       id="product_quantity"
                                       name="quantity"
                                       class="form-control"
                                       style="width:120px;"
                                       <?php echo $inStock ? '' : 'disabled'; ?>>
                                    <?php foreach ($quantityOptions as $idx => $qOpt): ?>
                                        <?php $qVal = $isWholeUnit ? (string) ((int) round($qOpt)) : rtrim(rtrim(number_format((float) $qOpt, 2), '0'), '.'); ?>
                                        <option value="<?php echo e($qVal); ?>" <?php echo $idx === 0 ? 'selected' : ''; ?>>
                                            <?php echo e($qVal); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="qty_inc" class="btn btn-outline-secondary" aria-label="Increase quantity">+</button>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary flex-grow-1" <?php echo $inStock ? '' : 'disabled'; ?>>
                                Add to Cart
                            </button>
                        </div>
                    </form>
                    <?php if ($inStock): ?>
                        <form method="POST" action="/add-to-cart.php" class="d-grid mt-2">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                            <input type="hidden" name="quantity" id="buy_now_quantity" value="1">
                            <?php if ($unitType === 'meter'): ?>
                                <input type="hidden" name="meter_length" id="buy_now_meter_length" value="<?php echo e(rtrim(rtrim(number_format($defaultMeterLength ?? max(1.0, (float) ($product['min_order_meters'] ?? 1)), 2), '0'), '.')); ?>">
                                <input type="hidden" name="bundle_quantity" id="buy_now_bundle_quantity" value="1">
                            <?php endif; ?>
                            <?php if (!empty($sizeOptions)): ?>
                                <input type="hidden" name="selected_size" id="selected_size_buy" value="<?php echo e($defaultSize); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="redirect_to" value="checkout">
                            <button type="submit" class="btn btn-outline-dark">Buy Now</button>
                        </form>
                        <script nonce="<?php echo $cspNonce; ?>">
                        (function () {
                            var qtyInput = document.getElementById('product_quantity');
                            var buyNowQty = document.getElementById('buy_now_quantity');
                            var qtyDec = document.getElementById('qty_dec');
                            var qtyInc = document.getElementById('qty_inc');
                            var isPieceUnit = <?php echo $isWholeUnit ? 'true' : 'false'; ?>;
                            var isMeterUnit = <?php echo $unitType === 'meter' ? 'true' : 'false'; ?>;
                            var meterLengthInput = document.getElementById('selected_meter_length');
                            var meterTotalInput = document.getElementById('meter_total_quantity');
                            var buyNowMeterLength = document.getElementById('buy_now_meter_length');
                            var buyNowBundleQty = document.getElementById('buy_now_bundle_quantity');
                            if (!qtyInput || !buyNowQty) return;

                            function syncQty() {
                                var qty = parseFloat(qtyInput.value);
                                if (!Number.isFinite(qty) || qty < 1) qty = 1;

                                if (isMeterUnit) {
                                    qty = Math.round(qty);
                                    qtyInput.value = String(qty);
                                    var meterLen = parseFloat(meterLengthInput ? meterLengthInput.value : '1');
                                    if (!Number.isFinite(meterLen) || meterLen <= 0) meterLen = 1;
                                    var totalMeters = meterLen * qty;
                                    var normalized = totalMeters.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                    if (meterTotalInput) {
                                        meterTotalInput.value = normalized;
                                    }
                                    buyNowQty.value = normalized;
                                    if (buyNowBundleQty) {
                                        buyNowBundleQty.value = String(qty);
                                    }
                                    if (buyNowMeterLength) {
                                        buyNowMeterLength.value = meterLen.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                    }
                                } else {
                                    buyNowQty.value = isPieceUnit ? String(Math.round(qty)) : qty.toFixed(2);
                                }
                            }

                            qtyInput.addEventListener('change', syncQty);
                            qtyInput.addEventListener('input', syncQty);
                            syncQty();

                            function bump(dir) {
                                if (!qtyInput || qtyInput.disabled) return;
                                if (qtyInput.tagName === 'SELECT') {
                                    var idx = qtyInput.selectedIndex + dir;
                                    if (idx < 0) idx = 0;
                                    if (idx >= qtyInput.options.length) idx = qtyInput.options.length - 1;
                                    qtyInput.selectedIndex = idx;
                                } else {
                                    var step = parseFloat(qtyInput.getAttribute('step') || '1');
                                    if (!Number.isFinite(step) || step <= 0) step = 1;
                                    var current = parseFloat(qtyInput.value || '1');
                                    if (!Number.isFinite(current) || current < 1) current = 1;
                                    var next = current + (dir * step);
                                    if (next < 1) next = 1;
                                    qtyInput.value = step >= 1
                                        ? String(Math.round(next))
                                        : next.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                }
                                syncQty();
                            }

                            if (qtyDec) qtyDec.addEventListener('click', function () { bump(-1); });
                            if (qtyInc) qtyInc.addEventListener('click', function () { bump(1); });

                            var sizeButtons = document.querySelectorAll('.size-option-btn');
                            var sizeAdd = document.getElementById('selected_size_add');
                            var sizeBuy = document.getElementById('selected_size_buy');
                            if (sizeButtons.length) {
                                sizeButtons.forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var val = btn.getAttribute('data-size') || '';
                                        sizeButtons.forEach(function (b) {
                                            b.classList.remove('btn-dark');
                                            b.classList.add('btn-outline-dark');
                                        });
                                        btn.classList.remove('btn-outline-dark');
                                        btn.classList.add('btn-dark');
                                        if (sizeAdd) sizeAdd.value = val;
                                        if (sizeBuy) sizeBuy.value = val;
                                    });
                                });
                            }

                            var meterButtons = document.querySelectorAll('.meter-option-btn');
                            if (meterButtons.length) {
                                meterButtons.forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        var val = parseFloat(btn.getAttribute('data-meters') || '0');
                                        if (!Number.isFinite(val) || val <= 0) return;
                                        meterButtons.forEach(function (b) {
                                            b.classList.remove('btn-primary');
                                            b.classList.add('btn-outline-primary');
                                        });
                                        btn.classList.remove('btn-outline-primary');
                                        btn.classList.add('btn-primary');
                                        var normalized = val.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                                        if (isMeterUnit) {
                                            if (meterLengthInput) {
                                                meterLengthInput.value = normalized;
                                            }
                                            if (buyNowMeterLength) {
                                                buyNowMeterLength.value = normalized;
                                            }
                                        } else if (qtyInput.tagName === 'SELECT') {
                                            var hasOption = false;
                                            for (var i = 0; i < qtyInput.options.length; i++) {
                                                if (qtyInput.options[i].value === normalized) {
                                                    hasOption = true;
                                                    break;
                                                }
                                            }
                                            if (hasOption) {
                                                qtyInput.value = normalized;
                                            }
                                        } else {
                                            qtyInput.value = normalized;
                                        }
                                        syncQty();
                                    });
                                });
                            }
                        })();
                        </script>
                    <?php else: ?>
                        <div class="d-grid mt-2">
                            <button type="button" class="btn btn-outline-dark" disabled>Buy Now</button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['description'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-semibold">Description</h6>
                        <p class="mb-0"><?php echo nl2br(e((string) $product['description'])); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <h6 class="fw-semibold">Fabric / Material</h6>
                    <p class="mb-0"><?php echo e((string) ($product['material'] ?? 'Not specified')); ?></p>
                </div>

                <div class="mb-3">
                    <h6 class="fw-semibold">Fabric Width</h6>
                    <p class="mb-0"><?php echo e((string) ($product['width'] ?? 'Not specified')); ?></p>
                </div>

                <?php if (!empty($product['wash_care'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-semibold">Wash Care</h6>
                        <p class="mb-0"><?php echo nl2br(e((string) $product['wash_care'])); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($product['dispatch_time'])): ?>
                    <div class="mb-3">
                        <h6 class="fw-semibold">Dispatch Time (India Orders)</h6>
                        <p class="mb-0"><?php echo e((string) $product['dispatch_time']); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <h6 class="fw-semibold">Shipping Note</h6>
                    <p class="mb-0 text-muted">Shipping timelines vary by destination and order volume. Final timeline is shared at confirmation.</p>
                </div>

                <div class="mb-0">
                    <h6 class="fw-semibold">Return Policy</h6>
                    <p class="mb-2 text-muted">Returns or exchanges are supported for eligible cases as per policy.</p>
                    <a href="return-policy.php" class="btn btn-sm btn-outline-secondary">View Return Policy</a>
                </div>

                <?php do_action('product.details.after', [
                    'conn' => $conn,
                    'product' => $product,
                    'customer_id' => (int) ($_SESSION['customer_id'] ?? 0),
                ]); ?>
            </div>
        </div>
    </div>
</section>

<section class="section-block pt-0">
    <div class="container">
        <div class="surface-panel">
            <h4 class="mb-3">International / Bulk Inquiry</h4>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small">MOQ</div>
                    <div class="fw-semibold"><?php echo e((string) ($product['moq'] ?? '-')); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Lead Time</div>
                    <div class="fw-semibold"><?php echo e((string) ($product['lead_time'] ?? '-')); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">GSM</div>
                    <div class="fw-semibold"><?php echo e((string) ($product['gsm'] ?? '-')); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Width</div>
                    <div class="fw-semibold"><?php echo e((string) ($product['width'] ?? '-')); ?></div>
                </div>
            </div>
            <div class="mt-4">
                <a href="contact.php" class="btn btn-outline-primary">Request International Quote</a>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
