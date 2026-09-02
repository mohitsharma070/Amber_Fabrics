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
  page.on('pageerror', (error) => {
    pageErrors.get(page).push(error.message);
  });
});

test.afterEach(async ({ page }) => {
  expect(providerAttempts.get(page), 'Storefront E2E must not contact payment, courier, or analytics providers.').toEqual([]);
  expect(pageErrors.get(page), 'Storefront E2E must not produce uncaught first-party page errors.').toEqual([]);
});

async function dismissCookieBanner(page) {
  const banner = page.locator('#cookieConsentBanner');
  const rejectButton = page.getByRole('button', { name: 'Reject' });
  if (await rejectButton.isVisible()) {
    const consentResponse = page.waitForResponse((response) => response.url().includes('/cookie-consent.php'));
    await rejectButton.focus();
    await rejectButton.press('Enter');
    const response = await consentResponse;
    expect(response.ok(), 'Cookie consent should be accepted by the local application.').toBe(true);
    await expect(response.json()).resolves.toMatchObject({ success: true, status: 'denied' });
    await expect(banner).toHaveAttribute('data-consent-status', 'denied');
    await expect(banner).toHaveClass(/\bd-none\b/);
    await expect(banner).toBeHidden();
  }
}

function productPurchaseForm(page) {
  return page.locator('#add_to_cart_form');
}

test('@desktop completes the storefront path through checkout review without submitting an order', async ({ page }) => {
  let deliveryRequestBody = '';
  await page.route('**/delivery-estimate', async (route) => {
    deliveryRequestBody = route.request().postData() || '';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        serviceability_status: 'estimated',
        estimated_dispatch_label: '1-2 business days',
        estimated_delivery_label: '3-5 business days',
        shipping_total: 49,
        payment_method: 'cod',
        cod_fee: 10,
        courier_name: 'E2E Courier',
      }),
    });
  });

  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Latest Drops' })).toBeVisible();
  await dismissCookieBanner(page);

  const primaryNavigation = page.getByRole('navigation', { name: 'Primary' });
  await primaryNavigation.getByRole('link', { name: 'Shop', exact: true }).click();
  await expect(page).toHaveURL(/\/catalog$/);
  await expect(page.getByRole('heading', { name: 'Shop Collection' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Filters', exact: true })).toBeVisible();

  await page.getByLabel('Category').first().selectOption('bedsheets');
  await page.getByRole('button', { name: 'Apply Filters' }).first().click();
  await expect(page).toHaveURL(/category=bedsheets/);

  await page.getByRole('link', { name: 'E2E Variant Product - Amber / Large', exact: true }).click();
  await expect(page).toHaveURL(/\/fabric/);
  await expect(page.getByRole('heading', { name: 'E2E Variant Product' })).toBeVisible();

  const amberVariant = page.getByRole('button', { name: 'Amber', exact: true });
  const largeVariant = page.getByRole('button', { name: 'Large', exact: true });
  await amberVariant.click();
  await expect(amberVariant).toHaveAttribute('aria-pressed', 'true');
  await largeVariant.click();
  await expect(largeVariant).toHaveAttribute('aria-pressed', 'true');

  const productQuantity = page.getByLabel(/Quantity \(pieces\)/);
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  await expect(productQuantity).toHaveValue('2');

  const selectedVariantId = await page.locator('#selected_variant_id_add').inputValue();
  expect(selectedVariantId).not.toBe('0');
  await expect(page.locator('#selected_variant_id_buy')).toHaveValue(selectedVariantId);
  await expect(page.locator('#buy_now_quantity')).toHaveValue('2');

  await page.getByLabel('Delivery pincode').fill('302001');
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.locator('#pdp_delivery_result')).toHaveText(/Estimated shipping.*Dispatch 1-2 business days.*Delivery 3-5 business days.*Shipping ₹49\.00.*includes COD fee ₹10\.00.*E2E Courier/);
  for (const field of ['csrf_token', 'product_id', 'variant_id', 'quantity', 'pincode', 'payment_method']) {
    expect(deliveryRequestBody).toContain(`name="${field}"`);
  }

  await productPurchaseForm(page).getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByText('E2E Variant Product', { exact: true })).toBeVisible();

  const cartQuantity = page.getByLabel('Quantity for E2E Variant Product');
  await expect(cartQuantity).toHaveValue('2');
  await Promise.all([
    page.waitForResponse((response) => response.url().includes('/update-cart.php')),
    page.getByRole('button', { name: 'Increase quantity' }).click(),
  ]);
  await expect(page.getByLabel('Quantity for E2E Variant Product')).toHaveValue('3');

  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('buyer@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('12 Test Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).click();

  await expect(page.getByRole('heading', { name: 'Payment Method' })).toBeVisible();
  await expect(page.getByText('Step 3 of 3: Review').first()).toBeVisible();
  const quoteToken = page.locator('#shipping_quote_token');
  await expect(quoteToken).not.toHaveValue('');
  const codQuoteToken = await quoteToken.inputValue();

  const onlineQuoteResponse = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  await page.getByRole('radio', { name: /Pay Online/ }).check();
  expect((await onlineQuoteResponse).ok(), 'Online payment selection should refresh the local shipping quote.').toBe(true);
  await expect(page.getByRole('radio', { name: /Pay Online/ })).toBeChecked();

  const cardQuoteResponse = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  await page.getByRole('button', { name: 'Card', exact: true }).click();
  expect((await cardQuoteResponse).ok(), 'Online method selection should refresh the local shipping quote.').toBe(true);
  await expect(page.getByRole('button', { name: 'Card', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByRole('button', { name: 'UPI', exact: true })).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('#online_method')).toHaveValue('card');
  await expect(quoteToken).not.toHaveValue('');
  expect(await quoteToken.inputValue()).not.toBe(codQuoteToken);
  const continuePaymentButton = page.locator('#checkout_continue_payment');
  await expect(continuePaymentButton).toBeEnabled();
  await expect(continuePaymentButton).not.toHaveClass(/\bis-loading\b/);

  const codQuoteResponse = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  await page.getByRole('radio', { name: /Cash on Delivery/ }).check();
  expect((await codQuoteResponse).ok(), 'COD selection should refresh the local shipping quote.').toBe(true);
  await expect(page.getByRole('radio', { name: /Cash on Delivery/ })).toBeChecked();
  await expect(quoteToken).not.toHaveValue('');
  await expect(page.getByRole('button', { name: /Place COD Order/ }).first()).toBeVisible();
});

