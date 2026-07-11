document.addEventListener("DOMContentLoaded", function () {

    // ── Ripple effect on all .btn clicks ─────────────────────────────────────
    (function () {
        document.addEventListener("click", function (e) {
            var btn = e.target.closest(".btn");
            if (!btn || btn.disabled || btn.classList.contains("is-loading")) return;

            var ripple = document.createElement("span");
            ripple.className = "btn-ripple";

            var rect = btn.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            ripple.style.width  = size + "px";
            ripple.style.height = size + "px";
            ripple.style.left   = (e.clientX - rect.left - size / 2) + "px";
            ripple.style.top    = (e.clientY - rect.top  - size / 2) + "px";

            btn.appendChild(ripple);
            ripple.addEventListener("animationend", function () { ripple.remove(); });
        });
    }());

    // ── Auto loading state: any form submit button shows spinner ─────────────
    (function () {
        document.addEventListener("submit", function (e) {
            var form = e.target;
            if (!form || form.tagName !== "FORM") return;

            // Don't apply to filter/sort forms that auto-submit and need to stay responsive
            if (form.classList.contains("js-no-loading") || form.classList.contains("cart-qty-form")) return;

            // Find the submit button that triggered the form
            var submitBtn = form.querySelector('[type="submit"]:not(.js-no-loading)');
            if (!submitBtn || submitBtn.disabled) return;

            // Store label, set loading
            var original = submitBtn.innerHTML;
            submitBtn.dataset.originalLabel = original;
            submitBtn.classList.add("is-loading");
            submitBtn.disabled = true;

            // Safety: restore after 12 s in case server is slow / page stays
            setTimeout(function () {
                submitBtn.classList.remove("is-loading");
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;
            }, 12000);
        });

        // Restore buttons when browser navigates back (bfcache)
        window.addEventListener("pageshow", function (e) {
            if (e.persisted) {
                document.querySelectorAll(".btn.is-loading").forEach(function (btn) {
                    btn.classList.remove("is-loading");
                    btn.disabled = false;
                    if (btn.dataset.originalLabel) {
                        btn.innerHTML = btn.dataset.originalLabel;
                    }
                });
            }
        });
    }());
    var animated = document.querySelectorAll(".animate-in");
    if (animated.length) {
        if (!("IntersectionObserver" in window)) {
            animated.forEach(function (el) {
                el.classList.add("is-visible");
            });
        } else {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add("is-visible");
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.16 });

            animated.forEach(function (el) {
                observer.observe(el);
            });
        }
    }

    // Storefront mobile drawer behavior (offcanvas)
    (function () {
        var drawer = document.getElementById("mobileNavDrawer");
        if (!drawer) return;

        drawer.addEventListener("shown.bs.offcanvas", function () {
            document.body.classList.add("mobile-nav-open");
        });

        drawer.addEventListener("hidden.bs.offcanvas", function () {
            document.body.classList.remove("mobile-nav-open");
        });

        var drawerLinks = drawer.querySelectorAll("a.nav-link, a.drawer-utility-link");
        drawerLinks.forEach(function (link) {
            link.addEventListener("click", function () {
                var instance = bootstrap.Offcanvas.getInstance(drawer);
                if (instance) {
                    instance.hide();
                }
            });
        });

        var bottomMenuBtn = document.querySelector("[data-mobile-bottom-menu]");
        if (bottomMenuBtn) {
            bottomMenuBtn.addEventListener("click", function () {
                var instance = bootstrap.Offcanvas.getOrCreateInstance(drawer);
                instance.show();
            });
        }
    }());

    // Avoid overlap with product sticky CTA on product detail pages
    (function () {
        if (document.getElementById("product-mobile-cta")) {
            document.body.classList.add("has-product-mobile-cta");
        }
    }());

    // Categories nav active state when on home #catSlider
    (function () {
        var categoryLinks = document.querySelectorAll('a.nav-link[href="/index.php#catSlider"]');
        if (!categoryLinks.length) return;

        function syncCategoriesActive() {
            var onHome = window.location.pathname === "/" || window.location.pathname.endsWith("/index.php");
            var onCategoriesHash = window.location.hash === "#catSlider";
            categoryLinks.forEach(function (link) {
                if (onHome && onCategoriesHash) {
                    link.classList.add("active");
                } else {
                    link.classList.remove("active");
                }
            });
        }

        syncCategoriesActive();
        window.addEventListener("hashchange", syncCategoriesActive);
    }());

    // Admin mobile navbar: auto-close on scroll and link clicks.
    (function () {
        if (!document.body.classList.contains("admin-shell")) return;

        var nav = document.getElementById("adminNav");
        if (!nav || typeof bootstrap === "undefined" || !bootstrap.Collapse) return;

        var toggler = document.querySelector('.admin-nav-toggler[data-bs-target="#adminNav"]');
        var collapse = bootstrap.Collapse.getOrCreateInstance(nav, { toggle: false });

        function isMobileWidth() {
            return window.matchMedia("(max-width: 991.98px)").matches;
        }

        function closeNav() {
            if (!isMobileWidth() || !nav.classList.contains("show")) return;
            collapse.hide();
        }

        nav.querySelectorAll("a.nav-link, a.dropdown-item").forEach(function (link) {
            link.addEventListener("click", function () {
                // Keep parent dropdown toggles open on mobile; close only on real navigation links.
                if (link.classList.contains("dropdown-toggle") || link.getAttribute("data-bs-toggle") === "dropdown") {
                    return;
                }
                closeNav();
            });
        });

        window.addEventListener("scroll", closeNav, { passive: true });
        window.addEventListener("touchmove", closeNav, { passive: true });

        if (toggler) {
            nav.addEventListener("shown.bs.collapse", function () {
                toggler.setAttribute("aria-expanded", "true");
            });
            nav.addEventListener("hidden.bs.collapse", function () {
                toggler.setAttribute("aria-expanded", "false");
            });
        }
    }());

    var filterToggleButtons = document.querySelectorAll(".mobile-filter-toggle");
    filterToggleButtons.forEach(function (button) {
        var targetSelector = button.getAttribute("data-bs-target");
        if (!targetSelector || targetSelector.charAt(0) !== "#") {
            return;
        }

        var target = document.querySelector(targetSelector);
        if (!target) {
            return;
        }

        var showLabel = button.getAttribute("data-show-label") || "Show Filters";
        var hideLabel = button.getAttribute("data-hide-label") || "Hide Filters";

        var syncLabel = function () {
            var isOpen = target.classList.contains("show");
            button.textContent = isOpen ? hideLabel : showLabel;
        };

        target.addEventListener("shown.bs.collapse", syncLabel);
        target.addEventListener("hidden.bs.collapse", syncLabel);

        syncLabel();
    });

    if (document.body.classList.contains("admin-shell")) {
        var adminTables = document.querySelectorAll(".table-responsive > table.table");
        adminTables.forEach(function (table) {
            if (table.classList.contains("admin-no-card-table")) {
                return;
            }
            table.classList.add("admin-card-table");

            var headerCells = table.querySelectorAll("thead th");
            var labels = Array.prototype.map.call(headerCells, function (th) {
                return (th.textContent || "").replace(/\s+/g, " ").trim();
            });

            var rows = table.querySelectorAll("tbody tr");
            rows.forEach(function (row) {
                var cells = row.querySelectorAll("td");
                if (cells.length === 1 && cells[0].hasAttribute("colspan")) {
                    row.classList.add("admin-empty-row");
                    return;
                }

                Array.prototype.forEach.call(cells, function (cell, idx) {
                    if (cell.getAttribute("data-label")) {
                        return;
                    }

                    var label = labels[idx] || "";
                    if (!label) {
                        label = cell.classList.contains("text-end") ? "Actions" : "Field";
                    }
                    cell.setAttribute("data-label", label);
                });
            });
        });
    }

    // Shared auto-submit for filter/sort dropdowns
    (function () {
        var autosubmitSelects = document.querySelectorAll(".js-auto-submit");
        if (!autosubmitSelects.length) return;

        autosubmitSelects.forEach(function (select) {
            select.addEventListener("change", function () {
                if (select.form) {
                    select.form.submit();
                }
            });
        });
    }());

    // Homepage announcements: auto-rotate + dismiss
    (function () {
        var bar = document.getElementById("announceBar");
        var btn = document.getElementById("announceClose");
        var track = document.getElementById("announceTrack");
        if (!bar || !btn) return;
        var announceKey = bar.getAttribute("data-announce-key") || "";

        btn.addEventListener("click", function () {
            bar.style.display = "none";
            if (!announceKey) return;
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var body = "key=" + encodeURIComponent(announceKey);
            if (csrfMeta) {
                body += "&csrf_token=" + encodeURIComponent(csrfMeta.getAttribute('content') || '');
            }
            fetch("/announcement-dismiss.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body
            }).catch(function () {});
        });

        if (!track) return;

        var items = Array.prototype.slice.call(track.children);
        if (!items.length) return;

        var currentIndex = 0;
        var timer = null;
        var INTERVAL = 3600;
        var THEMES = ["theme-teal", "theme-navy", "theme-sunrise", "theme-rose"];

        function applyTheme(index) {
            THEMES.forEach(function (theme) {
                bar.classList.remove(theme);
            });
            bar.classList.add(THEMES[index % THEMES.length]);
        }

        function show(index) {
            if (!items.length) return;
            var safeIndex = ((index % items.length) + items.length) % items.length;
            currentIndex = safeIndex;
            var itemHeight = items[0].offsetHeight || 24;
            track.style.transform = "translateY(" + (-itemHeight * safeIndex) + "px)";
            applyTheme(safeIndex);
        }

        function start() {
            if (timer || items.length < 2) return;
            timer = window.setInterval(function () {
                show(currentIndex + 1);
            }, INTERVAL);
        }

        function stop() {
            if (!timer) return;
            window.clearInterval(timer);
            timer = null;
        }

        bar.addEventListener("mouseenter", stop);
        bar.addEventListener("mouseleave", start);
        bar.addEventListener("focusin", stop);
        bar.addEventListener("focusout", start);
        bar.addEventListener("touchstart", stop, { passive: true });
        bar.addEventListener("touchend", function () {
            window.setTimeout(start, 900);
        }, { passive: true });

        show(0);
        start();

        if (announceKey) {
            fetch("/announcement-dismiss.php?key=" + encodeURIComponent(announceKey), {
                method: "GET",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success && data.dismissed) {
                        bar.style.display = "none";
                        stop();
                    }
                })
                .catch(function () {});
        }
    }());

    // Homepage horizontal sliders: auto-loop + drag/swipe
    (function () {
        var tracks = document.querySelectorAll(".slider-track");
        if (!tracks.length) return;

        var INTERVAL_PRODUCTS = 2600;
        var INTERVAL_CATEGORIES = 3400;

        tracks.forEach(function (track) {
            var originalSlides = Array.prototype.slice.call(track.children);
            if (originalSlides.length < 2) return;
            // Duplicate once for loop illusion (all horizontal sliders).
            originalSlides.forEach(function (slide) {
                var clone = slide.cloneNode(true);
                clone.setAttribute("aria-hidden", "true");
                track.appendChild(clone);
            });

            var timer = null;
            var isDragging = false;
            var dragStartX = 0;
            var dragScrollLeft = 0;
            var resumeHandle = null;

            function itemStepWidth() {
                var first = track.firstElementChild;
                if (!first) return 280;
                var gap = 16;
                try {
                    var computed = window.getComputedStyle(track).columnGap || window.getComputedStyle(track).gap || "16px";
                    var parsed = parseFloat(computed);
                    if (!Number.isNaN(parsed)) gap = parsed;
                } catch (e) {}
                return first.getBoundingClientRect().width + gap;
            }

            function scheduleResume(delayMs) {
                if (resumeHandle) {
                    window.clearTimeout(resumeHandle);
                }
                resumeHandle = window.setTimeout(function () {
                    startAuto();
                }, delayMs);
            }

            function normalizeLoopPosition() {
                var half = track.scrollWidth / 2;
                if (track.scrollLeft >= half) {
                    track.scrollLeft = track.scrollLeft - half;
                }
            }

            function autoStep() {
                var step = itemStepWidth();
                var half = track.scrollWidth / 2;
                if (track.scrollLeft + step >= half) {
                    track.scrollTo({ left: track.scrollLeft + step, behavior: "smooth" });
                    window.setTimeout(normalizeLoopPosition, 420);
                    return;
                }
                track.scrollBy({ left: step, behavior: "smooth" });
            }

            function startAuto() {
                if (timer) return;
                var interval = track.classList.contains("cat-slider-track") ? INTERVAL_CATEGORIES : INTERVAL_PRODUCTS;
                timer = window.setInterval(autoStep, interval);
            }

            function stopAuto() {
                if (timer) {
                    window.clearInterval(timer);
                    timer = null;
                }
            }

            track.addEventListener("mouseenter", function () {
                stopAuto();
            });
            track.addEventListener("mouseleave", function () {
                scheduleResume(700);
            });
            track.addEventListener("focusin", function () {
                stopAuto();
            });
            track.addEventListener("focusout", function () {
                scheduleResume(700);
            });

            track.addEventListener("touchstart", function () {
                stopAuto();
            }, { passive: true });

            track.addEventListener("touchend", function () {
                scheduleResume(1200);
            }, { passive: true });

            track.addEventListener("mousedown", function (e) {
                isDragging = true;
                stopAuto();
                track.style.cursor = "grabbing";
                dragStartX = e.pageX - track.offsetLeft;
                dragScrollLeft = track.scrollLeft;
                e.preventDefault();
            });

            document.addEventListener("mouseup", function () {
                if (!isDragging) return;
                isDragging = false;
                track.style.cursor = "";
                scheduleResume(900);
            });

            track.addEventListener("mousemove", function (e) {
                if (!isDragging) return;
                var x = e.pageX - track.offsetLeft;
                track.scrollLeft = dragScrollLeft - (x - dragStartX);
                normalizeLoopPosition();
            });

            startAuto();
        });
    }());

    // Global go-to-top button
    (function () {
        var btn = document.getElementById("goTopBtn");
        if (!btn) return;

        var SHOW_AFTER = 260;

        function syncVisibility() {
            var y = window.scrollY || document.documentElement.scrollTop || 0;
            if (y > SHOW_AFTER) {
                btn.classList.add("is-visible");
            } else {
                btn.classList.remove("is-visible");
            }
        }

        btn.addEventListener("click", function () {
            window.scrollTo({ top: 0, behavior: "smooth" });
        });

        window.addEventListener("scroll", syncVisibility, { passive: true });
        window.addEventListener("resize", syncVisibility);
        syncVisibility();
    }());

    // Marketing cookie consent banner
    (function () {
        var banner = document.getElementById("cookieConsentBanner");
        if (!banner) return;
        var openTriggers = document.querySelectorAll("[data-open-cookie-consent]");

        var buttons = banner.querySelectorAll("[data-consent-choice]");
        if (!buttons.length) return;

        function showBanner() {
            banner.classList.remove("d-none");
        }

        function hideBanner() {
            banner.classList.add("d-none");
        }

        if (String(banner.getAttribute("data-consent-status") || "").toLowerCase() !== "unknown") {
            hideBanner();
        }

        openTriggers.forEach(function (trigger) {
            trigger.addEventListener("click", function (e) {
                e.preventDefault();
                showBanner();
                banner.scrollIntoView({ behavior: "smooth", block: "end" });
            });
        });

        function setBusy(isBusy) {
            buttons.forEach(function (btn) {
                btn.disabled = !!isBusy;
            });
            banner.style.opacity = isBusy ? "0.75" : "1";
        }

        function submitChoice(choice) {
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = csrfMeta ? (csrfMeta.getAttribute("content") || "") : "";

            var body = new URLSearchParams();
            body.append("choice", choice);
            if (csrfToken) {
                body.append("csrf_token", csrfToken);
            }

            setBusy(true);
            fetch("/cookie-consent.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                credentials: "same-origin",
                body: body.toString()
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error("Consent update failed");
                    }
                    banner.setAttribute("data-consent-status", String(data.status || ""));
                    hideBanner();
                    if (String(data.status || "") === "granted") {
                        window.location.reload();
                    }
                })
                .catch(function () {
                    setBusy(false);
                });
        }

        buttons.forEach(function (btn) {
            btn.addEventListener("click", function () {
                var choice = String(btn.getAttribute("data-consent-choice") || "").toLowerCase();
                if (choice === "accept") {
                    submitChoice("accept");
                    return;
                }
                if (choice === "reject") {
                    submitChoice("reject");
                }
            });
        });
    }());
});

