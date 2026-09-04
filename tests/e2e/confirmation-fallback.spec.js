const path = require('node:path');
const { test, expect } = require('@playwright/test');

const sharedScript = path.resolve(__dirname, '../../js/script.js');
const bootstrapBundlePattern = '**/js/bootstrap.bundle-5.3.3.min.js';
const forbiddenProviderHosts = [
  /(^|\.)razorpay\.com$/i,
  /(^|\.)bigship\.in$/i,
  /(^|\.)facebook\.com$/i,
  /(^|\.)facebook\.net$/i,
  /(^|\.)google-analytics\.com$/i,
  /(^|\.)googletagmanager\.com$/i,
];

const providerAttempts = new WeakMap();
const pageErrors = new WeakMap();

test.beforeEach(async ({ page }) => {
  providerAttempts.set(page, []);
  pageErrors.set(page, []);

  await page.route('**/*', async (route) => {
    const hostname = new URL(route.request().url()).hostname;
    if (forbiddenProviderHosts.some((pattern) => pattern.test(hostname))) {
      providerAttempts.get(page).push(route.request().url());
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  page.on('pageerror', (error) => {
    pageErrors.get(page).push(error.message);
  });
});

test.afterEach(async ({ page }) => {
  expect(providerAttempts.get(page), 'Confirmation E2E must not contact payment, courier, or analytics providers.').toEqual([]);
  expect(pageErrors.get(page), 'Confirmation E2E must not produce uncaught first-party page errors.').toEqual([]);
});

async function dismissCookieBanner(page) {
  const rejectButton = page.getByRole('button', { name: 'Reject' });
  if (await rejectButton.isVisible()) {
    const consentResponse = page.waitForResponse((response) => response.url().includes('/cookie-consent.php'));
    await rejectButton.click();
    expect((await consentResponse).ok()).toBe(true);
  }
}

async function addSimpleFixtureToCart(page) {
  await page.goto('/fabric/e2e-simple-product');
  await expect(page.getByRole('heading', { name: 'E2E Simple Product' })).toBeVisible();
  await dismissCookieBanner(page);
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await page.waitForLoadState('load');
  await expect(page.getByText('E2E Simple Product', { exact: true })).toBeVisible();
}

async function blockBootstrapBundle(page) {
  await page.route(bootstrapBundlePattern, (route) => route.abort('failed'));
}

function captureNativeConfirmations(page, decisions) {
  const messages = [];
  page.on('dialog', async (dialog) => {
    expect(dialog.type()).toBe('confirm');
    messages.push(dialog.message());
    if (decisions.shift()) await dialog.accept();
    else await dialog.dismiss();
  });
  return messages;
}

async function fillCheckoutReview(page) {
  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await page.waitForLoadState('load');
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Confirmation Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('confirm@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('12 Reliability Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  await expect(page.getByRole('heading', { name: 'Payment Method' })).toBeVisible();
  await expect(page.locator('#shipping_quote_token')).not.toHaveValue('');
}

test('@bootstrap-available @desktop preserves the accessible Bootstrap modal confirmation', async ({ page }) => {
  await addSimpleFixtureToCart(page);

  expect(await page.evaluate(() => Boolean(window.bootstrap && window.bootstrap.Modal))).toBe(true);
  await page.locator('form[action="/remove-cart.php"]').getByRole('button', { name: 'Remove' }).click();

  const confirmation = page.getByRole('dialog', { name: 'Remove Item?' });
  await expect(confirmation).toBeVisible();
  await expect(page.locator('.modal.show#uiConfirmDialog')).toHaveCount(1);
  await confirmation.getByRole('button', { name: 'Keep Item' }).click();
  await expect(confirmation).toBeHidden();
  await expect(page.getByText('E2E Simple Product', { exact: true })).toBeVisible();
});

test('@bootstrap-failure @desktop cancels then accepts degraded cart removal exactly once', async ({ page }) => {
  await blockBootstrapBundle(page);
  const messages = captureNativeConfirmations(page, [false, true]);
  await addSimpleFixtureToCart(page);
  expect(await page.evaluate(() => Boolean(window.bootstrap && window.bootstrap.Modal))).toBe(false);

  const removeForm = page.locator('form[action="/remove-cart.php"]');
  const removeButton = removeForm.getByRole('button', { name: 'Remove' });
  await removeButton.click();
  await expect.poll(() => messages.length).toBe(1);
  await expect(removeForm).not.toHaveAttribute('data-confirm-pending', '1');
  await expect(page.getByText('E2E Simple Product', { exact: true })).toBeVisible();

  const requests = [];
  await page.route('**/remove-cart.php', async (route) => {
    requests.push(route.request());
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Cart removal captured</h1>' });
  });
  await removeButton.click();
  await expect(page.getByRole('heading', { name: 'Cart removal captured' })).toBeVisible();

  expect(messages).toHaveLength(2);
  expect(messages[0]).toContain('Remove this product from your cart?');
  expect(requests).toHaveLength(1);
  expect(requests[0].method()).toBe('POST');
  expect(requests[0].postData()).toContain('csrf_token=');
  expect(requests[0].postData()).toContain('cart_key=');
});

test('@bootstrap-failure @mobile keeps navigation, filters, and grouped footer sections keyboard-operable', async ({ page }) => {
  await blockBootstrapBundle(page);
  await page.goto('/');
  await dismissCookieBanner(page);
  expect(await page.evaluate(() => Boolean(window.bootstrap && window.bootstrap.Offcanvas))).toBe(false);

  const menuButton = page.getByRole('button', { name: 'Open menu' });
  await menuButton.focus();
  await menuButton.press('Enter');
  const navigationDrawer = page.locator('#mobileNavDrawer');
  await expect(navigationDrawer).toBeVisible();
  await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('body')).toHaveClass(/native-offcanvas-open/);
  await expect(navigationDrawer.locator(':focus')).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(navigationDrawer).toBeHidden();
  await expect(menuButton).toBeFocused();
  await expect(menuButton).toHaveAttribute('aria-expanded', 'false');

  const supportButton = page.getByRole('button', { name: 'Support', exact: true });
  const exploreButton = page.getByRole('button', { name: 'Explore', exact: true });
  await supportButton.click();
  await expect(page.locator('#footerSupport')).toBeVisible();
  await expect(supportButton).toHaveAttribute('aria-expanded', 'true');
  await exploreButton.click();
  await expect(page.locator('#footerSupport')).toBeHidden();
  await expect(page.locator('#footerExplore')).toBeVisible();
  await expect(supportButton).toHaveAttribute('aria-expanded', 'false');

  await page.goto('/catalog');
  const filterButton = page.getByRole('button', { name: /^Filters/ });
  await filterButton.click();
  const filterDrawer = page.locator('#catalogFiltersDrawer');
  await expect(filterDrawer).toBeVisible();
  await expect(filterButton).toHaveAttribute('aria-expanded', 'true');
  await filterDrawer.getByRole('button', { name: 'Close' }).click();
  await expect(filterDrawer).toBeHidden();
  await expect(filterButton).toBeFocused();

  await page.setViewportSize({ width: 800, height: 900 });
  await page.goto('/');
  const tabletMenuButton = page.locator('[data-mobile-nav-menu]');
  await expect(tabletMenuButton).toBeVisible();
  await tabletMenuButton.click();
  await expect(page.locator('#mobileNavDrawer')).toBeVisible();
});

test('@bootstrap-failure @desktop requires degraded confirmation for wishlist removal', async ({ page }) => {
  await blockBootstrapBundle(page);
  const messages = captureNativeConfirmations(page, [true]);
  await addSimpleFixtureToCart(page);
  await page.locator('form[action="/move-to-wishlist.php"]').getByRole('button', { name: 'Move to Wishlist' }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await page.waitForLoadState('load');

  const requests = [];
  await page.route('**/remove-wishlist.php', async (route) => {
    requests.push(route.request());
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Wishlist removal captured</h1>' });
  });
  await page.locator('form[action="/remove-wishlist.php"]').getByRole('button', { name: 'Remove' }).click();
  await expect(page.getByRole('heading', { name: 'Wishlist removal captured' })).toBeVisible();

  expect(messages).toHaveLength(1);
  expect(messages[0]).toContain('Remove this product from your saved items?');
  expect(requests).toHaveLength(1);
  expect(requests[0].method()).toBe('POST');
  expect(requests[0].postData()).toContain('csrf_token=');
  expect(requests[0].postData()).toContain('cart_key=');
});

test('@bootstrap-failure @desktop keeps checkout submission usable without contacting payment providers', async ({ page }) => {
  await blockBootstrapBundle(page);
  const messages = captureNativeConfirmations(page, [true]);
  await addSimpleFixtureToCart(page);
  await fillCheckoutReview(page);

  const requests = [];
  await page.route('**/place-order.php', async (route) => {
    requests.push(route.request());
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Checkout submission captured</h1>' });
  });
  await page.locator('#checkout_submit').click();
  await expect(page.getByRole('heading', { name: 'Checkout submission captured' })).toBeVisible();

  expect(messages).toHaveLength(1);
  expect(messages[0]).toContain('Payment method: Cash on Delivery.');
  expect(requests).toHaveLength(1);
  expect(requests[0].method()).toBe('POST');
  for (const field of ['csrf_token=', 'order_nonce=', 'shipping_quote_token=', 'payment_method=cod']) {
    expect(requests[0].postData()).toContain(field);
  }
});

test('@bootstrap-failure @desktop preserves the submitter and blocks duplicate degraded submissions', async ({ page }) => {
  const messages = captureNativeConfirmations(page, [false, true]);
  await page.setContent(`
    <form id="confirmation-fixture" action="/capture" method="post" data-confirm-modal
          data-confirm-title="Remove Item?" data-confirm-message="Remove this item?" data-confirm-variant="danger">
      <input type="hidden" name="csrf_token" value="fixture-csrf-token">
      <button type="submit" name="operation" value="remove">Remove</button>
      <button type="submit" name="operation" value="archive">Archive</button>
    </form>
  `);
  await page.addScriptTag({ path: sharedScript });
  await page.evaluate(() => {
    window.__acceptedSubmissions = [];
    document.addEventListener('submit', (event) => {
      if (!(event.target instanceof HTMLFormElement) || event.target.id !== 'confirmation-fixture') return;
      event.preventDefault();
      window.__acceptedSubmissions.push({
        submitterName: event.submitter && event.submitter.name,
        submitterValue: event.submitter && event.submitter.value,
        csrfToken: new FormData(event.target).get('csrf_token'),
      });
    });
  });

  const removeButton = page.getByRole('button', { name: 'Remove' });
  await removeButton.click();
  await expect.poll(() => messages.length).toBe(1);
  await expect(page.locator('#confirmation-fixture')).not.toHaveAttribute('data-confirm-pending', '1');
  expect(await page.evaluate(() => window.__acceptedSubmissions)).toEqual([]);

  await removeButton.click();
  await expect.poll(() => messages.length).toBe(2);
  await expect.poll(() => page.evaluate(() => window.__acceptedSubmissions.length)).toBe(1);
  await page.evaluate(() => {
    const form = document.getElementById('confirmation-fixture');
    const submitter = form.querySelector('button[value="remove"]');
    form.requestSubmit(submitter);
    form.requestSubmit(submitter);
  });

  expect(messages).toHaveLength(2);
  expect(await page.evaluate(() => window.__acceptedSubmissions)).toEqual([{
    submitterName: 'operation',
    submitterValue: 'remove',
    csrfToken: 'fixture-csrf-token',
  }]);
});
