const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

(async () => {
  let browser, server;
  const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-login-layout-'));
  try {
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const url = `http://127.0.0.1:${port}`;
    server = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', 'src/frontend'], { env: { ...process.env, BACKUP_STORAGE_PATH: recovery }, stdio: 'ignore' });
    server.on('error', error => console.error(error));
    for (let i = 0; i < 100; i++) {
      try { if ((await fetch(url)).ok) break; } catch {}
      if (i === 99) throw new Error('Login test server unavailable');
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    await page.route('https://**/*', route => route.abort());
    for (const theme of ['light', 'dark']) {
      for (const width of [320, 390, 715, 1428]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(url + '/?login=1');
        await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
        assert.equal(await page.locator('.landing-login-modal__flipper > .landing-login-modal__shell').count(), 2, 'Both faces are siblings');
        for (const face of ['login', 'recovery']) {
          const shell = page.locator(`.landing-login-modal__shell--${face}`);
          assert.equal(await shell.locator(':scope > .landing-login-modal__body').count(), 1, 'Form body is a grid child');
          assert.equal(await shell.locator('.landing-login-modal__body > form').count(), 1, 'Form follows heading');
          const input = shell.locator('input[type="text"]');
          await input.focus();
          const field = await input.boundingBox();
          assert(field.width > Math.min(230, width - 50), `Usable field width at ${width}px`);
          assert(field.x >= 0 && field.x + field.width <= width, 'Field fits viewport');
          if (width > 640) {
            const aside = await shell.locator('aside').boundingBox();
            assert(field.x >= aside.x + aside.width, 'Form sits beside welcome panel');
          }
          if (face === 'login') await page.getByRole('link', { name: 'Forgot password?' }).click();
        }
        await page.getByRole('link', { name: 'Back to login' }).click();
        await page.getByRole('button', { name: 'Close dialog' }).click();
        assert.equal(await page.locator('#loginModal').getAttribute('aria-hidden'), 'true');
      }
    }
    console.log('Login layout browser: passed (light/dark, mobile/desktop, recovery)');
  } finally {
    if (browser) await browser.close();
    if (server) server.kill();
    fs.rmSync(recovery, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
