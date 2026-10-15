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
async function checkQueueAccessibility(page) {
    await page.waitForFunction(() => document.getAnimations().every(animation =>
        animation.effect?.getComputedTiming().iterations === Infinity || animation.playState !== 'running'));
    const issues = await page.locator('main').evaluate(main => {
        const rgba = color => {
            if (!/^rgba?\(/.test(color)) throw new Error('Unsupported computed contrast color: ' + color);
            return color.match(/[\d.]+/g).map(Number);
        };
        const blend = (front, back) => front.slice(0, 3).map((value, i) => value * (front[3] ?? 1) + back[i] * (1 - (front[3] ?? 1)));
        const luminance = rgb => rgb.map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
            .reduce((sum, value, i) => sum + value * [.2126, .7152, .0722][i], 0);
        const issues = [];
        const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode, el = node.parentElement;
            if (!node.textContent.trim() || !el.checkVisibility() || el.closest('button:disabled, [hidden], script, style')) continue;
            const style = getComputedStyle(el);
            const layers = [];
            for (let parent = el; parent; parent = parent.parentElement) layers.unshift(rgba(getComputedStyle(parent).backgroundColor));
            const background = layers.reduce((back, front) => blend(front, back), [255, 255, 255]);
            const foreground = blend(rgba(style.color), background);
            const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
            const ratio = (values[0] + .05) / (values[1] + .05);
            const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.667 && parseInt(style.fontWeight) >= 700);
            if (ratio < (large ? 3 : 4.5)) issues.push(`${node.textContent.trim().slice(0, 40)}: contrast ${ratio.toFixed(2)}`);
        }
        for (const el of main.querySelectorAll('a, button, input, select, summary')) {
            if (!el.checkVisibility()) continue;
            const rect = el.getBoundingClientRect();
            if (rect.height < 44 || rect.width < 44) issues.push(`${el.textContent.trim() || el.getAttribute('aria-label')}: target ${rect.width}×${rect.height}`);
        }
        return issues;
    });
    assert.deepEqual(issues, [], 'Queue text meets AA contrast and controls have 44px targets');
}
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
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
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

        fixture('queue_many');
        const started = performance.now();
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        const rows = page.locator('.queue-item');
        assert.equal(await rows.count(), 1002);
        assert.equal(await page.locator('.queue-item:visible').count(), 25);
        const loadMs = Math.round(performance.now() - started);
        assert.match(await rows.first().innerText(), /Forecast urgency: Urgent/);
        await page.getByRole('button', {name: 'Next page', exact: true}).click();
        assert.match(await page.locator('.auto-page-summary').innerText(), /Showing 26–50 of 1002/);
        await page.getByRole('searchbox', {name: 'Search table', exact: true}).fill('QUEUE-0999');
        await page.waitForFunction(() => document.querySelector('.toolbar-count')?.textContent === '1 result');
        assert.equal(await page.locator('.queue-item:visible').count(), 1);
        assert.match(await page.locator('.queue-item:visible').innerText(), /Queue product 999/);
        await page.getByRole('searchbox', {name: 'Search table', exact: true}).fill('no-such-queue-product');
        await page.waitForFunction(() => document.querySelector('.toolbar-count')?.textContent === '0 results');
        assert.match(await page.locator('.auto-page-summary').innerText(), /Showing 0 results/);
        await page.getByRole('button', {name: 'Clear', exact: true}).click();
        assert.equal(await page.locator('.queue-item:visible').count(), 25);
        const artifacts = 'output/playwright/replenishment';
        fs.mkdirSync(artifacts, {recursive: true});
        for (const colorScheme of ['light', 'dark']) {
            await page.setViewportSize({width: 1280, height: 900});
            await page.emulateMedia({colorScheme, reducedMotion: 'reduce'});
            await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, colorScheme);
            for (const width of [1280, 768, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true,
                    `${colorScheme} queue fits ${width}px`);
                assert.equal(await page.locator('.queue-list').evaluate(el => el.scrollWidth > el.clientWidth), width < 1000);
                await checkQueueAccessibility(page);
                if (width === 1280 || width === 390) {
                    await page.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
                    await page.screenshot({path: `${artifacts}/${colorScheme}-${width}.png`});
                }
            }
        }
        // Drive native horizontal scrolling with Chromium's synthesized touch input.
        await page.setViewportSize({width: 390, height: 900});
        await page.locator('.queue-list').scrollIntoViewIfNeeded();
        const bounds = await page.locator('.queue-list').boundingBox();
        const touch = await context.newCDPSession(page);
        await touch.send('Emulation.setTouchEmulationEnabled', {enabled: true});
        const y = Math.max(160, Math.min(700, bounds.y + 150));
        await touch.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{x: 290, y}]});
        for (const x of [250, 200, 150, 100, 60]) {
            await touch.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x, y}]});
        }
        await touch.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
        await page.waitForFunction(() => document.querySelector('.queue-list').scrollLeft > 0);
        await touch.detach();
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        for (const width of [1280, 390]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await page.locator('main').evaluate(el => el.scrollWidth <= el.clientWidth), true,
                `200% text fits ${width}px without clipping the page`);
        }
        await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
        await page.locator('.queue-list').press('Tab');
        assert.equal(await page.evaluate(() => !!document.activeElement.closest('.queue-list')), true, 'Keyboard enters product links');
        await page.setViewportSize({width: 1280, height: 900});
        await page.getByText('Non-binding buying guidance', {exact: true}).click();
        assert.equal(await page.locator('.queue-guidance').getAttribute('open'), '');
        await page.getByText('Non-binding buying guidance', {exact: true}).press('Enter');
        assert.equal(await page.locator('.queue-guidance').getAttribute('open'), null);
        await page.emulateMedia({media: 'print'});
        await page.setViewportSize({width: 794, height: 1123});
        assert.equal(await page.locator('.queue-item:visible').count(), 1002, 'Print includes every row beyond the selected page');
        assert.equal(await page.getByRole('button', {name: 'Print all recommendations'}).isVisible(), false);
        await page.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
        await page.screenshot({path: `${artifacts}/print.png`});
        await page.emulateMedia({media: 'screen'});
        assert.equal(await page.locator('.queue-item:visible').count(), 25, 'Printing preserves screen pagination');
        console.log('Queue 1002-product load: ' + loadMs + 'ms; search, pagination, light/dark, 320–1280px, keyboard, and print passed.');
        fixture('queue_empty');
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        assert.match(await page.locator('main').innerText(), /No active products available/);
        assert.equal(await page.getByRole('button', {name: 'Print all recommendations'}).count(), 0);

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
        fixture('queue_failure');
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
        const failedText = await page.locator('main').innerText();
        assert.match(failedText, /Recommendations could not be loaded/);
        assert.doesNotMatch(failedText, /No active products available|SQLSTATE|PDOException/);
        assert.equal(await page.getByRole('link', {name: 'Reload', exact: true}).count(), 1);
        fixture('queue_recover');
        await page.getByRole('link', {name: 'Reload', exact: true}).click();
        await page.waitForSelector('.queue-item');
        assert.equal(await page.locator('.queue-item').count(), 2, 'Reload recovers recommendations after a load failure');
        assert.equal(await page.locator('main [role="status"]').count(), 0);
        assert.deepEqual(pageErrors, [], 'No JavaScript exceptions during queue workflows');
        console.log('Forecast decision browser passed.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
