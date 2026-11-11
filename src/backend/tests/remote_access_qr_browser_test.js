const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-qr-'))};
const output = path.resolve('output/remote-access-qr');
fs.mkdirSync(output, {recursive: true});
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch();
        const context = await browser.newContext({baseURL: origin, viewport: {width: 1440, height: 900}, permissions: ['clipboard-read', 'clipboard-write']});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.evaluate(() => localStorage.setItem('retailmind_sidebar_collapsed', '1'));
        await page.goto('/components/administrator/store_settings.php?section=remote-access');
        const skip = page.getByRole('button', {name: 'Skip guidance'});
        if (await skip.count()) await skip.click();
        // Supply a synthetic link without starting or changing the real tunnel service.
        const url = 'https://store.ngrok-free.dev/retailmind/src/frontend/';
        await page.evaluate(url => {
            document.querySelector('#remote-public-url').value = url;
            document.querySelector('#qr-code-display').innerHTML = '';
            document.querySelector('#remote-url-container').classList.remove('u-hidden');
        }, url);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const viewport of [{width: 1440, height: 900}, {width: 1280, height: 600}, {width: 390, height: 844}, {width: 320, height: 568}]) {
                await page.setViewportSize(viewport);
                await page.locator('#show-qr-code-btn').click();
                const modal = page.locator('#qr-code-modal');
                await page.screenshot({path: path.join(output, viewport.width + '-' + theme + '.png'), animations: 'disabled'});
                const geometry = await modal.evaluate(node => {
                    const rect = node.getBoundingClientRect();
                    const title = node.querySelector('.qr-modal-header').getBoundingClientRect();
                    return {
                        coversViewport: rect.left <= 0 && rect.top <= 0 && rect.right >= document.documentElement.clientWidth && rect.bottom >= innerHeight,
                        coversTopbar: node.contains(document.elementFromPoint(innerWidth / 2, 20)),
                        coversSidebar: node.contains(document.elementFromPoint(20, innerHeight / 2)),
                        headerReachable: title.top >= 0 && node.contains(document.elementFromPoint(title.left + 10, title.top + 10)),
                        fitsWidth: node.scrollWidth <= innerWidth,
                    };
                });
                assert.equal(geometry.coversTopbar, true, 'QR dialog backdrop must cover the topbar: ' + JSON.stringify(geometry));
                assert.equal(geometry.coversSidebar, true, 'QR backdrop covers the collapsed sidebar');
                assert.equal(geometry.coversViewport, true, 'QR backdrop fills the viewport: ' + JSON.stringify(geometry));
                assert.equal(geometry.headerReachable, true, 'QR header and close control stay reachable');
                assert.equal(geometry.fitsWidth, true, 'QR controls fit narrow viewports');
                assert(await page.locator('#qr-code-display').evaluate(node => {
                    const frame = node.getBoundingClientRect();
                    const image = node.querySelector('img, svg').getBoundingClientRect();
                    return image.top >= frame.top && image.bottom <= frame.bottom && image.left >= frame.left && image.right <= frame.right;
                }), 'QR image stays inside its white scanning area');
                assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), true, 'Background scrolling is locked');
                await page.locator('#close-qr-modal-footer-btn').focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.locator('#close-qr-modal-btn').evaluate(node => node === document.activeElement), true, 'Tab wraps within the dialog');
                const button = page.locator('#modal-copy-url-btn');
                assert(await button.evaluate(node => node.offsetHeight < 60), 'Copy URL stays on one line');
                await button.click();
                assert.equal(await page.evaluate(() => navigator.clipboard.readText()), url);
                await page.keyboard.press('Escape');
                assert(await modal.isHidden(), 'Escape dismisses QR dialog');
                assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), false, 'Closing restores background scrolling');
                assert.equal(await page.locator('#show-qr-code-btn').evaluate(node => node === document.activeElement), true, 'Focus returns to QR trigger');
            }
        }
        await page.locator('.settings-sidebar a[href="?section=store-receipts"]').click();
        await page.waitForURL('**/store_settings.php?section=store-receipts');
        await page.locator('.settings-sidebar a[href="?section=remote-access"]').click();
        await page.waitForURL('**/store_settings.php?section=remote-access');
        assert.equal(await page.locator('#qr-code-modal').count(), 1, 'Page navigation keeps one QR modal');
        assert(await page.locator('#qr-code-modal').isHidden(), 'Page navigation starts with a closed dialog');
        assert.deepEqual(errors, []);
        console.log('Remote Access QR: PASS (overlay, sizing, clipboard, focus, scroll lock and navigation; 4 viewports, light/dark)');
    } finally {
        await browser?.close();
        server?.kill();
        try { fixture('cleanup'); } catch {}
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
