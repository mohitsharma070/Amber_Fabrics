import { chromium } from 'playwright';

const html = String.raw`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Checkout Submission UX Browser Test</title>
<style>
  .visually-hidden {
    border: 0;
    clip: rect(0 0 0 0);
    height: 1px;
    margin: -1px;
    overflow: hidden;
    padding: 0;
    position: absolute;
    width: 1px;
  }
</style>
</head>
<body>
<form id="checkout_form" novalidate>
  <input id="checkout_email" name="email" type="email" value="buyer@example.com" />
  <button type="submit" id="place_order_btn">Place Order</button>
  <div id="checkout_submit_status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
</form>
<button type="button" id="mobile_place_order_btn">Place Order</button>

<script>
(function () {
  var checkoutForm = document.getElementById('checkout_form');
  var placeOrderBtn = document.getElementById('place_order_btn');
  var mobileSubmitBtn = document.getElementById('mobile_place_order_btn');
  var submitStatusEl = document.getElementById('checkout_submit_status');

  var shippingQuoteState = { pending: false, valid: true };
  var couponRequestInFlight = false;
  var validationShouldFail = false;
  var simulateServerFailure = false;

  var shippingSubmissionEnabled = true;
  var submissionState = { inProgress: false, restoreTimer: null };

  function setPlaceOrderButtonDisabled(button, disabled) {
    if (!button) return;
    button.disabled = !!disabled;
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
  }

  function setPlaceOrderButtonLabel(button, label, isProcessing) {
    if (!button) return;
    if (!button.getAttribute('data-default-label')) {
      button.setAttribute('data-default-label', String(button.textContent || '').trim() || 'Place Order');
    }
    if (!isProcessing) {
      button.textContent = label;
      return;
    }
    button.innerHTML = '<span class="spinner" aria-hidden="true">*</span> ' + label;
  }

  function updateSubmitAnnouncement(message) {
    if (!submitStatusEl) return;
    submitStatusEl.textContent = message || '';
  }

  function syncSubmitControls() {
    var disabled = submissionState.inProgress || !shippingSubmissionEnabled;
    setPlaceOrderButtonDisabled(placeOrderBtn, disabled);
    setPlaceOrderButtonDisabled(mobileSubmitBtn, disabled);
  }

  function clearSubmissionRestoreTimer() {
    if (!submissionState.restoreTimer) return;
    window.clearTimeout(submissionState.restoreTimer);
    submissionState.restoreTimer = null;
  }

  function exitSubmissionProcessing(reason) {
    if (!submissionState.inProgress) return;
    submissionState.inProgress = false;
    clearSubmissionRestoreTimer();
    checkoutForm.removeAttribute('aria-busy');
    setPlaceOrderButtonLabel(placeOrderBtn, placeOrderBtn.getAttribute('data-default-label') || 'Place Order', false);
    setPlaceOrderButtonLabel(mobileSubmitBtn, mobileSubmitBtn.getAttribute('data-default-label') || 'Place Order', false);
    syncSubmitControls();
    updateSubmitAnnouncement(reason || '');
  }

  function enterSubmissionProcessing() {
    if (submissionState.inProgress) return false;
    submissionState.inProgress = true;
    checkoutForm.setAttribute('aria-busy', 'true');
    setPlaceOrderButtonLabel(placeOrderBtn, 'Processing order…', true);
    setPlaceOrderButtonLabel(mobileSubmitBtn, 'Processing order…', true);
    syncSubmitControls();
    updateSubmitAnnouncement('Processing order…');
    clearSubmissionRestoreTimer();
    submissionState.restoreTimer = window.setTimeout(function () {
      exitSubmissionProcessing('Order processing timed out in this tab. Please review and try again.');
    }, 300);
    return true;
  }

  function setShippingSubmissionEnabled(enabled) {
    shippingSubmissionEnabled = !!enabled;
    syncSubmitControls();
  }

  function validateAddressSection() {
    return validationShouldFail ? ['validation failed'] : [];
  }

  mobileSubmitBtn.addEventListener('click', function () {
    if (submissionState.inProgress) return;
    checkoutForm.requestSubmit();
  });

  checkoutForm.addEventListener('submit', function (ev) {
    window.__submitCount = (window.__submitCount || 0) + 1;
    if (submissionState.inProgress) {
      ev.preventDefault();
      return;
    }

    var invalidFields = validateAddressSection();
    if (invalidFields.length > 0) {
      ev.preventDefault();
      return;
    }

    if (couponRequestInFlight) {
      ev.preventDefault();
      return;
    }

    if (shippingQuoteState.pending || !shippingQuoteState.valid) {
      ev.preventDefault();
      setShippingSubmissionEnabled(false);
      return;
    }

    if (!enterSubmissionProcessing()) {
      ev.preventDefault();
      return;
    }

    // Keep the harness page stable while validating UI state transitions.
    ev.preventDefault();

    if (simulateServerFailure) {
      setTimeout(function () {
        exitSubmissionProcessing('Server rejected order. Please retry.');
      }, 10);
    }
  });

  window.addEventListener('pageshow', function () {
    exitSubmissionProcessing('');
  });

  window.__setTestState = function (next) {
    validationShouldFail = !!next.validationShouldFail;
    couponRequestInFlight = !!next.couponPending;
    shippingQuoteState.pending = !!next.shippingPending;
    shippingQuoteState.valid = next.shippingValid !== false;
    simulateServerFailure = !!next.serverFailure;
    if (typeof next.resetSubmitCount === 'boolean' && next.resetSubmitCount) {
      window.__submitCount = 0;
    }
  };

  window.__getTestState = function () {
    return {
      submitCount: window.__submitCount || 0,
      inProgress: submissionState.inProgress,
      desktopDisabled: placeOrderBtn.disabled,
      mobileDisabled: mobileSubmitBtn.disabled,
      desktopText: placeOrderBtn.textContent.trim(),
      mobileText: mobileSubmitBtn.textContent.trim(),
      desktopAriaDisabled: placeOrderBtn.getAttribute('aria-disabled') || '',
      mobileAriaDisabled: mobileSubmitBtn.getAttribute('aria-disabled') || '',
      ariaBusy: checkoutForm.getAttribute('aria-busy') || '',
      announcement: submitStatusEl.textContent || ''
    };
  };

  placeOrderBtn.setAttribute('data-default-label', 'Place Order');
  mobileSubmitBtn.setAttribute('data-default-label', 'Place Order');
  syncSubmitControls();
})();
</script>
</body>
</html>`;

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.setContent(html);

