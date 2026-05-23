<script nonce="<?php echo $cspNonce; ?>">
(function () {
    var formEl = document.getElementById('product-editor-form');
    var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.product-editor-tab'));
    var unitSelect = document.querySelector('select[name="unit_type"]');
    var minOrderInput = document.querySelector('input[name="min_order_meters"]');
    var qtyStepInput = document.querySelector('input[name="qty_step"]');
    var meterOptionsInput = document.querySelector('input[name="meter_options"]');
    var lowStockUnitsInput = document.querySelector('input[name="low_stock_threshold_units"]');
    var lowStockMetersInput = document.querySelector('input[name="low_stock_threshold_meters"]');
    var categoryInput = document.querySelector('select[name="category"]');
    var materialInput = document.querySelector('input[name="material"]');
    var gsmInput = document.querySelector('input[name="gsm"]');
    var skuPreview = document.getElementById('sku_preview');
    var skuHidden = document.getElementById('sku_hidden');
    var variantsTabLink = document.getElementById('variants-tab-link');
    var prevTabBtn = document.getElementById('product-prev-tab-btn');
    var nextTabBtn = document.getElementById('product-next-tab-btn');
    var currentSection = 'details';
    var sectionOrder = ['details', 'pricing', 'content'];
    if (!unitSelect) return;

    function assignSections() {
        if (!formEl) return;
        Array.prototype.forEach.call(formEl.children, function (col) {
            if (!col || !col.querySelector) return;
            var explicitSection = String(col.getAttribute('data-editor-section') || '').trim();
            if (explicitSection !== '') {
                col.dataset.editorSection = explicitSection;
                return;
            }
            var submitBtn = col.querySelector('button[name="submit"]');
            var labelEl = col.querySelector('label.form-label, .form-check-label');
            if (submitBtn) {
                col.dataset.editorSection = 'actions';
                return;
            }
            var text = (labelEl ? labelEl.textContent : '').toLowerCase();
            var section = 'details';
            if (
                text.indexOf('price') !== -1 ||
                text.indexOf('order qty') !== -1 ||
                text.indexOf('quantity step') !== -1 ||
                text.indexOf('status') !== -1 ||
                text.indexOf('featured') !== -1 ||
                text.indexOf('available') !== -1
            ) {
                section = 'pricing';
            }
            if (text.indexOf('wash care') !== -1 || text.indexOf('description') !== -1) {
                section = 'content';
            }
            col.dataset.editorSection = section;
        });
    }

    function setSection(section) {
        var variantsCard = document.getElementById('variants-card');
        if (!formEl) return;
        currentSection = section;
        Array.prototype.forEach.call(formEl.children, function (col) {
            var colSection = col.dataset.editorSection || 'details';
            var show = colSection === section || colSection === 'actions';
            col.classList.toggle('d-none', !show);
        });
        tabButtons.forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-editor-tab') === section);
        });
        if (variantsTabLink) {
            variantsTabLink.classList.remove('active');
        }
        if (variantsCard) {
            // Only show variants card if section is 'variants'
            if (section === 'variants') {
                variantsCard.classList.remove('d-none');
            } else {
                variantsCard.classList.add('d-none');
            }
        }
        if (prevTabBtn) {
            prevTabBtn.disabled = false;
        }
        if (nextTabBtn) {
            // Hide Next button only in Content tab
            nextTabBtn.style.display = (section === 'content') ? 'none' : '';
        }
        Array.prototype.forEach.call(document.querySelectorAll('.js-content-only'), function (el) {
            el.classList.toggle('d-none', section !== 'content');
        });
    }

    function setVariantsTabActive() {
        tabButtons.forEach(function (btn) {
            btn.classList.remove('active');
        });
        if (variantsTabLink) {
            variantsTabLink.classList.add('active');
        }
    }

    function showVariantsSectionOnly() {
        var variantsCard = document.getElementById('variants-card');
        if (!formEl) return;
        currentSection = 'variants';
        Array.prototype.forEach.call(formEl.children, function (col) {
            col.classList.add('d-none');
        });
        if (variantsCard) {
            variantsCard.classList.remove('d-none');
        }
    }

    function applyUnitRules() {
        var unit = unitSelect.value;
        var isMeter = unit === 'meter';
        var isWhole = unit === 'piece' || unit === 'set';
        var meterOptionsRow = document.getElementById('meter_options_row');
        if (meterOptionsRow) {
            meterOptionsRow.style.display = isMeter ? '' : 'none';
        }
        if (meterOptionsInput) {
            meterOptionsInput.disabled = !isMeter;
            meterOptionsInput.required = isMeter;
            if (!isMeter) {
                meterOptionsInput.value = '';
            }
        }
        if (minOrderInput) {
            minOrderInput.step = isWhole ? '1' : '0.01';
        }
        if (qtyStepInput) {
            qtyStepInput.placeholder = isMeter ? 'e.g. 0.5' : '1';
        }
        if (lowStockUnitsInput) {
            lowStockUnitsInput.disabled = isMeter;
            if (isMeter) {
                lowStockUnitsInput.value = '';
            }
        }
        if (lowStockMetersInput) {
            lowStockMetersInput.disabled = !isMeter;
            if (!isMeter) {
                lowStockMetersInput.value = '';
            }
        }
    }

    function skuPart(value) {
        return String(value || '')
            .trim()
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function updateSkuPreview() {
        if (!skuPreview || !skuHidden) return;
        var parts = [
            categoryInput ? skuPart(categoryInput.value) : '',
            materialInput ? skuPart(materialInput.value) : '',
            gsmInput ? skuPart(gsmInput.value) : ''
        ].filter(Boolean);
        var sku = parts.length ? parts.join('-') : 'SKU';
        skuPreview.value = sku;
        skuHidden.value = sku;
    }

    unitSelect.addEventListener('change', applyUnitRules);
    [categoryInput, materialInput, gsmInput].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', updateSkuPreview);
        el.addEventListener('change', updateSkuPreview);
    });
    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setSection(btn.getAttribute('data-editor-tab') || 'details');
        });
    });
    if (variantsTabLink) {
        variantsTabLink.addEventListener('click', function (event) {
            event.preventDefault();
            setVariantsTabActive();
            showVariantsSectionOnly();
            var target = document.getElementById('variants-card');
            if (target && target.scrollIntoView) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
    if (nextTabBtn) {
        nextTabBtn.addEventListener('click', function () {
            var idx = sectionOrder.indexOf(currentSection);
            if (idx >= 0 && idx < sectionOrder.length - 1) {
                setSection(sectionOrder[idx + 1]);
            }
        });
    }
    if (prevTabBtn) {
        prevTabBtn.addEventListener('click', function () {
            var idx = sectionOrder.indexOf(currentSection);
            if (idx > 0) {
                setSection(sectionOrder[idx - 1]);
                return;
            }
            var fallbackHref = String(prevTabBtn.getAttribute('data-cancel-href') || '').trim();
            if (fallbackHref !== '') {
                window.location.href = fallbackHref;
            }
        });
    }

    assignSections();
    setSection('details');
    applyUnitRules();
    updateSkuPreview();
})();
</script>
