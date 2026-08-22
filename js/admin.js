(function () {
  "use strict";

  var AmberUI = window.AmberUI = window.AmberUI || {};
  var dialogState = new WeakMap();

  function one(selector, scope) { return (scope || document).querySelector(selector); }
  function all(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }
  function targetOf(event) { return event.target instanceof Element ? event.target : null; }
  function parseJson(value, fallback) { try { return JSON.parse(value || ""); } catch (error) { return fallback; } }
  function focusable(container) {
    return all("a[href],button:not([disabled]),input:not([disabled]):not([type='hidden']),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex='-1'])", container)
      .filter(function (item) { return !item.hidden && item.getClientRects().length > 0; });
  }

  function openDialog(dialog, trigger) {
    if (!(dialog instanceof HTMLElement) || !dialog.hasAttribute("data-ui-dialog")) return;
    dialog.hidden = false;
    dialogState.set(dialog, { trigger: trigger instanceof HTMLElement ? trigger : document.activeElement });
    document.body.classList.add("is-scroll-locked");
    window.requestAnimationFrame(function () {
      dialog.classList.add("is-open");
      var preferred = one("[autofocus]", dialog) || focusable(dialog)[0] || one("[role='dialog']", dialog);
      if (preferred) preferred.focus({ preventScroll: true });
    });
  }

  function closeDialog(dialog) {
    if (!(dialog instanceof HTMLElement) || dialog.hidden) return;
    var state = dialogState.get(dialog) || {};
    dialog.classList.remove("is-open");
    window.setTimeout(function () {
      dialog.hidden = true;
      document.body.classList.remove("is-scroll-locked");
      if (state.trigger && state.trigger.isConnected) state.trigger.focus({ preventScroll: true });
      dialogState.delete(dialog);
    }, 160);
  }

  AmberUI.openDialog = openDialog;
  AmberUI.closeDialog = closeDialog;

  function requestJson(url, options) {
    return fetch(url, options || {}).then(function (response) {
      return response.json().catch(function () { throw new Error("Invalid server response."); }).then(function (data) {
        if (!response.ok) throw new Error(data.message || "Request failed.");
        return data;
      });
    });
  }

  function setAlert(element, tone, message) {
    if (!element) return;
    element.hidden = false;
    element.className = "ui-alert ui-alert--" + (tone === "danger" ? "error" : tone) + " u-mt-3 u-mb-0";
    element.textContent = message;
  }

  function initNavigation() {
    var nav = one("[data-admin-nav]");
    var toggle = one("[data-admin-nav-toggle]");
    if (!nav || !toggle) return;
    function setOpen(open) {
      nav.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }
    toggle.addEventListener("click", function () { setOpen(!nav.classList.contains("is-open")); });
    nav.addEventListener("click", function (event) {
      var target = targetOf(event);
      if (target && target.closest("a") && window.matchMedia("(max-width: 64rem)").matches) setOpen(false);
    });
    window.addEventListener("resize", function () {
      if (!window.matchMedia("(max-width: 64rem)").matches) setOpen(false);
    });
  }

  function enhanceTable(table) {
    if (table.dataset.adminTableReady === "true" || table.classList.contains("admin-no-card-table")) return;
    table.dataset.adminTableReady = "true";
    table.classList.add("admin-card-table");
    var labels = all("thead th", table).map(function (heading) { return (heading.textContent || "").replace(/\s+/g, " ").trim(); });
    all("tbody tr", table).forEach(function (row) {
      var cells = all("td", row);
      if (cells.length === 1 && cells[0].hasAttribute("colspan")) { row.classList.add("admin-empty-row"); return; }
      cells.forEach(function (cell, index) { if (!cell.hasAttribute("data-label")) cell.setAttribute("data-label", labels[index] || "Field"); });
    });
  }

  function enforceReadOnlyMode() {
    if (!document.body.classList.contains("admin-shell") || document.body.dataset.adminCanMutate !== "0") return;
    all("form[method='post' i]").forEach(function (form) {
      if (form.classList.contains("admin-logout-form")) return;
      all("button,input[type='submit'],input[type='image']", form).forEach(function (control) {
        control.disabled = true;
        control.setAttribute("aria-disabled", "true");
        control.hidden = true;
        control.title = "Your admin role has read-only access on this page.";
      });
    });
    all("[data-admin-mutation]").forEach(function (control) { control.hidden = true; });
  }

  function initOtpTimer() {
    var button = one("#admin-otp-resend[data-cooldown]");
    if (!button) return;
    var remaining = Math.max(0, Number(button.dataset.cooldown) || 0);
    if (!remaining) return;
    var timer = window.setInterval(function () {
      remaining -= 1;
      if (remaining <= 0) { window.clearInterval(timer); button.disabled = false; button.textContent = "Resend OTP"; }
      else button.textContent = "Resend OTP in " + remaining + "s";
    }, 1000);
  }

  function initCouponEditor() {
    var dialog = one("#editCouponModal[data-ui-dialog]");
    if (!dialog) return;
    var fields = { id: "editCouponId", code: "editCouponCode", discountType: "editCouponDiscountType", discountValue: "editCouponDiscountValue", minOrderAmount: "editCouponMinOrderAmount", maxDiscount: "editCouponMaxDiscount", startDate: "editCouponStartDate", endDate: "editCouponEndDate", usageLimit: "editCouponUsageLimit", status: "editCouponStatus" };
    all("[data-ui-dialog-open='editCouponModal']").forEach(function (button) {
      button.addEventListener("click", function () {
        Object.keys(fields).forEach(function (key) {
          var input = document.getElementById(fields[key]);
          if (input) input.value = button.dataset[key] || (key === "discountType" ? "flat" : (key === "status" ? "active" : ""));
        });
      });
    });
  }

  function initDashboardChart() {
    var canvas = one("[data-admin-chart]");
    if (!canvas || typeof window.Chart !== "function") return;
    var config = parseJson(canvas.getAttribute("data-admin-chart"), null);
    if (!config || !Array.isArray(config.labels) || !Array.isArray(config.series)) return;
    new window.Chart(canvas, {
      type: "line",
      data: { labels: config.labels, datasets: [{ label: config.label || "Sales", data: config.series, tension: 0.35, borderColor: "#0f766e", backgroundColor: "rgba(15, 118, 110, 0.12)", fill: true, pointRadius: 3, pointHoverRadius: 4 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { callback: function (value) { return "Rs " + Number(value).toLocaleString(); } } } } }
    });
  }

  function initLiveRate() {
    var form = one("[data-admin-live-rate]");
    if (!form) return;
    var result = one("#live_rate_test_result");
    var button = one("#live_rate_test_button");
    form.setAttribute("data-ui-async", "");
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var pincode = String(one("#live_rate_pincode").value || "").trim();
      var subtotal = String(one("#live_rate_subtotal").value || "").trim();
      var payment = String(one("#live_rate_payment").value || "razorpay");
      if (!/^[1-9][0-9]{5}$/.test(pincode) || Number(subtotal) <= 0) { setAlert(result, "warning", "Enter a valid 6-digit pincode and an order subtotal above zero."); return; }
      var body = new URLSearchParams();
      body.set("csrf_token", form.dataset.csrfToken || ""); body.set("pincode", pincode); body.set("subtotal", subtotal); body.set("payment_method", payment);
      AmberUI.setButtonLoading(button, true, "Checking…");
      requestJson(form.dataset.endpoint || "shipping-rate-test.php", { method: "POST", headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" }, body: body.toString() })
        .then(function (data) {
          if (!data.ok) throw new Error(data.message || "Unable to check the courier rate.");
          if (data.live) {
            var courier = data.courier_name ? " via " + data.courier_name : "";
            setAlert(result, "success", "Live " + data.source + " quote" + courier + ": Rs " + Number(data.shipping_total).toFixed(2) + ".");
          } else setAlert(result, "warning", "Manual fallback: Rs " + Number(data.shipping_total).toFixed(2) + ". " + (data.debug_message || data.debug_reason || "No live courier rate was returned."));
        }).catch(function (error) { setAlert(result, "error", error.message || "Unable to check the courier rate. Please try again."); })
        .finally(function () { AmberUI.setButtonLoading(button, false); });
    });
  }

  function initProductEditor() {
    var form = one("#product-editor-form");
    if (!form) return;
    var sections = ["details", "pricing", "content", "shipping"];
    var tabs = all("[data-editor-tab]");
    var previous = one("#product-prev-tab-btn");
    var next = one("#product-next-tab-btn");
    var variantsLink = one("#variants-tab-link");
    var current = "details";
    var dirty = false;
    function setDirty(value) {
      dirty = Boolean(value); form.dataset.dirty = dirty ? "1" : "0";
      document.dispatchEvent(new CustomEvent("product-editor-dirty-change", { detail: { dirty: dirty } }));
    }
    function showSection(section) {
      if (sections.indexOf(section) < 0) section = "details";
      current = section;
      all("[data-editor-section]", form).forEach(function (field) { field.hidden = field.dataset.editorSection !== section && field.dataset.editorSection !== "actions"; });
      tabs.forEach(function (tab) { tab.classList.toggle("is-active", tab.dataset.editorTab === section); });
      if (variantsLink) variantsLink.classList.remove("is-active");
      var variants = one("#variants-card"); if (variants) variants.classList.add("u-hidden");
      if (next) next.hidden = section === sections[sections.length - 1];
      all(".js-content-only").forEach(function (item) { item.hidden = section !== "content"; });
      window.sessionStorage.setItem("amberProductEditorSection", section);
    }
    tabs.forEach(function (tab) { tab.addEventListener("click", function () { showSection(tab.dataset.editorTab || "details"); }); });
    if (next) next.addEventListener("click", function () { var index = sections.indexOf(current); if (index < sections.length - 1) showSection(sections[index + 1]); });
    if (previous) previous.addEventListener("click", function () { var index = sections.indexOf(current); if (index > 0) showSection(sections[index - 1]); else if (previous.dataset.cancelHref) window.location.assign(previous.dataset.cancelHref); });
    if (variantsLink) variantsLink.addEventListener("click", function (event) {
      event.preventDefault();
      all("[data-editor-section]", form).forEach(function (field) { field.hidden = true; });
      tabs.forEach(function (tab) { tab.classList.remove("is-active"); }); variantsLink.classList.add("is-active");
      var variants = one("#variants-card");
      if (variants) { variants.classList.remove("u-hidden"); variants.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" }); }
    });
    all("[data-submit-intent]").forEach(function (button) { button.addEventListener("click", function () { var intent = one("#product_submit_intent"); if (intent) intent.value = button.dataset.submitIntent || "save"; }); });
    form.addEventListener("input", function () { setDirty(true); }); form.addEventListener("change", function () { setDirty(true); });
    form.addEventListener("invalid", function (event) { var holder = event.target instanceof Element ? event.target.closest("[data-editor-section]") : null; if (holder && sections.indexOf(holder.dataset.editorSection) >= 0) showSection(holder.dataset.editorSection); }, true);
    form.addEventListener("submit", function () { setDirty(false); });
    window.addEventListener("beforeunload", function (event) { if (!dirty) return; event.preventDefault(); event.returnValue = ""; });
    showSection(form.dataset.initialEditorSection || window.sessionStorage.getItem("amberProductEditorSection") || "details"); setDirty(false);
  }

  function initProductMedia() {
    var root = one("[data-admin-product-media]");
    if (!root) return;
    var productId = Number(root.dataset.productId || 0), token = root.dataset.csrfToken || "", endpoint = root.dataset.endpoint || "product-media.php";
    var list = one("#product-media-list", root), message = one("#product-media-message", root), upload = one("#product-media-upload", root), dragged = null;
    function mediaRequest(data) {
      data.set("product_id", String(productId)); data.set("csrf_token", token);
      return requestJson(endpoint, { method: "POST", body: data, credentials: "same-origin" }).then(function (json) { if (!json.ok) throw new Error(json.message || "Request failed."); return json; });
    }
    function showMessage(text, isError) { message.className = "u-mt-2 " + (isError ? "u-text-danger" : "u-text-success"); message.textContent = text; }
    function render(items) {
      list.replaceChildren();
      (Array.isArray(items) ? items : []).forEach(function (media) {
        var item = document.createElement("article"); item.className = "l-col-md-third l-col-xl-quarter admin-media-card u-border u-rounded u-p-2"; item.dataset.mediaId = String(media.id); item.draggable = media.media_type === "image";
        var preview = document.createElement(media.media_type === "image" ? "img" : "video"); preview.src = "../images/fabrics/" + encodeURIComponent(media.filename || ""); preview.className = "admin-responsive-media u-rounded u-mb-2";
        if (media.media_type === "image") preview.alt = media.alt_text || ""; else preview.controls = true;
        var alt = document.createElement("input"); alt.className = "ui-input ui-input--small media-alt"; alt.maxLength = 255; alt.value = media.alt_text || ""; alt.placeholder = "Alt text";
        var actions = document.createElement("div"); actions.className = "u-flex u-gap-1 u-mt-2";
        if (media.media_type === "image") { var primary = document.createElement("button"); primary.type = "button"; primary.className = "ui-button ui-button--small " + (Number(media.is_primary) ? "ui-button--success" : "ui-button--secondary") + " media-primary"; primary.textContent = Number(media.is_primary) ? "Primary" : "Make primary"; actions.appendChild(primary); }
        var remove = document.createElement("button"); remove.type = "button"; remove.className = "ui-button ui-button--small ui-button--danger-outline u-ms-auto media-delete"; remove.textContent = "Remove"; actions.appendChild(remove);
        item.append(preview, alt, actions); list.appendChild(item);
      });
    }
    function load() { requestJson(endpoint + "?product_id=" + encodeURIComponent(productId), { credentials: "same-origin" }).then(function (json) { if (!json.ok) throw new Error(json.message); render(json.media); }).catch(function (error) { showMessage(error.message || "Unable to load media.", true); }); }
    upload.setAttribute("data-ui-async", "");
    upload.addEventListener("submit", function (event) {
      event.preventDefault(); var button = one("button[type='submit']", upload), data = new FormData(upload); data.set("action", "upload"); AmberUI.setButtonLoading(button, true, "Uploading…");
      mediaRequest(data).then(function (json) { render(json.media); upload.reset(); showMessage("Media uploaded.", false); }).catch(function (error) { showMessage(error.message, true); }).finally(function () { AmberUI.setButtonLoading(button, false); });
    });
    list.addEventListener("change", function (event) {
      var input = targetOf(event); if (!input || !input.classList.contains("media-alt")) return; var item = input.closest("[data-media-id]"), data = new FormData();
      data.set("action", "update"); data.set("media_id", item.dataset.mediaId); data.set("alt_text", input.value); mediaRequest(data).then(function (json) { render(json.media); }).catch(function (error) { showMessage(error.message, true); });
    });
    list.addEventListener("click", function (event) {
      var button = targetOf(event), item = button && button.closest("[data-media-id]"); if (!button || !item) return;
      function mutate(action) { var data = new FormData(); data.set("action", action); data.set("media_id", item.dataset.mediaId); if (action === "update") { data.set("set_primary", "1"); data.set("alt_text", one(".media-alt", item).value); } mediaRequest(data).then(function (json) { render(json.media); }).catch(function (error) { showMessage(error.message, true); }); }
      if (button.classList.contains("media-primary")) mutate("update");
      if (button.classList.contains("media-delete")) AmberUI.confirm({ title: "Remove Product Media", message: "Permanently remove this media file?", okText: "Remove Media", variant: "danger", trigger: button }).then(function (confirmed) { if (confirmed) mutate("delete"); });
    });
    list.addEventListener("dragstart", function (event) { dragged = targetOf(event) ? targetOf(event).closest("[data-media-id]") : null; });
    list.addEventListener("dragover", function (event) { var over = targetOf(event) ? targetOf(event).closest("[data-media-id]") : null; if (!dragged || !over || dragged === over) return; event.preventDefault(); list.insertBefore(dragged, over); });
    list.addEventListener("drop", function (event) { event.preventDefault(); var data = new FormData(); data.set("action", "reorder"); all("[data-media-id]", list).forEach(function (item) { data.append("media_ids[]", item.dataset.mediaId); }); mediaRequest(data).then(function (json) { render(json.media); }).catch(function (error) { showMessage(error.message, true); }); });
    var readiness = one("#check-readiness-btn");
    if (readiness) readiness.addEventListener("click", function () {
      var form = one("#product-editor-form"), box = one("#product-action-message");
      if (form && form.dataset.dirty === "1") { setAlert(box, "warning", "Save your changes before checking the saved draft."); return; }
      var data = new FormData(); data.set("product_id", String(productId)); data.set("action", "readiness"); data.set("csrf_token", token); AmberUI.setButtonLoading(readiness, true, "Checking…");
      requestJson(root.dataset.actionsEndpoint || "product-actions.php", { method: "POST", body: data, credentials: "same-origin" }).then(function (json) {
        setAlert(box, json.ready ? "success" : "warning", json.message || (json.ready ? "Saved draft is ready to publish." : "Publishing checklist incomplete."));
        var checks = json.checks || {}; if (Object.keys(checks).length) { var listElement = document.createElement("ul"); listElement.className = "u-mb-0 u-mt-2"; Object.keys(checks).forEach(function (key) { var item = document.createElement("li"); item.textContent = checks[key]; listElement.appendChild(item); }); box.appendChild(listElement); }
      }).catch(function (error) { setAlert(box, "error", error.message || "Unable to check the saved draft."); }).finally(function () { AmberUI.setButtonLoading(readiness, false); });
    });
    load();
  }

  function initVariants() {
    var root = one("[data-admin-variants]"); if (!root) return;
    var productId = Number(root.dataset.productId || 0), token = root.dataset.csrfToken || "", endpoint = root.dataset.endpoint || "fabric-variants.php", actionsEndpoint = root.dataset.actionsEndpoint || "product-actions.php";
    var unit = ["meter", "piece", "set"].indexOf(root.dataset.unitType) >= 0 ? root.dataset.unitType : "meter", presets = parseJson(root.dataset.sizePresets, []);
    var editor = one("#variant-editor-modal"), simpleDialog = one("#simple-inventory-modal"), tbody = one("#variants-tbody"), error = one("#vf_error_msg");
    function field(name) { return one("#vf_" + name); }
    function setField(name, value) { var input = field(name); if (input) input.value = value == null ? "" : String(value); }
    function syncVariantControls() {
      var sizeGroup = one("#vf_size_group"), preset = field("size_preset"), custom = field("size_custom"), hiddenSize = field("size");
      if (unit === "meter") { if (sizeGroup) sizeGroup.hidden = true; if (hiddenSize) hiddenSize.value = ""; }
      else { if (sizeGroup) sizeGroup.hidden = false; if (preset) preset.hidden = !presets.length; if (custom) custom.hidden = Boolean(presets.length && preset.value !== "__custom__"); if (hiddenSize) hiddenSize.value = presets.length && preset.value !== "__custom__" ? preset.value : (custom ? custom.value.trim() : ""); }
      var pack = one("#vf_pack_controls"); if (pack) pack.hidden = unit !== "set";
      var pieces = one("#vf_stock_pcs_wrap"), meters = one("#vf_stock_m_wrap"); if (pieces) pieces.hidden = unit === "meter"; if (meters) meters.hidden = unit !== "meter";
      var label = one("#vf_stock_label"); if (label) label.textContent = unit === "set" ? "Stock (sets)" : "Stock (pcs)";
    }
    function resetEditor() {
      ["variant_id", "color", "size", "sku", "image", "image2", "image3", "image4", "video", "price_override"].forEach(function (name) { setField(name, name === "variant_id" ? 0 : ""); });
      setField("stock", 0); setField("stock_meters", 0); setField("sort_order", 0); setField("units_per_set", 1); setField("pack_label", "Pack of 1");
      var active = field("is_active"); if (active) active.checked = true;
      ["image", "image2", "image3", "image4", "video"].forEach(function (name) { var file = field(name + "_file"); if (file) file.value = ""; var remove = field("remove_" + name); if (remove) remove.checked = false; var current = field(name + "_current"); if (current) current.hidden = true; var wrap = one("#vf_" + name + "_remove_wrap"); if (wrap) wrap.hidden = true; });
      var preset = field("size_preset"); if (preset) preset.value = ""; var custom = field("size_custom"); if (custom) custom.value = "";
      one("#variant-form-title").textContent = "Add Variant"; var note = one("#vf_editing_note"); if (note) note.hidden = true; error.textContent = ""; syncVariantControls();
    }
    function showExistingMedia(name, present) { var current = field(name + "_current"), wrap = one("#vf_" + name + "_remove_wrap"); if (current) { current.hidden = !present; current.textContent = present ? "Current override is saved." : ""; } if (wrap) wrap.hidden = !present; }
    function loadVariant(id, trigger) {
      resetEditor(); one("#variant-form-title").textContent = "Loading Variant…"; openDialog(editor, trigger);
      requestJson(endpoint + "?action=list&fabric_id=" + encodeURIComponent(productId), { credentials: "same-origin" }).then(function (json) {
        if (!json.success) throw new Error(json.message || "Could not load variant."); var variant = (json.variants || []).find(function (item) { return Number(item.id) === Number(id); }); if (!variant) throw new Error("Variant not found.");
        ["variant_id", "color", "sku", "image", "image2", "image3", "image4", "video", "price_override", "stock", "stock_meters", "sort_order", "units_per_set", "pack_label"].forEach(function (name) { setField(name, variant[name]); });
        var size = String(variant.size || ""), preset = field("size_preset"), custom = field("size_custom"); if (preset && presets.indexOf(size) >= 0) preset.value = size; else { if (preset) preset.value = "__custom__"; if (custom) custom.value = size; }
        var active = field("is_active"); if (active) active.checked = Number(variant.is_active) === 1;
        ["image", "image2", "image3", "image4", "video"].forEach(function (name) { showExistingMedia(name, Boolean(String(variant[name] || "").trim())); });
        one("#variant-form-title").textContent = "Edit Variant"; var note = one("#vf_editing_note"); if (note) { note.hidden = false; note.textContent = "Editing existing variant #" + variant.id + "."; } syncVariantControls();
      }).catch(function (failure) { one("#variant-form-title").textContent = "Unable to Load Variant"; error.textContent = failure.message; });
    }
    function variantPayload() {
      syncVariantControls(); var data = new FormData(); data.set("csrf_token", token); data.set("action", "save"); data.set("fabric_id", String(productId));
      ["variant_id", "sku", "color", "size", "image", "image2", "image3", "image4", "video", "price_override", "stock", "stock_meters", "sort_order"].forEach(function (name) { data.set(name, field(name) ? field(name).value : ""); });
      data.set("units_per_set", unit === "set" ? field("units_per_set").value : ""); data.set("pack_label", unit === "set" ? field("pack_label").value : ""); data.set("is_active", field("is_active").checked ? "1" : "0");
      ["image", "image2", "image3", "image4", "video"].forEach(function (name) { var remove = field("remove_" + name), file = field(name + "_file"); data.set("remove_" + name, remove && remove.checked ? "1" : "0"); if (file && file.files && file.files[0]) data.set(name + "_file", file.files[0]); });
      return data;
    }
    function appendCell(row, content) { var cell = document.createElement("td"); if (content instanceof Node) cell.appendChild(content); else cell.textContent = String(content == null ? "" : content); row.appendChild(cell); return cell; }
    function renderRows(rows) {
      tbody.replaceChildren();
      if (!rows.length) { var empty = document.createElement("tr"), cell = appendCell(empty, "No variants yet. Select Add Variant to create one."); cell.colSpan = 9; cell.className = "u-text-center u-text-muted u-py-4"; tbody.appendChild(empty); return; }
      rows.forEach(function (variant) {
        var row = document.createElement("tr"); row.dataset.vid = String(variant.id); appendCell(row, variant.color || "—"); appendCell(row, variant.size || "—");
        appendCell(row, unit === "set" ? ((variant.pack_label || ("Pack of " + (Number(variant.units_per_set) || 1))) + " (" + (Number(variant.units_per_set) || 1) + ")") : "—");
        var sku = document.createElement("code"); sku.textContent = variant.sku || ""; appendCell(row, sku);
        var images = ["image", "image2", "image3", "image4"].filter(function (key) { return Boolean(String(variant[key] || "").trim()); }).length; appendCell(row, images || variant.video ? images + " image" + (images === 1 ? "" : "s") + (variant.video ? " + video" : "") : "Base gallery");
        appendCell(row, "Rs " + Number(variant.effective_price || 0).toFixed(2) + (variant.inherits_price ? " (inherited)" : " (override)")); appendCell(row, Number(variant.effective_stock || 0).toFixed(unit === "meter" ? 2 : 0));
        var badge = document.createElement("span"); badge.className = "ui-badge " + (Number(variant.is_active) === 1 ? "ui-badge--success" : "ui-badge--neutral"); badge.textContent = Number(variant.is_active) === 1 ? "Yes" : "No"; appendCell(row, badge);
        var actions = document.createElement("div"); actions.className = "u-flex u-gap-1";
        [{ action: "edit", label: "Edit", className: "ui-button--outline" }, { action: "delete", label: "Delete", className: "ui-button--danger-outline" }].forEach(function (item) { var button = document.createElement("button"); button.type = "button"; button.className = "ui-button ui-button--xsmall " + item.className; button.dataset.action = item.action; button.dataset.variantId = String(variant.id); button.textContent = item.label; actions.appendChild(button); }); appendCell(row, actions); tbody.appendChild(row);
      });
    }
    function reloadRows() { return requestJson(endpoint + "?action=list&fabric_id=" + encodeURIComponent(productId), { credentials: "same-origin" }).then(function (json) { if (!json.success) throw new Error(json.message); renderRows(Array.isArray(json.variants) ? json.variants : []); }).catch(function (failure) { setAlert(one("#product-action-message"), "error", failure.message || "Could not reload variants."); }); }
    var add = one("#variants-add-btn"); if (add) add.addEventListener("click", function () { resetEditor(); openDialog(editor, add); });
    var save = one("#variant-save-btn"); if (save) save.addEventListener("click", function () {
      error.textContent = ""; AmberUI.setButtonLoading(save, true, "Saving…");
      requestJson(endpoint, { method: "POST", body: variantPayload(), credentials: "same-origin" }).then(function (json) { if (!json.success) { var errors = json.errors && Object.values(json.errors).filter(Boolean); throw new Error(errors && errors[0] ? errors[0] : (json.message || "Error saving variant.")); } closeDialog(editor); return reloadRows(); }).catch(function (failure) { error.textContent = failure.message || "Network error. Please try again."; }).finally(function () { AmberUI.setButtonLoading(save, false); });
    });
    var preset = field("size_preset"); if (preset) preset.addEventListener("change", syncVariantControls); var custom = field("size_custom"); if (custom) custom.addEventListener("input", syncVariantControls);
    var units = field("units_per_set"); if (units) units.addEventListener("input", function () { var pack = field("pack_label"), count = Math.max(1, parseInt(units.value || "1", 10) || 1); if (pack && (!pack.value.trim() || /^Pack of \d+$/i.test(pack.value.trim()))) pack.value = "Pack of " + count; });
    tbody.addEventListener("click", function (event) {
      var button = targetOf(event) ? targetOf(event).closest("button[data-action][data-variant-id]") : null; if (!button) return; var id = Number(button.dataset.variantId || 0);
      if (button.dataset.action === "edit") loadVariant(id, button);
      if (button.dataset.action === "delete") AmberUI.confirm({ title: "Remove Variant", message: "Remove this variant? Variants used in business records will be archived so history remains intact.", okText: "Remove Variant", variant: "danger", trigger: button }).then(function (confirmed) {
        if (!confirmed) return; var data = new FormData(); data.set("csrf_token", token); data.set("action", "delete"); data.set("fabric_id", String(productId)); data.set("variant_id", String(id)); AmberUI.setButtonLoading(button, true, "Removing…");
        requestJson(endpoint, { method: "POST", body: data, credentials: "same-origin" }).then(function (json) { if (!json.success) throw new Error(json.message); setAlert(one("#product-action-message"), json.archived ? "info" : "success", json.message || "Variant removed."); return reloadRows(); }).catch(function (failure) { setAlert(one("#product-action-message"), "error", failure.message || "Could not remove variant."); }).finally(function () { AmberUI.setButtonLoading(button, false); });
      });
    });
    function productMode(action, baseStock, button) {
      var data = new FormData(); data.set("csrf_token", token); data.set("action", action); data.set("product_id", String(productId)); if (baseStock != null) data.set("base_stock", baseStock); AmberUI.setButtonLoading(button, true, action === "enable-variants" ? "Enabling…" : "Switching…");
      requestJson(actionsEndpoint, { method: "POST", body: data, credentials: "same-origin" }).then(function (json) { if (!json.ok) throw new Error(json.message); window.location.reload(); }).catch(function (failure) { AmberUI.setButtonLoading(button, false); if (action === "disable-variants") { var box = one("#simple-inventory-error"); box.hidden = false; box.textContent = failure.message; } else setAlert(one("#product-action-message"), "error", failure.message || "Could not change inventory mode."); });
    }
    var enable = one("#enable-variants-btn"); if (enable) enable.addEventListener("click", function () { AmberUI.confirm({ title: "Enable Variable Inventory", message: "Base stock will be cleared and inventory control will move to variants. The product will remain in draft until a sellable variant is added.", okText: "Enable Variants", variant: "danger", trigger: enable }).then(function (confirmed) { if (confirmed) productMode("enable-variants", null, enable); }); });
    var disable = one("#disable-variants-btn"); if (disable) disable.addEventListener("click", function () { var stock = one("#simple-base-stock"); if (stock) stock.value = "0"; var box = one("#simple-inventory-error"); if (box) { box.hidden = true; box.textContent = ""; } openDialog(simpleDialog, disable); });
    var confirmSimple = one("#confirm-simple-inventory"); if (confirmSimple) confirmSimple.addEventListener("click", function () { var raw = String(one("#simple-base-stock").value || "").trim(), box = one("#simple-inventory-error"); if (raw === "" || !Number.isFinite(Number(raw)) || Number(raw) < 0 || (unit !== "meter" && !Number.isInteger(Number(raw)))) { box.hidden = false; box.textContent = unit === "meter" ? "Enter valid non-negative base stock." : "Piece and set stock must be a whole number."; return; } productMode("disable-variants", raw, confirmSimple); });
    syncVariantControls();
  }

  function initDialogs() {
    document.addEventListener("click", function (event) {
      var target = targetOf(event); if (!target) return;
      var opener = target.closest("[data-ui-dialog-open]"); if (opener) { var dialog = document.getElementById(opener.getAttribute("data-ui-dialog-open")); if (dialog) openDialog(dialog, opener); return; }
      var closer = target.closest("[data-ui-dialog-close]"); if (closer) closeDialog(closer.closest("[data-ui-dialog]"));
      if (target.hasAttribute("data-ui-dialog") && !target.hasAttribute("data-ui-dialog-static")) closeDialog(target);
    });
    document.addEventListener("keydown", function (event) {
      var active = one("[data-ui-dialog].is-open"); if (!active) return;
      if (event.key === "Escape") { event.preventDefault(); closeDialog(active); return; }
      if (event.key !== "Tab") return; var items = focusable(active); if (!items.length) { event.preventDefault(); return; }
      if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items[items.length - 1].focus(); }
      else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
    });
  }

  function init() {
    if (document.body.dataset.uiArea !== "admin") return;
    initDialogs(); initNavigation(); enforceReadOnlyMode(); all(".ui-table-wrap > table.ui-table").forEach(enhanceTable);
    initOtpTimer(); initCouponEditor(); initDashboardChart(); initLiveRate(); initProductEditor(); initProductMedia(); initVariants();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true }); else init();
}());
