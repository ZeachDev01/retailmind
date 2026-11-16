const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Replenishment stock browser: skipped (set RUN_DB_TESTS=1; disposable MySQL database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-replenishment-stock-'));
const testEnv = {...process.env, BACKUP_STORAGE_PATH: recovery};
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/replenishment_stock_fixture.php', action, database], {encoding: 'utf8', env: testEnv});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('stock_forms');
        const before = fixture('state');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = `http://127.0.0.1:${port}`;
        server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...testEnv, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            assert.ok(attempt < 99, 'Test server starts');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({baseURL: origin, reducedMotion: 'reduce'});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto('/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto('/components/inventory_management/replenishment_queue.php');
        const pack = page.locator('.queue-item[data-sku="THEME-ITEM"]');
        const stock = pack.locator('[data-on-hand-label]');
        assert.match(await stock.innerText(), /29 piece\s+1 pack \+ 17 loose · 12 piece\/pack\s+Expired: 13 piece \(1 pack \+ 1 loose\)/);
        assert.equal(await pack.getAttribute('data-stock'), 'Low stock');
        assert.equal(await pack.getAttribute('data-quantity'), '24');
        const expired = page.locator('.queue-item[data-sku="EXPIRED-ONLY"]');
        assert.equal(await expired.getAttribute('data-stock'), 'Out of stock');
        assert.equal(await expired.getAttribute('data-on-hand'), '0');
        assert.match(await expired.innerText(), /Expired: 13 piece/);
        const bottles = page.locator('.queue-item[data-sku="BOTTLES"] [data-on-hand-label]');
        assert.equal((await bottles.innerText()).trim(), '8 bottle');
        const artifacts = 'output/playwright/replenishment-stock';
        fs.mkdirSync(artifacts, {recursive: true});
        for (const colorScheme of ['light', 'dark']) {
            await page.emulateMedia({colorScheme});
            await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, colorScheme);
            for (const width of [1280, 390]) {
                await page.setViewportSize({width, height: 900});
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Page fits viewport');
                assert.ok(await stock.evaluate(el => el.scrollWidth <= el.clientWidth), 'Stock text wraps within its cell');
                await page.locator('.queue-list').evaluate((el, width) => { el.scrollLeft = width < 900 ? 260 : 0; }, width);
                await page.screenshot({path: `${artifacts}/${colorScheme}-${width}.png`, fullPage: true});
            }
        }
        await page.evaluate(() => {
            window.pdfStockText = [];
            const original = CanvasRenderingContext2D.prototype.fillText;
            CanvasRenderingContext2D.prototype.fillText = function (text, ...args) {
                if (args[0] === 540) window.pdfStockText.push(text);
                return original.call(this, text, ...args);
            };
        });
        await page.locator('#queue-actions-trigger').click();
        const downloadPromise = page.waitForEvent('download');
        await page.locator('#queue-download-pdf').click();
        const download = await downloadPromise;
        assert.equal(download.suggestedFilename(), 'replenishment-recommendations.pdf');
        assert.ok(fs.statSync(await download.path()).size > 1000, 'PDF is generated');
        const pdfText = (await page.evaluate(() => window.pdfStockText)).join('');
        assert.ok(pdfText.includes('29 piece') && pdfText.includes('1 pack + 17 loose') && pdfText.includes('Expired: 13 piece'), 'PDF carries screen stock values');
        assert.equal(fixture('state'), before, 'Viewing and exporting preserve recorded stock');
        assert.deepEqual(errors, []);
        console.log('Replenishment stock browser passed: actual forms, expiry, buying quantities, PDF, light/dark, desktop/mobile.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
