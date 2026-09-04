(function () {
    "use strict";

    var motionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");
    var interactiveSelector = "a, button, input, select, textarea, [tabindex]";

    function eventElement(event) {
        return event.target instanceof Element ? event.target : null;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute("content") || "") : "";
    }

    function fetchJson(url, options, timeoutMs) {
        var controller = new AbortController();
        var timeoutId = window.setTimeout(function () {
            controller.abort();
        }, timeoutMs || 10000);
        var requestOptions = Object.assign({}, options || {}, { signal: controller.signal });

        return fetch(url, requestOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("Request failed with status " + response.status);
                }
                return response.json();
            })
            .finally(function () {
                window.clearTimeout(timeoutId);
            });
    }

    var AmberUI = window.AmberUI = window.AmberUI || {};
    var activeConfirmation = null;

    function confirmationAttribute(form, submitter, name) {
        var submitterValue = submitter && typeof submitter.getAttribute === "function"
            ? submitter.getAttribute(name)
            : "";
        return submitterValue || form.getAttribute(name) || "";
    }

    function confirmationOptions(form, submitter) {
        var message = confirmationAttribute(form, submitter, "data-confirm-message")
            || confirmationAttribute(form, submitter, "data-confirm");
        var requiresModal = form.hasAttribute("data-confirm-modal")
            || (submitter && submitter.hasAttribute && submitter.hasAttribute("data-confirm-modal"));
        if (!message && !requiresModal) return null;

        var context = confirmationAttribute(form, submitter, "data-confirm-context");
        var variant = confirmationAttribute(form, submitter, "data-confirm-variant");
        var okText = confirmationAttribute(form, submitter, "data-confirm-ok");
        var title = confirmationAttribute(form, submitter, "data-confirm-title");
        var cancelText = confirmationAttribute(form, submitter, "data-confirm-cancel");

        if (context === "checkout") {
            var payment = form.querySelector('[name="payment_method"]:checked');
            var total = document.getElementById("summary_total");
            var isCod = !payment || payment.value === "cod";
            title = title || (isCod ? "Confirm COD Order" : "Continue to Secure Payment");
            message = "Payment method: " + (isCod ? "Cash on Delivery" : "Online payment")
                + ". Amount payable: " + (total ? total.textContent.trim() : "shown total") + ".";
            okText = okText || (isCod ? "Place COD Order" : "Continue to Payment");
            variant = variant || "primary";
        }

        if (!variant) {
            variant = /delete|remove|cancel|archive|refund/i.test((title || "") + " " + (okText || "") + " " + message)
                ? "danger"
                : "primary";
        }

        return {
            title: title || (variant === "danger" ? "Confirm action" : "Please confirm"),
            message: message || "Are you sure you want to continue?",
            okText: okText || "Confirm",
            cancelText: cancelText || "Cancel",
            variant: variant,
            trigger: submitter || document.activeElement
        };
    }

    function degradedConfirmation(options) {
        var title = options.title || "";
        var message = options.message || "Are you sure you want to continue?";
        var prompt = title ? title + "\n\n" + message : message;
        var accepted = false;
        if (typeof window.confirm === "function") {
            try {
                accepted = window.confirm(prompt);
            } catch (_error) {}
        }
        return new Promise(function (resolve) {
            window.setTimeout(function () { resolve(accepted); }, 0);
        });
    }

    AmberUI.confirm = function (options) {
        options = options || {};
        var dialog = document.getElementById("uiConfirmDialog");
        var Modal = window.bootstrap && window.bootstrap.Modal;
        if (activeConfirmation) return Promise.resolve(false);
        if (!dialog || !Modal) return degradedConfirmation(options);

        var title = document.getElementById("uiConfirmDialogTitle");
        var message = document.getElementById("uiConfirmDialogMessage");
        var icon = document.getElementById("uiConfirmDialogIcon");
        var cancel = document.getElementById("uiConfirmDialogCancel");
        var confirm = document.getElementById("uiConfirmDialogOk");
        if (!title || !message || !icon || !cancel || !confirm) {
            return degradedConfirmation(options);
        }
        var requestedVariant = options.variant || (/delete|remove|cancel|archive|refund/i.test(
            (options.title || "") + " " + (options.okText || "") + " " + (options.message || "")
        ) ? "danger" : "primary");
        var variant = requestedVariant === "danger" ? "danger" : (requestedVariant === "warning" ? "warning" : "primary");

        title.textContent = options.title || "Please confirm";
        message.textContent = options.message || "Are you sure you want to continue?";
        icon.hidden = options.icon === false;
        cancel.textContent = options.cancelText || "Cancel";
        confirm.textContent = options.okText || "Confirm";
        confirm.className = "btn btn-" + (variant === "warning" ? "warning" : variant);
        dialog.dataset.variant = variant;

        var modal;
        try {
            modal = Modal.getOrCreateInstance(dialog, { backdrop: "static", keyboard: true, focus: true });
        } catch (_error) {
            return degradedConfirmation(options);
        }
        return new Promise(function (resolve) {
            activeConfirmation = {
                accepted: false,
                resolve: resolve,
                trigger: options.trigger instanceof HTMLElement ? options.trigger : document.activeElement
            };
            try {
                modal.show();
            } catch (_error) {
                activeConfirmation = null;
                resolve(degradedConfirmation(options));
            }
        });
    };

    window.adminConfirm = function (options) {
        return AmberUI.confirm(options);
    };

    function submitConfirmedForm(form, submitter) {
        form.dataset.confirmed = "1";
        form.dataset.confirmSubmitting = "1";
        if (typeof form.requestSubmit === "function") {
            try {
                form.requestSubmit(submitter || undefined);
            } catch (_error) {
                delete form.dataset.confirmed;
                delete form.dataset.confirmSubmitting;
                return;
            }
            if (form.dataset.confirmed === "1") {
                delete form.dataset.confirmed;
                delete form.dataset.confirmSubmitting;
            }
            return;
        }

        var submitterField = null;
        if (submitter && submitter.name) {
            submitterField = document.createElement("input");
            submitterField.type = "hidden";
            submitterField.name = submitter.name;
            submitterField.value = submitter.value;
            form.appendChild(submitterField);
        }
        if (submitter && typeof AmberUI.setButtonLoading === "function") {
            AmberUI.setButtonLoading(submitter, true, "Processing");
        }
        try {
            HTMLFormElement.prototype.submit.call(form);
        } catch (_error) {
            if (submitterField) submitterField.remove();
            delete form.dataset.confirmed;
            delete form.dataset.confirmSubmitting;
        }
    }

    function initConfirmations() {
        var dialog = document.getElementById("uiConfirmDialog");
        var okButton = document.getElementById("uiConfirmDialogOk");
        if (dialog && okButton) {
            okButton.addEventListener("click", function () {
                if (!activeConfirmation) return;
                activeConfirmation.accepted = true;
                window.bootstrap.Modal.getOrCreateInstance(dialog).hide();
            });
            dialog.addEventListener("shown.bs.modal", function () { okButton.focus(); });
            dialog.addEventListener("hidden.bs.modal", function () {
                if (!activeConfirmation) return;
                var result = activeConfirmation.accepted;
                var resolve = activeConfirmation.resolve;
                var trigger = activeConfirmation.trigger;
                activeConfirmation = null;
                resolve(result);
                if (trigger && document.contains(trigger)) trigger.focus({ preventScroll: true });
            });
        }

        document.addEventListener("submit", function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.confirmed === "1") {
                delete form.dataset.confirmed;
                if (event.defaultPrevented) delete form.dataset.confirmSubmitting;
                return;
            }
            if (event.defaultPrevented) return;
            if (form.dataset.confirmSubmitting === "1") {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

            var submitter = event.submitter;
            var options = confirmationOptions(form, submitter);
            if (!options) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            if (form.dataset.confirmPending === "1") return;
            form.dataset.confirmPending = "1";
            AmberUI.confirm(options).then(function (confirmed) {
                delete form.dataset.confirmPending;
                if (!confirmed) return;
                submitConfirmedForm(form, submitter);
            }, function () {
                delete form.dataset.confirmPending;
            });
        });
    }

    function initSkipLink() {
        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            var link = target ? target.closest('.skip-link[href^="#"]') : null;
            if (!link) return;

            var destinationId = link.getAttribute("href").slice(1);
            var destination = destinationId ? document.getElementById(destinationId) : null;
            if (!destination) return;

            event.preventDefault();
            if (window.location.hash !== "#" + destinationId) {
                window.history.pushState(null, "", "#" + destinationId);
            }
            destination.focus({ preventScroll: true });
            destination.scrollIntoView({ behavior: "auto", block: "start" });
        });
    }

    AmberUI.setButtonLoading = function (button, loading, label) {
        if (!button) return;
        if (loading) {
            if (!button.dataset.originalLabel) button.dataset.originalLabel = button.innerHTML;
            button.dataset.originalDisabled = button.disabled ? "1" : "0";
            button.dataset.originalAriaLabel = button.hasAttribute("aria-label") ? button.getAttribute("aria-label") : "";
            button.dataset.hadAriaLabel = button.hasAttribute("aria-label") ? "1" : "0";
            button.classList.add("is-loading");
            button.disabled = true;
            button.setAttribute("aria-busy", "true");
            if (label) button.setAttribute("aria-label", label);
            return;
        }
        button.classList.remove("is-loading");
        button.disabled = button.dataset.originalDisabled === "1";
        button.removeAttribute("aria-busy");
        if (button.dataset.hadAriaLabel === "1") button.setAttribute("aria-label", button.dataset.originalAriaLabel || "");
        else button.removeAttribute("aria-label");
        if (button.dataset.originalLabel) {
            button.innerHTML = button.dataset.originalLabel;
            delete button.dataset.originalLabel;
        }
        delete button.dataset.originalDisabled;
        delete button.dataset.originalAriaLabel;
        delete button.dataset.hadAriaLabel;
    };

    function restoreLoadingButton(button) {
        AmberUI.setButtonLoading(button, false, button.getAttribute("aria-label") || "");
    }

    function initFormLoading() {
        document.addEventListener("submit", function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (e.defaultPrevented) return;
            if (form.classList.contains("js-no-loading") || form.classList.contains("cart-qty-form")) return;

            var submitBtn = e.submitter instanceof HTMLButtonElement || e.submitter instanceof HTMLInputElement
                ? e.submitter
                : form.querySelector('[type="submit"]:not(.js-no-loading)');
            if (!submitBtn || submitBtn.disabled || submitBtn.classList.contains("js-no-loading")) return;

            AmberUI.setButtonLoading(submitBtn, true, "Processing");
            window.setTimeout(function () {
                restoreLoadingButton(submitBtn);
            }, 12000);
        });

        window.addEventListener("pageshow", function (event) {
            if (!event.persisted) return;
            document.querySelectorAll(".btn.is-loading").forEach(restoreLoadingButton);
            document.querySelectorAll("form[data-confirm-submitting]").forEach(function (form) {
                delete form.dataset.confirmSubmitting;
                delete form.dataset.confirmed;
                delete form.dataset.confirmPending;
            });
        });
    }

    function initDelegatedClicks() {
        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            if (!target) return;

            var productCard = target.closest(".product-click-card");
            if (productCard && !target.closest("a, button, input, select, textarea, label, form")) {
                var href = productCard.getAttribute("data-href");
                if (href) window.location.assign(href);
            }
        });
    }

    function initRevealAnimations() {
        var elements = document.querySelectorAll(".animate-in");
        if (!elements.length) return;
        if (!("IntersectionObserver" in window) || motionQuery.matches) {
            elements.forEach(function (element) { element.classList.add("is-visible"); });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add("is-visible");
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.16 });
        elements.forEach(function (element) { observer.observe(element); });
    }

    function bootstrapComponent(name) {
        return window.bootstrap && window.bootstrap[name] ? window.bootstrap[name] : null;
    }

    function nativeFocusableElements(container) {
        return Array.from(container.querySelectorAll(interactiveSelector)).filter(function (element) {
            return !element.disabled
                && element.getAttribute("aria-hidden") !== "true"
                && element.getAttribute("tabindex") !== "-1"
                && element.getClientRects().length > 0;
        });
    }

    function initNativeOffcanvasFallback() {
        if (bootstrapComponent("Offcanvas")) return;

        var openDrawer = null;
        var openTrigger = null;
        var backdrop = null;

        function triggersFor(drawer) {
            return Array.from(document.querySelectorAll('[aria-controls="' + drawer.id + '"], [data-bs-target="#' + drawer.id + '"]'));
        }

        function syncDrawer(drawer, expanded) {
            drawer.classList.toggle("is-native-open", expanded);
            drawer.setAttribute("aria-hidden", expanded ? "false" : "true");
            triggersFor(drawer).forEach(function (trigger) {
                trigger.setAttribute("aria-expanded", expanded ? "true" : "false");
            });
            if (drawer.id === "mobileNavDrawer") {
                document.body.classList.toggle("mobile-nav-open", expanded);
            }
        }

        function closeDrawer(restoreFocus) {
            if (!openDrawer) return;
            var trigger = openTrigger;
            syncDrawer(openDrawer, false);
            openDrawer = null;
            openTrigger = null;
            document.body.classList.remove("native-offcanvas-open");
            if (backdrop) backdrop.remove();
            backdrop = null;
            if (restoreFocus && trigger && document.contains(trigger)) trigger.focus();
        }

        function showDrawer(drawer, trigger) {
            if (openDrawer && openDrawer !== drawer) closeDrawer(false);
            openDrawer = drawer;
            openTrigger = trigger;
            syncDrawer(drawer, true);
            document.body.classList.add("native-offcanvas-open");
            backdrop = document.createElement("div");
            backdrop.className = "native-offcanvas-backdrop";
            backdrop.addEventListener("click", function () { closeDrawer(true); });
            document.body.appendChild(backdrop);
            var focusable = nativeFocusableElements(drawer);
            (focusable[0] || drawer).focus();
        }

        document.querySelectorAll(".offcanvas").forEach(function (drawer) {
            drawer.setAttribute("aria-hidden", "true");
        });

        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            if (!target) return;
            var trigger = target.closest('[data-bs-toggle="offcanvas"], [data-mobile-nav-menu], [data-mobile-bottom-menu]');
            if (trigger) {
                var selector = trigger.getAttribute("data-bs-target") || "#mobileNavDrawer";
                var drawer = selector.charAt(0) === "#" ? document.querySelector(selector) : null;
                if (!drawer) return;
                event.preventDefault();
                showDrawer(drawer, trigger);
                return;
            }
            if (!openDrawer) return;
            if (target.closest('[data-bs-dismiss="offcanvas"]') || target.closest("a.nav-link, a.drawer-utility-link")) {
                closeDrawer(true);
            }
        });

        document.addEventListener("keydown", function (event) {
            if (!openDrawer) return;
            if (event.key === "Escape") {
                event.preventDefault();
                closeDrawer(true);
                return;
            }
            if (event.key !== "Tab") return;
            var focusable = nativeFocusableElements(openDrawer);
            if (!focusable.length) {
                event.preventDefault();
                openDrawer.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        window.addEventListener("pageshow", function () { closeDrawer(false); });
    }

    function initNativeCollapseFallback() {
        if (bootstrapComponent("Collapse")) return;
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function (button) {
            var selector = button.getAttribute("data-bs-target");
            var panel = selector && selector.charAt(0) === "#" ? document.querySelector(selector) : null;
            if (!panel) return;
            button.addEventListener("click", function () {
                var willOpen = !panel.classList.contains("show");
                var parentSelector = panel.getAttribute("data-bs-parent");
                var parent = parentSelector ? document.querySelector(parentSelector) : null;
                if (willOpen && parent) {
                    parent.querySelectorAll(".accordion-collapse.show").forEach(function (sibling) {
                        sibling.classList.remove("show");
                        sibling.setAttribute("aria-hidden", "true");
                        var siblingButton = parent.querySelector('[aria-controls="' + sibling.id + '"]');
                        if (siblingButton) {
                            siblingButton.classList.add("collapsed");
                            siblingButton.setAttribute("aria-expanded", "false");
                        }
                    });
                }
                panel.classList.toggle("show", willOpen);
                panel.setAttribute("aria-hidden", willOpen ? "false" : "true");
                button.classList.toggle("collapsed", !willOpen);
                button.setAttribute("aria-expanded", willOpen ? "true" : "false");
            });
            panel.setAttribute("aria-hidden", panel.classList.contains("show") ? "false" : "true");
        });
    }

    function initMobileDrawer() {
        var drawer = document.getElementById("mobileNavDrawer");
        if (!drawer) return;

        function syncExpanded(expanded) {
            document.body.classList.toggle("mobile-nav-open", expanded);
            document.querySelectorAll("[data-mobile-nav-menu], [data-mobile-bottom-menu]").forEach(function (button) {
                button.setAttribute("aria-expanded", expanded ? "true" : "false");
            });
        }

        drawer.addEventListener("shown.bs.offcanvas", function () { syncExpanded(true); });
        drawer.addEventListener("hidden.bs.offcanvas", function () { syncExpanded(false); });
        drawer.addEventListener("click", function (event) {
            var target = eventElement(event);
            var link = target ? target.closest("a.nav-link, a.drawer-utility-link") : null;
            var Offcanvas = bootstrapComponent("Offcanvas");
            if (!link || !Offcanvas) return;
            var instance = Offcanvas.getInstance(drawer);
            if (instance) instance.hide();
        });

        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            var trigger = target ? target.closest("[data-mobile-bottom-menu], [data-mobile-nav-menu]") : null;
            var Offcanvas = bootstrapComponent("Offcanvas");
            if (!trigger || !Offcanvas) return;
            event.preventDefault();
            Offcanvas.getOrCreateInstance(drawer).show();
        });
    }

    function initMobileViewport() {
        var bottomNav = document.querySelector(".mobile-bottom-nav");
        var viewport = window.visualViewport;
        if (!bottomNav || !viewport) return;
        var framePending = false;

        function sync() {
            framePending = false;
            if (window.innerWidth >= 768) {
                document.documentElement.style.removeProperty("--mobile-viewport-bottom");
                return;
            }
            var obscuredBottom = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
            document.documentElement.style.setProperty("--mobile-viewport-bottom", obscuredBottom.toFixed(2) + "px");
        }

        function requestSync() {
            if (framePending) return;
            framePending = true;
            window.requestAnimationFrame(sync);
        }

        sync();
        viewport.addEventListener("resize", requestSync, { passive: true });
        viewport.addEventListener("scroll", requestSync, { passive: true });
        window.addEventListener("resize", requestSync, { passive: true });
        window.addEventListener("orientationchange", requestSync, { passive: true });
    }

    function initNavigationState() {
        var links = document.querySelectorAll('a.nav-link[href="/index.php#catSlider"]');
        if (!links.length) return;
        function sync() {
            var onHome = window.location.pathname === "/" || window.location.pathname.endsWith("/index.php");
            var active = onHome && window.location.hash === "#catSlider";
            links.forEach(function (link) { link.classList.toggle("active", active); });
        }
        sync();
        window.addEventListener("hashchange", sync);
    }

    function initFilters() {
        document.querySelectorAll(".mobile-filter-toggle").forEach(function (button) {
            var selector = button.getAttribute("data-bs-target");
            if (!selector || selector.charAt(0) !== "#") return;
            var panel = document.querySelector(selector);
            if (!panel) return;
            function syncLabel() {
                button.textContent = panel.classList.contains("show")
                    ? (button.getAttribute("data-hide-label") || "Hide Filters")
                    : (button.getAttribute("data-show-label") || "Show Filters");
            }
            panel.addEventListener("shown.bs.collapse", syncLabel);
            panel.addEventListener("hidden.bs.collapse", syncLabel);
            syncLabel();
        });

        document.addEventListener("change", function (event) {
            var target = eventElement(event);
            if (target && target.matches(".js-auto-submit") && target.form) {
                target.form.submit();
            }
        });
    }

    function initAnnouncement() {
        var bar = document.getElementById("announceBar");
        var closeButton = document.getElementById("announceClose");
        if (!bar || !closeButton) return;
        var track = document.getElementById("announceTrack");
        var pauseButton = document.getElementById("announcePause");
        var key = bar.getAttribute("data-announce-key") || "";
        var items = track ? Array.from(track.children) : [];
        var themes = ["theme-teal", "theme-navy", "theme-sunrise", "theme-rose"];
        var currentIndex = 0;
        var timer = null;
        var userPaused = false;
        var hoverPaused = false;
        var focusPaused = false;
        var touchPaused = false;

        function stop() {
            if (timer) window.clearInterval(timer);
            timer = null;
        }

        function show(index) {
            if (!track || !items.length) return;
            currentIndex = ((index % items.length) + items.length) % items.length;
            track.style.transform = "translateY(" + (-(items[0].offsetHeight || 24) * currentIndex) + "px)";
            themes.forEach(function (theme) { bar.classList.remove(theme); });
            bar.classList.add(themes[currentIndex % themes.length]);
        }

        function start() {
            if (timer || items.length < 2 || motionQuery.matches || userPaused || hoverPaused || focusPaused || touchPaused) return;
            timer = window.setInterval(function () { show(currentIndex + 1); }, 3600);
        }

        function syncPauseButton() {
            if (!pauseButton) return;
            pauseButton.disabled = motionQuery.matches;
            pauseButton.setAttribute("aria-pressed", userPaused ? "true" : "false");
            pauseButton.textContent = motionQuery.matches ? "Paused" : (userPaused ? "Resume" : "Pause");
            pauseButton.setAttribute("aria-label", motionQuery.matches
                ? "Announcements paused for reduced motion"
                : (userPaused ? "Resume announcements" : "Pause announcements"));
        }

        closeButton.addEventListener("click", function () {
            bar.hidden = true;
            stop();
            if (!key) return;
            var body = new URLSearchParams({ key: key });
            var token = csrfToken();
            if (token) body.append("csrf_token", token);
            fetchJson("/announcement-dismiss.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString()
            }).catch(function () {});
        });

        if (pauseButton) {
            pauseButton.addEventListener("click", function () {
                userPaused = !userPaused;
                if (userPaused) stop(); else start();
                syncPauseButton();
            });
        }
        bar.addEventListener("mouseenter", function () { hoverPaused = true; stop(); });
        bar.addEventListener("mouseleave", function () { hoverPaused = false; start(); });
        bar.addEventListener("focusin", function () { focusPaused = true; stop(); });
        bar.addEventListener("focusout", function () { focusPaused = false; start(); });
        bar.addEventListener("touchstart", function () { touchPaused = true; stop(); }, { passive: true });
        bar.addEventListener("touchend", function () {
            window.setTimeout(function () { touchPaused = false; start(); }, 900);
        }, { passive: true });
        motionQuery.addEventListener("change", function () {
            if (motionQuery.matches) stop(); else start();
            syncPauseButton();
        });

        show(0);
        syncPauseButton();
        start();
        if (key) {
            fetchJson("/announcement-dismiss.php?key=" + encodeURIComponent(key), {
                method: "GET",
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            }).then(function (data) {
                if (data && data.success && data.dismissed) {
                    bar.hidden = true;
                    stop();
                }
            }).catch(function () {});
        }
    }

    function sanitizeSliderClone(slide) {
        var clone = slide.cloneNode(true);
        clone.setAttribute("aria-hidden", "true");
        clone.removeAttribute("id");
        if (clone.matches("a, button, input, select, textarea, [tabindex]")) {
            clone.setAttribute("tabindex", "-1");
        }
        clone.querySelectorAll(interactiveSelector).forEach(function (control) {
            control.setAttribute("tabindex", "-1");
        });
        clone.querySelectorAll("[id]").forEach(function (element) { element.removeAttribute("id"); });
        return clone;
    }

    function initSliders() {
        var tracks = document.querySelectorAll(".slider-track");
        if (!tracks.length) return;
        var activeDrag = null;

        document.addEventListener("mouseup", function () {
            if (!activeDrag) return;
            activeDrag.track.style.cursor = "";
            activeDrag.resume(900);
            activeDrag = null;
        });

        tracks.forEach(function (track) {
            var originalSlides = Array.from(track.children);
            if (originalSlides.length < 2 || track.dataset.sliderReady === "true") return;
            track.dataset.sliderReady = "true";
            originalSlides.forEach(function (slide) { track.appendChild(sanitizeSliderClone(slide)); });

            var wrap = track.closest(".slider-wrap");
            var toggle = wrap ? wrap.querySelector("[data-slider-toggle]") : null;
            var timer = null;
            var resumeHandle = null;
            var userPaused = false;
            var hoverPaused = false;
            var focusPaused = false;
            var touchPaused = false;

            function stop() {
                if (timer) window.clearInterval(timer);
                timer = null;
            }

            function normalize() {
                var half = track.scrollWidth / 2;
                if (track.scrollLeft >= half) track.scrollLeft -= half;
            }

            function stepWidth() {
                var first = track.firstElementChild;
                if (!first) return 280;
                var styles = window.getComputedStyle(track);
                var gap = parseFloat(styles.columnGap || styles.gap || "16");
                return first.getBoundingClientRect().width + (Number.isNaN(gap) ? 16 : gap);
            }

            function advance() {
                var step = stepWidth();
                var half = track.scrollWidth / 2;
                track.scrollBy({ left: step, behavior: motionQuery.matches ? "auto" : "smooth" });
                if (track.scrollLeft + step >= half) window.setTimeout(normalize, 420);
            }

            function start() {
                if (timer || motionQuery.matches || userPaused || hoverPaused || focusPaused || touchPaused) return;
                var interval = track.classList.contains("cat-slider-track") ? 3400 : 2600;
                timer = window.setInterval(advance, interval);
            }

            function resume(delay) {
                if (resumeHandle) window.clearTimeout(resumeHandle);
                resumeHandle = window.setTimeout(start, delay);
            }

            function syncToggle() {
                if (!toggle) return;
                toggle.disabled = motionQuery.matches;
                toggle.setAttribute("aria-pressed", userPaused ? "true" : "false");
                toggle.textContent = motionQuery.matches ? "Slider paused" : (userPaused ? "Resume slider" : "Pause slider");
            }

            if (toggle) {
                toggle.addEventListener("click", function () {
                    userPaused = !userPaused;
                    if (userPaused) stop(); else start();
                    syncToggle();
                });
            }
            track.addEventListener("mouseenter", function () { hoverPaused = true; stop(); });
            track.addEventListener("mouseleave", function () { hoverPaused = false; resume(700); });
            track.addEventListener("focusin", function () { focusPaused = true; stop(); });
            track.addEventListener("focusout", function () { focusPaused = false; resume(700); });
            track.addEventListener("touchstart", function () { touchPaused = true; stop(); }, { passive: true });
            track.addEventListener("touchend", function () {
                window.setTimeout(function () { touchPaused = false; start(); }, 1200);
            }, { passive: true });
            track.addEventListener("mousedown", function (event) {
                stop();
                track.style.cursor = "grabbing";
                activeDrag = {
                    track: track,
                    startX: event.pageX - track.offsetLeft,
                    scrollLeft: track.scrollLeft,
                    resume: resume
                };
                event.preventDefault();
            });
            track.addEventListener("mousemove", function (event) {
                if (!activeDrag || activeDrag.track !== track) return;
                var x = event.pageX - track.offsetLeft;
                track.scrollLeft = activeDrag.scrollLeft - (x - activeDrag.startX);
                normalize();
            });
            motionQuery.addEventListener("change", function () {
                if (motionQuery.matches) stop(); else start();
                syncToggle();
            });
            syncToggle();
            start();
        });
    }

    function initGoTop() {
        var button = document.getElementById("goTopBtn");
        if (!button) return;
        var framePending = false;
        function sync() {
            framePending = false;
            button.classList.toggle("is-visible", (window.scrollY || document.documentElement.scrollTop || 0) > 260);
        }
        function requestSync() {
            if (framePending) return;
            framePending = true;
            window.requestAnimationFrame(sync);
        }
        button.addEventListener("click", function () {
            window.scrollTo({ top: 0, behavior: motionQuery.matches ? "auto" : "smooth" });
        });
        window.addEventListener("scroll", requestSync, { passive: true });
        window.addEventListener("resize", requestSync, { passive: true });
        sync();
    }

    function initCookieConsent() {
        var banner = document.getElementById("cookieConsentBanner");
        if (!banner) return;
        var buttons = banner.querySelectorAll("[data-consent-choice]");
        if (!buttons.length) return;

        function setVisible(visible) {
            banner.classList.toggle("d-none", !visible);
            positionToastRegion();
        }
        function setBusy(busy) {
            buttons.forEach(function (button) { button.disabled = busy; });
            banner.classList.toggle("is-busy", busy);
        }

        if ((banner.getAttribute("data-consent-status") || "").toLowerCase() !== "unknown") setVisible(false);
        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            var opener = target ? target.closest("[data-open-cookie-consent]") : null;
            if (!opener) return;
            event.preventDefault();
            setVisible(true);
            banner.scrollIntoView({ behavior: motionQuery.matches ? "auto" : "smooth", block: "end" });
        });
        banner.addEventListener("click", function (event) {
            var target = eventElement(event);
            var button = target ? target.closest("[data-consent-choice]") : null;
            if (!button) return;
            var choice = (button.getAttribute("data-consent-choice") || "").toLowerCase();
            if (choice !== "accept" && choice !== "reject") return;
            var body = new URLSearchParams({ choice: choice });
            var token = csrfToken();
            if (token) body.append("csrf_token", token);
            setBusy(true);
            fetchJson("/cookie-consent.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString()
            }).then(function (data) {
                if (!data || !data.success) throw new Error("Consent update failed");
                banner.setAttribute("data-consent-status", String(data.status || ""));
                setVisible(false);
                if (String(data.status || "") === "granted") window.location.reload();
            }).catch(function () {
                setBusy(false);
            });
        });
    }

    function updateCartBadge(count) {
        count = Math.max(0, parseInt(count, 10) || 0);
        document.querySelectorAll('[data-cart-link]').forEach(function (cartLink) {
            var badge = cartLink.querySelector("[data-cart-badge]");
            if (!count) {
                if (badge) badge.remove();
                return;
            }
            if (!badge) {
                badge = document.createElement("span");
                badge.className = "cart-badge";
                badge.setAttribute("data-cart-badge", "");
                cartLink.appendChild(badge);
            }
            badge.textContent = String(count);
            badge.hidden = false;
        });
    }

    function positionToastRegion() {
        var region = document.getElementById("siteToastRegion");
        if (!region) return;
        var offset = 16;
        var nav = document.querySelector(".mobile-bottom-nav");
        var consent = document.getElementById("cookieConsentBanner");
        if (nav && window.getComputedStyle(nav).display !== "none") offset += nav.getBoundingClientRect().height;
        if (consent && !consent.classList.contains("d-none")) offset += consent.getBoundingClientRect().height;
        region.style.setProperty("--site-toast-bottom", offset + "px");
    }

    AmberUI.toast = function (options) {
        if (typeof options === "string") options = { message: options };
        options = options || {};
        var region = document.getElementById("siteToastRegion");
        if (!region || !options.message) return null;

        var type = ["success", "error", "warning", "info"].indexOf(options.type) >= 0 ? options.type : "info";
        var toast = document.createElement("div");
        var message = document.createElement("span");
        var close = document.createElement("button");
        var duration = Math.max(2000, Number(options.duration) || (type === "error" ? 6000 : 4000));
        var remaining = duration;
        var startedAt = 0;
        var timer = 0;

        toast.className = "site-toast site-toast--" + type;
        toast.setAttribute("role", type === "error" ? "alert" : "status");
        toast.setAttribute("aria-live", type === "error" ? "assertive" : "polite");
        message.className = "site-toast__message";
        message.textContent = String(options.message);
        close.type = "button";
        close.className = "site-toast__close";
        close.setAttribute("aria-label", "Dismiss notification");
        close.textContent = "\u00d7";
        toast.appendChild(message);
        toast.appendChild(close);
        region.appendChild(toast);
        positionToastRegion();

        function dismiss() {
            window.clearTimeout(timer);
            if (!toast.isConnected || toast.classList.contains("is-leaving")) return;
            toast.classList.add("is-leaving");
            window.setTimeout(function () { toast.remove(); }, 200);
        }
        function startTimer() {
            window.clearTimeout(timer);
            startedAt = Date.now();
            timer = window.setTimeout(dismiss, remaining);
        }
        function pauseTimer() {
            window.clearTimeout(timer);
            remaining = Math.max(0, remaining - (Date.now() - startedAt));
        }
        close.addEventListener("click", dismiss);
        toast.addEventListener("mouseenter", pauseTimer);
        toast.addEventListener("mouseleave", startTimer);
        toast.addEventListener("focusin", pauseTimer);
        toast.addEventListener("focusout", startTimer);
        startTimer();
        return toast;
    };

    function showToast(message, type) {
        return AmberUI.toast({ message: message, type: type });
    }

    function initAjaxCart() {
        document.addEventListener("click", function (event) {
            var target = eventElement(event);
            var button = target ? target.closest(".add-to-cart-btn") : null;
            if (!button || button.disabled) return;
            event.preventDefault();
            var fabricId = parseInt(button.dataset.fabricId, 10);
            if (!Number.isInteger(fabricId) || fabricId <= 0) return;
            var minimum = parseInt(button.dataset.min, 10) || 1;
            var quantitySource = button.dataset.qtySrc || button.dataset.qtysource;
            var quantity = minimum;
            if (quantitySource) {
                var input = document.getElementById(quantitySource);
                if (input) quantity = Math.max(minimum, parseInt(input.value, 10) || minimum);
            }

            var originalText = button.textContent;
            AmberUI.setButtonLoading(button, true, "Adding to cart");
            var body = new URLSearchParams({ action: "add", fabric_id: String(fabricId), quantity: String(quantity) });
            var token = csrfToken();
            if (token) body.append("csrf_token", token);

            fetchJson("/add-to-cart.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: body
            }, 10000).then(function (data) {
                if (!data || !data.success) throw new Error(data && data.message ? data.message : "Could not add to cart.");
                updateCartBadge(data.cart_count);
                if (data.meta_pixel_event && window.amberMetaPixelTrack) {
                    window.amberMetaPixelTrack(data.meta_pixel_event.name, data.meta_pixel_event.payload || {}, data.meta_pixel_event.event_id || "");
                }
                if (data.google_analytics_event && window.amberGoogleAnalyticsTrack) {
                    window.amberGoogleAnalyticsTrack(data.google_analytics_event.name, data.google_analytics_event.payload || {});
                }
                showToast(data.message || "Added to cart!", "success");
                AmberUI.setButtonLoading(button, false, "Adding to cart");
                button.textContent = "Added \u2713";
                window.setTimeout(function () {
                    button.textContent = originalText;
                    button.disabled = false;
                }, 1500);
            }).catch(function (error) {
                var message = error.name === "AbortError"
                    ? "Request timed out. Please try again."
                    : (error instanceof TypeError ? "Network error. Please try again." : error.message);
                showToast(message || "Network error. Please try again.", "error");
                AmberUI.setButtonLoading(button, false, "Adding to cart");
            });
        });
    }

    function init() {
        initConfirmations();
        initSkipLink();
        initFormLoading();
        initDelegatedClicks();
        initRevealAnimations();
        initNativeOffcanvasFallback();
        initNativeCollapseFallback();
        initMobileDrawer();
        initMobileViewport();
        initNavigationState();
        initFilters();
        initAnnouncement();
        initSliders();
        initGoTop();
        initCookieConsent();
        initAjaxCart();
        positionToastRegion();
        window.addEventListener("resize", positionToastRegion, { passive: true });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init, { once: true });
    } else {
        init();
    }
}());
