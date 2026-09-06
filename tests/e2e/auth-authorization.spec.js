const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

const root = path.join(__dirname, '..', '..');
const mailLog = path.join(root, 'tmp', 'local-mail.log');
const customerEmail = 'e2e-customer@example.test';
const customerPassword = ['E2E', 'Auth', '123!'].join('');
const guestEmail = 'e2e-guest-order@example.test';
const operationsEmail = 'e2e-operations-admin@example.test';
const viewerEmail = 'e2e-viewer-admin@example.test';

const forbiddenProviderHosts = [
  /(^|\.)razorpay\.com$/i,
  /(^|\.)bigship\.in$/i,
  /(^|\.)facebook\.com$/i,
  /(^|\.)facebook\.net$/i,
  /(^|\.)whatsapp\.com$/i,
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
  expect(providerAttempts.get(page), 'Authenticated E2E must not contact external providers.').toEqual([]);
  expect(pageErrors.get(page), 'Authenticated E2E must not produce uncaught page errors.').toEqual([]);
});

function fixtureOrder(email) {
  return JSON.parse(execFileSync('php', [path.join(__dirname, 'commerce-order-probe.php'), '-', email], {
    cwd: root,
    env: process.env,
    encoding: 'utf8',
  })).order;
}

function mailOffset() {
  return fs.existsSync(mailLog) ? fs.statSync(mailLog).size : 0;
}

function appendedMail(offset) {
  if (!fs.existsSync(mailLog)) return '';
  return fs.readFileSync(mailLog, 'utf8').slice(offset);
}

async function loginCustomer(page) {
  await page.goto('/customer/login');
  await page.getByLabel('Email Address').fill(customerEmail);
  await page.getByLabel('Password').fill(customerPassword);
  await page.getByRole('button', { name: 'Log In', exact: true }).click();
  await expect(page).toHaveURL(/\/(?:index\.php)?$/);
}

async function loginAdmin(page, email) {
  const offset = mailOffset();
  await page.goto('/admin/login.php');
  await page.getByLabel('Email Address').fill(email);
  await page.getByRole('button', { name: 'Send OTP' }).click();
  await expect(page).toHaveURL(/\/admin\/verify-otp(?:\.php)?$/);

  let otp = '';
  await expect.poll(() => {
    const message = appendedMail(offset);
    if (!message.includes(`To: ${email}`)) return '';
    return message.match(/Your admin login OTP is:\s*(\d{6})/)?.[1] || '';
  }).toMatch(/^\d{6}$/);
  const message = appendedMail(offset);
  otp = message.match(/Your admin login OTP is:\s*(\d{6})/)?.[1] || '';

  await page.getByLabel('OTP').fill(otp);
  await page.getByRole('button', { name: 'Verify and Login' }).click();
  await expect(page).toHaveURL(/\/admin\/dashboard(?:\.php)?$/);
}

test('@commerce @desktop registers a customer through the real form', async ({ page }) => {
  await page.goto('/customer/register');
  await page.locator('[name="name"]').fill('E2E Newly Registered Customer');
  await page.locator('[name="email"]').fill('e2e-new-registration@example.test');
  await page.locator('[name="phone"]').fill('9876543210');
  await page.locator('[name="country"]').fill('India');
  await page.locator('[name="password"]').fill(customerPassword);
  await page.locator('[name="confirm_password"]').fill(customerPassword);
  await page.getByRole('button', { name: 'Create Account' }).click();

  await expect(page).toHaveURL(/\/customer\/login(?:\.php)?$/);
  await expect(page.getByText(/Account created!/)).toBeVisible();
});

