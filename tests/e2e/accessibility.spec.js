const { test, expect } = require('@playwright/test');
const { AxeBuilder } = require('@axe-core/playwright');

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

function seriousOrCriticalViolations(results) {
  return results.violations.filter((violation) => (
    violation.impact === 'serious' || violation.impact === 'critical'
  ));
}

function formatViolations(violations) {
  return violations.map((violation) => {
    const targets = violation.nodes.map((node) => node.target.join(' ')).join(', ');
    return `${violation.id} (${violation.impact}): ${targets}`;
  }).join('\n');
}

async function scanForBaseline(page, state) {
  await expect.poll(async () => page.locator('.animate-in:not(.is-visible)').count()).toBe(0);
  const results = await new AxeBuilder({ page }).analyze();
  const violations = seriousOrCriticalViolations(results);
  expect(violations, `${state} has serious or critical axe violations:\n${formatViolations(violations)}`).toEqual([]);
}

test.beforeEach(async ({ page }) => {
  providerAttempts.set(page, []);
  pageErrors.set(page, []);
  await page.emulateMedia({ reducedMotion: 'reduce' });

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
  expect(providerAttempts.get(page), 'Accessibility scans must not contact payment, courier, or analytics providers.').toEqual([]);
  expect(pageErrors.get(page), 'Accessibility scans must not produce uncaught first-party page errors.').toEqual([]);
});

async function dismissCookieBanner(page) {
  const banner = page.locator('#cookieConsentBanner');
  const rejectButton = page.getByRole('button', { name: 'Reject' });
  if (await rejectButton.isVisible()) {
    const consentResponse = page.waitForResponse((response) => response.url().includes('/cookie-consent.php'));
    await rejectButton.click();
    const response = await consentResponse;
    expect(response.ok()).toBe(true);
    await expect(banner).toBeHidden();
  }
}

async function addSimpleFixtureToCart(page) {
  await page.goto('/fabric/e2e-simple-product');
  await expect(page.getByRole('heading', { name: 'E2E Simple Product' })).toBeVisible();
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await expect(page).toHaveURL(/\/cart$/);
}

async function unlockCheckoutPayment(page) {
  await page.goto('/checkout');
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Accessibility Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill('axe@example.test');
  await page.getByLabel('Address *', { exact: true }).fill('12 Test Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  await expect(page.getByRole('heading', { name: 'Payment Method' })).toBeVisible();
  await expect(page.getByText('Step 3 of 3: Review').first()).toBeVisible();
}

test('@axe @desktop enforces the serious-and-critical axe baseline across required storefront pages and purchase states', async ({ page }) => {
  await page.goto('/');
  await scanForBaseline(page, 'homepage');
  await dismissCookieBanner(page);

  await page.goto('/catalog');
  await scanForBaseline(page, 'catalog');

  await page.goto('/fabric/e2e-variant-product');
  const amberVariant = page.getByRole('button', { name: 'Amber', exact: true });
  const largeVariant = page.getByRole('button', { name: 'Large', exact: true });
  await amberVariant.click();
  await largeVariant.click();
  await expect(amberVariant).toHaveAttribute('aria-pressed', 'true');
  await expect(largeVariant).toHaveAttribute('aria-pressed', 'true');
  await scanForBaseline(page, 'selected variant product');

  await page.goto('/fabric/e2e-meter-product');
  const cutLength = page.getByRole('button', { name: '2.5m', exact: true });
  await cutLength.click();
  await page.getByRole('button', { name: 'Increase quantity' }).click();
  await expect(cutLength).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByLabel('Number of cuts')).toHaveValue('2');
  await expect(page.getByText(/2 cuts × 2\.5m = 5m/)).toBeVisible();
  await scanForBaseline(page, 'meter cut selection');

  await addSimpleFixtureToCart(page);
  await scanForBaseline(page, 'cart');

  await unlockCheckoutPayment(page);
  await scanForBaseline(page, 'checkout payment and review');

  await page.goto('/customer/login');
  await scanForBaseline(page, 'customer login');
});

test('@axe @mobile enforces the serious-and-critical axe baseline for mobile filter and navigation drawers', async ({ page }) => {
  await page.goto('/catalog');
  await dismissCookieBanner(page);
  await page.getByRole('button', { name: /^Filters/ }).click();
  await expect(page.locator('#catalogFiltersDrawer')).toBeVisible();
  await scanForBaseline(page, 'mobile catalog filter drawer');

  await page.goto('/');
  await page.getByRole('button', { name: 'Open menu' }).click();
  await expect(page.locator('#mobileNavDrawer')).toBeVisible();
  await scanForBaseline(page, 'mobile navigation drawer');
});
