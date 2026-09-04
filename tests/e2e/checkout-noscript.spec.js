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

test.use({ javaScriptEnabled: false });

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

test('@desktop checkout refresh quote with JavaScript disabled', async ({ page }) => {
  // 1. Seed a safe disposable cart fixture
  await page.goto('/catalog?q=E2E+Simple+Product');
  await page.getByRole('link', { name: 'E2E Simple Product', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'E2E Simple Product' })).toBeVisible();
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
  
  // 2. Go to checkout with JS disabled
  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await expect(page).toHaveURL(/\/checkout/);
  
  // 3. Enter valid address/contact information
  await page.getByLabel('Full Name *', { exact: true }).fill('NoJS Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('nojs@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('12 NoJS Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  
  // Choose online payment and 'card' method to test persistence
  await page.getByRole('radio', { name: /Pay Online/ }).check();
  const onlineMethodSelect = page.locator('#online_method_noscript');
  await onlineMethodSelect.selectOption('card');

  // 4. Submit Continue to Payment (intermediate refresh)
  await page.getByRole('button', { name: 'Continue to Payment' }).click();

  // 5. Verify PRG reload
  await expect(page).toHaveURL(/\/checkout\.php$/);

  // 6. Verify entered values remain
  await expect(page.getByLabel('Full Name *', { exact: true })).toHaveValue('NoJS Buyer');
  await expect(page.getByLabel('Pincode *', { exact: true })).toHaveValue('302001');
  await expect(page.getByRole('radio', { name: /Pay Online/ })).toBeChecked();
  await expect(page.locator('#online_method_noscript')).toHaveValue('card');
  
  // 7. Verify payment/review controls are server-rendered and usable
  // Check that the review section is now present in the DOM (it should be, since delivery is complete)
  const submitButton = page.locator('#checkout_submit');
  await expect(submitButton).toBeVisible();
  
  // Test invalid CSRF failure (simulate CSRF missing)
  // We can do this by removing the CSRF token and submitting again
  await page.evaluate(() => {
    // Evaluation in browser context won't work with JS disabled.
    // Instead we'll navigate manually by building a form without CSRF.
  });
  
  // Since evaluate doesn't work with JS disabled, we will simulate the bad CSRF by posting directly.
  const response = await page.request.post('/checkout.php', {
    form: {
      action: 'refresh_quote',
      full_name: 'Hacker Name',
      // No CSRF token
    }
  });
  
  expect(response.status()).toBe(302);
  const redirectUrl = response.headers().location;
  expect(redirectUrl).toContain('/checkout.php');
  
  // Reload the page and ensure 'Hacker Name' wasn't persisted
  await page.goto('/checkout.php');
  await expect(page.getByLabel('Full Name *', { exact: true })).toHaveValue('NoJS Buyer');
});

