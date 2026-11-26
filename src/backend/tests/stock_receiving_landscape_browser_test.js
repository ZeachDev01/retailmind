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
        const page = await browser.newPage({viewport: {width: 820, height: 1180}, hasTouch: true});
        await page.emulateMedia({reducedMotion: 'reduce'});
        page.setDefaultTimeout(10000);
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name:'Log in', exact:true}).click()]);
        await page.goto(origin + '/components/report/stock_receiving.php');
        await page.locator('.receiving-card-add').first().tap();
        assert.equal(await page.evaluate(() => document.activeElement.matches('input, textarea, select')), false, 'Add to delivery must not focus a field and open the on-screen keyboard');
        assert.equal(await page.locator('.receiving-group-toggle').getAttribute('aria-expanded'), 'true', 'Added product stays open for manual quantity entry');
        await page.locator('#received_qty').fill('12');
        await page.locator('#supplier').fill('Landscape supplier ' + 'long-name-'.repeat(12));
        await page.locator('#invoice_number').fill('LANDSCAPE-470');
        const measurements = [];
        for (const [width, height] of [[390,844], [640,360], [768,1024], [800,480], [820,1180], [900,1200], [901,1200], [960,600], [1024,768], [1180,820], [1280,800], [1366,768]]) {
            await page.setViewportSize({width, height});
            const catalog = await page.locator('#receiving_selection').boundingBox();
            const editor = await page.locator('.delivery-editor').boundingBox();
            if (width >= 768) {
                assert.ok(catalog.x + catalog.width <= editor.x, 'Tablet keeps catalog beside delivery lines at ' + width);
                assert.ok(Math.abs(catalog.y - editor.y) < 1, 'Catalog and editor start together at ' + width);
                const search = await page.locator('#product_search_scan').boundingBox();
                assert.ok(search.width >= 180, 'Tablet search stays usable at ' + width);
                const lot = await page.locator('#batch_number').locator('..').boundingBox();
                const expiry = await page.locator('#expiration_date').locator('..').boundingBox();
                assert.ok(Math.abs(lot.y - expiry.y) < 1, 'Batch and expiry stay paired at ' + width);
            } else {
                assert.ok(catalog.y + catalog.height <= editor.y, 'Phone keeps stacked panels');
            }
            fs.mkdirSync(output, {recursive:true});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => {
                    document.documentElement.dataset.theme = theme;
                    document.documentElement.style.colorScheme = theme;
                    scrollTo(0, 0);
                }, theme);
                await page.waitForTimeout(300);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Editor fits ' + width + ' ' + theme);
                await page.screenshot({path:path.join(output, `editor-${width}-${theme}.png`), fullPage:true});
            }
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
            if (width >= 768) {
                const review = await page.locator('.delivery-editor').boundingBox();
                const summary = await page.locator('.receiving-summary').boundingBox();
                assert.ok(review.x + review.width <= summary.x, 'Review and summary stay side by side at ' + width);
            }
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
        console.log('Receiving responsive geometry: passed 12 viewport rows, both editor themes, and Review (supplemental Chromium)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
