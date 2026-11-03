const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Forecast landing browser test: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-forecastlanding-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const php = process.env.PHP_BINARY || 'php';

const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        console.log('Test database created: ' + database);

        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database, FORECAST_REPLAY_DATABASE: database}, stdio: 'ignore'});

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(15000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));

        const login = async role => {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(u => !u.search.includes('login=1')),
                page.getByRole('button', {name: 'Log in', exact: true}).click(),
            ]);
            await page.waitForLoadState('domcontentloaded');
        };

        // -------------------------------------------------------------
        // 1. Administrator Persona Checks
        // -------------------------------------------------------------
        console.log('Testing Administrator persona on Demand Forecast landing page...');
        await login('admin');
        await page.goto(origin + '/components/report/predictions.php');
        await page.waitForLoadState('domcontentloaded');

        assert.match(await page.locator('h1').innerText(), /Demand Forecast/);
        assert.match(await page.locator('.page-subtitle').innerText(), /Expected sales/);
        const navText = await page.locator('.forecasting-nav-chips').innerText();
        for (const label of ['Forecast Exceptions', 'Data Readiness', 'Model Review & Approval', 'Store Calendar', 'Promotions']) assert.ok(navText.includes(label));
        assert.equal(await page.locator('main a[href$="replenishment_queue.php"]').count(), 1, 'Replenishment navigation is not duplicated');
        assert.equal(await page.locator('button[name="action"][value="generate"]').count(), 1);
        const s1Heading = page.locator('#heading-usability');
        const s2Heading = page.locator('#heading-actions');
        assert.match(await s1Heading.innerText(), /Forecast status/);
        assert.match(await s2Heading.innerText(), /Needs attention/);
        const performance = page.locator('details').filter({has: page.locator('#heading-performance')});
        assert.equal(await performance.getAttribute('open'), null, 'Technical performance starts collapsed');
        const productPosition = await page.locator('#product-forecasts').evaluate(el => el.getBoundingClientRect().top);
        const detailPosition = await performance.evaluate(el => el.getBoundingClientRect().top);
        assert.ok(productPosition < detailPosition, 'Products precede technical metrics');
        await performance.locator('summary').click();
        assert.match(await performance.innerText(), /Still collecting evidence|Evaluation Evidence/);
        assert.match(await performance.innerText(), /Daily errors/);
        await performance.locator('summary').click();
        assert.equal(await page.locator('.forecast-bars').count(), 0, 'Missing forecasts are not graphed as zero');
        const coverageHelp = page.getByRole('button', {name: 'About forecast availability'});
        await coverageHelp.focus();
        assert.equal(await page.locator('#coverage-help').isVisible(), true);
        await coverageHelp.press('Escape');
        assert.equal(await page.locator('#coverage-help').isVisible(), false);

        // Check Section 4: Product Forecasts Table & Search
        const searchInput = page.locator('input[type="search"][name="search"]');
        await searchInput.waitFor();
        assert.equal(await searchInput.count(), 1, 'Search input is present');

        // Test search interaction
        await searchInput.fill('NonexistentProductQuery12345');
        await page.locator('.search-form button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        assert.match(await page.locator('.empty-state').innerText(), /No matching products found|No Forecasts Available/);

        // Clear search
        await page.locator('a:has-text("Clear")').click();
        await page.waitForLoadState('domcontentloaded');

        // Check responsive layout across viewports and themes
        for (const theme of ['light', 'dark']) {
            await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
            for (const width of [1440, 640, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.waitForTimeout(50);
                assert.equal(await page.locator('h1').isVisible(), true, `Heading visible on width ${width}px theme ${theme}`);
                assert.equal(await s1Heading.isVisible(), true, `Section 1 visible on width ${width}px theme ${theme}`);
                assert.equal(await s2Heading.isVisible(), true, `Section 2 visible on width ${width}px theme ${theme}`);
                assert.equal(await page.locator('main').evaluate(el => document.documentElement.scrollWidth <= window.innerWidth), true, `No page overflow at ${width}px in ${theme}`);
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // -------------------------------------------------------------
        // 2. Inventory Manager Persona Checks (Read-Only Explanations)
        // -------------------------------------------------------------
        console.log('Testing Inventory Manager persona on Demand Forecast landing page...');
        await login('inventory_manager');
        await page.goto(origin + '/components/report/predictions.php');
        await page.waitForLoadState('domcontentloaded');

        // Usability and sections visible
        assert.match(await page.locator('#heading-usability').innerText(), /Forecast status/);
        assert.match(await page.locator('#heading-actions').innerText(), /Needs attention/);
        assert.equal(await page.locator('#heading-performance').isVisible(), false, 'Technical details remain collapsed for Inventory Manager');

        // Generate Now button MUST NOT be present for Inventory Manager
        assert.equal(await page.locator('button[name="action"][value="generate"]').count(), 0,
            'Inventory Manager cannot see Generate Now mutation button');

        // Unpermitted Model Review & Approval button MUST NOT be present in nav chips
        assert.equal(await page.locator('.forecasting-nav-chips a:has-text("Model Review & Approval")').count(), 0,
            'Inventory Manager does not receive Administrator Model Review link');

        // Any action items show read-only explanations with Administrator responsible
        const imIssues = page.locator('.action-issue-card');
        if (await imIssues.count() > 0) {
            const imText = await imIssues.first().innerText();
            assert.match(imText, /Administrator/);
        }

        fixture('forecast_workspace');
        await page.reload();
        assert.equal(await page.locator('.forecast-bar-row').count(), 1, 'Usable demand produces a real comparison chart');
        assert.equal(await page.locator('.page-heading-actions .btn').first().evaluate(el => el.scrollWidth <= el.clientWidth), true, 'Primary action text fits its button');
        const row = page.locator('#product-forecasts tbody tr').first();
        assert.match(await row.innerText(), /14[\s\S]*28[\s\S]*56/);
        await page.locator('.forecast-bar-row a').click();
        await page.waitForURL('**/product_investigation.php?product_id=1');
        await page.waitForLoadState('domcontentloaded');
        assert.equal(await page.locator('.daily-bar').count(), 28, 'Product chart shows the actual 28 daily outputs');
        await page.getByText('Daily forecast values', {exact: true}).click();
        assert.equal(await page.locator('details .table-wrap[aria-label="Daily forecast values"] tbody tr').count(), 28);
        await login('admin');
        for (const route of ['report/data_readiness.php', 'report/forecast_exceptions.php', 'administrator/model_review.php', 'inventory_management/replenishment_queue.php']) {
            const response = await page.goto(origin + '/components/' + route);
            assert.equal(response.status(), 200, route);
            assert.equal(await page.locator('body.forecast-workspace').count(), 1);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, route + ' fits ' + width);
            }
        }
        await login('super_admin');
        for (const route of ['report/forecast_analytics.php', 'system_administrator/ml_settings.php', 'system_administrator/forecast_import.php']) {
            const response = await page.goto(origin + '/components/' + route);
            assert.equal(response.status(), 200, route);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, route + ' fits ' + width);
            }
            if (route.includes('forecast_analytics')) {
                assert.equal(await page.locator('#analytics-tab-actual').getAttribute('aria-selected'), 'true');
                await page.locator('#analytics-tab-trend').click();
                assert.equal(await page.locator('#trendChart').count(), 0, 'Missing recorded training errors never produce a sample trend');
                await page.locator('#analytics-tab-features').click();
                assert.equal(await page.locator('#featureChart').count(), 0, 'Missing weights never produce invented feature importance');
            }
            if (route.includes('ml_settings')) {
                await page.locator('#settings-heading').click();
                const help = page.getByRole('button', {name: 'About Minimum history (days)', exact: true});
                await help.focus();
                const tooltip = page.locator('#minimum_history_days_help');
                assert.equal(await tooltip.isVisible(), true);
                const rect = await tooltip.boundingBox();
                assert.ok(rect.x >= 0 && rect.x + rect.width <= 320, 'Settings help stays inside a narrow viewport');
                await help.press('Escape');
                assert.equal(await tooltip.isVisible(), false);
            }
        }
        fixture('forecast_replay_workspace');
        const replay = await page.goto(origin + '/components/report/forecast_replay.php');
        assert.equal(replay.status(), 200);
        assert.equal(await page.locator('.replay-bar-pair').count(), 28);
        assert.match(await page.locator('main p[role="status"]').innerText(), /Experimental preview only/);
        await page.getByText('Daily comparison values', {exact: true}).click();
        assert.equal(await page.locator('#replay-comparison tbody tr').count(), 28);
        assert.deepEqual(pageErrors, [], 'No unexpected page errors occurred during test');
        console.log('PASS: Forecast landing browser test passed cleanly!');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})();
