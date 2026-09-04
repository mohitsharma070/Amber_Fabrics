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

test.beforeEach(async ({ page, context }) => {
  providerAttempts.set(page, []);
  pageErrors.set(page, []);
  await context.clearCookies();

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

test('@desktop checkout refresh quote with JavaScript disabled', async ({ page, context }) => {
  expect((await context.cookies()).filter((cookie) => cookie.name === 'amber_marketing_consent')).toEqual([]);

  // 1. Start as a genuine first-time visitor and seed a safe disposable cart fixture.
  await page.goto('/catalog?q=E2E+Simple+Product&utm_source=nojs-e2e');
  const consentBanner = page.locator('#cookieConsentBanner');
  await expect(consentBanner).toHaveAttribute('data-consent-status', 'unknown');
  await expect(consentBanner).toHaveCSS('position', 'static');
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

  // Refresh delivery first; payment controls are intentionally server-rendered
  // only after the address has been validated.
  await page.getByRole('button', { name: 'Continue to Payment' }).press('Enter');
  await expect(page).toHaveURL(/\/checkout(?:\.php)?$/);

  // Choose online payment and 'card', then refresh again to test PRG persistence.
  await page.getByRole('radio', { name: /Pay Online/ }).check();
  const onlineMethodSelect = page.locator('#online_method_noscript');
  await expect(onlineMethodSelect).toBeVisible();
  await onlineMethodSelect.selectOption('card');

  // 4. Submit Continue to Payment (intermediate refresh)
  await page.getByRole('button', { name: 'Continue to Payment' }).press('Enter');

  // 5. Verify PRG reload
  await expect(page).toHaveURL(/\/checkout(?:\.php)?$/);

  // 6. Verify entered values remain
  await expect(page.getByLabel('Full Name *', { exact: true })).toHaveValue('NoJS Buyer');
  await expect(page.getByLabel('Pincode *', { exact: true })).toHaveValue('302001');
  await expect(page.getByRole('radio', { name: /Pay Online/ })).toBeChecked();
  await expect(page.locator('#online_method_noscript')).toHaveValue('card');

  // 7. Verify payment/review controls are server-rendered and usable
  // Check that the review section is now present in the DOM (it should be, since delivery is complete)
  const submitButton = page.locator('#checkout_submit');
  await expect(submitButton).toBeVisible();

  const marketingCookies = (await context.cookies()).filter((cookie) => (
    cookie.name === 'amber_marketing_consent' || cookie.name === 'amber_utm'
  ));
  expect(marketingCookies, 'Unknown consent must not silently create consent or attribution cookies.').toEqual([]);
  await expect(page.locator('#cookieConsentBanner')).toHaveAttribute('data-consent-status', 'unknown');

  // Use the browser context's shared cookie jar to prove invalid CSRF cannot replace valid state.
  const sessionBefore = (await context.cookies()).find((cookie) => cookie.name === 'PHPSESSID');
  expect(sessionBefore, 'The CSRF request must use the active browser session.').toBeTruthy();
  const response = await context.request.post('/checkout.php', {
    maxRedirects: 0,
    form: {
      action: 'refresh_quote',
      full_name: 'Hacker Name',
      // No CSRF token
    }
  });
  
  expect(response.status()).toBe(302);
  const redirectUrl = response.headers().location;
  expect(redirectUrl).toContain('/checkout.php');
  const sessionAfter = (await context.cookies()).find((cookie) => cookie.name === 'PHPSESSID');
  expect(sessionAfter && sessionAfter.value).toBe(sessionBefore.value);

  // Reload the page and ensure 'Hacker Name' wasn't persisted
  await page.goto('/checkout.php');
  await expect(page.getByText('Security session expired. Please verify your details and try again.')).toBeVisible();
  await expect(page.getByLabel('Full Name *', { exact: true })).toHaveValue('NoJS Buyer');
});

test('@desktop no-JavaScript consent controls use server POST and keep Privacy Policy reachable', async ({ page, context }) => {
  await page.goto('/catalog');
  const banner = page.locator('#cookieConsentBanner');
  await expect(banner).toHaveAttribute('data-consent-status', 'unknown');
  await expect(banner).toHaveCSS('position', 'static');

  await banner.getByRole('link', { name: 'Privacy Policy' }).click();
  await expect(page).toHaveURL(/\/privacy-policy$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Privacy Policy' })).toBeVisible();

  await page.goBack();
  const rejectButton = page.locator('[data-consent-noscript-actions]').getByRole('button', { name: 'Reject' });
  await rejectButton.click();
  await expect(page).toHaveURL(/\/catalog$/);
  const consentCookie = (await context.cookies()).find((cookie) => cookie.name === 'amber_marketing_consent');
  expect(consentCookie && consentCookie.value).toBe('denied');
  await expect(page.locator('#cookieConsentBanner')).toHaveAttribute('data-consent-status', 'denied');
  await expect(page.locator('#cookieConsentBanner')).toBeHidden();
});

test('@desktop no-JavaScript checkout retains a server quote when the courier hook throws', async ({ page }) => {
  const fallbackOrigin = 'http://127.0.0.1:8002';
  await page.goto(`${fallbackOrigin}/fabric/e2e-simple-product`);
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await page.getByLabel('Full Name *', { exact: true }).fill('NoJS Courier Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('nojs-courier@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('18 Fallback Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).press('Enter');

  await expect(page).toHaveURL(`${fallbackOrigin}/checkout.php`);
  await expect(page.locator('#shipping_quote_token')).not.toHaveValue('');
  await expect(page.locator('#checkout_submit')).toBeVisible();
  await expect(page.getByText('E2E courier timeout containing provider-only detail')).toHaveCount(0);
});
