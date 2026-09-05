const { spawn, execFileSync } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const root = path.join(__dirname, '..', '..');
let children = [];
let origin;
let providerAttempts;
let pageErrors;
let orderErrors;

async function freePort() {
  const server = net.createServer();
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise((resolve) => server.close(resolve));
  return port;
}

async function startServer(port, router, env) {
  const child = spawn('php', ['-S', `127.0.0.1:${port}`, router], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  child.stderr.on('data', (data) => {
    for (const match of data.toString().matchAll(/place-order failed: ([^\r\n]+)/g)) orderErrors.push(match[1]);
  });
  children.push(child);
  await expect.poll(async () => {
    if (child.exitCode !== null) throw new Error('Local fixture server exited.');
    return new Promise((resolve) => {
      const socket = net.connect(port, '127.0.0.1');
      socket.on('connect', () => { socket.destroy(); resolve(true); });
      socket.on('error', () => resolve(false));
    });
  }).toBe(true);
}

test.beforeEach(async ({ page }) => {
  orderErrors = [];
  expect(process.env.APP_MODE).toBe('local');
  expect(process.env.APP_ENV).toBe('test');
  expect(process.env.E2E_FIXTURE_CONFIRM).toBe('1');
  expect(process.env.DB_NAME).toMatch(/_(test|e2e)$/);
  const stubPort = await freePort();
  const storePort = await freePort();
  origin = `http://127.0.0.1:${storePort}`;
  const env = { ...process.env, APP_URL: origin, SHIPPING_COURIER_ENABLED: '0',
    SHIPPING_COURIER_AUTO_CREATE: '0', SHIPPING_COURIER_TRACKING_SYNC: '0',
    GOOGLE_ANALYTICS_ENABLED: '0', META_PIXEL_ID: '', META_CAPI_PIXEL_ID: '', META_CAPI_ACCESS_TOKEN: '',
    COD_GUARD_WHATSAPP_PROVIDER: '', COD_GUARD_WHATSAPP_ACCESS_TOKEN: '', MAIL_DRIVER: 'log',
    E2E_BIGSHIP_STUB_URL: `http://127.0.0.1:${stubPort}` };
  await startServer(stubPort, 'tests/helpers/bigship_quote_stub.php', env);
  await startServer(storePort, 'tests/e2e/shipping-deadline-router.php', env);
  providerAttempts = [];
  pageErrors = [];
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.hostname !== '127.0.0.1') {
      providerAttempts.push(url.href);
      return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.goto(`${origin}/fabric/e2e-simple-product`);
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
});

test.afterEach(async () => {
  for (const child of children) {
    if (child.exitCode === null) {
      const closed = new Promise((resolve) => child.once('exit', resolve));
      child.kill();
      await closed;
    }
  }
  children = [];
  expect(providerAttempts).toEqual([]);
  expect(pageErrors).toEqual([]);
});

async function quote(page, pincode, payment = 'cod', extra = {}) {
  const csrf = await page.locator('#checkout_form [name="csrf_token"]').inputValue();
  return page.context().request.post(`${origin}/shipping-rate.php`, {
    form: { csrf_token: csrf, pincode, payment_method: payment, ...extra },
  });
}

async function fillAddress(page, pincode, email) {
  for (const [label, value] of [['Full Name', 'Shipping Test Buyer'], ['Phone', '9876543210'],
    ['Email', email], ['Address', '42 Deadline Street'], ['City', 'Jaipur'],
    ['State', 'Rajasthan'], ['Pincode', pincode]]) {
    await page.getByLabel(`${label} *`, { exact: true }).fill(value);
  }
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
}

test('@desktop provider success and errors preserve server totals and tokens for COD and online', async ({ page }) => {
  for (const payment of ['cod', 'razorpay']) {
    const manual = await (await quote(page, '302009', payment)).json();
    expect(manual).toMatchObject({ ok: true, source: 'manual' });
    const live = await (await quote(page, '302001', payment, { fallback_only: '1', shipping_total: '0' })).json();
    expect(live).toMatchObject({ ok: true, source: 'bigship', courier_id: 42, shipping_total: 85 });
    expect(live.quote_token).toMatch(/^[a-f0-9]{32}$/);
    expect(live.base_shipping + live.cod_fee).toBe(85);
    expect(live.cod_fee).toBe(payment === 'cod' ? 20 : 0);
    for (const pincode of ['302003', '302004', '302005', '302006']) {
      const response = await quote(page, pincode, payment);
      expect(response.status()).toBe(200);
      const fallback = await response.json();
      expect(fallback).toMatchObject({ ok: true, source: 'manual', base_shipping: manual.base_shipping,
        cod_fee: manual.cod_fee, shipping_total: manual.shipping_total, serviceability_status: manual.serviceability_status,
        estimated_delivery_start: manual.estimated_delivery_start, estimated_delivery_end: manual.estimated_delivery_end });
      expect(fallback.quote_token).toMatch(/^[a-f0-9]{32}$/);
      expect(fallback).not.toHaveProperty('debug_message');
      expect(JSON.stringify(fallback)).not.toContain('PRIVATE');
    }
  }
});

test('@desktop slow login plus rates returns manual token before browser timeout and place-order accepts it', async ({ page }) => {
  const email = `shipping-deadline-${Date.now()}@example.test`;
  const manual = await (await quote(page, '302009')).json();
  await fillAddress(page, '302002', email);
  await page.getByLabel('Coupon Code').fill('E2E10');
  await page.getByRole('button', { name: 'Apply', exact: true }).click();
  await expect(page.getByText('Coupon: E2E10')).toBeVisible();
  const responsePromise = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  const started = Date.now();
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  const response = await responsePromise;
  const fallback = await response.json();
  const elapsed = Date.now() - started;
  console.log(`Browser received server manual fallback in ${elapsed}ms`);
  expect(elapsed).toBeGreaterThanOrEqual(6500);
  expect(elapsed).toBeLessThan(10000);
  expect(fallback).toMatchObject({ ok: true, source: 'manual', shipping_total: manual.shipping_total });
  await expect(page.locator('#shipping_quote_token')).toHaveValue(fallback.quote_token);
  await expect(page.getByText('Step 3 of 3: Review').first()).toBeVisible();
  await expect(page.getByText('Shipping calculation timed out. Please try again.')).toHaveCount(0);
  const consent = page.locator('#cod_whatsapp_consent');
  if (await consent.isVisible()) await consent.check();
  await page.getByRole('button', { name: /Place COD Order/ }).first().click();
  await page.getByRole('dialog', { name: 'Confirm COD Order' }).getByRole('button', { name: /Place COD Order|Confirm/ }).click();
  await expect(page).toHaveURL(/\/order-success(?:\.php)?\?order=/);
  const probe = JSON.parse(execFileSync('php', [path.join(__dirname, 'commerce-order-probe.php'), '-', email], {
    cwd: root, env: process.env, encoding: 'utf8',
  }));
  expect(probe.order).toMatchObject({ shipping_source: 'manual', shipping_quote_token: fallback.quote_token, coupon_code: 'E2E10' });
  expect(Number(probe.order.coupon_discount)).toBeGreaterThan(0);
  const invoice = Number((Number(probe.order.subtotal) - Number(probe.order.coupon_discount)).toFixed(2));
  expect(Number(probe.quote.subtotal)).toBe(invoice);
  expect(Number(probe.quote.shipping_total)).toBe(fallback.shipping_total);
  expect(Number(probe.order.shipping_amount)).toBe(fallback.shipping_total);
  expect(Number(probe.order.total_amount)).toBe(Number((invoice + fallback.shipping_total).toFixed(2)));
  expect(probe.quote).toMatchObject({ source: 'manual', serviceability_status: fallback.serviceability_status,
    estimated_delivery_start: fallback.estimated_delivery_start, estimated_delivery_end: fallback.estimated_delivery_end });
  expect(probe.orders_for_email).toBe(1);
  expect(probe.coupon_reservations).toBe(1);
});

test('@desktop invalid pincode, CSRF and changed cart cannot receive fallback tokens', async ({ page }) => {
  for (const [pincode, extra, status] of [['000000', {}, 422], ['302001', { csrf_token: 'invalid' }, 403]]) {
    const response = await quote(page, pincode, 'cod', extra);
    expect(response.status()).toBe(status);
    expect(await response.json()).not.toHaveProperty('quote_token');
  }
  const csrf = await page.locator('#checkout_form [name="csrf_token"]').inputValue();
  expect((await page.context().request.post(`${origin}/__e2e/change-cart`, { form: { csrf_token: csrf } })).ok()).toBe(true);
  const changed = await quote(page, '302001');
  expect(changed.status()).toBe(409);
  expect(await changed.json()).toMatchObject({ ok: false, code: 'cart_changed', reload: true });
  expect(await changed.json()).not.toHaveProperty('quote_token');
});

test('@desktop tampered token and mismatched quote context cannot place an order', async ({ page }) => {
  await fillAddress(page, '302003', `tampered-${Date.now()}@example.test`);
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  await expect(page.locator('#shipping_quote_token')).not.toHaveValue('');
  const form = await page.locator('#checkout_form').evaluate((element) => Object.fromEntries(new FormData(element)));
  for (const override of [{ shipping_quote_token: '0'.repeat(32) }, { pincode: '302004' }, { payment_method: 'razorpay' }]) {
    const response = await page.context().request.post(`${origin}/place-order.php`, { form: { ...form, ...override }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toMatch(/checkout/);
    await page.goto(`${origin}/checkout`);
    await expect(page.getByText('Unable to place order right now. Please try again.').first()).toBeVisible();
    expect(orderErrors.at(-1)).toMatch(/Shipping quote (expired|changed)/);
    form.order_nonce = await page.locator('[name="order_nonce"]').inputValue();
  }
});

test('@desktop stale shipping response does not unlock a changed delivery address', async ({ page }) => {
  // Exercise the request-id/context guard even when AbortController is unavailable.
  await page.evaluate(() => { window.AbortController = undefined; });
  await fillAddress(page, '302003', `stale-${Date.now()}@example.test`);
  let release;
  const barrier = new Promise((resolve) => { release = resolve; });
  let received;
  const arrived = new Promise((resolve) => { received = resolve; });
  await page.route('**/shipping-rate.php', async (route) => {
    const response = await route.fetch(); // Real server quote; delay only delivery to the browser.
    received();
    await barrier;
    await route.fulfill({ response });
  });
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  await arrived;
  await page.getByLabel('Pincode *', { exact: true }).evaluate((input) => {
    input.value = '302004';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  const delivered = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  release();
  await delivered;
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  await expect(page.locator('#shipping_quote_token')).toHaveValue('');
  await expect(page.locator('#checkout_submit')).toBeHidden();
  await expect(page.locator('#checkout_section_payment')).toHaveAttribute('aria-hidden', 'true');
});
