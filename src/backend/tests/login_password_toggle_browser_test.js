const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

(async () => {
  let browser, server;
  const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-login-password-'));
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
        const password = page.locator('#landing-login-password');
        const toggle = page.locator('#landing-login-password-toggle');
        await password.fill('visibility-test');
        for (const position of [undefined, { x: 3, y: 3 }, { x: 3, y: 29 }]) {
          for (let click = 0; click < 6; click++) {
            const box = await toggle.boundingBox();
            const x = box.x + (position ? position.x : box.width / 2);
            const y = box.y + (position ? position.y : box.height / 2);
            await page.mouse.click(x, y);
            const visible = click % 2 === 0;
            assert.equal(await password.getAttribute('type'), visible ? 'text' : 'password', `Each click toggles visibility at ${width}px, position ${JSON.stringify(position)}`);
            assert.equal(await toggle.textContent(), visible ? 'Hide' : 'Show');
            assert.equal(await toggle.getAttribute('aria-pressed'), String(visible));
            assert.equal(await password.inputValue(), 'visibility-test', `Mouse preserves password at ${width}px`);
          }
        }
        await toggle.focus();
        await page.keyboard.press('Space');
        assert.equal(await password.getAttribute('type'), 'text', 'Keyboard shows the password');
        await toggle.focus();
        await page.keyboard.press('Enter');
        assert.equal(await password.getAttribute('type'), 'password', 'Keyboard hides the password');
        assert.equal(await password.inputValue(), 'visibility-test', 'Toggling preserves the password');
        await page.getByRole('button', { name: 'Close dialog' }).click();
        await page.locator('[data-login-modal-open]').first().click();
        await toggle.click();
        assert.equal(await password.getAttribute('type'), 'text', 'Toggle works after reopening login');
        await toggle.click();
        assert.equal(await password.getAttribute('type'), 'password');
      }
    }
    console.log('Login password toggle browser: passed');
  } finally {
    if (browser) await browser.close();
    if (server) server.kill();
    fs.rmSync(recovery, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