const snapshots = [];

async function setState(next) {
  await page.evaluate((state) => {
    window.__setTestState(state);
  }, next);
}

async function state(name) {
  const s = await page.evaluate(() => window.__getTestState());
  snapshots.push({ name, s });
  return s;
}

// 1) Rapid double-click protection
await setState({ resetSubmitCount: true });
await Promise.all([
  page.click('#place_order_btn'),
  page.click('#place_order_btn'),
]);
let s = await state('rapid double-click');
assert(s.submitCount >= 1, 'Expected at least one submit event');
assert(s.inProgress === true, 'Expected processing state after first valid submit');
assert(s.desktopDisabled && s.mobileDisabled, 'Expected desktop/mobile disabled together');
assert(s.desktopText.includes('Processing order'), 'Expected processing text on desktop');

// 2) Desktop + mobile share one state
await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
await setState({ resetSubmitCount: true });
await page.click('#mobile_place_order_btn');
s = await state('mobile activation shared state');
assert(s.inProgress === true, 'Expected mobile click to enter processing state');
assert(s.desktopDisabled && s.mobileDisabled, 'Expected both controls disabled after mobile click');

// 3) Enter-key submission
await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
await setState({ resetSubmitCount: true });
await page.focus('#checkout_email');
await page.keyboard.press('Enter');
s = await state('enter-key submission');
assert(s.submitCount === 1, 'Expected Enter key to submit exactly once initially');
assert(s.inProgress === true, 'Expected Enter key to trigger processing state');

// 4) Validation failure should not enter processing
await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
await setState({ validationShouldFail: true, resetSubmitCount: true });
await page.click('#place_order_btn');
s = await state('validation failure');
assert(s.inProgress === false, 'Validation failure must not enter processing state');
assert(!s.desktopDisabled && !s.mobileDisabled, 'Validation failure should keep buttons enabled');

// 5) Pending shipping request blocks submission
await setState({ validationShouldFail: false, shippingPending: true, resetSubmitCount: true });
await page.click('#place_order_btn');
s = await state('pending shipping request');
assert(s.inProgress === false, 'Pending shipping must block processing state');

// 6) Network/server failure restores controls safely
await setState({ shippingPending: false, shippingValid: true, serverFailure: true, resetSubmitCount: true });
await page.click('#place_order_btn');
await page.waitForTimeout(50);
s = await state('server failure restore');
assert(s.inProgress === false, 'Server failure before navigation should restore controls');
assert(!s.desktopDisabled && !s.mobileDisabled, 'Controls should be re-enabled after failure');

// 7) Browser back navigation (bfcache pageshow) resets stuck processing
await setState({ serverFailure: false, resetSubmitCount: true });
await page.click('#place_order_btn');
s = await state('processing before pageshow');
assert(s.inProgress === true, 'Expected processing before pageshow recovery');
await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
s = await state('pageshow recovery');
assert(s.inProgress === false, 'Expected pageshow to clear processing state');
assert(!s.desktopDisabled && !s.mobileDisabled, 'Expected controls restored after pageshow');

console.log('Submission UX browser snapshots:');
for (const item of snapshots) {
  console.log('- ' + item.name + ': count=' + item.s.submitCount + ', inProgress=' + item.s.inProgress + ', desktopDisabled=' + item.s.desktopDisabled + ', mobileDisabled=' + item.s.mobileDisabled + ', announcement=' + JSON.stringify(item.s.announcement));
}

await browser.close();
