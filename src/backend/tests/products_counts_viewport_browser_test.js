const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Product/Count viewports: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-products-counts-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/inventory_recovery_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

const VIEWPORTS = [
    {width: 640, height: 360},
    {width: 800, height: 480},
    {width: 960, height: 600},
    {width: 1024, height: 768},
    {width: 1280, height: 800},
    {width: 1366, height: 768},
];

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
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 80));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});

        const context = await browser.newContext({viewport: {width: 1366, height: 768}, hasTouch: true});
        const page = await context.newPage();
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        async function fits(label) {
            const geometry = await page.evaluate(() => ({width: innerWidth, scroll: document.documentElement.scrollWidth}));
            assert(geometry.scroll <= geometry.width + 1, label + ': whole page width ' + JSON.stringify(geometry));
        }
        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await page.goto(origin + '/components/inventory_management/products.php');
            await fits('Products ' + viewport.width);
            await page.locator('#product-search').fill('no matching product 469');
            await page.locator('#catalog-no-results').waitFor({state:'visible'});
            await fits('Product empty state');
            await page.locator('#empty-clear-filters').click();
            await page.locator('#product-add-trigger').click();
            await page.locator('#add-product-btn').click();
            await page.locator('#wizard-next').click();
            assert(await page.locator('#add-product-form :invalid').count() > 0, 'Required Product fields block advance');
            await page.locator('#add-product-form [name="product_name"]').fill('Long Product name '.repeat(10));
            const categoryName = await page.locator('#product-category option').nth(1).innerText();
            await page.locator('#product-category-search').tap();
            await page.getByRole('option', {name:categoryName, exact:true}).tap();
            assert.notEqual(await page.locator('#product-category').inputValue(), '', 'Tapping visible category commits underlying value');
            assert.equal(await page.locator('#product-category-search').evaluate(field => field.checkValidity()), true, 'Selected category passes required validation');
            await page.evaluate(() => RetailMindInventoryDrafts.flush());
            await page.reload();
            const resume = page.locator('[data-recovery-id="add-product-form"]').getByRole('button', {name:'Resume', exact:true});
            await resume.waitFor({state:'visible'});
            let resumePosts = 0;
            const trackPost = request => { if (request.method() === 'POST' && request.url().includes('/products.php')) resumePosts++; };
            page.on('request', trackPost);
            await resume.click();
            await page.locator('[data-recovery-id="add-product-form"]').waitFor({state:'detached'});
            assert.equal(resumePosts, 0, 'Explicit Resume never posts Product');
            page.off('request', trackPost);
            for (const size of [viewport, {width:viewport.width,height:240}, viewport]) {
                await page.setViewportSize(size);
                await page.locator('#wizard-next').scrollIntoViewIfNeeded();
                const box = await page.locator('#wizard-next').boundingBox();
                assert(box.y >= 0 && box.y + box.height <= size.height + 1, 'Product Next fits reduced viewport');
                assert.equal(await page.locator('#add-product-form [name="product_name"]').inputValue(), 'Long Product name '.repeat(10));
            }
            await page.keyboard.press('Escape');
            await page.reload();
            await page.locator('[data-recovery-id="add-product-form"]').getByRole('button', {name:'Discard', exact:true}).click();
            await page.reload();
            assert.equal(await page.locator('[data-recovery-id="add-product-form"]').count(), 0, 'Explicit Discard clears Product draft');
            await page.goto(origin + '/components/inventory_management/inventory_counts.php');
            await fits('Counts ' + viewport.width);
            await page.locator('#product_id').selectOption('1', {force:true});
            await page.locator('#batch_reconciliation_section').waitFor({state:'visible'});
            await page.locator('#add_extra_batch_btn').click();
            await page.locator('[aria-label="Found batch number or label"]').fill('Long count batch '.repeat(12));
            await page.locator('[aria-label="Found batch notes"]').fill('Long notes '.repeat(30));
            await fits('Count batch entry ' + viewport.width);
            for (const table of await page.locator('.count-panel .table-wrap').all()) {
                await table.evaluate(node => {node.scrollLeft = node.scrollWidth;});
            }
            await page.screenshot({path: path.join(output, 'count-' + viewport.width + '.png'), fullPage: true});
            if (viewport.width === 640) {
                await page.locator('[aria-label="Found batch number or label"]').fill('TABLET-469');
                await page.locator('[aria-label="Found batch counted quantity"]').fill('2');
                await page.locator('[name="discrepancy_reason"]').fill('Disposable tablet count verification');
                await page.getByRole('button', {name:'Record Count', exact:true}).click();
                await page.waitForFunction(() => document.body.textContent.includes('recorded and sent for approval'));
                const approve = page.locator('form.inline-form').filter({has:page.locator('[name="action"][value="approve"]')}).first();
                await approve.getByRole('button', {name:'Approve', exact:true}).click();
                await page.waitForFunction(() => document.body.textContent.includes('approved and stock reconciled'));
            }

            console.log(viewport.width + 'x' + viewport.height + ': Product required/empty/retained wizard and Count long batch/local tables passed');
        }
        await context.close();
        console.log('Supplemental Chromium only; artifacts ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exit(1); });
