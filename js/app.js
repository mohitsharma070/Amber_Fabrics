(function () {
  "use strict";

  var AmberUI = window.AmberUI = window.AmberUI || {};
  var initialized = false;
  var loadingButtons = new Map();
  var submittingForms = new WeakSet();
  var confirmedForms = new WeakSet();
  var activeOverlay = null;
  var lockedOverlays = 0;

  function select(selector, scope) {
    return (scope || document).querySelector(selector);
  }

  function selectAll(selector, scope) {
    return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
  }

  function parseData(element, name, fallback) {
    if (!element) {
      return fallback;
    }
    var raw = element.getAttribute("data-" + name);
    if (raw === null || raw === "") {
      return fallback;
    }
    try {
      return JSON.parse(raw);
    } catch (error) {
      return fallback;
    }
  }

  function focusableElements(container) {
    if (!container) {
      return [];
    }
    return selectAll("a[href], button:not([disabled]), input:not([disabled]):not([type='hidden']), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])", container)
      .filter(function (element) {
        return !element.hidden && element.getAttribute("aria-hidden") !== "true" && element.getClientRects().length > 0;
      });
  }

  function lockPage() {
    lockedOverlays += 1;
    if (lockedOverlays !== 1) {
      return;
    }
    var width = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.setProperty("--scrollbar-compensation", Math.max(0, width) + "px");
    document.body.classList.add("is-scroll-locked");
  }

  function unlockPage() {
    lockedOverlays = Math.max(0, lockedOverlays - 1);
    if (lockedOverlays === 0) {
      document.body.classList.remove("is-scroll-locked");
      document.documentElement.style.removeProperty("--scrollbar-compensation");
    }
  }

  function moveFocusWithin(event, container) {
    if (event.key !== "Tab") {
      return;
    }
    var items = focusableElements(container);
    if (!items.length) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function createConfirmDialog() {
    var backdrop = document.createElement("div");
    backdrop.className = "ui-dialog-backdrop";
    backdrop.hidden = true;
    backdrop.setAttribute("data-ui-confirm-dialog", "");

    var dialog = document.createElement("section");
    dialog.className = "ui-dialog";
    dialog.setAttribute("role", "alertdialog");
    dialog.setAttribute("aria-modal", "true");
    dialog.setAttribute("aria-labelledby", "ui-confirm-title");
    dialog.setAttribute("aria-describedby", "ui-confirm-message");
    dialog.setAttribute("tabindex", "-1");

    var header = document.createElement("header");
    header.className = "ui-dialog__header";
    var icon = document.createElement("span");
    icon.className = "ui-dialog__icon";
    icon.setAttribute("data-ui-confirm-icon", "");
    icon.setAttribute("aria-hidden", "true");
    icon.textContent = "?";
    var heading = document.createElement("div");
    heading.className = "ui-dialog__heading";
    var title = document.createElement("h2");
    title.className = "ui-dialog__title";
    title.id = "ui-confirm-title";
    title.setAttribute("data-ui-confirm-title", "");
    heading.appendChild(title);
    var close = document.createElement("button");
    close.type = "button";
    close.className = "ui-button ui-button--secondary ui-button--icon";
    close.setAttribute("data-ui-confirm-cancel", "");
    close.setAttribute("aria-label", "Close dialog");
    close.textContent = "×";
    header.append(icon, heading, close);

    var body = document.createElement("div");
    body.className = "ui-dialog__body";
    body.id = "ui-confirm-message";
    body.setAttribute("data-ui-confirm-message", "");

    var footer = document.createElement("footer");
    footer.className = "ui-dialog__footer";
    var cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "ui-button ui-button--secondary";
    cancel.setAttribute("data-ui-confirm-cancel", "");
    var confirm = document.createElement("button");
    confirm.type = "button";
    confirm.className = "ui-button ui-button--primary";
    confirm.setAttribute("data-ui-confirm-ok", "");
    footer.append(cancel, confirm);
    dialog.append(header, body, footer);
    backdrop.appendChild(dialog);
    document.body.appendChild(backdrop);
    return backdrop;
  }

  function getConfirmDialog() {
    return select("[data-ui-confirm-dialog]") || createConfirmDialog();
  }

  var pendingConfirm = null;

  function settleConfirm(answer) {
    if (!pendingConfirm) {
      return;
    }
    var pending = pendingConfirm;
    pendingConfirm = null;
    pending.backdrop.classList.remove("is-open");
    window.setTimeout(function () {
      pending.backdrop.hidden = true;
      pending.backdrop.removeAttribute("data-static");
      if (pending.trigger && pending.trigger.isConnected) {
        pending.trigger.focus({ preventScroll: true });
      }
      unlockPage();
      pending.resolve(Boolean(answer));
    }, 160);
  }

  AmberUI.confirm = function (options) {
    options = typeof options === "string" ? { message: options } : (options || {});
    if (pendingConfirm) {
      settleConfirm(false);
    }
    var backdrop = getConfirmDialog();
    var dialog = select(".ui-dialog", backdrop);
    var title = select("[data-ui-confirm-title]", backdrop);
    var message = select("[data-ui-confirm-message]", backdrop);
    var cancelButtons = selectAll("[data-ui-confirm-cancel]", backdrop);
    var ok = select("[data-ui-confirm-ok]", backdrop);
    var tone = options.variant || options.tone || "default";
    var isStatic = options.staticBackdrop === true || ["danger", "order", "payment"].indexOf(tone) !== -1;
    var trigger = options.trigger instanceof HTMLElement ? options.trigger : document.activeElement;

    title.textContent = options.title || "Please confirm";
    message.textContent = options.message || "Are you sure you want to continue?";
    ok.textContent = options.okText || options.confirmLabel || "Confirm";
    cancelButtons.forEach(function (button) {
      if (button.getAttribute("aria-label") !== "Close dialog") {
        button.textContent = options.cancelText || options.cancelLabel || "Cancel";
      }
    });
    ok.className = "ui-button " + (tone === "danger" ? "ui-button--danger" : "ui-button--primary");
    dialog.setAttribute("data-tone", tone);
    backdrop.toggleAttribute("data-static", isStatic);
    backdrop.hidden = false;
    lockPage();
    window.requestAnimationFrame(function () {
      backdrop.classList.add("is-open");
      ok.focus({ preventScroll: true });
    });

    return new Promise(function (resolve) {
      pendingConfirm = { backdrop: backdrop, resolve: resolve, trigger: trigger };
    });
  };

  window.adminConfirm = function (options) {
    return AmberUI.confirm(options);
  };

  function ensureToastRegion() {
    var region = select("[data-ui-toast-region]");
    if (region) {
      return region;
    }
    region = document.createElement("div");
    region.className = "ui-toast-region";
    region.setAttribute("data-ui-toast-region", "");
    region.setAttribute("aria-label", "Notifications");
    region.setAttribute("aria-live", "polite");
    region.setAttribute("aria-atomic", "false");
    document.body.appendChild(region);
    return region;
  }

  AmberUI.toast = function (options) {
    options = typeof options === "string" ? { message: options } : (options || {});
    var tone = options.type || options.tone || "info";
    var region = ensureToastRegion();
    var toast = document.createElement("article");
    toast.className = "ui-toast";
    toast.setAttribute("data-tone", tone);
    toast.setAttribute("role", tone === "error" ? "alert" : "status");

    var marker = document.createElement("span");
    marker.className = "ui-toast__marker";
    marker.setAttribute("aria-hidden", "true");
    marker.textContent = tone === "success" ? "✓" : (tone === "error" ? "!" : "i");
    var content = document.createElement("div");
    if (options.title) {
      var heading = document.createElement("div");
      heading.className = "ui-toast__title";
      heading.textContent = String(options.title);
      content.appendChild(heading);
    }
    var message = document.createElement("div");
    message.className = "ui-toast__message";
    message.textContent = String(options.message || "");
    content.appendChild(message);
    var close = document.createElement("button");
    close.type = "button";
    close.className = "ui-button ui-button--secondary ui-button--icon ui-button--small";
    close.setAttribute("aria-label", "Dismiss notification");
    close.textContent = "×";
    toast.append(marker, content, close);
    region.appendChild(toast);

    var duration = Number(options.duration);
    if (!Number.isFinite(duration)) {
      duration = tone === "error" ? 8000 : 5000;
    }
    var timer = null;
    var remaining = Math.max(0, duration);
    var started = 0;

    function dismiss() {
      window.clearTimeout(timer);
      toast.classList.remove("is-visible");
      window.setTimeout(function () { toast.remove(); }, 180);
    }

    function resume() {
      if (!remaining) {
        return;
      }
      started = Date.now();
      timer = window.setTimeout(dismiss, remaining);
    }

    function pause() {
      window.clearTimeout(timer);
      remaining = Math.max(0, remaining - (Date.now() - started));
    }

    close.addEventListener("click", dismiss);
    toast.addEventListener("mouseenter", pause);
    toast.addEventListener("mouseleave", resume);
    toast.addEventListener("focusin", pause);
    toast.addEventListener("focusout", resume);
    window.requestAnimationFrame(function () { toast.classList.add("is-visible"); });
    resume();
    return { element: toast, dismiss: dismiss };
  };

  AmberUI.setButtonLoading = function (button, loading, label) {
    if (!(button instanceof HTMLElement)) {
      return;
    }
    if (loading) {
      if (loadingButtons.has(button)) {
        return;
      }
      var snapshot = {
        nodes: Array.prototype.map.call(button.childNodes, function (node) { return node.cloneNode(true); }),
        disabled: Boolean(button.disabled),
        ariaDisabled: button.getAttribute("aria-disabled")
      };
      loadingButtons.set(button, snapshot);
      var spinner = document.createElement("span");
      spinner.className = "ui-button__spinner";
      spinner.setAttribute("aria-hidden", "true");
      var text = document.createElement("span");
      text.textContent = label || button.getAttribute("data-loading-label") || "Working…";
      button.replaceChildren(spinner, text);
      button.disabled = true;
      button.setAttribute("aria-busy", "true");
      return;
    }
    var saved = loadingButtons.get(button);
    if (!saved) {
      return;
    }
    button.replaceChildren.apply(button, saved.nodes);
    button.disabled = saved.disabled;
    button.removeAttribute("aria-busy");
    if (saved.ariaDisabled === null) {
      button.removeAttribute("aria-disabled");
    } else {
      button.setAttribute("aria-disabled", saved.ariaDisabled);
    }
    loadingButtons.delete(button);
  };

  function restorePendingControls() {
    loadingButtons.forEach(function (_snapshot, button) {
      AmberUI.setButtonLoading(button, false);
    });
    selectAll("form[data-ui-submitting='true']").forEach(function (form) {
      form.removeAttribute("data-ui-submitting");
      submittingForms.delete(form);
    });
  }

  function attributeFrom(form, submitter, name) {
    if (submitter && submitter.hasAttribute(name)) {
      return submitter.getAttribute(name);
    }
    return form.hasAttribute(name) ? form.getAttribute(name) : null;
  }

  function confirmationFor(form, submitter) {
    var direct = attributeFrom(form, submitter, "data-confirm");
    var modalMessage = attributeFrom(form, submitter, "data-confirm-message");
    var enabled = direct !== null || modalMessage !== null || form.hasAttribute("data-confirm-modal") || Boolean(submitter && submitter.hasAttribute("data-confirm-modal"));
    if (!enabled) {
      return null;
    }
    return {
      title: attributeFrom(form, submitter, "data-confirm-title") || "Please confirm",
      message: modalMessage || direct || "Are you sure you want to continue?",
      okText: attributeFrom(form, submitter, "data-confirm-ok") || "Confirm",
      cancelText: attributeFrom(form, submitter, "data-confirm-cancel") || "Cancel",
      variant: attributeFrom(form, submitter, "data-confirm-variant") || "default",
      trigger: submitter || form,
      staticBackdrop: attributeFrom(form, submitter, "data-confirm-static") === "true"
    };
  }

  function submitForm(form, submitter) {
    confirmedForms.add(form);
    if (typeof form.requestSubmit === "function") {
      form.requestSubmit(submitter || undefined);
      return;
    }
    if (submitter && submitter.name) {
      var input = document.createElement("input");
      input.type = "hidden";
      input.name = submitter.name;
      input.value = submitter.value;
      form.appendChild(input);
    }
    form.submit();
  }

  function onSubmit(event) {
    if (event.defaultPrevented) {
      return;
    }
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (form.hasAttribute("data-ui-async")) {
      return;
    }
    var submitter = event.submitter || select("button[type='submit']:focus, input[type='submit']:focus", form);
    if (submittingForms.has(form)) {
      event.preventDefault();
      return;
    }
    var validationEvent = new CustomEvent("amber:validate", { bubbles: false, cancelable: true, detail: { submitter: submitter } });
    if (!form.dispatchEvent(validationEvent)) {
      event.preventDefault();
      return;
    }
    if (!confirmedForms.has(form)) {
      var options = confirmationFor(form, submitter);
      if (options) {
        event.preventDefault();
        AmberUI.confirm(options).then(function (confirmed) {
          if (confirmed) {
            submitForm(form, submitter);
          }
        });
        return;
      }
    }
    confirmedForms.delete(form);
    submittingForms.add(form);
    form.setAttribute("data-ui-submitting", "true");
    if (submitter) {
      AmberUI.setButtonLoading(submitter, true, submitter.getAttribute("data-loading-label") || "Processing…");
    }
    window.setTimeout(function () {
      if (form.isConnected) {
        form.removeAttribute("data-ui-submitting");
        submittingForms.delete(form);
        if (submitter) {
          AmberUI.setButtonLoading(submitter, false);
        }
      }
    }, 30000);
  }

  function closeDrawer(drawer, returnFocus) {
    if (!drawer || !drawer.classList.contains("is-open")) {
      return;
    }
    var backdrop = select("[data-ui-drawer-backdrop]");
    drawer.classList.remove("is-open");
    drawer.setAttribute("aria-hidden", "true");
    if (backdrop) {
      backdrop.classList.remove("is-open");
    }
    window.setTimeout(function () {
      if (backdrop) {
        backdrop.hidden = true;
      }
      unlockPage();
      if (returnFocus && returnFocus.isConnected) {
        returnFocus.focus({ preventScroll: true });
      }
    }, 220);
    activeOverlay = null;
    selectAll("[data-ui-drawer-open][aria-expanded='true']").forEach(function (button) {
      button.setAttribute("aria-expanded", "false");
    });
  }

  function openDrawer(drawer, trigger) {
    if (!drawer) {
      return;
    }
    var backdrop = select("[data-ui-drawer-backdrop]");
    if (!backdrop) {
      backdrop = document.createElement("div");
      backdrop.className = "ui-drawer-backdrop";
      backdrop.setAttribute("data-ui-drawer-backdrop", "");
      backdrop.hidden = true;
      document.body.appendChild(backdrop);
    }
    drawer._uiTrigger = trigger;
    drawer.setAttribute("aria-hidden", "false");
    backdrop.hidden = false;
    lockPage();
    window.requestAnimationFrame(function () {
      drawer.classList.add("is-open");
      backdrop.classList.add("is-open");
      (focusableElements(drawer)[0] || drawer).focus({ preventScroll: true });
    });
    trigger.setAttribute("aria-expanded", "true");
    activeOverlay = drawer;
  }

  function toggleMenu(trigger) {
    var id = trigger.getAttribute("aria-controls") || trigger.getAttribute("data-ui-menu-toggle");
    var menu = id ? document.getElementById(id) : null;
    if (!menu) {
      return;
    }
    var opening = menu.hidden;
    selectAll("[data-ui-menu]").forEach(function (candidate) {
      candidate.hidden = true;
      var owner = select("[aria-controls='" + candidate.id + "']");
      if (owner) {
        owner.setAttribute("aria-expanded", "false");
      }
    });
    menu.hidden = !opening;
    trigger.setAttribute("aria-expanded", opening ? "true" : "false");
    if (opening) {
      var first = focusableElements(menu)[0];
      if (first) {
        first.focus();
      }
    }
  }

  function toggleDisclosure(trigger) {
    var id = trigger.getAttribute("aria-controls") || trigger.getAttribute("data-ui-disclosure");
    var panel = id ? document.getElementById(id) : null;
    if (!panel) {
      return;
    }
    var expanded = trigger.getAttribute("aria-expanded") === "true";
    trigger.setAttribute("aria-expanded", expanded ? "false" : "true");
    panel.hidden = expanded;
  }

  function handleDocumentClick(event) {
    var target = event.target;
    var cancel = target.closest("[data-ui-confirm-cancel]");
    if (cancel && pendingConfirm) {
      settleConfirm(false);
      return;
    }
    var ok = target.closest("[data-ui-confirm-ok]");
    if (ok && pendingConfirm) {
      settleConfirm(true);
      return;
    }
    if (pendingConfirm && target === pendingConfirm.backdrop && !pendingConfirm.backdrop.hasAttribute("data-static")) {
      settleConfirm(false);
      return;
    }
    var drawerTrigger = target.closest("[data-ui-drawer-open]");
    if (drawerTrigger) {
      event.preventDefault();
      openDrawer(document.getElementById(drawerTrigger.getAttribute("data-ui-drawer-open") || drawerTrigger.getAttribute("aria-controls")), drawerTrigger);
      return;
    }
    var drawerClose = target.closest("[data-ui-drawer-close]");
    if (drawerClose) {
      event.preventDefault();
      var drawer = drawerClose.closest("[data-ui-drawer]");
      closeDrawer(drawer, drawer && drawer._uiTrigger);
      return;
    }
    if (target.matches("[data-ui-drawer-backdrop]")) {
      closeDrawer(activeOverlay, activeOverlay && activeOverlay._uiTrigger);
      return;
    }
    var menuTrigger = target.closest("[data-ui-menu-toggle]");
    if (menuTrigger) {
      event.preventDefault();
      toggleMenu(menuTrigger);
      return;
    }
    var disclosure = target.closest("[data-ui-disclosure]");
    if (disclosure) {
      event.preventDefault();
      toggleDisclosure(disclosure);
      return;
    }
    var dismiss = target.closest("[data-ui-dismiss]");
    if (dismiss) {
      var dismissTarget = dismiss.closest("[data-ui-dismissible]");
      if (dismissTarget) {
        dismissTarget.remove();
      }
      return;
    }
    if (!target.closest("[data-ui-menu]") && !target.closest("[data-ui-menu-toggle]")) {
      selectAll("[data-ui-menu]").forEach(function (menu) {
        menu.hidden = true;
        var owner = menu.id ? select("[aria-controls='" + menu.id + "']") : null;
        if (owner) {
          owner.setAttribute("aria-expanded", "false");
        }
      });
    }
  }

  function handleKeydown(event) {
    if (pendingConfirm) {
      if (event.key === "Escape") {
        event.preventDefault();
        settleConfirm(false);
      } else {
        moveFocusWithin(event, select(".ui-dialog", pendingConfirm.backdrop));
      }
      return;
    }
    if (activeOverlay) {
      if (event.key === "Escape") {
        event.preventDefault();
        closeDrawer(activeOverlay, activeOverlay._uiTrigger);
      } else {
        moveFocusWithin(event, activeOverlay);
      }
    }
  }

  function loadExternalScript(source, marker) {
    if (select("script[data-ui-provider='" + marker + "']")) {
      return;
    }
    var script = document.createElement("script");
    script.async = true;
    script.src = source;
    script.setAttribute("data-ui-provider", marker);
    document.head.appendChild(script);
  }

  function initializeAnalytics() {
    var google = select("[data-ui-google-analytics]");
    if (google && google.getAttribute("data-ui-ready") !== "true") {
      google.setAttribute("data-ui-ready", "true");
      var measurementId = google.getAttribute("data-measurement-id") || "";
      var googleConfig = parseData(google, "google-config", {});
      window.dataLayer = window.dataLayer || [];
      window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
      window.amberGoogleAnalyticsTrack = function (eventName, payload) {
        window.gtag("event", eventName, payload || {});
      };
      if (measurementId) {
        window.gtag("js", new Date());
        window.gtag("config", measurementId, googleConfig || {});
        loadExternalScript("https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(measurementId), "google-analytics");
      }
    }

    selectAll("[data-ui-google-events]").forEach(function (node) {
      if (node.getAttribute("data-ui-ready") === "true") { return; }
      node.setAttribute("data-ui-ready", "true");
      var events = parseData(node, "ui-google-events", []);
      if (!Array.isArray(events)) { return; }
      events.forEach(function (event) {
        var payload = event && event.payload && typeof event.payload === "object" ? event.payload : {};
        if (event && event.name === "page_view" && !payload.page_title) { payload.page_title = document.title; }
        if (event && event.name && typeof window.amberGoogleAnalyticsTrack === "function") {
          window.amberGoogleAnalyticsTrack(event.name, payload);
        }
      });
    });

    var meta = select("[data-ui-meta-pixel]");
    if (meta && meta.getAttribute("data-ui-ready") !== "true") {
      meta.setAttribute("data-ui-ready", "true");
      var pixelId = meta.getAttribute("data-pixel-id") || "";
      if (typeof window.fbq !== "function") {
        var queue = function () {
          if (queue.callMethod) { queue.callMethod.apply(queue, arguments); }
          else { queue.queue.push(arguments); }
        };
        queue.queue = [];
        queue.loaded = false;
        queue.version = "2.0";
        window.fbq = queue;
        window._fbq = queue;
      }
      window.amberMetaPixelTrack = function (eventName, payload, eventId) {
        window.fbq("track", eventName, payload || {}, eventId ? { eventID: eventId } : {});
      };
      if (pixelId) {
        window.fbq("init", pixelId);
        window.fbq("track", "PageView");
        loadExternalScript("https://connect.facebook.net/en_US/fbevents.js", "meta-pixel");
      }
    }

    selectAll("[data-ui-meta-events]").forEach(function (node) {
      if (node.getAttribute("data-ui-ready") === "true") { return; }
      node.setAttribute("data-ui-ready", "true");
      var events = parseData(node, "ui-meta-events", []);
      if (!Array.isArray(events)) { return; }
      events.forEach(function (event) {
        if (event && event.name && typeof window.amberMetaPixelTrack === "function") {
          window.amberMetaPixelTrack(event.name, event.payload || {}, event.event_id || "");
        }
      });
    });
  }

  AmberUI.parseData = parseData;
  AmberUI.restore = restorePendingControls;
  AmberUI.init = function () {
    if (initialized) {
      initializeAnalytics();
      return;
    }
    initialized = true;
    document.addEventListener("click", handleDocumentClick);
    document.addEventListener("keydown", handleKeydown);
    document.addEventListener("submit", onSubmit, true);
    window.addEventListener("pageshow", restorePendingControls);
    window.addEventListener("pagehide", restorePendingControls);
    initializeAnalytics();
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", AmberUI.init, { once: true });
  } else {
    AmberUI.init();
  }
}());
