const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Receiving landscape: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = path.resolve('output/playwright/stock-receiving-landscape');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('open_fiscal_period');
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
        page.setDefaultTimeout(10000);
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name:'Log in', exact:true}).click()]);
        await page.goto(origin + '/components/report/stock_receiving.php');
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#received_qty').fill('12');
        await page.locator('#supplier').fill('Landscape supplier ' + 'long-name-'.repeat(12));
        await page.locator('#invoice_number').fill('LANDSCAPE-470');
        const measurements = [];
        for (const [width, height] of [[640,360], [800,480], [960,600], [1024,768], [1280,800], [1366,768]]) {
            await page.setViewportSize({width, height});
            assert.equal(await page.locator('#received_qty').inputValue(), '12', 'Resize retains entered quantity');
            assert.equal(await page.locator('#invoice_number').inputValue(), 'LANDSCAPE-470', 'Resize retains receipt');
            for (const selector of ['#received_qty', '#supplier', '#receiving_next']) {
                const field = page.locator(selector);
                await field.scrollIntoViewIfNeeded();
                const bounds = await field.boundingBox();
                assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, selector + ' fits width');
                if (bounds.y < 0 || bounds.y + bounds.height > height + 1) { fs.mkdirSync(output,{recursive:true}); await page.screenshot({path:path.join(output, 'red.png')}); }
                assert.ok(bounds.y >= 0 && bounds.y + bounds.height <= height + 1, selector + ' can be scrolled into view: ' + JSON.stringify({width,height,bounds}));
            }
            await page.locator('#receiving_next').click();
            await page.locator('#receiving_review').waitFor({state:'visible'});
            assert.equal(await page.locator('#receiving_review').isVisible(), true);
            await page.locator('.btn-submit').scrollIntoViewIfNeeded();
            const submit = await page.locator('.btn-submit').boundingBox();
            assert.ok(submit.y >= 0 && submit.y + submit.height <= height + 1, 'Save reachable');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No whole-page horizontal overflow');
            measurements.push({width, height, submit});
            fs.mkdirSync(output, {recursive:true});
            await page.screenshot({path:path.join(output, width + 'x' + height + '.png')});
            await page.locator('#receiving_back').click();
        }
        fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify(measurements, null, 2));
        console.log('Receiving landscape geometry: passed six viewport rows (supplemental Chromium)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
