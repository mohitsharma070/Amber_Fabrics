(function () {
    'use strict';

    function productDetailData() {
        var dataElement = document.getElementById('product-detail-data');
        if (!dataElement) return null;
        try {
            var parsed = JSON.parse(dataElement.textContent || '{}');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function initProductMedia() {
        var mainImage = document.getElementById('product-main-image');
        var mainVideo = document.getElementById('product-main-video');
        var mainWebpSource = document.getElementById('product-main-webp-source');
        var thumbsWrap = document.getElementById('product-media-thumbs');
        var mediaStatus = document.getElementById('product-media-status');
        if (!mainImage || !thumbsWrap) return null;
        var lastImageDescriptor = null;
        var recoveringVideo = false;
        var intentionalVideoReset = false;

        function positiveDimension(value) {
            var dimension = parseInt(String(value || '0'), 10);
            return Number.isFinite(dimension) && dimension > 0 ? dimension : 0;
        }

        function normalizeDescriptor(value) {
            if (!value || typeof value !== 'object') return null;
            var type = value.type === 'video' ? 'video' : 'image';
            var src = String(value.src || '').trim();
            if (src === '') return null;
            return {
                type: type,
                src: src,
                thumb_src: String(value.thumb_src || value.src || '').trim(),
                webp_srcset: String(value.webp_srcset || '').trim(),
                width: positiveDimension(value.width),
                height: positiveDimension(value.height),
                thumb_width: positiveDimension(value.thumb_width),
                thumb_height: positiveDimension(value.thumb_height),
                alt: String(value.alt || '').trim()
            };
        }

        function descriptorFromThumb(thumb) {
            return normalizeDescriptor({
                type: thumb.getAttribute('data-type'),
                src: thumb.getAttribute('data-src'),
                webp_srcset: thumb.getAttribute('data-webp-srcset'),
                width: thumb.getAttribute('data-width'),
                height: thumb.getAttribute('data-height'),
                alt: thumb.getAttribute('data-alt')
            });
        }

        function applyDimension(element, attribute, value) {
            if (value > 0) {
                element.setAttribute(attribute, String(value));
            } else {
                element.removeAttribute(attribute);
            }
        }

        function clearVideoSource() {
            if (!mainVideo) return;
            mainVideo.pause();
            mainVideo.classList.add('d-none');
            var source = mainVideo.querySelector('source');
            if (source && source.getAttribute('src')) {
                intentionalVideoReset = true;
                source.removeAttribute('src');
                mainVideo.load();
                window.setTimeout(function () { intentionalVideoReset = false; }, 0);
            }
            mainVideo.removeAttribute('aria-label');
        }

        function showImage(descriptor) {
            lastImageDescriptor = descriptor;
            if (mainVideo) {
                mainVideo.pause();
                mainVideo.classList.add('d-none');
            }
            if (mainWebpSource) {
                if (descriptor.webp_srcset !== '') {
                    mainWebpSource.setAttribute('srcset', descriptor.webp_srcset);
                } else {
                    mainWebpSource.removeAttribute('srcset');
                }
            }
            mainImage.classList.remove('d-none');
            mainImage.setAttribute('src', descriptor.src);
            mainImage.setAttribute('alt', descriptor.alt || 'Product image');
            applyDimension(mainImage, 'width', descriptor.width);
            applyDimension(mainImage, 'height', descriptor.height);
        }

        function showVideo(descriptor) {
            if (!mainVideo) return;
            mainImage.classList.add('d-none');
            var source = mainVideo.querySelector('source');
            if (source && source.getAttribute('src') !== descriptor.src) {
                source.setAttribute('src', descriptor.src);
                mainVideo.load();
            }
            mainVideo.setAttribute('aria-label', descriptor.alt || 'Product video');
            mainVideo.classList.remove('d-none');
        }

        function restoreImageAfterVideoFailure(event) {
            if (event && event.type === 'abort' && intentionalVideoReset) {
                intentionalVideoReset = false;
                return;
            }
            if (recoveringVideo) return;
            recoveringVideo = true;
            clearVideoSource();
            if (lastImageDescriptor) {
                showImage(lastImageDescriptor);
            } else {
                mainImage.classList.add('d-none');
            }
            if (mediaStatus) {
                mediaStatus.textContent = 'Product video could not be played. The product image has been restored.';
            }
            recoveringVideo = false;
        }

        if (mainVideo) {
            ['error', 'abort', 'stalled'].forEach(function (eventName) {
                mainVideo.addEventListener(eventName, restoreImageAfterVideoFailure);
            });
        }

        lastImageDescriptor = normalizeDescriptor({
            type: 'image',
            src: mainImage.getAttribute('src'),
            webp_srcset: mainWebpSource ? mainWebpSource.getAttribute('srcset') : '',
            width: mainImage.getAttribute('width'),
            height: mainImage.getAttribute('height'),
            alt: mainImage.getAttribute('alt')
        });

        function activateThumb(thumb) {
            var descriptor = descriptorFromThumb(thumb);
            if (!descriptor) return;
            var thumbs = thumbsWrap.querySelectorAll('.media-thumb');
            thumbs.forEach(function (item) {
                item.classList.remove('border-primary');
                item.classList.add('border-light');
                item.setAttribute('aria-current', 'false');
            });
            thumb.classList.remove('border-light');
            thumb.classList.add('border-primary');
            thumb.setAttribute('aria-current', 'true');

            if (descriptor.type === 'video') {
                showVideo(descriptor);
            } else {
                showImage(descriptor);
            }
        }

        thumbsWrap.addEventListener('click', function (event) {
            var thumb = event.target && event.target.closest ? event.target.closest('.media-thumb') : null;
            if (!thumb) return;
            activateThumb(thumb);
        });

        return {
            setMedia: function (media) {
                var descriptors = (Array.isArray(media) ? media : []).map(normalizeDescriptor).filter(Boolean);
                clearVideoSource();

                var imageNumber = 0;
                var controls = descriptors.map(function (descriptor, index) {
                    var thumb = document.createElement('button');
                    thumb.type = 'button';
                    thumb.className = 'btn p-0 border rounded media-thumb product-media-thumb '
                        + (index === 0 ? 'border-primary' : 'border-light');
                    thumb.setAttribute('data-type', descriptor.type);
                    thumb.setAttribute('data-src', descriptor.src);
                    thumb.setAttribute('data-alt', descriptor.alt);
                    thumb.setAttribute('aria-current', index === 0 ? 'true' : 'false');

                    if (descriptor.type === 'video') {
                        thumb.classList.add('position-relative');
                        thumb.setAttribute('aria-label', descriptor.alt || 'Product video');
                        var videoLabel = document.createElement('div');
                        videoLabel.className = 'product-media-thumb-video';
                        videoLabel.textContent = 'Video';
                        thumb.appendChild(videoLabel);
                        return thumb;
                    }

                    imageNumber++;
                    thumb.setAttribute('data-webp-srcset', descriptor.webp_srcset);
                    thumb.setAttribute('data-width', String(descriptor.width));
                    thumb.setAttribute('data-height', String(descriptor.height));
                    thumb.setAttribute('aria-label', 'View ' + (descriptor.alt || 'product image') + ' ' + imageNumber);

                    var thumbnailImage = document.createElement('img');
                    thumbnailImage.setAttribute('src', descriptor.thumb_src || descriptor.src);
                    thumbnailImage.setAttribute('alt', (descriptor.alt || 'Product') + ' thumbnail ' + imageNumber);
                    thumbnailImage.setAttribute('loading', 'lazy');
                    applyDimension(thumbnailImage, 'width', descriptor.thumb_width);
                    applyDimension(thumbnailImage, 'height', descriptor.thumb_height);
                    thumb.appendChild(thumbnailImage);
                    return thumb;
                });
                thumbsWrap.replaceChildren.apply(thumbsWrap, controls);

                var firstThumb = thumbsWrap.querySelector('.media-thumb');
                if (firstThumb) {
                    activateThumb(firstThumb);
                } else {
                    clearVideoSource();
                    mainImage.classList.add('d-none');
                    if (mainWebpSource) mainWebpSource.removeAttribute('srcset');
                    mainImage.removeAttribute('src');
                    mainImage.setAttribute('alt', '');
                    mainImage.removeAttribute('width');
                    mainImage.removeAttribute('height');
                }
            }
        };
    }

    function initProductPurchase(data, mediaController) {
        var qtyInput = document.getElementById('product_quantity');
        var buyNowQty = document.getElementById('buy_now_quantity');
        var qtyDec = document.getElementById('qty_dec');
        var qtyInc = document.getElementById('qty_inc');
        var isPieceUnit = data.unitType === 'piece' || data.unitType === 'set';
        var isMeterUnit = data.unitType === 'meter';
        var meterLengthInput = document.getElementById('selected_meter_length');
        var meterTotalInput = document.getElementById('meter_total_quantity');
        var buyNowMeterLength = document.getElementById('buy_now_meter_length');
        var buyNowBundleQty = document.getElementById('buy_now_bundle_quantity');
        var meterPurchaseSummary = document.getElementById('meter_purchase_summary');
        var basePricePerUnit = Number(data.basePricePerUnit) || 0;
        var currentPricePerUnit = basePricePerUnit;
        var regularPricePerUnit = Number(data.regularPricePerUnit) || 0;
        var unitSingleLabel = String(data.unitSingleLabel || '');
        var productPriceBlock = document.getElementById('product_price_block');
        var basePriceMarkup = productPriceBlock ? productPriceBlock.innerHTML : '';
        var minimumOrderQty = Number(data.minimumOrderQty) || 0;
        var quantityStep = Number(data.quantityStep) || 0;
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
                if (meterPurchaseSummary) {
                    var line = String(qty) + (qty === 1 ? ' cut' : ' cuts') + ' × ' + meterLen.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + 'm = ' + normalized + 'm';
                    if (Number.isFinite(currentPricePerUnit) && currentPricePerUnit > 0) {
                        line += ' · Total: Rs ' + (currentPricePerUnit * totalMeters).toFixed(2);
                    }
                    meterPurchaseSummary.textContent = line;
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
                var index = qtyInput.selectedIndex + dir;
                if (index < 0) index = 0;
                if (index >= qtyInput.options.length) index = qtyInput.options.length - 1;
                qtyInput.selectedIndex = index;
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

        var VARIANTS = Array.isArray(data.variants) ? data.variants : [];
        var HIDE_VARIANT_SIZE = data.hideVariantSize === true;
        var mainImageEl = document.getElementById('product-main-image');
        var mainVideoEl = document.getElementById('product-main-video');
        var mainWebpSourceEl = document.getElementById('product-main-webp-source');
        var defaultMainImageSrc = mainImageEl ? String(mainImageEl.getAttribute('src') || '') : '';
        var defaultMainWebpSrcset = mainWebpSourceEl ? String(mainWebpSourceEl.getAttribute('srcset') || '') : '';
        var defaultMedia = Array.isArray(data.defaultMedia) ? data.defaultMedia : [];
        var colorSwatches = document.querySelectorAll('.color-swatch-btn');
        var sizeButtons = document.querySelectorAll('.size-option-btn');
        var sizeSection = document.getElementById('size-picker-section');
        var packInfoSection = document.getElementById('pack-info-section');
        var packInfoLabel = document.getElementById('pack-info-label');
        var stockBadgeEl = document.getElementById('variant-stock-badge');

        var colorAdd = document.getElementById('selected_color_add');
        var colorBuy = document.getElementById('selected_color_buy');
        var sizeAdd = document.getElementById('selected_size_add');
        var sizeBuy = document.getElementById('selected_size_buy');
        var vidAdd = document.getElementById('selected_variant_id_add');
        var vidBuy = document.getElementById('selected_variant_id_buy');

        var addBtn = document.getElementById('add_to_cart_submit');
        var buyBtn = document.getElementById('buy_now_submit');
        var currentVariant = null;
        var isSetUnit = data.unitType === 'set';
        var isPackLikeSize = function (value) {
            return /^pack\s+of\s+\d+$/i.test(String(value || '').trim());
        };
        var packLabelForVariant = function (variant) {
            if (!variant || !isSetUnit) return '';
            var packLabel = (variant.pack_label && String(variant.pack_label).trim() !== '') ? String(variant.pack_label).trim() : '';
            if (packLabel !== '') return packLabel;
            var unitsPerSet = parseInt(String(variant.units_per_set || '0'), 10);
            if (Number.isFinite(unitsPerSet) && unitsPerSet > 0) return 'Pack of ' + unitsPerSet;
            return '';
        };

        function variantSizeLabel(v) {
            if (!v) return '';
            var rawSize = String(v.size || '').trim();
            if (rawSize !== '') return rawSize;
            if (isSetUnit) {
                var packLabel = (v.pack_label && String(v.pack_label).trim() !== '') ? String(v.pack_label).trim() : '';
                if (packLabel !== '') return packLabel;
                var unitsPerSet = parseInt(String(v.units_per_set || '0'), 10);
                if (Number.isFinite(unitsPerSet) && unitsPerSet > 0) return 'Pack of ' + unitsPerSet;
            }
            return '';
        }

        function findVariant(color, size) {
            var fallbackByColor = null;
            for (var index = 0; index < VARIANTS.length; index++) {
                var variant = VARIANTS[index];
                if (parseInt(variant.is_active) !== 1) continue;
                if (variant.color !== color) continue;
                if (fallbackByColor === null) fallbackByColor = variant;
                if (HIDE_VARIANT_SIZE) {
                    return variant;
                }
                if (variant.size === size) {
                    return variant;
                }
            }
            if (!HIDE_VARIANT_SIZE && String(size || '').trim() === '') {
                return fallbackByColor;
            }
            return null;
        }

        function currentColor() {
            return colorAdd ? colorAdd.value : '';
        }

        function currentSize() {
            return sizeAdd ? sizeAdd.value : '';
        }

        function formatInr(value) {
            return new Intl.NumberFormat('en-IN', {
                style: 'currency',
                currency: 'INR',
                minimumFractionDigits: 2
            }).format(value);
        }

        function updateVariantPrice(v) {
            var override = v && v.price_override !== null && String(v.price_override).trim() !== ''
                ? parseFloat(v.price_override)
                : 0;
            currentPricePerUnit = Number.isFinite(override) && override > 0 ? override : basePricePerUnit;
            if (!productPriceBlock) return;
            if (!(Number.isFinite(override) && override > 0)) {
                productPriceBlock.innerHTML = basePriceMarkup;
                return;
            }
            var price = document.createElement('span');
            price.className = 'fs-4 fw-bold text-primary';
            price.textContent = formatInr(override) + ' / ' + unitSingleLabel;
            productPriceBlock.replaceChildren(price);
            if (regularPricePerUnit > override) {
                var mrp = document.createElement('span');
                mrp.className = 'ms-3 text-muted';
                var deleted = document.createElement('del');
                deleted.textContent = formatInr(regularPricePerUnit) + ' / ' + unitSingleLabel;
                mrp.appendChild(deleted);
                productPriceBlock.appendChild(mrp);
            }
        }

        function selectedVariantStock(v) {
            if (!v) return 0;
            var stock = parseFloat(isMeterUnit ? v.stock_meters : v.stock);
            return Number.isFinite(stock) ? Math.max(0, stock) : 0;
        }

        function updateVariantQuantity(v) {
            if (VARIANTS.length === 0) return true;
            if (!v) {
                qtyInput.disabled = true;
                if (qtyDec) qtyDec.disabled = true;
                if (qtyInc) qtyInc.disabled = true;
                document.querySelectorAll('.meter-option-btn').forEach(function (button) { button.disabled = true; });
                return false;
            }
            var stock = selectedVariantStock(v);
            var canBuy = false;
            if (isMeterUnit) {
                var enabledMeterButton = null;
                document.querySelectorAll('.meter-option-btn').forEach(function (button) {
                    var length = parseFloat(button.getAttribute('data-meters') || '0');
                    var enabled = Number.isFinite(length) && length > 0 && length <= stock;
                    button.disabled = !enabled;
                    if (enabled && !enabledMeterButton) enabledMeterButton = button;
                });
                var meterLength = parseFloat(meterLengthInput ? meterLengthInput.value : '0');
                if ((!Number.isFinite(meterLength) || meterLength <= 0 || meterLength > stock) && enabledMeterButton) {
                    meterLength = parseFloat(enabledMeterButton.getAttribute('data-meters') || '0');
                    document.querySelectorAll('.meter-option-btn').forEach(function (button) {
                        var selected = button === enabledMeterButton;
                        button.classList.toggle('btn-primary', selected);
                        button.classList.toggle('btn-outline-primary', !selected);
                        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                    });
                    var meterValue = String(meterLength).replace(/\.0+$/, '');
                    if (meterLengthInput) meterLengthInput.value = meterValue;
                    if (buyNowMeterLength) buyNowMeterLength.value = meterValue;
                }
                var maxBundles = Number.isFinite(meterLength) && meterLength > 0
                    ? Math.floor((stock + 0.000001) / meterLength)
                    : 0;
                qtyInput.max = String(Math.max(0, maxBundles));
                var bundles = parseInt(qtyInput.value || '1', 10);
                if (!Number.isFinite(bundles) || bundles < 1) bundles = 1;
                if (maxBundles > 0 && bundles > maxBundles) bundles = maxBundles;
                qtyInput.value = String(bundles);
                canBuy = maxBundles >= 1;
            } else if (qtyInput.tagName === 'SELECT') {
                var previous = parseFloat(qtyInput.value || '0');
                qtyInput.innerHTML = '';
                var start = Math.max(1, Math.ceil(minimumOrderQty));
                var step = Math.max(1, Math.round(quantityStep));
                var available = Math.max(0, Math.floor(stock));
                var limit = available >= start ? Math.min(available, start + ((20 - 1) * step)) : 0;
                for (var quantity = start; quantity <= limit; quantity += step) {
                    var option = document.createElement('option');
                    option.value = String(quantity);
                    option.textContent = String(quantity);
                    qtyInput.appendChild(option);
                }
                canBuy = qtyInput.options.length > 0;
                if (canBuy) {
                    var previousValue = String(Math.round(previous));
                    if (Array.prototype.some.call(qtyInput.options, function (optionItem) { return optionItem.value === previousValue; })) {
                        qtyInput.value = previousValue;
                    }
                }
            }
            qtyInput.disabled = !canBuy;
            if (qtyDec) qtyDec.disabled = !canBuy;
            if (qtyInc) qtyInc.disabled = !canBuy;
            syncQty();
            return canBuy;
        }

        function updateVariantState(color, size) {
            var v = (VARIANTS.length > 0) ? findVariant(color, size) : null;
            currentVariant = v;
            var variantId = v ? v.id : 0;
            if (colorAdd) colorAdd.value = color;
            if (colorBuy) colorBuy.value = color;
            if (sizeAdd) sizeAdd.value = size;
            if (sizeBuy) sizeBuy.value = size;
            if (vidAdd) vidAdd.value = variantId;
            if (vidBuy) vidBuy.value = variantId;
            updateVariantPrice(v);

            if (isSetUnit && packInfoSection && packInfoLabel) {
                var packText = packLabelForVariant(v);
                if (packText !== '') {
                    packInfoLabel.textContent = packText;
                    packInfoSection.style.display = '';
                } else {
                    packInfoSection.style.display = 'none';
                }
            }

            if (mediaController && typeof mediaController.setMedia === 'function') {
                var variantMedia = v && Array.isArray(v.media) ? v.media : [];
                if (variantMedia.length > 0) {
                    mediaController.setMedia(variantMedia);
                } else {
                    mediaController.setMedia(defaultMedia);
                }
            } else if (mainImageEl && defaultMainImageSrc !== '') {
                mainImageEl.setAttribute('src', defaultMainImageSrc);
                if (mainWebpSourceEl) {
                    mainWebpSourceEl.setAttribute('srcset', defaultMainWebpSrcset);
                }
                if (mainVideoEl) {
                    mainVideoEl.pause();
                    mainVideoEl.classList.add('d-none');
                }
                mainImageEl.classList.remove('d-none');
            }

            if (stockBadgeEl && VARIANTS.length > 0) {
                if (!v) {
                    stockBadgeEl.innerHTML = '';
                } else {
                    var stockNumber = selectedVariantStock(v);
                    var inStock = stockNumber > 0;
                    var badgeClass = inStock ? 'bg-success' : 'bg-secondary';
                    var label = inStock ? 'In Stock (' + stockNumber + ')' : 'Out of Stock';
                    stockBadgeEl.innerHTML = '<span class="badge ' + badgeClass + '">' + label + '</span>';
                }
            }

            var canAdd = VARIANTS.length > 0 ? updateVariantQuantity(v) : true;
            if (addBtn) addBtn.disabled = VARIANTS.length > 0 ? !canAdd : addBtn.disabled;
            if (buyBtn) buyBtn.disabled = VARIANTS.length > 0 ? !canAdd : buyBtn.disabled;
        }

        function activateColor(color, preferredSize) {
            colorSwatches.forEach(function (button) {
                var selected = button.getAttribute('data-color') === color;
                button.classList.toggle('btn-dark', selected);
                button.classList.toggle('btn-outline-dark', !selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            var sizesForColor = [];
            if (!HIDE_VARIANT_SIZE) {
                VARIANTS.forEach(function (variant) {
                    var variantSize = String(variant.size || '').trim();
                    if (parseInt(variant.is_active) === 1 && variant.color === color && variantSize !== '' && !(isSetUnit && isPackLikeSize(variantSize))) {
                        sizesForColor.push(variant.size);
                    }
                });
            }
            var hasSizes = sizesForColor.length > 0;
            if (sizeSection) sizeSection.style.display = hasSizes ? '' : 'none';
            sizeButtons.forEach(function (button) {
                var size = button.getAttribute('data-size');
                var visible = sizesForColor.indexOf(size) !== -1;
                button.style.display = visible ? '' : 'none';
                if (visible) {
                    var matchingVariant = findVariant(color, size);
                    var sizeLabel = variantSizeLabel(matchingVariant);
                    if (sizeLabel !== '') {
                        button.textContent = sizeLabel;
                    }
                }
            });
            var preferred = String(preferredSize || '').trim();
            var firstSize = hasSizes ? sizesForColor[0] : '';
            if (preferred !== '' && sizesForColor.indexOf(preferred) !== -1) {
                firstSize = preferred;
            }
            sizeButtons.forEach(function (button) {
                var size = button.getAttribute('data-size');
                button.classList.toggle('btn-dark', hasSizes && size === firstSize);
                button.classList.toggle('btn-outline-dark', !(hasSizes && size === firstSize));
                button.setAttribute('aria-pressed', hasSizes && size === firstSize ? 'true' : 'false');
            });
            updateVariantState(color, HIDE_VARIANT_SIZE ? '' : firstSize);
        }

        colorSwatches.forEach(function (button) {
            button.addEventListener('click', function () {
                activateColor(button.getAttribute('data-color') || '', '');
            });
        });

        sizeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.style.display === 'none') return;
                sizeButtons.forEach(function (sizeButton) {
                    if (sizeButton.style.display !== 'none') {
                        sizeButton.classList.remove('btn-dark');
                        sizeButton.classList.add('btn-outline-dark');
                        sizeButton.setAttribute('aria-pressed', 'false');
                    }
                });
                button.classList.remove('btn-outline-dark');
                button.classList.add('btn-dark');
                button.setAttribute('aria-pressed', 'true');
                updateVariantState(currentColor(), button.getAttribute('data-size') || '');
            });
        });

        if (VARIANTS.length > 0) {
            activateColor(currentColor(), currentSize());
        } else if (sizeButtons.length) {
            sizeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var value = button.getAttribute('data-size') || '';
                    sizeButtons.forEach(function (sizeButton) {
                        sizeButton.classList.remove('btn-dark');
                        sizeButton.classList.add('btn-outline-dark');
                        sizeButton.setAttribute('aria-pressed', 'false');
                    });
                    button.classList.remove('btn-outline-dark');
                    button.classList.add('btn-dark');
                    button.setAttribute('aria-pressed', 'true');
                    if (sizeAdd) sizeAdd.value = value;
                    if (sizeBuy) sizeBuy.value = value;
                });
            });
        }

        var meterButtons = document.querySelectorAll('.meter-option-btn');
        if (meterButtons.length) {
            meterButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var value = parseFloat(button.getAttribute('data-meters') || '0');
                    if (!Number.isFinite(value) || value <= 0) return;
                    meterButtons.forEach(function (meterButton) {
                        meterButton.classList.remove('btn-primary');
                        meterButton.classList.add('btn-outline-primary');
                        meterButton.setAttribute('aria-pressed', 'false');
                    });
                    button.classList.remove('btn-outline-primary');
                    button.classList.add('btn-primary');
                    button.setAttribute('aria-pressed', 'true');
                    var normalized = value.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                    if (isMeterUnit) {
                        if (meterLengthInput) {
                            meterLengthInput.value = normalized;
                        }
                        if (buyNowMeterLength) {
                            buyNowMeterLength.value = normalized;
                        }
                    } else if (qtyInput.tagName === 'SELECT') {
                        var hasOption = false;
                        for (var index = 0; index < qtyInput.options.length; index++) {
                            if (qtyInput.options[index].value === normalized) {
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
                    if (currentVariant) {
                        updateVariantQuantity(currentVariant);
                    } else {
                        syncQty();
                    }
                });
            });
        }
    }

    function initDeliveryEstimate() {
        var form = document.getElementById('pdp_delivery_form');
        if (!form) return;
        var deliveryRequestId = 0;
        var deliveryAbortController = null;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var output = document.getElementById('pdp_delivery_result');
            var submitButton = form.querySelector('[type="submit"]');
            var requestId = ++deliveryRequestId;
            if (deliveryAbortController) {
                deliveryAbortController.abort();
            }
            var controller = new AbortController();
            deliveryAbortController = controller;
            var timeoutId = window.setTimeout(function () {
                controller.abort();
            }, 10000);
            var selectedVariant = document.getElementById('selected_variant_id_add');
            var quantity = document.getElementById('meter_total_quantity') || document.getElementById('product_quantity');
            var deliveryVariant = document.getElementById('delivery_variant_id');
            var deliveryQuantity = document.getElementById('delivery_quantity');
            if (deliveryVariant) deliveryVariant.value = selectedVariant ? selectedVariant.value : '0';
            if (deliveryQuantity) deliveryQuantity.value = quantity ? quantity.value : '1';
            output.textContent = 'Checking…';
            if (submitButton) {
                submitButton.classList.add('is-loading');
                submitButton.disabled = true;
            }
            try {
                var response = await fetch('/delivery-estimate', {
                    method: 'POST',
                    headers: {'Accept': 'application/json'},
                    body: new FormData(form),
                    signal: controller.signal
                });
                if (!response.ok) {
                    throw new Error('delivery_request_failed');
                }
                var data = null;
                try {
                    data = await response.json();
                } catch (jsonError) {
                    throw new Error('delivery_response_invalid');
                }
                if (requestId !== deliveryRequestId) return;
                if (!data || !data.ok) {
                    output.textContent = 'Delivery estimate is unavailable. Please try again.';
                    return;
                }
                var parts = [
                    (data.serviceability_status === 'live' ? 'Live courier rate' : 'Estimated shipping'),
                    'Dispatch ' + data.estimated_dispatch_label,
                    'Delivery ' + data.estimated_delivery_label,
                    data.shipping_total > 0 ? 'Shipping ₹' + Number(data.shipping_total).toFixed(2) : 'Free shipping'
                ];
                if (data.payment_method === 'cod' && Number(data.cod_fee) > 0) {
                    parts.push('includes COD fee ₹' + Number(data.cod_fee).toFixed(2));
                }
                if (data.courier_name) parts.push(data.courier_name);
                output.textContent = parts.join(' · ');
            } catch (error) {
                if (requestId !== deliveryRequestId) return;
                output.textContent = error && error.name === 'AbortError'
                    ? 'Delivery check timed out. Please try again.'
                    : 'Unable to check delivery right now. Please try again.';
            } finally {
                window.clearTimeout(timeoutId);
                if (requestId === deliveryRequestId) {
                    deliveryAbortController = null;
                    if (submitButton) {
                        submitButton.classList.remove('is-loading');
                        submitButton.disabled = false;
                    }
                }
            }
        });
    }

    function initProductDetail() {
        var data = productDetailData();
        if (!data) return;
        var mediaController = initProductMedia();
        initProductPurchase(data, mediaController);
        initDeliveryEstimate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProductDetail, { once: true });
    } else {
        initProductDetail();
    }
}());