test('@commerce @desktop enforces customer order ownership and CSRF-protected logout', async ({ page }) => {
  const ownOrder = fixtureOrder(customerEmail);
  const otherOrder = fixtureOrder('e2e-other-customer@example.test');

  await loginCustomer(page);
  await page.goto('/customer/orders');
  await expect(page.getByRole('cell', { name: ownOrder.order_number, exact: true })).toBeVisible();
  await page.goto(`/customer/order-view.php?id=${ownOrder.id}`);
  await expect(page.getByRole('heading', { name: `Order ${ownOrder.order_number}` })).toBeVisible();

  await page.goto(`/customer/order-view.php?id=${otherOrder.id}`);
  await expect(page).toHaveURL(/\/customer\/orders(?:\.php)?$/);
  await expect(page.getByText('Order not found.')).toBeVisible();

  const rejectedLogout = await page.context().request.post('/customer/logout.php', {
    form: { csrf_token: 'invalid-e2e-token' },
    maxRedirects: 0,
  });
  expect(rejectedLogout.status()).toBe(302);
  await page.goto('/customer/profile');
  await expect(page.getByRole('heading', { name: 'Account Settings' })).toBeVisible();

  await page.getByLabel('Customer logout').getByRole('button', { name: 'Log out' }).click();
  await expect(page).toHaveURL(/\/customer\/login(?:\.php)?$/);
  await expect(page.getByText('You have been logged out.')).toBeVisible();
});

test('@commerce @desktop grants one guest order session and rejects invalid or reused tokens', async ({ page }) => {
  const guestOrder = fixtureOrder(guestEmail);
  const offset = mailOffset();

  await page.goto('/guest/order-access');
  await page.locator('[name="order_number"]').fill(guestOrder.order_number);
  await page.locator('[name="email"]').fill(guestEmail);
  await page.getByRole('button', { name: 'Email Secure Link' }).click();
  await expect(page.getByText('If those details match an order, a secure link has been sent.')).toBeVisible();

  let token = '';
  await expect.poll(() => {
    const message = appendedMail(offset);
    if (!message.includes(`To: ${guestEmail}`)) return '';
    return message.match(/\/guest\/order-auth\?token=([a-f0-9]{64})/)?.[1] || '';
  }).toMatch(/^[a-f0-9]{64}$/);
  token = appendedMail(offset).match(/\/guest\/order-auth\?token=([a-f0-9]{64})/)?.[1] || '';

  await page.goto(`/guest/order-auth?token=${token}`);
  await expect(page).toHaveURL(new RegExp(`/guest/order(?:\\.php)?\\?id=${guestOrder.id}$`));
  await expect(page.getByRole('heading', { name: `Order ${guestOrder.order_number}` })).toBeVisible();

  await page.goto(`/guest/order-auth?token=${token}`);
  await expect(page).toHaveURL(/\/guest\/order-access$/);
  await expect(page.getByText('This secure link is invalid or expired. Request a new one.')).toBeVisible();

  await page.goto(`/guest/order-auth?token=${'0'.repeat(64)}`);
  await expect(page).toHaveURL(/\/guest\/order-access$/);
  await expect(page.getByText('This secure link is invalid or expired. Request a new one.')).toBeVisible();
});

test('@commerce @desktop rejects a viewer admin mutation even with a valid CSRF token', async ({ page }) => {
  const order = fixtureOrder(guestEmail);
  await loginAdmin(page, viewerEmail);
  await page.goto(`/admin/order-view.php?id=${order.id}`);
  const csrfToken = await page.locator('[name="csrf_token"]').first().inputValue();

  const response = await page.context().request.post(`/admin/order-view.php?id=${order.id}`, {
    form: {
      csrf_token: csrfToken,
      action: 'workflow_transition',
      expected_status: 'pending',
      target_status: 'confirmed',
    },
    maxRedirects: 0,
  });
  expect(response.status()).toBe(403);
  expect((await response.text()).trim()).toBe('Forbidden');
  expect(fixtureOrder(guestEmail).order_status).toBe('pending');
});

test('@commerce @desktop completes OTP login and a CSRF-protected admin order transition', async ({ page }) => {
  const order = fixtureOrder(guestEmail);
  await loginAdmin(page, operationsEmail);

  const rejected = await page.context().request.post(`/admin/order-view.php?id=${order.id}`, {
    form: {
      csrf_token: 'invalid-e2e-token',
      action: 'workflow_transition',
      expected_status: 'pending',
      target_status: 'confirmed',
    },
    maxRedirects: 0,
  });
  expect(rejected.status()).toBe(302);
  expect(fixtureOrder(guestEmail).order_status).toBe('pending');

  await page.goto(`/admin/order-view.php?id=${order.id}`);
  await page.getByRole('button', { name: 'Confirmed', exact: true }).click();
  await expect(page.getByText('Order moved to Confirmed.')).toBeVisible();
  expect(fixtureOrder(guestEmail).order_status).toBe('confirmed');
});
