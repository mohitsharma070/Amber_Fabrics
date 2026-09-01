<?php
/** @var array<string, array<string, mixed>> $catalogFilterFields */
/** @var string $catalogFilterMode */
/** @var string $catalogFilterIdPrefix */

$catalogFilterField = static function (string $key) use ($catalogFilterFields): array {
    return $catalogFilterFields[$key];
};
$catalogFilterId = static function (array $field) use ($catalogFilterIdPrefix): string {
    return $catalogFilterIdPrefix . (string) $field['name'];
};
$catalogFilterOptions = static function (array $field): void {
    foreach ((array) ($field['options'] ?? []) as $option) {
        $value = (string) ($option['value'] ?? '');
        ?>
        <option value="<?php echo e($value); ?>"<?php echo (string) ($field['value'] ?? '') === $value ? ' selected' : ''; ?>><?php echo e((string) ($option['label'] ?? '')); ?></option>
        <?php
    }
};
$catalogTextFieldKeys = ['material', 'color', 'size', 'dispatch'];
?>

<?php if ($catalogFilterMode === 'desktop'): ?>
    <?php $field = $catalogFilterField('category'); ?>
    <div class="col-12">
        <label class="form-label" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
        <select class="form-select" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>">
            <?php $catalogFilterOptions($field); ?>
        </select>
    </div>
    <div class="col-12">
        <div class="form-label">Price Range (Rs)</div>
        <div class="row g-2">
            <?php foreach (['min_price', 'max_price'] as $key): ?>
                <?php $field = $catalogFilterField($key); ?>
                <div class="col-6"><label class="visually-hidden" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label><input type="number" min="<?php echo (int) ($field['min'] ?? 0); ?>" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>" class="form-control" value="<?php echo (int) ($field['value'] ?? 0); ?>" placeholder="<?php echo e((string) ($field['placeholder'] ?? '')); ?>"></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php $field = $catalogFilterField('in_stock'); ?>
    <div class="col-12 form-check mt-2 ms-1">
        <input class="form-check-input" type="checkbox" value="<?php echo e((string) $field['value']); ?>" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>"<?php echo !empty($field['checked']) ? ' checked' : ''; ?>>
        <label class="form-check-label" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
    </div>
    <?php foreach ($catalogTextFieldKeys as $key): ?>
        <?php $field = $catalogFilterField($key); ?>
        <div class="col-12">
            <label class="form-label" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
            <input type="text" class="form-control" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>" value="<?php echo e((string) $field['value']); ?>" placeholder="<?php echo e((string) ($field['placeholder'] ?? '')); ?>">
        </div>
    <?php endforeach; ?>
    <?php foreach (['sort', 'per_page'] as $key): ?>
        <?php $field = $catalogFilterField($key); ?>
        <div class="col-12">
            <label class="form-label" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
            <select class="form-select" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>">
                <?php $catalogFilterOptions($field); ?>
            </select>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($catalogFilterMode === 'mobile'): ?>
    <?php foreach (['category', 'sort'] as $key): ?>
        <?php $field = $catalogFilterField($key); ?>
        <div class="<?php echo $key === 'category' ? 'col-12' : 'col-6'; ?>">
            <label class="form-label fw-semibold" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
            <select class="form-select" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>">
                <?php $catalogFilterOptions($field); ?>
            </select>
        </div>
    <?php endforeach; ?>
    <?php foreach (['min_price', 'max_price'] as $key): ?>
        <?php $field = $catalogFilterField($key); ?>
        <div class="col-6">
            <label class="form-label fw-semibold" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
            <input type="number" min="<?php echo (int) ($field['min'] ?? 0); ?>" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>" class="form-control" value="<?php echo (int) ($field['value'] ?? 0); ?>">
        </div>
    <?php endforeach; ?>
    <?php $field = $catalogFilterField('in_stock'); ?>
    <div class="col-12 form-check ms-1">
        <input class="form-check-input" type="checkbox" value="<?php echo e((string) $field['value']); ?>" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>"<?php echo !empty($field['checked']) ? ' checked' : ''; ?>>
        <label class="form-check-label" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
    </div>
    <div class="col-12">
        <button
            type="button"
            class="btn btn-outline-secondary w-100 mobile-advanced-toggle"
            data-bs-toggle="collapse"
            data-bs-target="#mobileAdvancedFilters"
            aria-expanded="<?php echo !empty($catalogFilterAdvancedOpen) ? 'true' : 'false'; ?>"
            aria-controls="mobileAdvancedFilters"
        >
            Advanced Filters
        </button>
    </div>
    <div class="col-12 collapse <?php echo !empty($catalogFilterAdvancedOpen) ? 'show' : ''; ?>" id="mobileAdvancedFilters">
        <div class="row g-3 mobile-advanced-group">
            <?php $field = $catalogFilterField('per_page'); ?>
            <div class="col-12">
                <label class="form-label fw-semibold" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
                <select class="form-select" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>">
                    <?php foreach ((array) ($field['options'] ?? []) as $option): ?>
                        <?php $value = (string) ($option['value'] ?? ''); ?>
                        <option value="<?php echo e($value); ?>"<?php echo (string) ($field['value'] ?? '') === $value ? ' selected' : ''; ?>><?php echo e((string) ($option['label'] ?? '')); ?> items</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($catalogTextFieldKeys as $key): ?>
                <?php $field = $catalogFilterField($key); ?>
                <div class="col-6">
                    <label class="form-label fw-semibold" for="<?php echo e($catalogFilterId($field)); ?>"><?php echo e((string) $field['label']); ?></label>
                    <input type="text" class="form-control" id="<?php echo e($catalogFilterId($field)); ?>" name="<?php echo e((string) $field['name']); ?>" value="<?php echo e((string) $field['value']); ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
