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
      if (!form) {
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
          if (data.meta_pixel_event && typeof window.amberMetaPixelTrack === "function") {
            window.amberMetaPixelTrack(data.meta_pixel_event.name, data.meta_pixel_event.payload || {}, data.meta_pixel_event.event_id || "");
          }
          if (data.google_analytics_event && typeof window.amberGoogleAnalyticsTrack === "function") {
            window.amberGoogleAnalyticsTrack(data.google_analytics_event.name, data.google_analytics_event.payload || {});
          }
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

  function initializeProductPage() {
    var root = document.querySelector("[data-ui-product]");
    if (!root || root.getAttribute("data-ui-ready") === "true") {
      return;
    }
    root.setAttribute("data-ui-ready", "true");
    var config = AmberUI.parseData ? AmberUI.parseData(root, "product-config", {}) : {};
    var variants = Array.isArray(config.variants) ? config.variants : [];
    var mainImage = document.getElementById("product-main-image");
    var mainVideo = document.getElementById("product-main-video");
    var webpSource = document.getElementById("product-main-webp-source");
    var thumbs = root.querySelector("[data-product-media-thumbs]");
    var defaultImage = mainImage ? mainImage.getAttribute("src") || "" : "";
    var defaultSrcset = webpSource ? webpSource.getAttribute("srcset") || "" : "";
    var quantity = document.getElementById("product_quantity");
    var decrement = document.getElementById("qty_dec");
    var increment = document.getElementById("qty_inc");
    var buyQuantity = document.getElementById("buy_now_quantity");
    var meterLength = document.getElementById("selected_meter_length");
    var meterTotal = document.getElementById("meter_total_quantity");
    var buyMeterLength = document.getElementById("buy_now_meter_length");
    var buyBundleQuantity = document.getElementById("buy_now_bundle_quantity");
    var meterSummary = document.getElementById("meter_purchase_summary");
    var priceBlock = document.getElementById("product_price_block");
    var basePriceNodes = priceBlock ? Array.prototype.map.call(priceBlock.childNodes, function (node) { return node.cloneNode(true); }) : [];
    var colorInput = document.getElementById("selected_color_add");
    var buyColorInput = document.getElementById("selected_color_buy");
    var sizeInput = document.getElementById("selected_size_add");
    var buySizeInput = document.getElementById("selected_size_buy");
    var variantInput = document.getElementById("selected_variant_id_add");
    var buyVariantInput = document.getElementById("selected_variant_id_buy");
    var deliveryVariant = document.getElementById("delivery_variant_id");
    var colorButtons = Array.prototype.slice.call(root.querySelectorAll(".color-swatch-btn"));
    var sizeButtons = Array.prototype.slice.call(root.querySelectorAll(".size-option-btn"));
    var meterButtons = Array.prototype.slice.call(root.querySelectorAll(".meter-option-btn"));
    var sizeSection = document.getElementById("size-picker-section");
    var packSection = document.getElementById("pack-info-section");
    var packLabel = document.getElementById("pack-info-label");
    var stockBadge = document.getElementById("variant-stock-badge");
    var addButton = document.getElementById("add_to_cart_submit");
    var buyButton = document.getElementById("buy_now_submit");
    var isMeter = config.unitType === "meter";
    var isSet = config.unitType === "set";
    var currentPrice = Number(config.basePrice) || 0;
    var currentVariant = null;

    function shortNumber(value) {
      return String(Math.round(Number(value) * 100) / 100);
    }

    function setSelected(buttons, selected, selectedClass, idleClass) {
      buttons.forEach(function (button) {
        var active = button === selected;
        button.classList.toggle(selectedClass, active);
        button.classList.toggle(idleClass, !active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
    }

    function activateMedia(button) {
      if (!button || !mainImage) {
        return;
      }
      Array.prototype.forEach.call(thumbs.querySelectorAll("[data-media-type]"), function (candidate) {
        var active = candidate === button;
        candidate.classList.toggle("u-border-primary", active);
        candidate.classList.toggle("u-border-light", !active);
        candidate.setAttribute("aria-current", active ? "true" : "false");
      });
      var type = button.getAttribute("data-media-type");
      var source = button.getAttribute("data-media-src") || "";
      if (type === "video" && mainVideo) {
        mainImage.classList.add("u-hidden");
        mainVideo.classList.remove("u-hidden");
        if (webpSource) {
          webpSource.srcset = "";
        }
        var videoSource = mainVideo.querySelector("source");
        if (videoSource && videoSource.getAttribute("src") !== source) {
          videoSource.src = source;
          mainVideo.load();
        }
        return;
      }
      if (mainVideo) {
        mainVideo.pause();
        mainVideo.classList.add("u-hidden");
      }
      mainImage.classList.remove("u-hidden");
      mainImage.src = source;
      if (webpSource) {
        webpSource.srcset = button.getAttribute("data-webp-srcset") || "";
      }
    }

    function mediaButton(type, source, index) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "ui-button u-p-0 u-border u-rounded media-thumb product-media-thumb u-border-light";
      button.setAttribute("data-media-type", type);
      button.setAttribute("data-media-src", source);
      button.setAttribute("aria-current", "false");
      if (type === "video") {
        button.classList.add("u-relative");
        button.setAttribute("aria-label", "Play product video");
        var marker = document.createElement("span");
        marker.className = "product-media-thumb-video";
        marker.textContent = "Video";
        button.appendChild(marker);
      } else {
        button.setAttribute("aria-label", "View product image " + (index + 1));
        var image = document.createElement("img");
        image.src = source;
        image.alt = "Product thumbnail " + (index + 1);
        button.appendChild(image);
      }
      return button;
    }

    function renderMedia(images, video) {
      if (!thumbs) {
        if (mainImage && defaultImage) {
          mainImage.src = defaultImage;
          if (webpSource) {
            webpSource.srcset = defaultSrcset;
          }
        }
        return;
      }
      var fragment = document.createDocumentFragment();
      (images || []).forEach(function (filename, index) {
        if (String(filename).trim()) {
          fragment.appendChild(mediaButton("image", "/images/fabrics/" + encodeURIComponent(String(filename).trim()), index));
        }
      });
      if (String(video || "").trim()) {
        fragment.appendChild(mediaButton("video", "/images/fabrics/" + encodeURIComponent(String(video).trim()), (images || []).length));
      }
      thumbs.replaceChildren(fragment);
      var first = thumbs.querySelector("[data-media-type]");
      if (first) {
        activateMedia(first);
      }
    }

    if (thumbs) {
      thumbs.addEventListener("click", function (event) {
        var button = event.target.closest("[data-media-type]");
        if (button && thumbs.contains(button)) {
          activateMedia(button);
        }
      });
    }

    function syncQuantity() {
      if (!quantity || !buyQuantity) {
        return;
      }
      var count = Number(quantity.value);
      if (!Number.isFinite(count) || count < 1) {
        count = 1;
      }
      if (isMeter) {
        count = Math.round(count);
        quantity.value = String(count);
        var length = Number(meterLength ? meterLength.value : 1);
        if (!Number.isFinite(length) || length <= 0) {
          length = 1;
        }
        var total = length * count;
        if (meterTotal) {
          meterTotal.value = shortNumber(total);
        }
        buyQuantity.value = shortNumber(total);
        if (buyBundleQuantity) {
          buyBundleQuantity.value = String(count);
        }
        if (buyMeterLength) {
          buyMeterLength.value = shortNumber(length);
        }
        if (meterSummary) {
          meterSummary.textContent = count + " × " + shortNumber(length) + "m = " + shortNumber(total) + "m" + (currentPrice > 0 ? " | Total: Rs " + (currentPrice * total).toFixed(2) : "");
        }
      } else {
        buyQuantity.value = config.isWholeUnit ? String(Math.round(count)) : shortNumber(count);
      }
      var deliveryQuantity = document.getElementById("delivery_quantity");
      if (deliveryQuantity) {
        deliveryQuantity.value = isMeter && meterTotal ? meterTotal.value : quantity.value;
      }
    }

    function bumpQuantity(direction) {
      if (!quantity || quantity.disabled) {
        return;
      }
      if (quantity.tagName === "SELECT") {
        quantity.selectedIndex = Math.max(0, Math.min(quantity.options.length - 1, quantity.selectedIndex + direction));
      } else {
        var step = Number(quantity.step || 1);
        var next = (Number(quantity.value) || 1) + direction * (Number.isFinite(step) && step > 0 ? step : 1);
        quantity.value = shortNumber(Math.max(1, next));
      }
      syncQuantity();
    }

    if (quantity) {
      quantity.addEventListener("input", syncQuantity);
      quantity.addEventListener("change", syncQuantity);
    }
    if (decrement) {
      decrement.addEventListener("click", function () { bumpQuantity(-1); });
    }
    if (increment) {
      increment.addEventListener("click", function () { bumpQuantity(1); });
    }

    function variantSize(variant) {
      var size = String((variant && variant.size) || "").trim();
      if (size) {
        return size;
      }
      var label = String((variant && variant.pack_label) || "").trim();
      var units = Number(variant && variant.units_per_set);
      return isSet && (label || units > 0) ? (label || "Pack of " + units) : "";
    }

    function findVariant(color, size) {
      var fallback = null;
      for (var index = 0; index < variants.length; index += 1) {
        var variant = variants[index];
        if (Number(variant.is_active) !== 1 || String(variant.color || "") !== color) {
          continue;
        }
        fallback = fallback || variant;
        if (config.hideVariantSize || String(variant.size || "") === size) {
          return variant;
        }
      }
      return String(size || "").trim() === "" ? fallback : null;
    }

    function updatePrice(variant) {
      var override = Number(variant && variant.price_override);
      currentPrice = Number.isFinite(override) && override > 0 ? override : Number(config.basePrice) || 0;
      if (!priceBlock) {
        return;
      }
      if (!(Number.isFinite(override) && override > 0)) {
        priceBlock.replaceChildren.apply(priceBlock, basePriceNodes.map(function (node) { return node.cloneNode(true); }));
        return;
      }
      var price = document.createElement("span");
      price.className = "u-text-large u-font-bold u-text-primary";
      price.textContent = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", minimumFractionDigits: 2 }).format(override) + " / " + (config.unitLabel || "unit");
      priceBlock.replaceChildren(price);
      if (Number(config.regularPrice) > override) {
        var compare = document.createElement("del");
        compare.className = "u-ms-2 u-text-muted";
        compare.textContent = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", minimumFractionDigits: 2 }).format(Number(config.regularPrice)) + " / " + (config.unitLabel || "unit");
        priceBlock.appendChild(compare);
      }
    }

    function availableStock(variant) {
      var stock = Number(isMeter ? variant && variant.stock_meters : variant && variant.stock);
      return Number.isFinite(stock) ? Math.max(0, stock) : 0;
    }

    function updateQuantityAvailability(variant) {
      if (!quantity || variants.length === 0) {
        return true;
      }
      var stock = availableStock(variant);
      var purchasable = false;
      if (!variant) {
        quantity.disabled = true;
      } else if (isMeter) {
        var firstAvailable = null;
        meterButtons.forEach(function (button) {
          var length = Number(button.getAttribute("data-meters"));
          button.disabled = !(length > 0 && length <= stock);
          firstAvailable = firstAvailable || (!button.disabled ? button : null);
        });
        var selectedLength = Number(meterLength && meterLength.value);
        if (!(selectedLength > 0 && selectedLength <= stock) && firstAvailable) {
          selectedLength = Number(firstAvailable.getAttribute("data-meters"));
          if (meterLength) {
            meterLength.value = shortNumber(selectedLength);
          }
          setSelected(meterButtons, firstAvailable, "ui-button--primary", "ui-button--outline");
        }
        var maximum = selectedLength > 0 ? Math.floor((stock + 0.000001) / selectedLength) : 0;
        quantity.max = String(Math.max(0, maximum));
        quantity.value = String(Math.min(Math.max(1, Number(quantity.value) || 1), Math.max(1, maximum)));
        purchasable = maximum >= 1;
      } else if (quantity.tagName === "SELECT") {
        var previous = quantity.value;
        var minimum = Math.max(1, Math.ceil(Number(config.minimumOrderQuantity) || 1));
        var step = Math.max(1, Math.round(Number(config.quantityStep) || 1));
        var fragment = document.createDocumentFragment();
        for (var count = minimum; count <= Math.min(Math.floor(stock), 20); count += step) {
          var option = document.createElement("option");
          option.value = String(count);
          option.textContent = String(count);
          fragment.appendChild(option);
        }
        quantity.replaceChildren(fragment);
        purchasable = quantity.options.length > 0;
        if (purchasable && Array.prototype.some.call(quantity.options, function (option) { return option.value === previous; })) {
          quantity.value = previous;
        }
      }
      quantity.disabled = !purchasable;
      if (decrement) { decrement.disabled = !purchasable; }
      if (increment) { increment.disabled = !purchasable; }
      syncQuantity();
      return purchasable;
    }

    function displayStock(variant) {
      if (!stockBadge || variants.length === 0) {
        return;
      }
      stockBadge.replaceChildren();
      if (!variant) {
        return;
      }
      var stock = availableStock(variant);
      var badge = document.createElement("span");
      badge.className = "ui-badge " + (stock > 0 ? "ui-badge--success" : "ui-badge--neutral");
      badge.textContent = stock > 0 ? "In Stock (" + shortNumber(stock) + ")" : "Out of Stock";
      stockBadge.appendChild(badge);
    }

    function updateVariant(color, size) {
      var variant = variants.length ? findVariant(color, size) : null;
      currentVariant = variant;
      var id = variant ? String(variant.id) : "0";
      if (colorInput) { colorInput.value = color; }
      if (buyColorInput) { buyColorInput.value = color; }
      if (sizeInput) { sizeInput.value = size; }
      if (buySizeInput) { buySizeInput.value = size; }
      if (variantInput) {
        variantInput.value = id;
        variantInput.dispatchEvent(new Event("change", { bubbles: true }));
      }
      if (buyVariantInput) { buyVariantInput.value = id; }
      if (deliveryVariant) { deliveryVariant.value = id; }
      updatePrice(variant);
      if (packSection && packLabel && isSet) {
        var label = variantSize(variant);
        packLabel.textContent = label;
        packSection.hidden = !label;
      }
      if (variant) {
        var images = [variant.image, variant.image2, variant.image3, variant.image4].filter(function (item) { return String(item || "").trim() !== ""; });
        renderMedia(images.length || variant.video ? images : config.galleryImages, images.length || variant.video ? variant.video : config.videoFile);
      } else if (variants.length) {
        renderMedia(config.galleryImages, config.videoFile);
      }
      displayStock(variant);
      var canBuy = variants.length ? updateQuantityAvailability(variant) : true;
      if (addButton) { addButton.disabled = !canBuy; }
      if (buyButton) { buyButton.disabled = !canBuy; }
    }

    function activateColor(color, preferredSize) {
      var selectedColor = colorButtons.find(function (button) { return button.getAttribute("data-color") === color; });
      setSelected(colorButtons, selectedColor, "ui-button--navy", "ui-button--secondary");
      var validSizes = variants.filter(function (variant) {
        return Number(variant.is_active) === 1 && String(variant.color || "") === color && variantSize(variant) !== "";
      }).map(variantSize);
      if (sizeSection) {
        sizeSection.hidden = config.hideVariantSize || validSizes.length === 0;
      }
      sizeButtons.forEach(function (button) {
        button.hidden = validSizes.indexOf(button.getAttribute("data-size") || "") === -1;
      });
      var size = validSizes.indexOf(preferredSize) !== -1 ? preferredSize : (validSizes[0] || "");
      var selectedSize = sizeButtons.find(function (button) { return !button.hidden && button.getAttribute("data-size") === size; });
      setSelected(sizeButtons, selectedSize, "ui-button--navy", "ui-button--secondary");
      updateVariant(color, config.hideVariantSize ? "" : size);
    }

    colorButtons.forEach(function (button) {
      button.addEventListener("click", function () { activateColor(button.getAttribute("data-color") || "", ""); });
    });
    sizeButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        if (button.hidden) {
          return;
        }
        setSelected(sizeButtons, button, "ui-button--navy", "ui-button--secondary");
        var value = button.getAttribute("data-size") || "";
        if (variants.length) {
          updateVariant(colorInput ? colorInput.value : "", value);
        } else {
          if (sizeInput) { sizeInput.value = value; }
          if (buySizeInput) { buySizeInput.value = value; }
        }
      });
    });
    meterButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        var value = Number(button.getAttribute("data-meters"));
        if (!(value > 0)) {
          return;
        }
        setSelected(meterButtons, button, "ui-button--primary", "ui-button--outline");
        if (meterLength) { meterLength.value = shortNumber(value); }
        if (buyMeterLength) { buyMeterLength.value = shortNumber(value); }
        if (currentVariant) {
          updateQuantityAvailability(currentVariant);
        } else {
          syncQuantity();
        }
      });
    });

    if (variants.length) {
      activateColor(colorInput ? colorInput.value : String(variants[0].color || ""), sizeInput ? sizeInput.value : "");
    } else {
      var initialSize = sizeButtons.find(function (button) { return button.getAttribute("aria-pressed") === "true"; });
      if (initialSize) {
        setSelected(sizeButtons, initialSize, "ui-button--navy", "ui-button--secondary");
      }
    }
    syncQuantity();
  }

  function initializeDeliveryEstimate() {
    var form = document.querySelector("form[data-ui-delivery-estimate]");
    if (!form) {
      return;
    }
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var output = document.getElementById("pdp_delivery_result");
      var button = event.submitter || form.querySelector("[type='submit']");
      var selectedVariant = document.getElementById("selected_variant_id_add");
      var quantity = document.getElementById("meter_total_quantity") || document.getElementById("product_quantity");
      var variantField = document.getElementById("delivery_variant_id");
      var quantityField = document.getElementById("delivery_quantity");
      if (variantField) { variantField.value = selectedVariant ? selectedVariant.value : "0"; }
      if (quantityField) { quantityField.value = quantity ? quantity.value : "1"; }
      if (output) { output.textContent = "Checking…"; }
      if (button && AmberUI.setButtonLoading) { AmberUI.setButtonLoading(button, true, "Checking…"); }
      fetchJson("/delivery-estimate", { method: "POST", body: new FormData(form) })
        .then(function (data) {
          if (!output) { return; }
          var parts = [
            data.serviceability_status === "live" ? "Live courier rate" : "Estimated shipping",
            "Dispatch " + data.estimated_dispatch_label,
            "Delivery " + data.estimated_delivery_label,
            Number(data.shipping_total) > 0 ? "Shipping Rs " + Number(data.shipping_total).toFixed(2) : "Free shipping"
          ];
          if (data.payment_method === "cod" && Number(data.cod_fee) > 0) {
            parts.push("includes COD fee Rs " + Number(data.cod_fee).toFixed(2));
          }
          if (data.courier_name) { parts.push(data.courier_name); }
          output.textContent = parts.join(" · ");
        })
        .catch(function (error) {
          if (output) { output.textContent = error.message || "Unable to check delivery right now."; }
        })
        .finally(function () {
          if (button && AmberUI.setButtonLoading) { AmberUI.setButtonLoading(button, false); }
        });
    });
  }

  function initializeBackInStock() {
    var block = document.querySelector("[data-ui-back-in-stock]");
    var selected = document.getElementById("selected_variant_id_add");
    var target = document.getElementById("back_in_stock_alert_variant_id");
    var product = document.querySelector("[data-ui-product]");
    if (!block || !selected || !target || !product) {
      return;
    }
    var config = AmberUI.parseData ? AmberUI.parseData(product, "product-config", {}) : {};
    var variants = Array.isArray(config.variants) ? config.variants : [];
    var unitType = block.getAttribute("data-unit-type") || "piece";
    function sync() {
      var id = Number(selected.value || 0);
      var variant = variants.find(function (item) { return Number(item.id) === id; });
      var stock = Number(unitType === "meter" && variant ? variant.stock_meters : variant && variant.stock);
      var shouldShow = Boolean(variant && Number(variant.is_active) === 1 && !(Number.isFinite(stock) && stock > 0));
      block.hidden = !shouldShow;
      target.value = shouldShow ? String(variant.id) : "0";
    }
    selected.addEventListener("change", sync);
    sync();
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

  function initializeCartQuantities() {
    document.querySelectorAll("form[data-ui-cart-quantity]").forEach(function (form) {
      var input = form.querySelector("input[name='quantity'], input[name='bundle_quantity']");
      var decrease = form.querySelector(".qty-dec");
      var increase = form.querySelector(".qty-inc");
      if (!input || !decrease || !increase) {
        return;
      }

      function normalize(raw) {
        var minimum = Number(input.min || 1);
        var maximum = input.max === "" ? Infinity : Number(input.max);
        var step = Number(input.step || 1);
        var value = Number(raw);
        if (!Number.isFinite(value)) {
          value = minimum;
        }
        value = Math.max(minimum, Math.min(maximum, value));
        return step >= 1 ? String(Math.round(value)) : String(Math.round(value * 100) / 100);
      }

      function apply(delta) {
        var step = Number(input.step || 1);
        input.value = normalize(Number(input.value || input.min || 1) + (delta * (Number.isFinite(step) && step > 0 ? step : 1)));
        form.requestSubmit();
      }

      decrease.addEventListener("click", function () { apply(-1); });
      increase.addEventListener("click", function () { apply(1); });
      input.addEventListener("change", function () {
        input.value = normalize(input.value);
        form.requestSubmit();
      });
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

  function initializeCheckout() {
    var form = document.querySelector("form[data-ui-checkout]");
    if (!form || form.getAttribute("data-ui-ready") === "true") {
      return;
    }
    form.setAttribute("data-ui-ready", "true");
    var config = AmberUI.parseData ? AmberUI.parseData(form, "checkout-config", {}) : {};
    var byId = function (id) { return document.getElementById(id); };
    var cod = byId("payment_cod");
    var online = byId("payment_razorpay");
    var country = form.querySelector("[name='country']");
    var name = byId("checkout_full_name");
    var phone = byId("checkout_phone");
    var email = byId("checkout_email");
    var address = byId("checkout_address");
    var city = byId("checkout_city");
    var state = byId("checkout_state");
    var pincode = byId("checkout_pincode");
    var addressId = byId("shipping_address_id");
    var savedAddresses = byId("saved_address_select");
    var quoteToken = byId("shipping_quote_token");
    var shippingAmount = byId("summary_shipping");
    var codFee = byId("summary_cod_fee");
    var total = byId("summary_total");
    var mobileTotal = byId("mobile_summary_total");
    var shippingNote = byId("summary_shipping_note");
    var deliveryEstimate = byId("checkout_delivery_estimate");
    var deliveryStatus = byId("checkout_delivery_status");
    var continueButton = byId("checkout_continue_payment");
    var submitButton = byId("checkout_submit");
    var mobileSubmitButton = byId("mobile_place_order_btn");
    var mobileSubmitLabel = byId("mobile_place_order_label");
    var paymentSection = byId("checkout_section_payment");
    var reviewSection = byId("checkout_review_section");
    var mobileReview = byId("checkout_mobile_review_section");
    var addressSection = byId("checkout_section_address");
    var addressBody = byId("checkout_address_body");
    var addressSummary = byId("checkout_address_summary");
    var addressEdit = byId("checkout_edit_address");
    var paymentBody = byId("checkout_payment_body");
    var paymentSummary = byId("checkout_payment_summary");
    var paymentEdit = byId("checkout_edit_payment");
    var onlineMethod = byId("online_method");
    var createAccount = byId("create_account");
    var accountFields = byId("create_account_fields");
    var accountPassword = byId("create_account_password");
    var accountConfirm = byId("create_account_confirm_password");
    var whatsappWrap = byId("cod_whatsapp_consent_wrap");
    var whatsapp = byId("cod_whatsapp_consent");
    var paymentCards = Array.prototype.slice.call(document.querySelectorAll("[data-pay-option]"));
    var onlineButtons = Array.prototype.slice.call(document.querySelectorAll("[data-online-method]"));
    var onlinePanels = Array.prototype.slice.call(document.querySelectorAll("[data-online-panel]"));
    var codPanel = byId("cod-panel");
    var razorpayPanel = byId("razorpay-panel");
    var abortController = null;
    var requestSerial = 0;
    var deliveryUnlocked = Boolean(config.deliveryUnlocked);
    var requestPending = false;
    var checkoutTotal = Number(config.currentTotal) || 0;

    if (!cod || !online || !country || !shippingAmount || !codFee || !total) {
      return;
    }

    function conceal(element, hidden) {
      if (!element) { return; }
      element.classList.toggle("u-hidden", hidden);
      element.setAttribute("aria-hidden", hidden ? "true" : "false");
    }

    function money(value) {
      return "Rs " + Number(value || 0).toFixed(2);
    }

    function selectedPayment() {
      return cod.checked ? "cod" : "razorpay";
    }

    function setFieldValidity(field, invalid) {
      if (!field) { return; }
      field.classList.toggle("is-invalid", invalid);
      field.setAttribute("aria-invalid", invalid ? "true" : "false");
    }

    function validateAddress() {
      var checks = [
        [name, String(name ? name.value : "").trim() === ""],
        [phone, !/^[0-9+\-\s()]{7,20}$/.test(String(phone ? phone.value : "").trim())],
        [email, !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email ? email.value : "").trim())],
        [address, String(address ? address.value : "").trim() === ""],
        [city, String(city ? city.value : "").trim() === ""],
        [state, String(state ? state.value : "").trim() === ""],
        [pincode, !/^[1-9][0-9]{5}$/.test(String(pincode ? pincode.value : "").trim())]
      ];
      checks.forEach(function (check) { setFieldValidity(check[0], check[1]); });
      return !checks.some(function (check) { return check[1]; });
    }

    function focusFirstInvalid() {
      var field = form.querySelector(".is-invalid");
      if (field) {
        field.focus({ preventScroll: true });
        field.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "center" });
      }
    }

    function whatsappRequired() {
      return Boolean(whatsapp && cod.checked && checkoutTotal >= Number(config.codWhatsappThreshold || 0));
    }

    function syncWhatsapp() {
      var required = whatsappRequired();
      conceal(whatsappWrap, !required);
      if (whatsapp) {
        whatsapp.required = required;
        whatsapp.setAttribute("aria-required", required ? "true" : "false");
        if (!required) { setFieldValidity(whatsapp, false); }
      }
    }

    function shippingFallbackMessage(reason, detail) {
      var messages = {
        shipping_quote_refreshing: "Updating live shipping rate…",
        shipping_courier_disabled: "Manual shipping is active because live courier rates are disabled.",
        shipping_courier_not_configured: "Manual shipping is active because the courier service is not configured.",
        shipping_quote_context_invalid: "Enter a valid delivery pincode to calculate live shipping.",
        bigship_origin_or_parcel_invalid: "Manual shipping is active because parcel details need attention.",
        bigship_rate_api_failed: "Live courier pricing is temporarily unavailable; manual shipping is being used.",
        bigship_rate_unavailable: "No live courier rate is available for this order; manual shipping is being used."
      };
      return messages[reason] || (detail ? "Manual shipping fallback: " + detail : "Manual shipping active. Free shipping above Rs 999; otherwise Rs 70. COD adds Rs 50 handling fee.");
    }

    function setShippingMessage(source, courierName, reason, detail) {
      if (!shippingNote) { return; }
      if (String(source || "").toLowerCase() !== "manual" && String(source || "") !== "") {
        shippingNote.textContent = courierName ? "Live courier rate active: " + courierName + "." : "Live courier rate active.";
      } else {
        shippingNote.textContent = shippingFallbackMessage(reason, detail);
      }
    }

    function syncSummary() {
      var taxable = Math.max(0, Number(config.subtotal || 0) - Number(config.discount || 0));
      if (!deliveryUnlocked) {
        checkoutTotal = taxable;
        shippingAmount.textContent = "—";
        codFee.textContent = "—";
        total.textContent = money(taxable);
        if (mobileTotal) { mobileTotal.textContent = money(taxable); }
        if (deliveryEstimate) { deliveryEstimate.textContent = ""; }
        if (shippingNote) { shippingNote.textContent = "Enter your delivery address and pincode to calculate shipping."; }
      } else {
        checkoutTotal = Number(String(total.textContent || "").replace(/[^0-9.]/g, "")) || taxable;
      }
      var prefix = cod.checked ? "Place COD Order — " : "Pay Securely — ";
      if (submitButton) { submitButton.textContent = prefix + money(checkoutTotal); }
      if (mobileSubmitLabel) { mobileSubmitLabel.textContent = prefix; }
      syncWhatsapp();
    }

    function setUnlocked(unlocked) {
      deliveryUnlocked = Boolean(unlocked);
      conceal(paymentSection, !deliveryUnlocked);
      conceal(reviewSection, !deliveryUnlocked);
      conceal(mobileReview, !deliveryUnlocked);
      if (!deliveryUnlocked && quoteToken) { quoteToken.value = ""; }
      syncSummary();
    }

    function setPending(pending) {
      requestPending = Boolean(pending);
      if (continueButton && AmberUI.setButtonLoading) {
        AmberUI.setButtonLoading(continueButton, requestPending, "Checking delivery…");
      } else if (continueButton) {
        continueButton.disabled = requestPending;
      }
      if (submitButton) { submitButton.disabled = requestPending; }
      if (mobileSubmitButton) { mobileSubmitButton.disabled = requestPending; }
    }

    function invalidateQuote(message) {
      requestSerial += 1;
      if (abortController) {
        abortController.abort();
        abortController = null;
      }
      setPending(false);
      setUnlocked(false);
      if (deliveryStatus && message !== false) {
        deliveryStatus.textContent = message || "Delivery details changed. Continue again to refresh shipping.";
      }
    }

    function quoteShipping() {
      var destination = String(country.value || "").trim().toLowerCase();
      var postalCode = String(pincode ? pincode.value : "").trim();
      if (destination !== "india" || !/^[1-9][0-9]{5}$/.test(postalCode)) {
        setShippingMessage("manual", "", "shipping_quote_context_invalid", "");
        return Promise.resolve(false);
      }
      if (abortController) { abortController.abort(); }
      abortController = new AbortController();
      var serial = ++requestSerial;
      var context = destination + "|" + postalCode + "|" + selectedPayment();
      var body = new URLSearchParams({ csrf_token: csrfToken(), pincode: postalCode, payment_method: selectedPayment() });
      if (quoteToken) { quoteToken.value = ""; }
      setPending(true);
      if (shippingNote) { shippingNote.textContent = "Checking delivery service and shipping…"; }
      return window.fetch("/shipping-rate.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
        signal: abortController.signal
      }).then(function (response) {
        if (!response.ok) { throw new Error("shipping_rate_failed"); }
        return response.json();
      }).then(function (data) {
        var currentContext = String(country.value || "").trim().toLowerCase() + "|" + String(pincode ? pincode.value : "").trim() + "|" + selectedPayment();
        if (serial !== requestSerial || currentContext !== context) { return false; }
        if (!data || !data.ok) { throw new Error("shipping_rate_failed"); }
        var shipping = Number(data.base_shipping || 0);
        var handling = Number(data.cod_fee || 0);
        checkoutTotal = Math.max(0, Number(config.subtotal || 0) - Number(config.discount || 0)) + shipping + handling;
        shippingAmount.textContent = money(shipping);
        codFee.textContent = money(handling);
        total.textContent = money(checkoutTotal);
        if (mobileTotal) { mobileTotal.textContent = money(checkoutTotal); }
        if (quoteToken) { quoteToken.value = String(data.quote_token || ""); }
        if (deliveryEstimate && data.estimated_delivery_label) { deliveryEstimate.textContent = "Estimated delivery: " + data.estimated_delivery_label; }
        setShippingMessage(data.source || "manual", data.courier_name || "", data.debug_reason || "", data.debug_message || "");
        setUnlocked(Boolean(data.quote_token));
        if (deliveryStatus) { deliveryStatus.textContent = data.serviceability_status === "live" ? "Delivery address verified with a live courier rate." : "Delivery address verified with an estimated shipping rate."; }
        if (typeof window.gtag === "function") { window.gtag("event", "add_shipping_info", { currency: "INR", value: checkoutTotal, shipping_tier: data.source || "manual" }); }
        return Boolean(data.quote_token);
      }).catch(function (error) {
        if (error && error.name === "AbortError") { return false; }
        if (serial === requestSerial) { setShippingMessage("manual", "", "bigship_rate_api_failed", ""); }
        return false;
      }).finally(function () {
        if (serial === requestSerial) {
          abortController = null;
          setPending(false);
        }
      });
    }

    function applySavedAddress(option) {
      if (!option) { return; }
      var id = String(option.value || "");
      if (addressId) { addressId.value = id; }
      if (!id) { return; }
      [[name, "data-full-name"], [phone, "data-phone"], [address, "data-address"], [city, "data-city"], [state, "data-state"], [pincode, "data-pincode"]].forEach(function (pair) {
        if (pair[0]) { pair[0].value = option.getAttribute(pair[1]) || ""; }
      });
      country.value = "India";
    }

    function sectionCollapsed(section, body, summary, edit, collapsed) {
      if (!section || !body || !summary || !edit) { return; }
      section.classList.toggle("checkout-section-collapsed", collapsed);
      conceal(body, collapsed);
      conceal(summary, !collapsed);
      conceal(edit, !collapsed);
    }

    function updateSummaries() {
      if (addressSummary) {
        addressSummary.textContent = [name && name.value, phone && phone.value, [city && city.value, pincode && pincode.value].filter(Boolean).join(" - ")].filter(Boolean).join(" | ");
      }
      if (paymentSummary) { paymentSummary.textContent = cod.checked ? "Cash on Delivery" : "Online Payment (Razorpay)"; }
    }

    function syncPaymentPanels() {
      var selected = selectedPayment();
      paymentCards.forEach(function (card) { card.classList.toggle("is-active", card.getAttribute("data-pay-option") === selected); });
      if (codPanel) { codPanel.classList.toggle("is-open", selected === "cod"); }
      if (razorpayPanel) { razorpayPanel.classList.toggle("is-open", selected === "razorpay"); }
      if (onlineMethod && selected === "cod") { onlineMethod.value = ""; }
      syncSummary();
    }

    function activateOnlineMethod(method) {
      onlineButtons.forEach(function (button) {
        var selected = button.getAttribute("data-online-method") === method;
        button.classList.toggle("is-active", selected);
        button.setAttribute("aria-pressed", selected ? "true" : "false");
      });
      onlinePanels.forEach(function (panel) { panel.classList.toggle("is-active", panel.getAttribute("data-online-panel") === method); });
      if (onlineMethod) { onlineMethod.value = method || "upi"; }
    }

    function syncAccountFields() {
      if (!createAccount || !accountFields) { return; }
      conceal(accountFields, !createAccount.checked);
      if (accountPassword) { accountPassword.required = createAccount.checked; }
      if (accountConfirm) { accountConfirm.required = createAccount.checked; }
    }

    function preserveState(couponForm) {
      var notes = form.querySelector("[name='order_notes']");
      var values = {
        full_name: name ? name.value : "", phone: phone ? phone.value : "", email: email ? email.value : "",
        address: address ? address.value : "", city: city ? city.value : "", state: state ? state.value : "",
        pincode: pincode ? pincode.value : "", country: "India", order_notes: notes ? notes.value : "",
        payment_method: selectedPayment(), online_method: onlineMethod ? onlineMethod.value : "",
        shipping_address_id: addressId ? addressId.value : "0", cod_whatsapp_consent: whatsapp && whatsapp.checked ? "1" : "0"
      };
      Object.keys(values).forEach(function (fieldName) {
        var field = couponForm.querySelector("input[type='hidden'][name='" + fieldName + "']");
        if (!field) {
          field = document.createElement("input");
          field.type = "hidden";
          field.name = fieldName;
          couponForm.appendChild(field);
        }
        field.value = values[fieldName];
      });
    }

    if (savedAddresses) {
      savedAddresses.addEventListener("change", function () { applySavedAddress(savedAddresses.options[savedAddresses.selectedIndex]); invalidateQuote(); });
      if (savedAddresses.value) { applySavedAddress(savedAddresses.options[savedAddresses.selectedIndex]); }
    }
    [name, phone, address, city, state, pincode, country].forEach(function (field) {
      if (!field) { return; }
      field.addEventListener("input", function () {
        if (addressId) { addressId.value = ""; }
        if (savedAddresses) { savedAddresses.value = ""; }
        invalidateQuote();
      });
    });
    [cod, online].forEach(function (radio) {
      radio.addEventListener("change", function () {
        if (!radio.checked) { return; }
        syncPaymentPanels();
        invalidateQuote(false);
        if (validateAddress()) { quoteShipping(); }
        if (typeof window.gtag === "function") { window.gtag("event", "add_payment_info", { currency: "INR", value: checkoutTotal, payment_type: radio.value }); }
      });
    });
    onlineButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        activateOnlineMethod(button.getAttribute("data-online-method") || "upi");
        online.checked = true;
        online.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
    if (continueButton) {
      continueButton.addEventListener("click", function () {
        if (!validateAddress()) {
          setUnlocked(false);
          if (deliveryStatus) { deliveryStatus.textContent = "Please complete the highlighted delivery fields."; }
          focusFirstInvalid();
          return;
        }
        quoteShipping().then(function (quoted) {
          if (!quoted) {
            setUnlocked(false);
            if (deliveryStatus) { deliveryStatus.textContent = "We could not calculate shipping. Please check the pincode and try again."; }
            return;
          }
          updateSummaries();
          sectionCollapsed(addressSection, addressBody, addressSummary, addressEdit, true);
          if (paymentSection) { paymentSection.scrollIntoView({ behavior: "smooth", block: "start" }); }
        });
      });
    }
    if (addressEdit) { addressEdit.addEventListener("click", function () { sectionCollapsed(addressSection, addressBody, addressSummary, addressEdit, false); if (name) { name.focus(); } }); }
    if (paymentEdit) { paymentEdit.addEventListener("click", function () { sectionCollapsed(paymentSection, paymentBody, paymentSummary, paymentEdit, false); cod.focus(); }); }
    if (createAccount) { createAccount.addEventListener("change", syncAccountFields); }
    document.querySelectorAll("[data-preserve-checkout-state]").forEach(function (couponForm) { couponForm.addEventListener("submit", function () { preserveState(couponForm); }); });
    if (whatsapp) { whatsapp.addEventListener("change", function () { setFieldValidity(whatsapp, whatsappRequired() && !whatsapp.checked); }); }

    form.addEventListener("amber:validate", function (event) {
      updateSummaries();
      var addressValid = validateAddress();
      var whatsappValid = !whatsappRequired() || Boolean(whatsapp && whatsapp.checked);
      setFieldValidity(whatsapp, !whatsappValid);
      var quoteValid = deliveryUnlocked && quoteToken && quoteToken.value !== "" && !requestPending;
      if (!addressValid || !whatsappValid || !quoteValid) {
        event.preventDefault();
        sectionCollapsed(addressSection, addressBody, addressSummary, addressEdit, false);
        sectionCollapsed(paymentSection, paymentBody, paymentSummary, paymentEdit, false);
        if (!addressValid) { focusFirstInvalid(); }
        else if (!whatsappValid && whatsapp) { whatsapp.focus(); }
        else if (deliveryStatus) { deliveryStatus.textContent = "Continue to payment again so we can confirm shipping."; }
      }
    });

    updateSummaries();
    activateOnlineMethod(onlineMethod && onlineMethod.value ? onlineMethod.value : "upi");
    syncAccountFields();
    syncPaymentPanels();
    setShippingMessage(config.shippingSource, config.shippingCourierName, config.shippingDebugReason, config.shippingDebugMessage);
    setUnlocked(deliveryUnlocked);
    focusFirstInvalid();
  }

  function initializeRazorpay() {
    var root = document.querySelector("[data-ui-razorpay]");
    if (!root || root.getAttribute("data-ui-ready") === "true") {
      return;
    }
    root.setAttribute("data-ui-ready", "true");
    var config = AmberUI.parseData ? AmberUI.parseData(root, "payment-config", null) : null;
    var button = document.getElementById("rzpPayBtn");
    var hint = document.getElementById("rzpPayHint");
    var progress = document.getElementById("rzpPayLoading");
    var submitting = false;
    var opened = false;
    if (!config || !button) {
      return;
    }

    function setLoading(active) {
      if (AmberUI.setButtonLoading) {
        AmberUI.setButtonLoading(button, active, "Processing payment…");
      } else {
        button.disabled = active;
      }
      if (hint) {
        hint.classList.toggle("u-hidden", active);
      }
      if (progress) {
        progress.classList.toggle("u-hidden", !active);
      }
    }

    function post(url, payload) {
      if (submitting) {
        return;
      }
      submitting = true;
      setLoading(true);
      var form = document.createElement("form");
      form.method = "POST";
      form.action = url;
      Object.keys(payload).forEach(function (name) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = payload[name] == null ? "" : String(payload[name]);
        form.appendChild(input);
      });
      var csrf = document.createElement("input");
      csrf.type = "hidden";
      csrf.name = "csrf_token";
      csrf.value = String(config.csrfToken || csrfToken());
      form.appendChild(csrf);
      document.body.appendChild(form);
      form.submit();
    }

    function methodOptions(preference) {
      var methods = ["upi", "card", "netbanking", "wallet", "emi", "paylater"];
      if (methods.indexOf(preference) === -1) {
        return undefined;
      }
      return methods.reduce(function (result, method) {
        result[method] = method === preference;
        return result;
      }, {});
    }

    function open() {
      if (submitting || opened) {
        return;
      }
      if (typeof window.Razorpay !== "function") {
        if (AmberUI.toast) {
          AmberUI.toast({ type: "error", message: "Secure payment could not load. Please check your connection and retry." });
        }
        return;
      }
      opened = true;
      var options = {
        key: config.key,
        amount: Number(config.amount),
        currency: config.currency || "INR",
        name: config.name || "Amber Fabrics",
        description: config.description || "Order payment",
        order_id: config.orderId,
        prefill: config.prefill || {},
        method: methodOptions(config.preferredMethod),
        theme: { color: config.themeColor || "#0f766e" },
        handler: function (response) {
          post(config.verifyUrl || "/payment/razorpay-verify.php", {
            razorpay_payment_id: response.razorpay_payment_id || "",
            razorpay_order_id: response.razorpay_order_id || "",
            razorpay_signature: response.razorpay_signature || ""
          });
        },
        modal: {
          ondismiss: function () {
            opened = false;
            if (!submitting) {
              post(config.failureUrl || "/payment/razorpay-failure.php", {
                event_type: "cancelled",
                razorpay_order_id: config.orderId || ""
              });
            }
          }
        }
      };
      var checkout = new window.Razorpay(options);
      checkout.on("payment.failed", function (response) {
        if (submitting) {
          return;
        }
        var error = response && response.error ? response.error : {};
        var metadata = error.metadata || {};
        post(config.failureUrl || "/payment/razorpay-failure.php", {
          event_type: "failed",
          razorpay_payment_id: metadata.payment_id || "",
          razorpay_order_id: metadata.order_id || config.orderId || "",
          error_code: error.code || "",
          error_description: error.description || ""
        });
      });
      checkout.open();
    }

    button.addEventListener("click", open);
    function autoOpen() {
      window.setTimeout(open, 250);
    }
    if (document.readyState === "complete") {
      autoOpen();
    } else {
      window.addEventListener("load", autoOpen, { once: true });
    }
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
    initializeProductPage();
    initializeDeliveryEstimate();
    initializeBackInStock();
    initializeQuantityControls();
    initializeCartQuantities();
    initializeCheckout();
    initializeCheckoutConfirmation();
    initializeRazorpay();
  }

  window.AmberCommerce = { init: init, fetchJson: fetchJson, updateCartCount: updateCartCount, csrfToken: csrfToken };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
}());
