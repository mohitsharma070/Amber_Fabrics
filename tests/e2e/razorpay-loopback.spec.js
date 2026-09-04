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

test('@commerce @desktop initializes online payment only through the guarded loopback API', async ({ page }) => {
  const blockedProviders = [];
  const pageErrors = [];
  await page.route('**/*', async (route) => {
    const hostname = new URL(route.request().url()).hostname;
    if (forbiddenProviderHosts.some((pattern) => pattern.test(hostname))) {
      blockedProviders.push(route.request().url());
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));

  const email = `online-${Date.now()}@example.test`;
  await page.goto('/fabric/e2e-simple-product');
  await page.locator('#add_to_cart_form').getByRole('button', { name: 'Add to Cart', exact: true }).click();
  await page.getByRole('link', { name: 'Proceed to Checkout' }).click();
  await page.getByLabel('Full Name *', { exact: true }).fill('E2E Online Buyer');
  await page.getByLabel('Phone *', { exact: true }).fill('9876543210');
  await page.getByLabel('Email *', { exact: true }).fill(email);
  await page.getByLabel('Address *', { exact: true }).fill('7 Loopback Test Street');
  await page.getByLabel('City *', { exact: true }).fill('Jaipur');
  await page.getByLabel('State *', { exact: true }).fill('Rajasthan');
  await page.getByLabel('Pincode *', { exact: true }).fill('302001');
  await page.getByLabel('Country *', { exact: true }).selectOption('India');
  await page.getByRole('button', { name: 'Continue to Payment' }).click();
  const quoteResponse = page.waitForResponse((response) => response.url().includes('/shipping-rate.php'));
  await page.getByRole('radio', { name: /Pay Online/ }).check();
  expect((await quoteResponse).ok()).toBe(true);

  await page.getByRole('button', { name: /Pay Securely/ }).first().click();
  const confirmation = page.getByRole('dialog', { name: 'Continue to Secure Payment' });
  await expect(confirmation).toBeVisible();
  await confirmation.getByRole('button', { name: /Continue to Payment|Confirm/ }).click();
  await expect(page).toHaveURL(/\/payment\/razorpay-create\.php$/);
  await expect(page.locator('#rzpPayStatus')).toContainText(/could not be loaded/i);
  await expect(page.locator('#rzpRetrySdk')).toBeVisible();
  await expect(page.locator('#rzpPayBtn')).toBeDisabled();
  await expect(page.getByRole('link', { name: 'Return to orders', exact: true })).toBeVisible();

  await page.goto('/catalog');
  await page.goBack();
  await expect(page.locator('#rzpPayBtn')).toBeDisabled();
  await expect(page.locator('#rzpRetrySdk')).toBeVisible();

  const probePath = path.join(__dirname, 'commerce-order-probe.php');
  const latestOrder = JSON.parse(execFileSync('php', [probePath, '-', email], {
    cwd: path.join(__dirname, '..', '..'),
    env: process.env,
    encoding: 'utf8',
  }));
  expect(latestOrder.payment).toMatchObject({ payment_method: 'razorpay', payment_status: 'pending' });
  expect(latestOrder.payment.razorpay_order_id).toMatch(/^order_e2e_/);
  expect(blockedProviders.some((url) => url.startsWith('https://checkout.razorpay.com/'))).toBe(true);
  expect(blockedProviders.filter((url) => !url.startsWith('https://checkout.razorpay.com/'))).toEqual([]);
  expect(pageErrors).toEqual([]);
});
