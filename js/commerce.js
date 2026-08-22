(function () {
  "use strict";

  var initialized = false;
  var AmberUI = window.AmberUI = window.AmberUI || {};

  function csrfToken() {
    var meta = document.querySelector("meta[name='csrf-token']");
    return meta ? meta.content : "";
  }

  function fetchJson(url, options, timeoutMs) {
    var controller = new AbortController();
    var timer = window.setTimeout(function () { controller.abort(); }, timeoutMs || 15000);
    var request = Object.assign({}, options || {}, {
      credentials: "same-origin",
      headers: Object.assign({ "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }, (options && options.headers) || {}),
      signal: controller.signal
    });
    return window.fetch(url, request).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        if (!response.ok || data.success === false) {
          throw new Error(data.message || "The request could not be completed.");
        }
        return data;
      });
    }).finally(function () { window.clearTimeout(timer); });
  }

  function updateCartCount(count) {
    document.querySelectorAll("[data-cart-link]").forEach(function (link) {
      var badge = link.querySelector("[data-cart-badge]");
      if (Number(count) <= 0) {
        if (badge) {
          badge.remove();
        }
        return;
      }
      if (!badge) {
        badge = document.createElement("span");
        badge.className = "cart-count";
        badge.setAttribute("data-cart-badge", "");
        link.appendChild(badge);
      }
      badge.textContent = String(count);
    });
  }

  function initializeCardLinks() {
    document.addEventListener("click", function (event) {
      var card = event.target.closest("[data-product-link]");
      if (!card || event.target.closest("a, button, input, select, textarea, label, form")) {
        return;
      }
      var href = card.getAttribute("data-product-link");
      if (href) {
        window.location.assign(href);
      }
    });
  }

  function initializeAnnouncement() {
    var bar = document.querySelector("[data-ui-announcement]");
    if (!bar) {
      return;
    }
    var messages = Array.prototype.slice.call(bar.querySelectorAll("[data-announcement-message]"));
    var pause = bar.querySelector("[data-announcement-pause]");
    var dismiss = bar.querySelector("[data-announcement-dismiss]");
    var key = bar.getAttribute("data-announcement-key") || "";
    var index = 0;
    var timer = null;
    var paused = false;

    function show(next) {
      messages.forEach(function (message, position) {
        message.hidden = position !== next;
      });
      index = next;
    }

    function stop() {
      window.clearInterval(timer);
      timer = null;
    }

    function start() {
      stop();
      if (!paused && messages.length > 1) {
        timer = window.setInterval(function () { show((index + 1) % messages.length); }, 5000);
      }
    }

    if (pause) {
      pause.addEventListener("click", function () {
        paused = !paused;
        pause.setAttribute("aria-pressed", paused ? "true" : "false");
        pause.textContent = paused ? "Play" : "Pause";
        start();
      });
    }
    if (dismiss) {
      dismiss.addEventListener("click", function () {
        bar.hidden = true;
        stop();
        if (key) {
          var body = new URLSearchParams({ key: key, csrf_token: csrfToken() });
          fetchJson("/announcement-dismiss.php", { method: "POST", body: body }).catch(function () {});
        }
      });
    }
    bar.addEventListener("mouseenter", stop);
    bar.addEventListener("mouseleave", start);
    show(0);
    start();
  }

  function initializeSliders() {
    document.querySelectorAll("[data-ui-slider]").forEach(function (slider) {
      if (slider.getAttribute("data-ui-ready") === "true") {
        return;
      }
      slider.setAttribute("data-ui-ready", "true");
      var track = slider.querySelector("[data-slider-track]");
      var toggle = slider.querySelector("[data-slider-toggle]");
      if (!track) {
        return;
      }
      var timer = null;
      var paused = false;
      var interval = Math.max(2500, Number(slider.getAttribute("data-slider-interval")) || 4500);

      function stepWidth() {
        var item = track.querySelector("[data-slider-item]");
        if (!item) {
          return Math.max(250, track.clientWidth * 0.8);
        }
        var style = window.getComputedStyle(track);
        return item.getBoundingClientRect().width + (parseFloat(style.columnGap || style.gap) || 0);
      }

      function advance() {
        var width = stepWidth();
        var end = track.scrollWidth - track.clientWidth;
        track.scrollTo({ left: track.scrollLeft + width >= end - 4 ? 0 : track.scrollLeft + width, behavior: "smooth" });
      }

      function stop() {
        window.clearInterval(timer);
        timer = null;
      }

      function start() {
        stop();
        if (!paused && track.scrollWidth > track.clientWidth + 4) {
          timer = window.setInterval(advance, interval);
        }
      }

      if (toggle) {
        toggle.addEventListener("click", function () {
          paused = !paused;
          toggle.setAttribute("aria-pressed", paused ? "true" : "false");
          toggle.setAttribute("aria-label", paused ? "Play slider" : "Pause slider");
          start();
        });
      }
      slider.addEventListener("mouseenter", stop);
      slider.addEventListener("mouseleave", start);
      slider.addEventListener("focusin", stop);
      slider.addEventListener("focusout", start);
      document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
          stop();
        } else {
          start();
        }
      });
      start();
    });
  }

  function initializeGoTop() {
    var button = document.querySelector("[data-ui-go-top]");
    if (!button) {
      return;
    }
    var queued = false;
    function sync() {
      button.classList.toggle("is-visible", window.scrollY > 500);
      queued = false;
    }
    window.addEventListener("scroll", function () {
      if (!queued) {
        queued = true;
        window.requestAnimationFrame(sync);
      }
    }, { passive: true });
    button.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
    });
    sync();
  }

  function initializeCookieConsent() {
    var banner = document.querySelector("[data-ui-cookie-consent]");
    if (!banner) {
      return;
    }
    function show(visible) {
      banner.hidden = !visible;
    }
    show((banner.getAttribute("data-consent-status") || "unknown") === "unknown");
    document.addEventListener("click", function (event) {
      var opener = event.target.closest("[data-open-cookie-consent]");
      if (opener) {
        event.preventDefault();
        show(true);
        return;
      }
      var button = event.target.closest("[data-consent-choice]");
      if (!button || !banner.contains(button)) {
        return;
      }
      var choice = button.getAttribute("data-consent-choice") === "accept" ? "granted" : "denied";
      var controls = banner.querySelectorAll("button");
      controls.forEach(function (control) { control.disabled = true; });
      fetchJson("/marketing-consent.php", {
        method: "POST",
        body: new URLSearchParams({ status: choice, csrf_token: csrfToken() })
      }).then(function (data) {
        banner.setAttribute("data-consent-status", data.status || choice);
        show(false);
        window.dispatchEvent(new CustomEvent("amber:consent", { detail: { status: data.status || choice } }));
      }).catch(function (error) {
        if (AmberUI.toast) {
          AmberUI.toast({ type: "error", message: error.message });
        }
      }).finally(function () {
        controls.forEach(function (control) { control.disabled = false; });
      });
    });
  }

  function initializeAjaxCart() {
    document.addEventListener("submit", function (event) {
      var form = event.target.closest("form[data-ajax-cart]");
      if (!form || event.defaultPrevented) {
        return;
      }
      event.preventDefault();
      var button = event.submitter || form.querySelector("[type='submit']");
      if (button && button.getAttribute("aria-busy") === "true") {
        return;
      }
      if (button && AmberUI.setButtonLoading) {
        AmberUI.setButtonLoading(button, true, "Adding…");
      }
      fetchJson(form.action || "/add-to-cart.php", { method: "POST", body: new FormData(form) })
        .then(function (data) {
          updateCartCount(data.cart_count || data.cartCount || 0);
          if (AmberUI.toast) {
            AmberUI.toast({ type: "success", title: "Added to cart", message: data.message || "The item is now in your cart." });
          }
          form.dispatchEvent(new CustomEvent("amber:cart-added", { bubbles: true, detail: data }));
        })
        .catch(function (error) {
          if (AmberUI.toast) {
            AmberUI.toast({ type: "error", title: "Could not add item", message: error.message });
          }
        })
        .finally(function () {
          if (button && AmberUI.setButtonLoading) {
            AmberUI.setButtonLoading(button, false);
          }
        });
    });
  }

  function initializeGallery() {
    document.querySelectorAll("[data-ui-gallery]").forEach(function (gallery) {
      var image = gallery.querySelector("[data-gallery-main]");
      if (!image) {
        return;
      }
      gallery.addEventListener("click", function (event) {
        var thumb = event.target.closest("[data-gallery-src]");
        if (!thumb) {
          return;
        }
        event.preventDefault();
        image.src = thumb.getAttribute("data-gallery-src") || image.src;
        if (thumb.getAttribute("data-gallery-alt")) {
          image.alt = thumb.getAttribute("data-gallery-alt");
        }
        gallery.querySelectorAll("[data-gallery-src]").forEach(function (candidate) {
          candidate.setAttribute("aria-current", candidate === thumb ? "true" : "false");
        });
      });
    });
  }

  function initializeQuantityControls() {
    document.addEventListener("click", function (event) {
      var control = event.target.closest("[data-quantity-change]");
      if (!control) {
        return;
      }
      var root = control.closest("[data-ui-quantity]");
      var input = root && root.querySelector("input[type='number']");
      if (!input) {
        return;
      }
      var direction = Number(control.getAttribute("data-quantity-change")) || 0;
      var step = Number(input.step) || 1;
      var min = input.min === "" ? step : Number(input.min);
      var max = input.max === "" ? Infinity : Number(input.max);
      var value = Math.min(max, Math.max(min, (Number(input.value) || min) + (direction * step)));
      input.value = String(Math.round(value * 100) / 100);
      input.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function initializeCheckoutConfirmation() {
    document.querySelectorAll("form[data-confirm-context='checkout']").forEach(function (form) {
      function refresh() {
        var method = form.querySelector("[name='payment_method']:checked");
        var total = document.querySelector("[data-checkout-payable]");
        var label = method && (method.getAttribute("data-label") || (method.labels && method.labels[0] && method.labels[0].textContent.trim()));
        var message = "Place this order" + (label ? " using " + label : "") + (total ? " for " + total.textContent.trim() : "") + "?";
        form.setAttribute("data-confirm-message", message);
        form.setAttribute("data-confirm-title", "Confirm your order");
        form.setAttribute("data-confirm-ok", "Place Order");
        form.setAttribute("data-confirm-static", "true");
      }
      form.addEventListener("change", refresh);
      form.addEventListener("input", refresh);
      refresh();
    });
  }

  function init() {
    if (initialized || document.body.getAttribute("data-ui-area") !== "storefront") {
      return;
    }
    initialized = true;
    initializeCardLinks();
    initializeAnnouncement();
    initializeSliders();
    initializeGoTop();
    initializeCookieConsent();
    initializeAjaxCart();
    initializeGallery();
    initializeQuantityControls();
    initializeCheckoutConfirmation();
  }

  window.AmberCommerce = { init: init, fetchJson: fetchJson, updateCartCount: updateCartCount, csrfToken: csrfToken };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
}());
