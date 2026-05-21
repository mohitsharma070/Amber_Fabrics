<?php
$isEdit = !empty($isEdit);
$hasRealVariants = !empty($hasRealVariants);
$submitLabel = isset($submitLabel) ? (string) $submitLabel : ($isEdit ? 'Update Product' : 'Save Product');
$cancelHref = isset($cancelHref) ? (string) $cancelHref : 'fabrics.php';
$cancelLabel = isset($cancelLabel) ? (string) $cancelLabel : ($isEdit ? 'Back' : 'Cancel');
?>
<div class="card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills product-editor-tabs" id="productEditorTabs">
            <li class="nav-item"><button type="button" class="nav-link active product-editor-tab" data-editor-tab="details">Details</button></li>
            <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="pricing">Pricing & Inventory</button></li>
            <li class="nav-item"><button type="button" class="nav-link product-editor-tab" data-editor-tab="content">Content</button></li>
            <?php if ($isEdit): ?>
                <li class="nav-item"><a class="nav-link" href="#variants-card" id="variants-tab-link">Variants</a></li>
            <?php else: ?>
                <li class="nav-item"><button type="button" class="nav-link disabled" title="Save product first to manage variants" disabled>Variants</button></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" class="row g-3" id="product-editor-form">
    <?php echo csrf_field(); ?>
    <div class="col-sm-6">
        <label class="form-label">Product Name *</label>
        <input type="text" name="name" class="<?php echo form_class($errors, 'name'); ?>" required value="<?php echo e($old['name']); ?>">
        <?php echo form_error($errors, 'name'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">Category *</label>
        <select name="category" class="<?php echo form_class($errors, 'category', 'form-select'); ?>" required>
            <option value="">Select Category</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo e($cat['slug']); ?>" <?php echo $old['category'] === $cat['slug'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php echo form_error($errors, 'category'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">Unit Type *</label>
        <select name="unit_type" class="<?php echo form_class($errors, 'unit_type', 'form-select'); ?>" required>
            <option value="meter" <?php echo $old['unit_type'] === 'meter' ? 'selected' : ''; ?>>Meter (decimal qty, e.g. 1.5m)</option>
            <option value="piece" <?php echo $old['unit_type'] === 'piece' ? 'selected' : ''; ?>>Piece (whole numbers)</option>
            <option value="set" <?php echo $old['unit_type'] === 'set' ? 'selected' : ''; ?>>Set (whole numbers)</option>
        </select>
        <?php echo form_error($errors, 'unit_type'); ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label">SKU <small class="text-muted">(auto-generated)</small></label>
        <input type="text" id="sku_preview" class="form-control" value="<?php echo e($old['sku']); ?>" readonly>
        <input type="hidden" name="sku" id="sku_hidden" value="<?php echo e($old['sku']); ?>">
        <small class="text-muted">Generated from Category + Material + GSM.</small>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Regular Price *</label>
        <input type="number" step="0.01" min="0" name="price" class="<?php echo form_class($errors, 'price'); ?>" required value="<?php echo e($old['price']); ?>">
        <?php echo form_error($errors, 'price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Sale Price</label>
        <input type="number" step="0.01" min="0" name="sale_price" class="<?php echo form_class($errors, 'sale_price'); ?>" value="<?php echo e($old['sale_price']); ?>">
        <?php echo form_error($errors, 'sale_price'); ?>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Cost Price *</label>
        <input type="number" step="0.01" min="0" name="cost_price" class="<?php echo form_class($errors, 'cost_price'); ?>" required value="<?php echo e($old['cost_price']); ?>">
        <?php echo form_error($errors, 'cost_price'); ?>
    </div>
    <?php if (!$isEdit): ?>
    <div class="col-6 col-md-3">
        <label class="form-label">Initial Stock</label>
        <input type="number" step="0.01" min="0" name="stock" class="<?php echo form_class($errors, 'stock'); ?>" value="<?php echo e($old['stock']); ?>" placeholder="0">
        <?php echo form_error($errors, 'stock'); ?>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
        <label class="form-label">Min. Order Qty</label>
        <input type="number" step="0.01" min="0" name="min_order_meters" class="form-control" value="<?php echo e($old['min_order_meters']); ?>" placeholder="1 for piece/set, decimals for meter">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label">Quantity Step <small class="text-muted">(optional)</small></label>
        <input type="number" step="0.0001" min="0" name="qty_step" class="form-control" value="<?php echo e($old['qty_step']); ?>" placeholder="e.g. 0.5">
    </div>
    <div class="col-6 col-md-4" id="meter_options_row">
        <label class="form-label">Meter Options <small class="text-muted">(comma separated)</small></label>
        <input type="text" name="meter_options" class="form-control" placeholder="e.g. 1, 1.5, 2, 2.5" value="<?php echo e($old['meter_options']); ?>">
    </div>
    <div class="col-sm-6">
        <label class="form-label">Material / Fabric</label>
        <input type="text" name="material" class="form-control" value="<?php echo e($old['material']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Print Style</label>
        <input type="text" name="print_style" class="form-control" placeholder="e.g. Floral, Solid, Block Print" value="<?php echo e($old['print_style']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">GSM</label>
        <input type="text" name="gsm" class="form-control" value="<?php echo e($old['gsm']); ?>">
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label">Width</label>
        <input type="text" name="width" class="form-control" value="<?php echo e($old['width']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">MOQ (International Buyers)</label>
        <input type="text" name="moq" class="form-control" value="<?php echo e($old['moq']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">Lead Time (International Buyers)</label>
        <input type="text" name="lead_time" class="form-control" value="<?php echo e($old['lead_time']); ?>">
    </div>
    <div class="col-sm-4">
        <label class="form-label">Dispatch Time (India Orders)</label>
        <input type="text" name="dispatch_time" class="form-control" value="<?php echo e($old['dispatch_time']); ?>">
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label">Status *</label>
        <select name="status" class="<?php echo form_class($errors, 'status', 'form-select'); ?>" required>
            <option value="active" <?php echo $old['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $old['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <?php echo form_error($errors, 'status'); ?>
    </div>
    <div class="col-12">
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" <?php echo $old['is_featured'] ? 'checked' : ''; ?>>
            <label class="form-check-label" for="is_featured">Featured Product</label>
        </div>
        <?php if ($isEdit && !$hasRealVariants): ?>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="is_available" id="is_available" <?php echo $old['is_available'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="is_available">Available for purchase</label>
            </div>
        <?php else: ?>
            <span class="text-muted small ms-2">Availability is auto-derived from active variants with stock.</span>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <label class="form-label">Wash Care</label>
        <textarea name="wash_care" rows="3" class="form-control"><?php echo e($old['wash_care']); ?></textarea>
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" rows="4" class="form-control"><?php echo e($old['description']); ?></textarea>
    </div>
    <div class="col-12" data-editor-section="actions">
        <button type="button" class="btn btn-outline-secondary" id="product-prev-tab-btn" data-cancel-href="<?php echo e($cancelHref); ?>">Back</button>
        <button type="button" class="btn btn-outline-primary" id="product-next-tab-btn">Next</button>
        <!-- Next button will be hidden only in Content tab by JS -->
        <button name="submit" class="btn btn-primary js-content-only"><?php echo e($submitLabel); ?></button>
        <!-- Removed Cancel and Next buttons from Content tab -->
    </div>
</form>
