const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Receiving UI: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = path.resolve('output/playwright/stock-receiving');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/receiving_ui_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create')); fixture('open_fiscal_period');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        // Each navigation starts a separate scenario; draft persistence has its own test.
        await page.addInitScript(() => {
            Object.keys(sessionStorage).filter(key => key.startsWith('receiving-draft:')).forEach(key => sessionStorage.removeItem(key));
        });
        page.setDefaultTimeout(20000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/report/stock_receiving.php';
        await page.goto(url);
        fs.mkdirSync(output, {recursive:true});
        await page.evaluate(() => addReceivingLine({product_id:1, discrepancy_notes:'Torn packaging', notes:'Check delivery'}));
        await page.locator('#receiving_next').click();
        assert.equal(await page.locator('#notes').inputValue(), 'Torn packaging\nCheck delivery');
        assert.equal(await page.locator('.receiving-extras textarea').count(), 1);
        await page.locator('.receiving-extras summary').click();
        assert.equal(await page.locator('#notes').isVisible(), false);
        await page.locator('.receiving-extras summary').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#cost_price').isVisible(), true);
        assert.equal(await page.locator('#batch_number').isVisible(), true);
        assert.equal(await page.locator('#expiration_date').isVisible(), true);
        await page.locator('#received_qty').fill('5');
        await page.locator('#damaged_qty').fill('1');
        await page.locator('#cost_price').fill('3.50');
        await page.locator('#notes').fill('One damaged unit; packaging torn');
        await page.evaluate(() => { addReceivingLine({product_id:2, received_qty:'3'}); showStep(2); });
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width,height:1000});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
                const box = await page.locator('#notes').boundingBox();
                assert.ok(box.height >= 44 && box.height < 85, 'compact accessible notes field');
                await page.screenshot({path:path.join(output, `compact-${theme}-${width}.png`),fullPage:true});
            }
        }
        await page.locator('#receiving_next').click();
        assert.match(await page.locator('#receiving_review').innerText(), /One damaged unit; packaging torn/);
        await Promise.all([page.waitForNavigation(), page.locator('.btn-submit').click()]);
        console.log((await page.locator('body').innerText()).slice(-3000));
        assert.match(await page.locator('.message.success').innerText(), /4 accepted units.*3 accepted units/);
        const state = JSON.parse(fixture('inventory_state'));
        assert.equal(state.stock_receiving.find(row => Number(row.product_id) === 1).notes, 'One damaged unit; packaging torn');
        assert.deepEqual(errors, []);
        console.log('Compact receiving: keyboard disclosure, legacy notes, multiple products, light/dark 1440/390/320 layouts, review and persisted notes passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });





