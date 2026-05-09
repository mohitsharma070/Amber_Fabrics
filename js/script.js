document.addEventListener("DOMContentLoaded", function () {
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
});

// ─── Add to Cart (AJAX) ───────────────────────────────────────────────────────
(function () {
    'use strict';

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
