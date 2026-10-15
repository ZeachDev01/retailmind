const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Forecast decision browser: skipped (set RUN_DB_TESTS=1; disposable MySQL database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-forecast-decision-'));
const testEnv = {...process.env, BACKUP_STORAGE_PATH: recovery};
const route = '/components/inventory_management/replenishment_requests.php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/forecast_decision_fixture.php', action, database], {encoding: 'utf8', env: testEnv});
    if (result.status !== 0) throw new Error(result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('stock_guidance');
        console.log('Disposable forecast database seeded.');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...testEnv, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let errors = ''; server.stderr.on('data', chunk => { errors += chunk; });
        for (let i = 0; i < 100; i++) {
            try { if ((await fetch(origin, {signal: AbortSignal.timeout(1000)})).ok) break; } catch {}
            if (i === 99) throw new Error(errors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        console.log('Browser launched.');
        const context = await browser.newContext({baseURL: origin});
        const page = await context.newPage();
        await page.goto('/?login=1', {waitUntil: 'domcontentloaded'});
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('decision_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const queueRoute = '/components/inventory_management/replenishment_queue.php';
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        const stockOnly = page.locator('.queue-item').filter({hasText: 'Decision Test Item'});
        assert.match(await stockOnly.innerText(), /Out of stock/);
        assert.match(await stockOnly.innerText(), /16 units/);
        assert.match(await stockOnly.innerText(), /Stock rules/);
        assert.match(await stockOnly.innerText(), /Forecast unavailable/);
        fixture('seed');
        const unchanged = JSON.parse(fixture('state'));
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        assert.match(await stockOnly.innerText(), /Demand Forecast/);
        assert.match(await stockOnly.innerText(), /32 units/);
        const missing = page.locator('.queue-item').filter({hasText: 'Unrelated legacy product'});
        assert.match(await missing.innerText(), /Forecast unavailable/);
        assert.match(await missing.innerText(), /Low stock/);
        assert.equal(await page.getByRole('link', {name: 'Review Request', exact: true}).count(), 0);
        const response = await context.request.post(queueRoute, {form: {action: 'accept', product_id: 1}});
        assert.equal(response.status(), 405);
        assert.deepEqual(JSON.parse(fixture('state')), unchanged, 'Viewing and accepting guidance cannot mutate stock or purchasing');
        for (const mode of ['low_readiness', 'demo', 'production', 'unavailable']) {
            fixture(mode);
            const beforeView = JSON.parse(fixture('state'));
            await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
            const text = await stockOnly.innerText();
            if (mode === 'low_readiness') assert.match(text, /Low readiness forecast/);
            if (mode === 'demo') assert.match(await page.locator('main').innerText(), /Demo Mode/);
            if (mode === 'production') assert.match(await page.locator('main').innerText(), /Production forecast/);
            if (mode === 'unavailable') {
                assert.match(text, /Stock rules/);
                assert.match(text, /Forecast unavailable/);
                assert.match(text, /16 units/);
            }
            assert.deepEqual(JSON.parse(fixture('state')), beforeView, 'Guidance leaves the database unchanged in ' + mode);
        }
        fixture('restore_forecast');
        fs.mkdirSync('.scratch/ticket179', {recursive: true});
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        await page.screenshot({path: '.scratch/ticket179/desktop.png', fullPage: true});
        await page.setViewportSize({width: 390, height: 844});
        await page.screenshot({path: '.scratch/ticket179/mobile.png', fullPage: true});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Recommendations fit mobile width');
        await page.setViewportSize({width: 1280, height: 720});
        // Non-binding handoff to stock receiving
        const handoffLink = page.locator('tr.queue-item a', {hasText: 'Add bought goods'}).first();
        assert.equal(await handoffLink.count(), 1, 'Handoff action present on recommendation row');
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), handoffLink.click()]);
        assert.match(page.url(), /stock_receiving\.php\?product_id=1/);
        assert.equal(await page.locator('#product_id').inputValue(), '1', 'Product 1 pre-selected from recommendations');
        assert.equal(await page.locator('#received_qty').inputValue(), '', 'Bought quantity never prefilled from recommendations');

        // Assert retired procurement routes return 404 and cannot execute
        for (const retiredRoute of [
            '/components/inventory_management/legacy_reorder_planner.php',
            '/components/inventory_management/reorder_planner.php',
            route,
            '/components/invoice/purchase_orders.php',
            '/components/inventory_management/suppliers.php',
        ]) {
            const resp = await page.goto(retiredRoute);
            assert.equal(resp.status(), 404, retiredRoute + ' must return 404');
        }

        // Direct POST to retired procurement URLs must return 404 and cannot mutate stock or create records
        const beforePost = JSON.parse(fixture('state'));
        for (const [targetUrl, payload] of [
            [route, {action: 'create', product_id: 1, request_qty: 10}],
            ['/components/invoice/purchase_orders.php', {action: 'create', supplier_id: 1, 'request_ids[]': 1}],
            ['/components/inventory_management/suppliers.php', {action: 'save', supplier_name: 'Test'}]
        ]) {
            const postResp = await context.request.post(targetUrl, {form: payload});
            assert.equal(postResp.status(), 404, targetUrl + ' POST must return 404');
        }
        assert.deepEqual(JSON.parse(fixture('state')), beforePost, 'Direct HTTP calls cannot mutate records or stock');
        console.log('Forecast decision browser passed.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
