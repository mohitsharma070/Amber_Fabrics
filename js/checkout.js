(function () {
    "use strict";

    function initCheckout() {
        var dataElement = document.getElementById('checkout-data');
        if (!dataElement) return;
        var checkoutData;
        try {
            checkoutData = JSON.parse(dataElement.textContent || '{}');
        } catch (error) {
            return;
        }
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';
        var codRadio = document.getElementById('payment_cod');
        var razorpayRadio = document.getElementById('payment_razorpay');
        var countryInput = document.querySelector('[name="country"]');
        var savedAddressSelect = document.getElementById('saved_address_select');
        var shippingAddressIdInput = document.getElementById('shipping_address_id');
        var fullNameInput = document.getElementById('checkout_full_name');
        var phoneInput = document.getElementById('checkout_phone');
        var addressInput = document.getElementById('checkout_address');
        var cityInput = document.getElementById('checkout_city');
        var stateInput = document.getElementById('checkout_state');
        var pincodeInput = document.getElementById('checkout_pincode');
        var countryFieldInput = document.getElementById('checkout_country');
        var subtotal = Number(checkoutData.subtotal || 0);
        var discount = Number(checkoutData.discount || 0);
        var codWhatsappThreshold = Number(checkoutData.codWhatsappThreshold || 0);
        var checkoutCurrentTotal = Number(checkoutData.currentTotal || 0);

        var shippingEl = document.getElementById('summary_shipping');
        var codFeeEl = document.getElementById('summary_cod_fee');
        var totalEl = document.getElementById('summary_total');
        var shippingNoteEl = document.getElementById('summary_shipping_note');
        var shippingSource = String(checkoutData.shippingSource || 'manual');
        var shippingCourierName = String(checkoutData.shippingCourierName || '');
        var shippingDebugReason = String(checkoutData.shippingDebugReason || '');
        var shippingDebugMessage = String(checkoutData.shippingDebugMessage || '');
        var shippingRateTimer = null;
        var shippingRateRequestId = 0;
        var shippingRateAbortController = null;

        var payOptionCards = document.querySelectorAll('[data-pay-option]');
        var codPanel = document.getElementById('cod-panel');
        var codWhatsappConsentWrap = document.getElementById('cod_whatsapp_consent_wrap');
        var codWhatsappConsent = document.getElementById('cod_whatsapp_consent');
        var razorpayPanel = document.getElementById('razorpay-panel');
        var onlineMethodButtons = document.querySelectorAll('.checkout-online-method');
        var onlinePanels = document.querySelectorAll('.checkout-online-panel');
        var onlineMethodInput = document.getElementById('online_method');
        var shippingQuoteTokenInput = document.getElementById('shipping_quote_token');
        var mobileTotalEl = document.getElementById('mobile_summary_total');
        var mobileSubmitBtn = document.getElementById('mobile_place_order_btn');
        var mobileSubmitLabel = document.getElementById('mobile_place_order_label');
        var checkoutForm = document.getElementById('checkout_form');
        var sectionAddress = document.getElementById('checkout_section_address');
        var sectionPayment = document.getElementById('checkout_section_payment');
        var sectionAddressBody = document.getElementById('checkout_address_body');
        var sectionPaymentBody = document.getElementById('checkout_payment_body');
        var sectionAddressSummary = document.getElementById('checkout_address_summary');
        var sectionPaymentSummary = document.getElementById('checkout_payment_summary');
        var editAddressBtn = document.getElementById('checkout_edit_address');
        var editPaymentBtn = document.getElementById('checkout_edit_payment');
        var createAccountCheckbox = document.getElementById('create_account');
        var createAccountFields = document.getElementById('create_account_fields');
        var checkoutSubmit = document.getElementById('checkout_submit');
        var checkoutDeliveryEstimate = document.getElementById('checkout_delivery_estimate');
        var createAccountPassword = document.getElementById('create_account_password');
        var createAccountConfirmPassword = document.getElementById('create_account_confirm_password');
        var couponStateForms = document.querySelectorAll('[data-preserve-checkout-state]');
        var continuePaymentBtn = document.getElementById('checkout_continue_payment');
        var deliveryStatusEl = document.getElementById('checkout_delivery_status');
        var checkoutReviewSection = document.getElementById('checkout_review_section');
        var mobileReviewSection = document.getElementById('checkout_mobile_review_section');
        var deliveryUnlocked = checkoutData.deliveryUnlocked === true;
        var deliveryRequestPending = false;

        if (!checkoutForm || !codRadio || !razorpayRadio || !shippingEl || !codFeeEl || !totalEl || !countryInput) {
            return;
        }
        if (checkoutForm.dataset.checkoutReady === "true") return;
        checkoutForm.dataset.checkoutReady = "true";

        function applySavedAddressOption(optionEl) {
            if (!optionEl) return;
            var selectedId = String(optionEl.value || '');
            if (shippingAddressIdInput) {
                shippingAddressIdInput.value = selectedId;
            }
            if (selectedId === '') {
                return;
            }
            if (fullNameInput) fullNameInput.value = optionEl.getAttribute('data-full-name') || '';
            if (phoneInput) phoneInput.value = optionEl.getAttribute('data-phone') || '';
            if (addressInput) addressInput.value = optionEl.getAttribute('data-address') || '';
            if (cityInput) cityInput.value = optionEl.getAttribute('data-city') || '';
            if (stateInput) stateInput.value = optionEl.getAttribute('data-state') || '';
            if (pincodeInput) pincodeInput.value = optionEl.getAttribute('data-pincode') || '';
            if (countryFieldInput) countryFieldInput.value = 'India';
        }

        function toMoney(v) {
            return 'Rs ' + Number(v).toFixed(2);
        }

        function codRequiresWhatsappConsent() {
            return !!(codWhatsappConsent && codRadio.checked && checkoutCurrentTotal >= codWhatsappThreshold);
        }

        function syncWhatsappConsentRequirement() {
            var required = codRequiresWhatsappConsent();
            if (codWhatsappConsentWrap) codWhatsappConsentWrap.classList.toggle('d-none', !required);
            if (!codWhatsappConsent) return;
            codWhatsappConsent.required = required;
            codWhatsappConsent.setAttribute('aria-required', required ? 'true' : 'false');
            if (!required) codWhatsappConsent.classList.remove('is-invalid');
        }

        function setCouponStateField(form, name, value) {
            var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.appendChild(input);
            }
            input.value = String(value == null ? '' : value);
        }

        function preserveCheckoutState(form) {
            var emailInput = document.getElementById('checkout_email');
            var notesInput = document.querySelector('[name="order_notes"]');
            var checkedPayment = document.querySelector('[name="payment_method"]:checked');
            var state = {
                full_name: fullNameInput ? fullNameInput.value : '',
                phone: phoneInput ? phoneInput.value : '',
                email: emailInput ? emailInput.value : '',
                address: addressInput ? addressInput.value : '',
                city: cityInput ? cityInput.value : '',
                state: stateInput ? stateInput.value : '',
                pincode: pincodeInput ? pincodeInput.value : '',
                country: 'India',
                order_notes: notesInput ? notesInput.value : '',
                payment_method: checkedPayment ? checkedPayment.value : 'cod',
                online_method: onlineMethodInput ? onlineMethodInput.value : '',
                shipping_address_id: shippingAddressIdInput ? shippingAddressIdInput.value : '0'
            };
            state.cod_whatsapp_consent = codWhatsappConsent && codWhatsappConsent.checked ? '1' : '0';
            Object.keys(state).forEach(function (name) {
                setCouponStateField(form, name, state[name]);
            });
        }

        function setShippingNote(source, courierName, debugReason, debugMessage) {
            if (!shippingNoteEl) {
                return;
            }
            var src = String(source || '').trim().toLowerCase();
            var courier = String(courierName || '').trim();
            var reason = String(debugReason || '').trim();
            var message = String(debugMessage || '').trim();
            if (src !== '' && src !== 'manual') {
                shippingNoteEl.textContent = courier !== ''
                    ? ('Live courier rate active: ' + courier + '.')
                    : 'Live courier rate active.';
                return;
            }
            if (reason !== '') {
                var fallbackMessages = {
                    shipping_quote_refreshing: 'Updating live shipping rate...',
                    shipping_courier_disabled: 'Manual shipping is active because live courier rates are disabled.',
                    shipping_courier_not_configured: 'Manual shipping is active because the courier service is not configured.',
                    shipping_quote_context_invalid: 'Enter a valid delivery pincode to calculate live shipping.',
                    bigship_origin_or_parcel_invalid: 'Manual shipping is active because parcel details need attention.',
                    bigship_rate_api_failed: 'Live courier pricing is temporarily unavailable; manual shipping is being used.',
                    bigship_rate_unavailable: 'No live courier rate is available for this order; manual shipping is being used.'
                };
                shippingNoteEl.textContent = fallbackMessages[reason]
                    || ('Manual shipping fallback' + (message !== '' ? (': ' + message) : '.'));
                return;
            }
            shippingNoteEl.textContent = 'Manual shipping active. Free shipping above Rs 999; otherwise Rs 70. COD adds Rs 50 handling fee.';
        }

        function syncSummary() {
            var paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
            var taxable = Math.max(0, subtotal - discount);
            if (!deliveryUnlocked) {
                checkoutCurrentTotal = taxable;
                shippingEl.textContent = '—';
                codFeeEl.textContent = '—';
                totalEl.textContent = toMoney(taxable);
                if (mobileTotalEl) mobileTotalEl.textContent = toMoney(taxable);
                if (checkoutDeliveryEstimate) checkoutDeliveryEstimate.textContent = '';
                if (shippingNoteEl) shippingNoteEl.textContent = 'Enter your delivery address and pincode to calculate shipping.';
                syncWhatsappConsentRequirement();
                return;
            }
            var currentTotal = Number(String(totalEl.textContent || '').replace(/[^0-9.]/g, '')) || taxable;
            checkoutCurrentTotal = currentTotal;
            syncWhatsappConsentRequirement();
            if (checkoutSubmit) checkoutSubmit.textContent = (paymentMethod === 'cod' ? 'Place COD Order — ' : 'Pay Securely — ') + toMoney(currentTotal);
            if (mobileSubmitLabel) mobileSubmitLabel.textContent = paymentMethod === 'cod' ? 'Place COD Order — ' : 'Pay Securely — ';
        }

        function setCheckoutUnlocked(unlocked) {
            deliveryUnlocked = !!unlocked;
            if (sectionPayment) {
                sectionPayment.classList.toggle('d-none', !deliveryUnlocked);
                sectionPayment.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
            }
            if (checkoutReviewSection) {
                checkoutReviewSection.classList.toggle('d-none', !deliveryUnlocked);
                checkoutReviewSection.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
            }
            if (mobileReviewSection) {
                mobileReviewSection.classList.toggle('d-none', !deliveryUnlocked);
                mobileReviewSection.setAttribute('aria-hidden', deliveryUnlocked ? 'false' : 'true');
            }
            if (!deliveryUnlocked && shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
            syncSummary();
        }

        function setDeliveryRequestPending(pending) {
            deliveryRequestPending = !!pending;
            if (continuePaymentBtn) {
                continuePaymentBtn.disabled = deliveryRequestPending;
                continuePaymentBtn.classList.toggle('is-loading', deliveryRequestPending);
            }
            if (checkoutSubmit) checkoutSubmit.disabled = deliveryRequestPending;
            if (mobileSubmitBtn) mobileSubmitBtn.disabled = deliveryRequestPending;
        }

        function invalidateDeliveryQuote() {
            if (shippingRateTimer) {
                window.clearTimeout(shippingRateTimer);
                shippingRateTimer = null;
            }
            shippingRateRequestId++;
            if (shippingRateAbortController) {
                shippingRateAbortController.abort();
                shippingRateAbortController = null;
            }
            setDeliveryRequestPending(false);
            setCheckoutUnlocked(false);
            if (deliveryStatusEl) deliveryStatusEl.textContent = 'Delivery details changed. Continue again to refresh shipping.';
        }

        function syncCreateAccountFields() {
            if (!createAccountCheckbox || !createAccountFields) return;
            var enabled = !!createAccountCheckbox.checked;
            createAccountFields.style.display = enabled ? '' : 'none';
            if (createAccountPassword) createAccountPassword.required = enabled;
            if (createAccountConfirmPassword) createAccountConfirmPassword.required = enabled;
        }

        function isValidEmail(val) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(val || '').trim());
        }

        function setFieldError(input, hasError) {
            if (!input) return;
            input.classList.toggle('is-invalid', !!hasError);
        }

        function validateAddressSection() {
            var hasError = false;
            var fv = String(fullNameInput ? fullNameInput.value : '').trim();
            var ph = String(phoneInput ? phoneInput.value : '').trim();
            var em = String(document.getElementById('checkout_email') ? document.getElementById('checkout_email').value : '').trim();
            var ad = String(addressInput ? addressInput.value : '').trim();
            var ct = String(cityInput ? cityInput.value : '').trim();
            var st = String(stateInput ? stateInput.value : '').trim();
            var pc = String(pincodeInput ? pincodeInput.value : '').trim();
            setFieldError(fullNameInput, fv === '');
            setFieldError(phoneInput, !/^[0-9+\-\s()]{7,20}$/.test(ph));
            setFieldError(document.getElementById('checkout_email'), !isValidEmail(em));
            setFieldError(addressInput, ad === '');
            setFieldError(cityInput, ct === '');
            setFieldError(stateInput, st === '');
            setFieldError(pincodeInput, !/^[1-9][0-9]{5}$/.test(pc));
            hasError = [fullNameInput, phoneInput, document.getElementById('checkout_email'), addressInput, cityInput, stateInput, pincodeInput]
                .some(function (el) { return !!(el && el.classList.contains('is-invalid')); });
            return !hasError;
        }

        function updateSectionSummaries() {
            if (sectionAddressSummary) {
                var nm = String(fullNameInput ? fullNameInput.value : '').trim();
                var ph = String(phoneInput ? phoneInput.value : '').trim();
                var ct = String(cityInput ? cityInput.value : '').trim();
                var pc = String(pincodeInput ? pincodeInput.value : '').trim();
                sectionAddressSummary.textContent = [nm, ph, [ct, pc].filter(Boolean).join(' - ')].filter(Boolean).join(' | ');
            }
            if (sectionPaymentSummary) {
                sectionPaymentSummary.textContent = codRadio.checked ? 'Cash on Delivery' : 'Online Payment (Razorpay)';
            }
        }

        function setSectionCollapsed(sectionEl, bodyEl, summaryEl, editBtn, collapsed) {
            if (!sectionEl || !bodyEl || !summaryEl || !editBtn) return;
            sectionEl.classList.toggle('checkout-section-collapsed', !!collapsed);
            bodyEl.classList.toggle('d-none', !!collapsed);
            summaryEl.classList.toggle('d-none', !collapsed);
            editBtn.classList.toggle('d-none', !collapsed);
        }

        function focusFirstError() {
            if (!checkoutForm) return false;
            var firstError = checkoutForm.querySelector('.is-invalid');
            if (!firstError) return false;
            firstError.focus({ preventScroll: true });
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }

        async function maybeFetchLiveRate() {
            var country = String(countryInput.value || '').trim().toLowerCase();
            var pincode = pincodeInput ? String(pincodeInput.value || '').trim() : '';
            if (country !== 'india' || !/^[1-9][0-9]{5}$/.test(pincode)) {
                if (shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
                shippingDebugReason = 'shipping_quote_context_invalid';
                setShippingNote('manual', '', shippingDebugReason, '');
                return false;
            }
            if (shippingRateAbortController) shippingRateAbortController.abort();
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            shippingRateAbortController = controller;
            var requestId = ++shippingRateRequestId;
            if (shippingQuoteTokenInput) shippingQuoteTokenInput.value = '';
            var paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
            var requestContext = country + '|' + pincode + '|' + paymentMethod;
            var body = new URLSearchParams();
            body.set('csrf_token', csrfToken);
            body.set('pincode', pincode);
            body.set('payment_method', paymentMethod);
            setDeliveryRequestPending(true);
            if (shippingNoteEl) shippingNoteEl.textContent = 'Checking delivery service and shipping…';
            try {
                var res = await fetch('/shipping-rate.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString(),
                    signal: controller ? controller.signal : undefined
                });
                var data = null;
                try {
                    data = await res.json();
                } catch (jsonError) {
                    data = null;
                }
                if (data && data.code === 'cart_changed' && data.reload === true) {
                    window.location.reload();
                    return false;
                }
                var currentContext = String(countryInput.value || '').trim().toLowerCase()
                    + '|' + String(pincodeInput ? pincodeInput.value : '').trim()
                    + '|' + (codRadio.checked ? 'cod' : 'razorpay');
                if (requestId !== shippingRateRequestId || requestContext !== currentContext) {
                    return false;
                }
                if (!res.ok || !data || !data.ok) {
                    shippingDebugReason = 'bigship_rate_api_failed';
                    setShippingNote('manual', '', shippingDebugReason, '');
                    return false;
                }
                var liveShipping = Number(data.base_shipping || 0);
                var liveCodFee = Number(data.cod_fee || 0);
                if (shippingQuoteTokenInput && data.quote_token) {
                    shippingQuoteTokenInput.value = String(data.quote_token);
                }
                shippingSource = String(data.source || 'manual');
                shippingCourierName = String(data.courier_name || '');
                shippingDebugReason = String(data.debug_reason || '');
                shippingDebugMessage = String(data.debug_message || '');
                var taxable = Math.max(0, subtotal - discount);
                var total = taxable + liveShipping + liveCodFee;
                checkoutCurrentTotal = total;
                shippingEl.textContent = toMoney(liveShipping);
                codFeeEl.textContent = toMoney(liveCodFee);
                totalEl.textContent = toMoney(total);
                if (mobileTotalEl) {
                    mobileTotalEl.textContent = toMoney(total);
                }
                if(checkoutSubmit){checkoutSubmit.textContent=(paymentMethod==='cod'?'Place COD Order — ':'Pay Securely — ')+toMoney(total);}
                if(mobileSubmitLabel){mobileSubmitLabel.textContent=paymentMethod==='cod'?'Place COD Order — ':'Pay Securely — ';}
                if(checkoutDeliveryEstimate&&data.estimated_delivery_label){checkoutDeliveryEstimate.textContent='Estimated delivery: '+String(data.estimated_delivery_label);}
                if(typeof window.gtag==='function'){window.gtag('event','add_shipping_info',{currency:'INR',value:total,shipping_tier:shippingSource});}
                setShippingNote(shippingSource, shippingCourierName, shippingDebugReason, shippingDebugMessage);
                syncWhatsappConsentRequirement();
                setCheckoutUnlocked(true);
                if (deliveryStatusEl) deliveryStatusEl.textContent = data.serviceability_status === 'live'
                    ? 'Delivery address verified with a live courier rate.'
                    : 'Delivery address verified with an estimated shipping rate.';
                return true;
            } catch (error) {
                if (error && error.name === 'AbortError') return false;
                if (requestId === shippingRateRequestId) {
                    shippingDebugReason = 'bigship_rate_api_failed';
                    setShippingNote('manual', '', shippingDebugReason, '');
                }
                return false;
            } finally {
                if (requestId === shippingRateRequestId) {
                    shippingRateAbortController = null;
                    setDeliveryRequestPending(false);
                }
            }
        }

        function scheduleLiveRate(delay) {
            if (shippingRateTimer) {
                window.clearTimeout(shippingRateTimer);
            }
            shippingRateTimer = window.setTimeout(function () { maybeFetchLiveRate(); }, Number(delay || 0));
        }

        function syncPaymentPanels() {
            var selected = codRadio.checked ? 'cod' : 'razorpay';
            payOptionCards.forEach(function (card) {
                card.classList.toggle('is-active', card.getAttribute('data-pay-option') === selected);
            });
            if (codPanel) {
                codPanel.classList.toggle('is-open', selected === 'cod');
            }
            syncWhatsappConsentRequirement();
            if (razorpayPanel) {
                razorpayPanel.classList.toggle('is-open', selected === 'razorpay');
            }
            if (onlineMethodInput && selected === 'cod') {
                onlineMethodInput.value = '';
            }
        }

        function activateOnlineMethod(method) {
            onlineMethodButtons.forEach(function (btn) {
                var selected = btn.getAttribute('data-online-method') === method;
                btn.classList.toggle('is-active', selected);
                btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            onlinePanels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-online-panel') === method);
            });
            if (onlineMethodInput) {
                onlineMethodInput.value = method || 'upi';
            }
        }

        codRadio.addEventListener('change', syncSummary);
        razorpayRadio.addEventListener('change', syncSummary);
        [codRadio,razorpayRadio].forEach(function(radio){radio.addEventListener('change',function(){if(this.checked&&typeof window.gtag==='function'){window.gtag('event','add_payment_info',{currency:'INR',value:Number(totalEl.textContent.replace(/[^0-9.]/g,''))||0,payment_type:this.value});}});});
        codRadio.addEventListener('change', syncPaymentPanels);
        razorpayRadio.addEventListener('change', syncPaymentPanels);
        countryInput.addEventListener('input', syncSummary);
        if (pincodeInput) {
            pincodeInput.addEventListener('input', function () {
                invalidateDeliveryQuote();
            });
        }
        countryInput.addEventListener('change', invalidateDeliveryQuote);
        function refreshQuoteForPaymentChange() {
            invalidateDeliveryQuote();
            if (validateAddressSection()) scheduleLiveRate(0);
        }
        codRadio.addEventListener('change', refreshQuoteForPaymentChange);
        razorpayRadio.addEventListener('change', refreshQuoteForPaymentChange);
        onlineMethodButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateOnlineMethod(btn.getAttribute('data-online-method'));
                razorpayRadio.checked = true;
                syncPaymentPanels();
                syncSummary();
                refreshQuoteForPaymentChange();
            });
        });
        if (savedAddressSelect) {
            savedAddressSelect.addEventListener('change', function () {
                applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
                invalidateDeliveryQuote();
            });
        }
        [fullNameInput, phoneInput, addressInput, cityInput, stateInput, pincodeInput, countryFieldInput].forEach(function (field) {
            if (!field) return;
            field.addEventListener('input', function () {
                if (shippingAddressIdInput && shippingAddressIdInput.value !== '') {
                    shippingAddressIdInput.value = '';
                }
                if (savedAddressSelect && savedAddressSelect.value !== '') {
                    savedAddressSelect.value = '';
                }
                if (field !== pincodeInput) invalidateDeliveryQuote();
            });
        });
        if (savedAddressSelect && savedAddressSelect.value !== '') {
            applySavedAddressOption(savedAddressSelect.options[savedAddressSelect.selectedIndex] || null);
        }
        setShippingNote(shippingSource, shippingCourierName, shippingDebugReason, shippingDebugMessage);
        setCheckoutUnlocked(deliveryUnlocked);
        if (continuePaymentBtn) {
            continuePaymentBtn.addEventListener('click', async function () {
                if (!validateAddressSection()) {
                    setCheckoutUnlocked(false);
                    if (deliveryStatusEl) deliveryStatusEl.textContent = 'Please complete the highlighted delivery fields.';
                    focusFirstError();
                    return;
                }
                var quoted = await maybeFetchLiveRate();
                if (!quoted) {
                    setCheckoutUnlocked(false);
                    if (deliveryStatusEl) deliveryStatusEl.textContent = 'We could not calculate shipping. Please check the pincode and try again.';
                    return;
                }
                updateSectionSummaries();
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
                if (sectionPayment) sectionPayment.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        }
        if (createAccountCheckbox) {
            createAccountCheckbox.addEventListener('change', syncCreateAccountFields);
            syncCreateAccountFields();
        }
        couponStateForms.forEach(function (form) {
            form.addEventListener('submit', function () {
                preserveCheckoutState(form);
            });
        });
        if (editAddressBtn) {
            editAddressBtn.addEventListener('click', function () {
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, false);
                if (fullNameInput) fullNameInput.focus();
            });
        }
        if (editPaymentBtn) {
            editPaymentBtn.addEventListener('click', function () {
                setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, false);
                if (codRadio) codRadio.focus();
            });
        }
        if (sectionPayment) {
            sectionPayment.addEventListener('click', function () {
                updateSectionSummaries();
                if (validateAddressSection()) {
                    setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
                }
            });
        }
        if (checkoutForm) {
            checkoutForm.addEventListener('submit', function (ev) {
                updateSectionSummaries();
                var okAddress = validateAddressSection();
                var okWhatsappConsent = !codRequiresWhatsappConsent() || codWhatsappConsent.checked;
                if (codWhatsappConsent) codWhatsappConsent.classList.toggle('is-invalid', !okWhatsappConsent);
                if (!okAddress || !okWhatsappConsent || !deliveryUnlocked || !shippingQuoteTokenInput || shippingQuoteTokenInput.value === '') {
                    ev.preventDefault();
                    setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, false);
                    setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, false);
                    if (!okAddress) {
                        focusFirstError();
                    } else if (!okWhatsappConsent && codWhatsappConsent) {
                        codWhatsappConsent.focus();
                    } else if (deliveryStatusEl) {
                        deliveryStatusEl.textContent = 'Continue to payment again so we can confirm shipping.';
                    }
                    return;
                }
                setSectionCollapsed(sectionAddress, sectionAddressBody, sectionAddressSummary, editAddressBtn, true);
                setSectionCollapsed(sectionPayment, sectionPaymentBody, sectionPaymentSummary, editPaymentBtn, true);
            });
        }
        updateSectionSummaries();
        if (codWhatsappConsent) {
            codWhatsappConsent.addEventListener('change', function () {
                codWhatsappConsent.classList.toggle('is-invalid', !codWhatsappConsent.checked && codRequiresWhatsappConsent());
            });
        }
        focusFirstError();
        activateOnlineMethod(onlineMethodInput && onlineMethodInput.value ? onlineMethodInput.value : 'upi');
        syncPaymentPanels();
    }

    initCheckout();
}());
