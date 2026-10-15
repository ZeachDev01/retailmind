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
        await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});

        await page.goto('/components/inventory_management/reorder_planner.php', {waitUntil: 'domcontentloaded'});
        assert.match(page.url(), /replenishment_queue.php/);
        await page.goto(route, {waitUntil: 'domcontentloaded'});
        console.log('Replenishment page loaded.');
        assert.equal(await page.locator('input[name="source"][value="ml_forecast"]').count(), 1, 'Forecast creation form rendered');
        const create = page.locator('form').filter({has: page.locator('input[name="source"][value="ml_forecast"]')}).first();
        assert.equal(await create.locator('input[name="forecast_job_id"]').inputValue(), '10', 'Form retains displayed forecast job');
        assert.equal(await create.locator('input[name="forecast_prediction_id"]').count(), 0, 'Product ID never submitted as legacy prediction');
        console.log('Forecast form source checked.');
        fixture('upgrade');
        console.log('Legacy schema upgraded twice.');
        const before = JSON.parse(fixture('state'));
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), create.evaluate(form => form.submit())]);
        const after = JSON.parse(fixture('state'));
        assert.equal(after.requests.length, before.requests.length + 1);
        assert.equal(after.decisions.length, before.decisions.length + 1);
        const decision = after.decisions.at(-1);
        assert.equal(decision.decision, 'accepted');
        assert.equal(decision.forecast_job_id, 10);
        assert.equal(decision.product_id, 1);
        assert.equal(decision.prediction_id, null);
        assert.equal(after.requests.at(-1).forecast_prediction_id, null);
        assert.equal(after.requests.at(-1).forecast_job_id, 10);
        assert.deepEqual(after.decisions[0], {...before.decisions[0], forecast_job_id: null}, 'Migration retains legacy decision');
        assert.equal(after.requests[0].forecast_prediction_id, 100, 'Migration retains legacy request');

        const state = () => JSON.parse(fixture('state'));
        const submit = async form => Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), form.evaluate(el => el.submit())]);
        const dismiss = page.locator('form').filter({has: page.locator('input[name="action"][value="reject_forecast"]')}).first();
        for (const collision of [false, true]) {
            if (collision) fixture('collision');
            for (const action of (collision ? ['accepted', 'modified', 'rejected'] : ['modified', 'rejected'])) {
                await page.goto(route, {waitUntil: 'domcontentloaded'});
                const form = action === 'rejected' ? dismiss : create;
                assert.equal(await form.locator('input[name="forecast_job_id"]').inputValue(), '10');
                const original = Number(await form.locator('input[name="original_suggested_qty"]').inputValue());
                const quantity = action === 'rejected' ? 0 : original + (action === 'modified' ? 1 : 0);
                if (action === 'modified') {
                    await form.locator('input[name="request_qty"]').fill(String(quantity));
                    await form.locator('input[name="override_reason"]').fill('Supplier package adjustment');
                }
                const prior = state();
                // A newly completed run must not relabel an already displayed recommendation.
                if (collision && action === 'rejected') fixture('next_run');
                await submit(form);
                const saved = state();
                const record = saved.decisions.at(-1);
                assert.equal(saved.decisions.length, prior.decisions.length + 1);
                assert.equal(saved.requests.length, prior.requests.length + (action === 'rejected' ? 0 : 1));
                assert.equal(record.decision, action);
                assert.equal(record.forecast_job_id, 10);
                assert.equal(record.product_id, 1);
                assert.equal(record.prediction_id, null);
                assert.equal(record.original_suggested_qty, original);
                assert.equal(record.final_quantity, quantity);
                assert.equal(record.decided_by, 3);
                if (action !== 'rejected') assert.equal(record.replenishment_request_id, saved.requests.at(-1).request_id);
            }
        }
        await page.goto(route, {waitUntil: 'domcontentloaded'});
        const token = await create.locator('input[name="csrf_token"]').inputValue();
        const prior = state();
        for (const invalid of [{forecast_job_id: 10, product_id: 2}, {forecast_job_id: 999, product_id: 1}, {forecast_prediction_id: 1, product_id: 1}]) {
            const response = await context.request.post(route, {form: {action: 'create', source: 'ml_forecast', request_qty: 28,
                original_suggested_qty: 28, csrf_token: token, ...invalid}});
            assert.match(await response.text(), /The forecast source is unavailable/);
            assert.deepEqual(state(), prior, 'Invalid source leaves no request or decision');
        }
        for (const mode of ['observe', 'suspend']) {
            fixture('activate');
            await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
            const review = page.getByRole('link', {name: 'Review Request', exact: true});
            assert.equal(await review.count(), 0, 'Recommendations never lead directly to purchasing');
            await page.goto(route, {waitUntil: 'domcontentloaded'});
            assert.equal(await page.locator('#request_qty').inputValue(), '', 'Queue must not copy forecast quantities into manual purchasing');
            assert.equal(await create.locator('input[name="source"]').inputValue(), 'ml_forecast');
            const staleToken = await create.locator('input[name="csrf_token"]').inputValue();
            fixture(mode);
            const beforeGate = state();
            await submit(create);
            assert.match(await page.locator('.message.error').innerText(), /Forecast-backed purchasing is unavailable/);
            assert.deepEqual(state(), beforeGate, 'Stale forecast form cannot save requests or decisions');
            assert.equal(await page.getByRole('button', {name: 'Create Request from Forecast', exact: true}).count(), 0);
            assert.equal(await page.getByRole('button', {name: 'Dismiss', exact: true}).count(), 0);
            for (const action of ['create', 'reject_forecast']) {
                const response = await context.request.post(route, {form: {action, source: 'ml_forecast',
                    forecast_job_id: 10, product_id: 1, request_qty: 28, original_suggested_qty: 28,
                    override_reason: 'Direct submission', csrf_token: staleToken}});
                assert.match(await response.text(), /Forecast-backed purchasing is unavailable/);
                assert.deepEqual(state(), beforeGate, 'Direct submission cannot bypass recommendation gate');
            }
            await page.goto(queueRoute, {waitUntil: 'domcontentloaded'});
            assert.equal(await review.count(), 0, 'Inactive recommendations have no actionable review links');
            assert.match(await page.locator('.queue-list').innerText(), /Stock rules/);
            assert.match(await page.locator('.queue-list').innerText(), mode === 'observe' ? /Observation Mode/ : /Suspended/);
            assert.deepEqual(state(), beforeGate, 'Restricted guidance does not mutate inventory or purchasing');
            await page.goto(route + '?product_id=1&suggested_qty=999', {waitUntil: 'domcontentloaded'});
            assert.equal(await page.locator('#request_qty').inputValue(), '', 'Old queue URLs cannot prefill manual quantity');
            const manual = page.locator('.create-section form');
            await manual.locator('#product_id').selectOption('1');
            await manual.locator('#request_qty').fill('5');
            await submit(manual);
            assert.match(await page.locator('.message.success').innerText(), /created successfully/);
            assert.equal(state().requests.length, beforeGate.requests.length + 1, 'Manual purchasing stays available');
            assert.equal(state().requests.at(-1).source, 'manual');
            assert.equal(state().requests.at(-1).forecast_job_id, null);
            assert.deepEqual(state().decisions, beforeGate.decisions, 'Manual request never records a forecast decision');
        }
        fixture('activate');
        fixture('fail');
        for (const form of [create, dismiss]) {
            await page.goto(route, {waitUntil: 'domcontentloaded'});
            const prior = state();
            await submit(form);
            assert.match(await page.locator('.message.error').innerText(), /could not be saved/);
            assert.equal(await page.locator('.message.success').count(), 0, 'Persistence failure has no success acknowledgment');
            assert.deepEqual(state(), prior, 'Decision failure rolls back request and audit transaction');
        }
        console.log('Forecast decision browser passed.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
