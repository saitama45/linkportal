const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_DIR = path.resolve(__dirname, '..', '.auth');

/**
 * Logs in once per role and saves the session so every other test starts
 * already authenticated. Runs before the admin/vendor projects (see config).
 */

test('authenticate as staff/admin', async ({ page }) => {
  const email = process.env.E2E_ADMIN_EMAIL;
  const password = process.env.E2E_ADMIN_PASSWORD;
  expect(email, 'E2E_ADMIN_EMAIL must be set in .env.e2e').toBeTruthy();

  await page.goto('/admin/login'); // staff login lives under /admin now
  await page.fill('input[type=email]', email);
  await page.fill('input#password', password);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/dashboard/, { timeout: 15_000 });

  fs.mkdirSync(AUTH_DIR, { recursive: true });
  await page.context().storageState({ path: path.join(AUTH_DIR, 'admin.json') });
});

test('authenticate as vendor', async ({ page }) => {
  const email = process.env.E2E_VENDOR_EMAIL;
  const password = process.env.E2E_VENDOR_PASSWORD;
  expect(email, 'E2E_VENDOR_EMAIL must be set in .env.e2e').toBeTruthy();

  await page.goto('/login'); // vendor login is the public /login now
  await page.fill('input[type=email]', email);
  await page.fill('input#password', password);
  await page.click('button[type=submit]');
  await page.waitForURL(/\/vendor\/dashboard/, { timeout: 15_000 });

  fs.mkdirSync(AUTH_DIR, { recursive: true });
  await page.context().storageState({ path: path.join(AUTH_DIR, 'vendor.json') });
});
