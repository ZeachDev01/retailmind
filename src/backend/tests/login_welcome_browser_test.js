// Run with: node src/backend/tests/login_welcome_browser_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
  const source = fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/ui.js'), 'utf8');
  const toast = source.slice(source.indexOf('  RM.toast ='), source.indexOf('  RM.alert ='));
  const notifications = source.slice(source.indexOf('  function initServerNotifications()'), source.indexOf('  function initCommandPalette()'));
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent('<div id="rm-flash-messages" hidden></div>');
    await page.addStyleTag({ path: path.join(__dirname, '../../frontend/assets/css/style.css') });
    await page.addStyleTag({ content: '.bi-x-lg::before { content: "×"; }' });
    await page.evaluate(() => {
      document.querySelector('#rm-flash-messages').dataset.loginSuccess = 'Login successful! Welcome, <img src=x onerror=alert(1)>!';
    });
    await page.clock.install();
    await page.clock.pauseAt(new Date());
    await page.addScriptTag({ content: `
      const qs = (selector, root = document) => root.querySelector(selector);
      const qsa = selector => [...document.querySelectorAll(selector)];
      const RM = { sanitizeAlert: message => ({ message }) };
      ${toast}
      ${notifications}
      initServerNotifications();
      initServerNotifications();
    ` });
    assert.equal(await page.locator('.rm-toast').count(), 0, 'Welcome does not appear immediately');
    await page.clock.runFor(499);
    assert.equal(await page.locator('.rm-toast').count(), 0, 'Welcome waits half a second');
    await page.clock.runFor(1);
    assert.equal(await page.locator('.rm-toast.success').count(), 1, 'Welcome appears once even if initialization repeats');
    assert.equal(await page.locator('.rm-toast-copy span').textContent(), 'Login successful! Welcome, <img src=x onerror=alert(1)>!');
    assert.equal(await page.locator('.rm-toast img').count(), 0, 'Username is rendered as text');
    assert.equal(await page.locator('.bi-check-circle-fill').count(), 1);
    const alerts = await page.evaluate(() => {
      const alerts = [];
      window.Swal = { fire() {} };
      RM.queueAlert = (message, kind) => { alerts.push({ message, kind }); return Promise.resolve(); };
      const flash = document.querySelector('#rm-flash-messages');
      for (const kind of ['success', 'error', 'warning', 'info']) {
        flash.dataset[kind] = kind === 'success' ? 'Password changed successfully.' : `${kind} notice`;
      }
      // The sidebar survives page switches; content initialization runs again.
      initServerNotifications();
      initServerNotifications();
      initServerNotifications();
      return alerts;
    });
    assert.deepEqual(alerts, [
      { message: 'Password changed successfully.', kind: 'success' },
      { message: 'error notice', kind: 'error' },
      { message: 'warning notice', kind: 'warning' },
      { message: 'info notice', kind: 'info' },
    ], 'Persistent sidebar flash messages appear once across page initializations');
    await page.clock.runFor(300);
    for (const viewport of [{ width: 1280, height: 720 }, { width: 375, height: 667 }]) {
      await page.setViewportSize(viewport);
      const bounds = await page.locator('.rm-toast').boundingBox();
      const visibleWidth = await page.evaluate(() => document.documentElement.clientWidth);
      assert.ok(Math.abs(bounds.x + bounds.width / 2 - visibleWidth / 2) < 1, 'Welcome is horizontally centered');
      assert.ok(Math.abs(bounds.y + bounds.height / 2 - viewport.height / 2) < 1, 'Welcome is vertically centered');
    }
    await page.clock.runFor(1699);
    assert.equal(await page.locator('.rm-toast.show').count(), 1, 'Welcome stays visible for two seconds');
    await page.clock.runFor(1);
    assert.equal(await page.locator('.rm-toast.show').count(), 0, 'Welcome closes after two seconds');
    await page.clock.runFor(250);
    assert.equal(await page.locator('.rm-toast').count(), 0, 'Welcome is removed after its exit transition');
    await page.evaluate(() => RM.toast('Login successful! Welcome, cashier!', 'success'));
    await page.clock.runFor(300);
    await page.getByRole('button', { name: 'Dismiss notification' }).click();
    await page.clock.runFor(250);
    assert.equal(await page.locator('.rm-toast').count(), 0, 'Welcome can still be dismissed manually');
    console.log('Login welcome toast: passed');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
