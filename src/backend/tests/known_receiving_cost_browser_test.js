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
const output = path.resolve('output/issue-477');
const packageOutput = path.resolve('output/issue-483');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const fixtureFile = action === 'create' ? 'known_cost_fixture.php' : 'theme_fixture.php';
    const result = spawnSync(php, ['src/backend/tests/support/' + fixtureFile, action, database], {encoding: 'utf8', env});
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
        // Each navigation starts a separate scenario; draft persistence has its own test.
        await page.addInitScript(() => {
            Object.keys(sessionStorage).filter(key => key.startsWith('receiving-draft:')).forEach(key => sessionStorage.removeItem(key));
        });
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/report/stock_receiving.php';
        await page.goto(url + '?product_id=1');
        assert.equal(await page.locator('#received_qty').isVisible(), true, 'quantities are editable beside the catalog immediately');
        assert.equal(await page.locator('#batch_number').isVisible(), true, 'lot does not need disclosure');
        assert.equal(await page.locator('#expiration_date').isVisible(), true, 'expiry does not need disclosure');
        if (await page.locator('.receiving-extras').getAttribute('open') === null) await page.locator('.receiving-extras summary').click();
        assert.equal(await page.locator('#cost_price').inputValue(), '', 'legacy zero is never prefilled as intentional');
        assert.match(await page.locator('#cost_hint').innerText(), /Cost not recorded — enter cost if known/);
        await page.locator('#received_qty').fill('2');
        await page.locator('#cost_price').fill('0');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.match(await page.locator('#receiving_review').innerText(), /Cost: ₱0/);
        await page.waitForFunction(() => Object.keys(localStorage).some(k => k.startsWith('retailmind:inventory:v1:')));
        await page.waitForTimeout(500);
        await page.reload();
        await page.getByRole('button', {name: 'Resume', exact: true}).click();
        assert.equal(await page.locator('#cost_price').inputValue(), '0', 'explicit zero survives encrypted draft');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.match(await page.locator('#receiving_review').innerText(), /Cost: ₱0/);
        assert.deepEqual(errors, []);
        for (const [width, theme] of [[390, 'light'], [768, 'dark'], [1440, 'light']]) {
            await page.setViewportSize({width, height: 1000});
            await page.emulateMedia({reducedMotion: 'reduce'});
            await page.evaluate(value => document.documentElement.dataset.theme = value, theme);
            assert.equal(await page.locator('#receiving_review').isVisible(), true);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'cost text must fit narrow layouts');
            fs.mkdirSync(output, {recursive: true});
            await page.screenshot({path: path.join(output, `known-zero-${width}-${theme}.png`), fullPage: true});
        }
        await page.goto(url + '?product_id=2');
        if (await page.locator('.receiving-extras').getAttribute('open') === null) await page.locator('.receiving-extras summary').click();
        assert.match(await page.locator('#cost_hint').innerText(), /Blank uses saved cost: ₱0.00/);
        assert.deepEqual(await page.locator('#cost_basis option').evaluateAll(options => options.map(option => option.value)), ['units']);
        await page.locator('#received_qty').fill('1');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.match(await page.locator('#receiving_review').innerText(), /Cost: ₱0.00/);
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}), page.locator('.btn-submit').click()]);
        assert.match(await page.locator('body').innerText(), /Receiving entry saved/);
        await page.goto(origin + '/components/report/receiving_history.php');
        await page.locator('.receiving-entry-card summary').first().click();
        assert.match(await page.locator('body').innerText(), /₱0.00/, 'saved history preserves free goods');
        await page.goto(url + '?product_id=3');
        await page.locator('#received_qty').fill('2');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.equal(await page.locator('#summary_cost').innerText(), '₱10.00', 'blank positive cost uses saved fallback');
        await page.goto(url + '?product_id=1');
        await page.locator('.receiving-extras summary').click();
        await page.locator('#received_qty').fill('24');
        await page.locator('#cost_price').fill('40');
        const basis = page.locator('#cost_basis');
        await basis.focus();
        await basis.press('ArrowDown');
        assert.equal(await basis.inputValue(), 'packages');
        assert.equal(await page.locator('#cost_price').inputValue(), '', 'switching basis requires a fresh amount');
        assert.match(await basis.locator('option:checked').innerText(), /case.*24/i);
        await page.locator('#cost_price').fill('100');
        assert.equal(await page.locator('#summary_cost').innerText(), '₱100.00');
        await page.waitForTimeout(500);
        await page.reload();
        await page.getByRole('button', {name: 'Resume', exact: true}).click();
        assert.equal(await basis.inputValue(), 'packages');
        assert.equal(await page.locator('#cost_price').inputValue(), '100');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.match(await page.locator('#receiving_review').innerText(), /₱100\.00.*case.*₱4\.17.*₱100\.00/s);
        assert.match(await page.locator('#receiving_review').innerText(), /rounded/i);
        for (const [width, theme] of [[1440, 'light'], [390, 'dark']]) {
            await page.setViewportSize({width, height: 900});
            await page.emulateMedia({reducedMotion: 'reduce'});
            await page.evaluate(value => {
                document.documentElement.dataset.theme = value;
                document.documentElement.style.colorScheme = value;
            }, theme);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            fs.mkdirSync(packageOutput, {recursive: true});
            await page.screenshot({path: path.join(packageOutput, `review-${width}-${theme}.png`), fullPage: true});
        }
        fixture('receiving_conversion_changed');
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('.btn-submit').click()]);
        assert.match(await page.locator('.message.error').innerText(), /purchase cost conversion changed/i);
        assert.equal(await basis.inputValue(), 'packages');
        assert.equal(await page.locator('#received_qty').inputValue(), '24');
        assert.equal(await page.locator('#cost_price').inputValue(), '100');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        assert.equal(await page.locator('#summary_cost').innerText(), '₱200.00', 'fresh Review uses the new cost conversion');
        await page.locator('#receiving_back').click();
        await page.locator('#cost_price').fill('60000000');
        assert.match(await page.locator('#cost_preview').innerText(), /too large/);
        fixture('receiving_pack_config');
        await page.locator('#receiving_next').click();
        await page.waitForFunction(() => document.getElementById('entry_status').textContent.includes('changed'));
        assert.equal(await page.locator('#receiving_review').isVisible(), false, 'cost basis staleness is independent of quantity mode');
        assert.match(await basis.locator('option[value="packs"]').innerText(), /Sales Pack.*6/);
        assert.equal(await page.locator('#summary_cost').innerText(), '₱60,000,000.00', 'stale draft cost bounds must not block a fresh conversion');
        await page.locator('#cost_price').fill('100');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state: 'visible'});
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('.btn-submit').click()]);
        assert.match(await page.locator('body').innerText(), /Receiving entry saved/);
        await page.goto(origin + '/components/report/receiving_history.php');
        await page.locator('.receiving-entry-card summary').first().click();
        assert.match(await page.locator('.receiving-entry-card').first().innerText(), /₱100\.00.*24.*₱100\.00/s);
        assert.match(await page.locator('.receiving-entry-card').first().innerText(), /Approx\. ₱4\.17/);
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.locator('#product-add-trigger').click();
        await page.locator('#add-product-btn').click();
        await page.locator('#product-name').fill('Product with missing cost');
        const category = await page.locator('#product-category option').nth(1).textContent();
        await page.locator('#product-category-search').fill(category);
        await page.getByRole('option', {name: category, exact: true}).click();
        await page.locator('#selling-price').fill('25');
        await page.locator('#product-create-save').click();
        await page.waitForFunction(() => document.getElementById('cost-price').getAttribute('aria-invalid') === 'true');
        assert.equal(await page.locator('#cost-price').getAttribute('aria-invalid'), 'true', 'Creation rejects blank cost while receiving retains unknown costs');
        console.log('Known receiving cost browser and draft: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
