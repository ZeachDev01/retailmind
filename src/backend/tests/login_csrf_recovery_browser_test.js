// Run against a local RetailMind server: node src/backend/tests/login_csrf_recovery_browser_test.js
// LOGIN_TEST_URL can override the default Apache URL. No login is attempted.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.route('https://**/*', route => route.abort());
    await page.goto(process.env.LOGIN_TEST_URL || 'http://localhost/retailmind/', { waitUntil: 'domcontentloaded' });

    const form = page.locator('.landing-login-form').first();
    const action = await form.getAttribute('action');
    const actionUrl = new URL(action, page.url()).toString();
    const current = await context.request.get(actionUrl + '?csrf_refresh=1');
    assert.equal(current.status(), 200);
    const { csrf_token: expectedToken } = await current.json();

    let releaseScript;
    await page.route('https://cdn.jsdelivr.net/npm/sweetalert2@11', route => new Promise(resolve => {
      releaseScript = () => route.abort().then(resolve);
    }));
    let heroRequests = 0;
    page.on('request', request => { if (request.url().includes('retailmind-landing-hero.jpg')) heroRequests++; });
    await page.goto(actionUrl + '?login=1', {waitUntil: 'commit'});
    await page.locator('#landing-login-password-toggle').click();
    assert.equal(await form.locator('input[name="password"]').getAttribute('type'), 'text',
      'Login controls must work while the external alert script is still loading');
    assert.equal(heroRequests, 0, 'Login does not download the obscured landing hero');
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.querySelector('img[src*="retailmind-landing-hero.jpg"]')?.complete);
    assert.equal(heroRequests, 1, 'Closing login restores the landing hero');
    await releaseScript();
    await page.unroute('https://cdn.jsdelivr.net/npm/sweetalert2@11');

    for (const scenario of ['refresh', 'timeout', 'invalid-json', 'network-error']) {
      await page.goto(actionUrl, { waitUntil: 'domcontentloaded' });
      await form.locator('input[name="csrf_token"]').evaluate((input) => { input.value = 'stale-token'; });
      await page.locator('[data-login-modal-open]').first().click();
      await form.locator('input[name="username"]').fill('test@example.invalid');
      await form.locator('input[name="password"]').fill('test-password');

      await page.route('**/*csrf_refresh=1', async (route) => {
        if (scenario === 'timeout') {
          await new Promise(resolve => setTimeout(resolve, 15000));
          return route.abort().catch(() => {});
        }
        if (scenario === 'invalid-json') {
          return route.fulfill({ contentType: 'text/html', body: '<html>Hosting browser check</html>' });
        }
        if (scenario === 'network-error') return route.abort();
        return route.continue();
      });
      let submittedToken;
      await page.route(actionUrl, async (route) => {
        if (route.request().method() !== 'POST') {
          return route.continue();
        }
        submittedToken = new URLSearchParams(route.request().postData()).get('csrf_token');
        await route.fulfill({ status: 200, body: 'captured' });
      });
      await form.locator('button[type="submit"]').click();
      await page.waitForURL(actionUrl, { waitUntil: 'load', timeout: 10000 });
      await page.waitForFunction(() => document.body.textContent === 'captured', null, { timeout: 10000 });
      assert.equal(submittedToken, scenario === 'refresh' ? expectedToken : 'stale-token',
        'Login must submit after ' + scenario + '; the server still validates the token');
      await page.unrouteAll({ behavior: 'wait' });
    }

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
