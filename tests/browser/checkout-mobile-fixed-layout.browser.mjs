import { chromium } from 'playwright';

const html = String.raw`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Checkout Mobile Fixed Layout Browser Test</title>
<style>
  :root {
    --safe-area-bottom: env(safe-area-inset-bottom, 0px);
    --checkout-mobile-bar-height: 0px;
    --cookie-consent-height: 0px;
  }

  body {
    margin: 0;
    font-family: "Segoe UI", sans-serif;
    background: #f7f8fb;
  }

  .container {
    max-width: 760px;
    margin: 0 auto;
    padding: 12px;
  }

  .field {
    display: block;
    width: 100%;
    margin: 0 0 12px;
    padding: 11px 12px;
    border: 1px solid #d5dce7;
    border-radius: 8px;
    box-sizing: border-box;
    background: #fff;
  }

  .checkout-mobile-submit-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1050;
    background: #fff;
    border-top: 1px solid #d9e0ea;
    padding: 10px 12px calc(10px + var(--safe-area-bottom));
    box-shadow: 0 -8px 22px rgba(15, 33, 55, 0.12);
    transition: opacity 0.18s ease, transform 0.2s ease, visibility 0.2s ease;
  }

  body.checkout-mobile-bar-hidden .checkout-mobile-submit-bar {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(100%);
  }

  .checkout-mobile-submit-bar .btn,
  .cookie-consent-banner .btn {
    min-height: 44px;
    border: 1px solid #c8d3e0;
    border-radius: 8px;
    background: #fff;
    padding: 8px 10px;
  }

  .cookie-consent-banner {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 1060;
    background: #15202d;
    color: #fff;
    padding: 12px 12px calc(12px + var(--safe-area-bottom));
  }

  .cookie-consent-banner.d-none {
    display: none;
  }

  @media (max-width: 767.98px) {
    body.checkout-has-mobile-submit-bar {
      padding-bottom: calc(max(var(--checkout-mobile-bar-height), var(--cookie-consent-height)) + var(--safe-area-bottom) + 1rem);
    }

    body.checkout-has-mobile-submit-bar.checkout-mobile-bar-hidden {
      padding-bottom: calc(var(--cookie-consent-height) + var(--safe-area-bottom) + 1rem);
    }

    body.checkout-has-mobile-submit-bar :is(input, button, [tabindex]):focus-visible {
      scroll-margin-bottom: calc(max(var(--checkout-mobile-bar-height), var(--cookie-consent-height)) + var(--safe-area-bottom) + 1.25rem);
    }
  }
</style>
</head>
<body>
  <main class="container" id="content">
    <h1>Checkout</h1>
    <p>Mobile fixed layout overlap harness.</p>
    <div id="fields"></div>
  </main>

  <div id="checkout_mobile_submit_bar" class="checkout-mobile-submit-bar" aria-label="Checkout quick submit bar">
    <div>Total Rs 1234</div>
    <button type="button" class="btn" id="mobile_place_order_btn">Place Order</button>
  </div>

  <div id="cookieConsentBanner" class="cookie-consent-banner d-none" data-consent-status="unknown">
    <div style="margin-bottom:10px;">We use cookies for attribution after consent.</div>
    <button class="btn" type="button" data-consent-choice="reject">Reject</button>
    <button class="btn" type="button" data-consent-choice="accept">Accept</button>
  </div>

<script>
(function () {
  var fields = document.getElementById('fields');
  for (var i = 1; i <= 24; i += 1) {
    var input = document.createElement('input');
    input.className = 'field';
    input.type = 'text';
    input.placeholder = 'Field ' + i;
    input.id = i === 24 ? 'checkout_last_field' : ('field_' + i);
    fields.appendChild(input);
  }

  var mobileSubmitBar = document.getElementById('checkout_mobile_submit_bar');
  var cookieConsentBanner = document.getElementById('cookieConsentBanner');

  function isElementVisible(el) {
    if (!el || el.classList.contains('d-none')) return false;
    return window.getComputedStyle(el).display !== 'none';
  }

  function measuredHeight(el) {
    if (!isElementVisible(el)) return 0;
    return Math.ceil(el.getBoundingClientRect().height || 0);
  }

  function syncMobileCheckoutLayout() {
    var isMobileViewport = window.matchMedia('(max-width: 991.98px)').matches;
    var cookieVisible = isElementVisible(cookieConsentBanner);
    var cookieHeight = cookieVisible ? measuredHeight(cookieConsentBanner) : 0;
    var barHeight = (!cookieVisible && isMobileViewport) ? measuredHeight(mobileSubmitBar) : 0;

    document.documentElement.style.setProperty('--cookie-consent-height', cookieHeight + 'px');
    document.documentElement.style.setProperty('--checkout-mobile-bar-height', barHeight + 'px');
    document.body.classList.add('checkout-has-mobile-submit-bar');
    document.body.classList.toggle('checkout-mobile-bar-hidden', cookieVisible || !isMobileViewport);
    mobileSubmitBar.setAttribute('aria-hidden', (cookieVisible || !isMobileViewport) ? 'true' : 'false');
  }

  function notifyConsentLayout() {
    var visible = !cookieConsentBanner.classList.contains('d-none') && window.getComputedStyle(cookieConsentBanner).display !== 'none';
    var height = visible ? Math.ceil(cookieConsentBanner.getBoundingClientRect().height || 0) : 0;
    document.dispatchEvent(new CustomEvent('cookie-consent-visibility-change', {
      detail: { visible: visible, height: height }
    }));
  }

  window.__toggleCookieBanner = function (visible) {
    cookieConsentBanner.classList.toggle('d-none', !visible);
    notifyConsentLayout();
    syncMobileCheckoutLayout();
  };

  window.__snapshotLayout = function () {
    var barRect = mobileSubmitBar.getBoundingClientRect();
    var cookieRect = cookieConsentBanner.getBoundingClientRect();
    var lastField = document.getElementById('checkout_last_field');
    var lastRect = lastField.getBoundingClientRect();
    var cookieVisible = isElementVisible(cookieConsentBanner);
    var barHidden = document.body.classList.contains('checkout-mobile-bar-hidden');
    var ctaOverlapCookie = cookieVisible && !barHidden && (barRect.bottom > cookieRect.top);

    return {
      viewport: { width: window.innerWidth, height: window.innerHeight },
      vars: {
        checkoutBar: getComputedStyle(document.documentElement).getPropertyValue('--checkout-mobile-bar-height').trim(),
        cookieHeight: getComputedStyle(document.documentElement).getPropertyValue('--cookie-consent-height').trim(),
        bodyPaddingBottom: getComputedStyle(document.body).paddingBottom
      },
      cookieVisible: cookieVisible,
      barHidden: barHidden,
      ctaOverlapCookie: ctaOverlapCookie,
      barRect: { top: barRect.top, bottom: barRect.bottom, height: barRect.height },
      cookieRect: { top: cookieRect.top, bottom: cookieRect.bottom, height: cookieRect.height },
      lastRect: { top: lastRect.top, bottom: lastRect.bottom, height: lastRect.height }
    };
  };

  document.addEventListener('cookie-consent-visibility-change', syncMobileCheckoutLayout);
  window.addEventListener('resize', syncMobileCheckoutLayout);
  window.addEventListener('orientationchange', syncMobileCheckoutLayout);
  syncMobileCheckoutLayout();
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

const viewports = [
  { width: 360, height: 800, name: '360x800' },
  { width: 390, height: 844, name: '390x844' },
  { width: 412, height: 915, name: '412x915' },
  { width: 768, height: 1024, name: '768x1024' }
];

const report = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.waitForTimeout(30);

  await page.evaluate(() => {
    window.__toggleCookieBanner(false);
    const last = document.getElementById('checkout_last_field');
    last.focus();
    last.scrollIntoView({ block: 'nearest' });
  });

  let state = await page.evaluate(() => window.__snapshotLayout());
  const viewportBottom = state.viewport.height;
  assert(state.ctaOverlapCookie === false, `No cookie overlap expected when hidden (${vp.name})`);
  assert(state.barHidden === false, `CTA should remain visible without cookie (${vp.name})`);
  assert(state.lastRect.bottom <= viewportBottom - 8, `Focused field should stay visible above CTA (${vp.name})`);

  const noCookie = {
    name: vp.name,
    stage: 'cookie-hidden',
    bar: state.vars.checkoutBar,
    cookie: state.vars.cookieHeight,
    bodyPaddingBottom: state.vars.bodyPaddingBottom,
    overlap: state.ctaOverlapCookie,
    barHidden: state.barHidden,
    focusedBottom: Math.round(state.lastRect.bottom),
    viewportBottom: viewportBottom
  };
  report.push(noCookie);

  await page.evaluate(() => {
    window.__toggleCookieBanner(true);
    const last = document.getElementById('checkout_last_field');
    last.focus();
    last.scrollIntoView({ block: 'nearest' });
  });

  state = await page.evaluate(() => window.__snapshotLayout());
  assert(state.barHidden === true, `CTA should hide while cookie banner is visible (${vp.name})`);
  assert(state.ctaOverlapCookie === false, `CTA/cookie overlap must be prevented (${vp.name})`);
  assert(parseInt(state.vars.cookieHeight, 10) > 0, `Cookie height var should be populated (${vp.name})`);
  assert(state.lastRect.bottom <= state.viewport.height - 8, `Focused field should stay visible with cookie banner (${vp.name})`);

  report.push({
    name: vp.name,
    stage: 'cookie-visible',
    bar: state.vars.checkoutBar,
    cookie: state.vars.cookieHeight,
    bodyPaddingBottom: state.vars.bodyPaddingBottom,
    overlap: state.ctaOverlapCookie,
    barHidden: state.barHidden,
    focusedBottom: Math.round(state.lastRect.bottom),
    viewportBottom: state.viewport.height
  });
}

console.log('Checkout mobile fixed-layout viewport report:');
for (const row of report) {
  console.log(
    '- ' + row.name + ' [' + row.stage + ']: '
      + 'bar=' + row.bar
      + ', cookie=' + row.cookie
      + ', bodyPaddingBottom=' + row.bodyPaddingBottom
      + ', barHidden=' + row.barHidden
      + ', overlap=' + row.overlap
      + ', focusedBottom=' + row.focusedBottom + '/' + row.viewportBottom
  );
}

await browser.close();