// ─── Add to Cart (AJAX) ───────────────────────────────────────────────────────
(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var card = e.target.closest('.product-click-card');
        if (!card) return;

        if (e.target.closest('a, button, input, select, textarea, label, form')) {
            return;
        }

        var href = card.getAttribute('data-href');
        if (href) {
            window.location.href = href;
        }
    });

    function updateCartBadge(count) {
        var badges = document.querySelectorAll('.cart-badge');
        badges.forEach(function (el) {
            el.textContent = count;
            el.style.display = count > 0 ? '' : 'none';
        });

        // If no badge exists yet and count > 0, add one to the cart nav link
        if (badges.length === 0 && count > 0) {
            var cartLink = document.querySelector('a[href*="cart.php"]');
            if (cartLink) {
                var span = document.createElement('span');
                span.className = 'cart-badge';
                span.textContent = count;
                cartLink.style.position = 'relative';
                cartLink.appendChild(span);
            }
        }
    }

    function showToast(message, type) {
        var existing = document.getElementById('cart-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'cart-toast';
        toast.style.cssText = [
            'position:fixed', 'bottom:1.5rem', 'right:1.5rem', 'z-index:9999',
            'background:' + (type === 'error' ? '#dc2626' : '#0f766e'),
            'color:#fff', 'padding:0.75rem 1.25rem', 'border-radius:8px',
            'box-shadow:0 4px 12px rgba(0,0,0,0.15)',
            'font-size:0.9rem', 'max-width:320px',
            'transition:opacity 0.3s'
        ].join(';');
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.add-to-cart-btn');
        if (!btn) return;

        e.preventDefault();

        var fabricId = parseInt(btn.dataset.fabricId, 10);
        var minQty   = parseInt(btn.dataset.min, 10) || 1;
        var qtySrc   = btn.dataset.qtySrc || btn.dataset.qtysource;
        var qty      = minQty;

        if (qtySrc) {
            var qtyInput = document.getElementById(qtySrc);
            if (qtyInput) qty = Math.max(minQty, parseInt(qtyInput.value, 10) || minQty);
        }

        btn.disabled = true;
        var origText = btn.textContent;
        btn.textContent = 'Adding…';

        var body = new URLSearchParams();
        body.append('action', 'add');
        body.append('fabric_id', fabricId);
        body.append('quantity', qty);

        // CSRF token (if present in page meta tag)
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) body.append('csrf_token', csrfMeta.getAttribute('content'));

        var controller = new AbortController();
        var timeoutId = setTimeout(function () { controller.abort(); }, 10000);

        fetch('/add-to-cart.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
            signal: controller.signal
        })
        .then(function (res) { clearTimeout(timeoutId); return res.json(); })
        .then(function (data) {
            if (data.success) {
                updateCartBadge(data.cart_count);
                if (data.meta_pixel_event && window.amberMetaPixelTrack) {
                    window.amberMetaPixelTrack(
                        data.meta_pixel_event.name,
                        data.meta_pixel_event.payload || {},
                        data.meta_pixel_event.event_id || ''
                    );
                }
                if (data.google_analytics_event && window.amberGoogleAnalyticsTrack) {
                    window.amberGoogleAnalyticsTrack(
                        data.google_analytics_event.name,
                        data.google_analytics_event.payload || {}
                    );
                }
                showToast(data.message || 'Added to cart!', 'success');
                btn.textContent = 'Added ✓';
                setTimeout(function () {
                    btn.textContent = origText;
                    btn.disabled = false;
                }, 1500);
            } else {
                showToast(data.message || 'Could not add to cart.', 'error');
                btn.textContent = origText;
                btn.disabled = false;
            }
        })
        .catch(function () {
            showToast('Network error. Please try again.', 'error');
            btn.textContent = origText;
            btn.disabled = false;
        });
    });
}());