test('@mobile renders the 360px storefront and keeps native navigation and cart behavior usable', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await dismissCookieBanner(page);

  const mobileNavigation = page.getByRole('navigation', { name: 'Mobile bottom navigation' });
  await mobileNavigation.getByRole('link', { name: 'Shop' }).click();
  await expect(page).toHaveURL(/\/catalog$/);
  await expect(page.getByRole('heading', { name: 'Shop Collection' })).toBeVisible();

  await page.getByRole('button', { name: /^Filters/ }).click();
  const filterDrawer = page.locator('#catalogFiltersDrawer');
  await expect(filterDrawer).toBeVisible();
  await filterDrawer.getByLabel('Category').selectOption('bedsheets');
  await filterDrawer.getByRole('button', { name: 'Apply Filters' }).press('Enter');
  await expect(page).toHaveURL(/category=bedsheets/);

  await page.goto('/catalog?q=E2E+Simple+Product');
  await page.getByRole('link', { name: 'E2E Simple Product', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'E2E Simple Product' })).toBeVisible();
  await productPurchaseForm(page).getByRole('button', { name: 'Add to Cart', exact: true }).click();

  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByText('E2E Simple Product', { exact: true })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Mobile bottom navigation' }).getByRole('link', { name: 'Cart' })).toHaveAttribute('aria-current', 'page');

  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Mobile Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('mobile-buyer@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('34 Mobile Test Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).click();

  const consent = page.locator('#cod_whatsapp_consent');
  if (await consent.isVisible()) await consent.check();
  const mobilePlaceOrder = page.locator('#mobile_place_order_btn');
  await expect(mobilePlaceOrder).toBeVisible();
  await expect(mobilePlaceOrder).toHaveAttribute('form', 'checkout_form');
  await mobilePlaceOrder.click();
  const confirmation = page.getByRole('dialog', { name: 'Confirm COD Order' });
  await expect(confirmation).toBeVisible();
  await expect(page.locator('.modal.show#uiConfirmDialog')).toHaveCount(1);
  await confirmation.getByRole('button', { name: 'Cancel' }).click();
  await expect(confirmation).toBeHidden();
  await expect(page).toHaveURL(/\/checkout$/);
});

test('@desktop preserves meter cut length and number-of-cuts representation in cart', async ({ page }) => {
  await page.goto('/fabric/e2e-meter-product');
  await expect(page.getByRole('heading', { name: 'E2E Meter Product' })).toBeVisible();
  await dismissCookieBanner(page);

  const cutLength = page.getByRole('button', { name: '2.5m', exact: true });
  await cutLength.click();
  await expect(cutLength).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  await expect(page.getByLabel('Number of cuts')).toHaveValue('2');
  await expect(page.getByText(/2 cuts × 2\.5m = 5m/)).toBeVisible();
  await expect(page.locator('#selected_meter_length')).toHaveValue('2.5');
  await expect(page.locator('#meter_total_quantity')).toHaveValue('5');
  await expect(page.locator('#buy_now_quantity')).toHaveValue('5');
  await expect(page.locator('#buy_now_meter_length')).toHaveValue('2.5');
  await expect(page.locator('#buy_now_bundle_quantity')).toHaveValue('2');

  await productPurchaseForm(page).getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByText('E2E Meter Product', { exact: true })).toBeVisible();
  await expect(page.getByText(/Qty:\s*2\s*x\s*2\.5m\s*=\s*5m/i)).toBeVisible();
});
