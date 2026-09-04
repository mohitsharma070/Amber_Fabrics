const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

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
  page.on('pageerror', (error) => pageErrors.get(page).push(error.message));
});

test.afterEach(async ({ page }) => {
  expect(providerAttempts.get(page), 'Real commerce E2E must not contact external providers.').toEqual([]);
  expect(pageErrors.get(page), 'Real commerce E2E must not produce uncaught page errors.').toEqual([]);
});

async function addProduct(page, slug) {
  await page.goto(`/fabric/${slug}`);
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
}

async function fillCheckoutAddress(page, email) {
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Commerce Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill(email);
  await page.getByLabel('Address *', { exact: true }).fill('42 Integration Test Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
}

test('@commerce @desktop completes real cart, wishlist, coupon, shipping, COD, and persistence flows', async ({ page }) => {
  const email = `commerce-${Date.now()}@example.test`;

  await addProduct(page, 'e2e-simple-product');
  await page.locator('form[action="/move-to-wishlist.php"]').getByRole('button', { name: 'Move to Wishlist' }).click();
  await expect(page.getByText('E2E Simple Product', { exact: true })).toBeVisible();

  await addProduct(page, 'e2e-simple-product');
  await page.locator('form[action="/move-to-cart.php"]').getByRole('button', { name: 'Move to Cart' }).click();
  await expect(page.getByLabel('Quantity for E2E Simple Product')).toHaveValue('2');

  await page.goto('/fabric/e2e-high-moq-50-product');
  await expect(page.getByLabel(/Quantity \(pieces\)/)).toHaveValue('50');
  await page.getByRole('button', { name: 'Buy Now', exact: true }).click();
  await expect(page).toHaveURL(/\/checkout$/);

  await page.goto('/fabric/e2e-variant-product');
  await page.getByRole('button', { name: 'Amber', exact: true }).click();
  await page.getByRole('button', { name: 'Large', exact: true }).click();
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);

  await page.goto('/fabric/e2e-meter-product');
  await page.getByRole('button', { name: '2.5m', exact: true }).click();
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  await expect(page.getByLabel('Number of cuts')).toHaveValue('2');
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  const meterCartLine = page.locator('.cart-line-item').filter({
    has: page.getByRole('link', { name: 'E2E Meter Product', exact: true }),
  });
  await expect(meterCartLine).toContainText(/Qty:\s*2\s*x\s*2\.5m\s*=\s*5m/i);

  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await fillCheckoutAddress(page, email);
  await page.getByLabel('Coupon Code').fill('E2E10');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await expect(page.getByText('Coupon: E2E10')).toBeVisible();
  await expect(page.getByLabel('Email *', { exact: true })).toHaveValue(email);

  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  await expect(page.getByText('Step 3 of 3: Review').first()).toBeVisible();
  await expect(page.locator('#shipping_quote_token')).not.toHaveValue('');
  await expect(page.getByRole('radio', { name: /Cash on Delivery/ })).toBeChecked();
  const consent = page.locator('#cod_whatsapp_consent');
  if (await consent.isVisible()) await consent.check();

  let originalSubmission = '';
  page.on('request', (submittedRequest) => {
    if (submittedRequest.url().includes('/place-order.php') && submittedRequest.method() === 'POST') {
      originalSubmission = submittedRequest.postData() || '';
    }
  });
  await page.getByRole('button', { name: /Place COD Order/ }).first().click();
  const confirmation = page.getByRole('dialog', { name: 'Confirm COD Order' });
  await expect(confirmation).toBeVisible();
  await confirmation.getByRole('button', { name: /Place COD Order|Confirm/ }).click();
  await expect(page).toHaveURL(/\/order-success(?:\.php)?\?order=/);
  await expect(page.getByRole('heading', { name: 'Order Placed Successfully' })).toBeVisible();
  expect(originalSubmission).toContain('shipping_quote_token=');

  const orderNumber = (await page.locator('.surface-panel p.fs-4').textContent()).trim();
  expect(orderNumber).not.toBe('');

  const duplicate = await page.context().request.post('/place-order.php', {
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    data: originalSubmission,
    maxRedirects: 0,
  });
  expect(duplicate.status()).toBe(302);

  const probePath = path.join(__dirname, 'commerce-order-probe.php');
  const probe = JSON.parse(execFileSync('php', [probePath, orderNumber, email], {
    cwd: path.join(__dirname, '..', '..'),
    env: process.env,
    encoding: 'utf8',
  }));
  expect(probe.orders_for_email).toBe(1);
  expect(probe.order).toMatchObject({
    payment_method: 'cod',
    payment_status: 'pending',
    coupon_code: 'E2E10',
  });
  expect(Number(probe.order.coupon_discount)).toBeGreaterThan(0);
  expect(probe.order.shipping_quote_token).not.toBe('');
  expect(probe.order.inventory_reserved_at).not.toBeNull();
  expect(probe.payment).toMatchObject({ payment_method: 'cod', payment_status: 'pending' });
  expect(probe.coupon_reservations).toBe(1);

  const items = Object.fromEntries(probe.items.map((item) => [item.product_code, item]));
  expect(Number(items['E2E-SIMPLE'].quantity_meters)).toBe(2);
  expect(Number(items['E2E-HIGH-MOQ-50'].quantity_meters)).toBe(50);
  expect(Number(items['E2E-VARIANT'].quantity_meters)).toBe(1);
  expect(Number(items['E2E-METER'].quantity_meters)).toBe(5);
  expect(Number(items['E2E-METER'].bundle_quantity)).toBe(2);
  expect(Number(items['E2E-METER'].meter_length)).toBe(2.5);
  expect(Number(items['E2E-VARIANT'].variant_id)).toBeGreaterThan(0);
});
