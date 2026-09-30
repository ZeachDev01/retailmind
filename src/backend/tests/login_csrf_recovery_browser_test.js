// Run against a local RetailMind server: node src/backend/tests/login_csrf_recovery_browser_test.js
// LOGIN_TEST_URL can override the default Apache URL. No login is attempted.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(process.env.LOGIN_TEST_URL || 'http://localhost/retailmind/', { waitUntil: 'domcontentloaded' });

    const form = page.locator('.landing-login-form').first();
    const action = await form.getAttribute('action');
    const actionUrl = new URL(action, page.url()).toString();
    const current = await context.request.get(actionUrl + '?csrf_refresh=1');
    assert.equal(current.status(), 200);
    const { csrf_token: expectedToken } = await current.json();

    await form.locator('input[name="csrf_token"]').evaluate((input) => { input.value = 'stale-token'; });
    await page.locator('[data-login-modal-open]').first().click();
    await form.locator('input[name="username"]').fill('test@example.invalid');
    await form.locator('input[name="password"]').fill('test-password');

    const submitted = new Promise((resolve) => {
      page.route(actionUrl, async (route) => {
        if (route.request().method() !== 'POST') {
          return route.continue();
        }
        resolve(new URLSearchParams(route.request().postData()).get('csrf_token'));
        await route.fulfill({ status: 200, body: 'captured' });
      });
    });
    await form.locator('button[type="submit"]').click();
    assert.equal(await submitted, expectedToken, 'The browser must refresh the stale form token before POST');

    const rejected = await context.request.post(actionUrl, {
      form: { csrf_token: 'stale-token', username: 'test@example.invalid', password: '' },
      maxRedirects: 0,
    });
    assert.equal(rejected.status(), 303, 'A stale login POST must return to a fresh form');
    const retry = await context.request.get(new URL(rejected.headers().location, actionUrl).toString());
    assert.match(await retry.text(), /Your login page expired\. Please try again\./);
    console.log('Login CSRF recovery: passed');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
