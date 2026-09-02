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
  expect(providerAttempts.get(page), 'PDP quantity tests must not contact payment, courier, or analytics providers.').toEqual([]);
  expect(pageErrors.get(page), 'PDP quantity tests must not produce uncaught first-party page errors.').toEqual([]);
});

async function dismissCookieBanner(page) {
  const reject = page.getByRole('button', { name: 'Reject' });
  if (await reject.isVisible()) {
    const response = page.waitForResponse((candidate) => candidate.url().includes('/cookie-consent.php'));
    await reject.click();
    expect((await response).ok()).toBe(true);
  }
}

async function visitProduct(page, slug, name) {
  await page.goto(`/fabric/${slug}`);
  await expect(page.getByRole('heading', { name })).toBeVisible();
  await dismissCookieBanner(page);
}

async function optionValues(page) {
  return page.locator('#product_quantity option').evaluateAll((options) => options.map((option) => option.value));
}

async function captureAddToCart(page) {
  let body = '';
  await page.route('**/add-to-cart.php', async (route) => {
    if (route.request().method() === 'POST') {
      body = route.request().postData() || '';
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<h1>Captured</h1>' });
      return;
    }
    await route.continue();
  });
  return () => body;
}

test('@desktop uses a valid MOQ-relative window for MOQ 50 / stock 100 and preserves increment/decrement', async ({ page }) => {
  await visitProduct(page, 'e2e-high-moq-50-product', 'E2E High MOQ 50 Product');

  await expect.poll(() => optionValues(page)).toEqual(['50', '55', '60', '65', '70', '75', '80', '85', '90', '95', '100']);
  await expect(page.locator('#buy_now_quantity')).toHaveValue('50');
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  await expect(page.locator('#product_quantity')).toHaveValue('55');
  await expect(page.locator('#buy_now_quantity')).toHaveValue('55');
  await page.getByRole('button', { name: 'Decrease quantity' }).click();
  await expect(page.locator('#product_quantity')).toHaveValue('50');
});

test('@desktop exposes MOQ 25 / stock 25, MOQ 1 / stock 10, and no below-MOQ purchase', async ({ page }) => {
  await visitProduct(page, 'e2e-high-moq-25-product', 'E2E High MOQ 25 Product');
  await expect.poll(() => optionValues(page)).toEqual(['25']);
  await expect(page.locator('#buy_now_quantity')).toHaveValue('25');

  await visitProduct(page, 'e2e-moq-1-stock-10-product', 'E2E MOQ 1 Stock 10 Product');
  await expect.poll(() => optionValues(page)).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);

  await visitProduct(page, 'e2e-high-moq-low-stock-product', 'E2E High MOQ Low Stock Product');
  await expect(page.locator('#product_quantity option')).toHaveCount(0);
  await expect(page.locator('#product_quantity')).toBeDisabled();
  await expect(page.locator('#add_to_cart_submit')).toBeDisabled();
  await expect(page.locator('#buy_now_submit')).toHaveCount(0);
});

test('@desktop rebuilds the valid quantity window when whole-unit variant stock changes', async ({ page }) => {
  await visitProduct(page, 'e2e-high-moq-variant-product', 'E2E High MOQ Variant Product');

  await page.locator('.color-swatch-btn[data-color="Navy"]').click();
  await expect.poll(() => optionValues(page)).toEqual([
    '25', '26', '27', '28', '29', '30', '31', '32', '33', '34',
    '35', '36', '37', '38', '39', '40', '41', '42', '43', '44',
  ]);
  await page.locator('.color-swatch-btn[data-color="Amber"]').click();
  await expect.poll(() => optionValues(page)).toEqual(['25']);
  await expect(page.locator('#selected_variant_id_add')).not.toHaveValue('');
  await expect(page.locator('#selected_variant_id_buy')).toHaveValue(await page.locator('#selected_variant_id_add').inputValue());

  await page.locator('.color-swatch-btn[data-color="Stone"]').click();
  await expect(page.locator('#product_quantity option')).toHaveCount(0);
  await expect(page.locator('#add_to_cart_submit')).toBeDisabled();
  await expect(page.locator('#buy_now_submit')).toBeDisabled();
  await page.locator('.color-swatch-btn[data-color="Navy"]').click();
  await expect.poll(() => optionValues(page)).toHaveLength(20);
  await expect(page.locator('#add_to_cart_submit')).toBeEnabled();
  await expect(page.locator('#buy_now_submit')).toBeEnabled();
});

test('@desktop preserves Add to Cart quantity payload for a high MOQ product', async ({ page }) => {
  await visitProduct(page, 'e2e-high-moq-50-product', 'E2E High MOQ 50 Product');
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  const requestBody = await captureAddToCart(page);
  await page.locator('#add_to_cart_submit').click();
  await expect(page.getByRole('heading', { name: 'Captured' })).toBeVisible();

  expect(requestBody()).toContain('quantity=55');
  expect(requestBody()).toContain('product_id=');
  expect(requestBody()).toContain('csrf_token=');
});

test('@desktop preserves Buy Now quantity payload for a high MOQ product', async ({ page }) => {
  await visitProduct(page, 'e2e-high-moq-50-product', 'E2E High MOQ 50 Product');
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  const requestBody = await captureAddToCart(page);
  await page.getByRole('button', { name: 'Buy Now', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Captured' })).toBeVisible();

  expect(requestBody()).toContain('quantity=55');
  expect(requestBody()).toContain('redirect_to=checkout');
  expect(requestBody()).toContain('product_id=');
  expect(requestBody()).toContain('csrf_token=');
});
