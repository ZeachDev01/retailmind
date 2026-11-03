const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Receiving help dialog: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-help-'))};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
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
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const output = 'output/playwright/stock-receiving-help';
        fs.mkdirSync(output, {recursive: true});
        for (const viewport of [{width: 1440, height: 1000}, {width: 390, height: 844}]) {
            await page.setViewportSize(viewport);
            await page.goto(origin + '/components/report/stock_receiving.php');
            await page.getByRole('button', {name: 'How to receive stock', exact: true}).click();
            const dialog = page.getByRole('dialog', {name: 'How to receive stock', exact: true});
            const box = await dialog.boundingBox();
            const availableWidth = await page.evaluate(() => document.documentElement.getBoundingClientRect().width);
            assert.ok(box, 'Help dialog is visible');
            assert.ok(Math.abs(box.x + box.width / 2 - availableWidth / 2) <= 1, 'Help dialog is horizontally centered at ' + viewport.width);
            assert.ok(Math.abs(box.y + box.height / 2 - viewport.height / 2) <= 1, 'Help dialog is vertically centered at ' + viewport.width);
            assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= viewport.width && box.y + box.height <= viewport.height, 'Help dialog fits the viewport');
            await page.screenshot({path: path.join(output, viewport.width + '.png')});
            await dialog.getByRole('button', {name: 'Close', exact: true}).click();
            assert.equal(await dialog.isVisible(), false, 'Close dismisses the help dialog');
        }
        console.log('Receiving help dialog centered on desktop and mobile: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
