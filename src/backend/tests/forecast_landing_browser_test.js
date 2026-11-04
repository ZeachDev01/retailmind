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
        const page = await browser.newPage({viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
        page.setDefaultTimeout(15000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const pageErrors = [];
        let eligibilityPolicy;
        page.on('pageerror', error => pageErrors.push(error.message));

        const login = async role => {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.waitForFunction(() => document.activeElement === document.getElementById('landing-login-username'));
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(u => !u.search.includes('login=1'), {waitUntil: 'domcontentloaded'}),
                page.getByRole('button', {name: 'Log in', exact: true}).click(),
            ]);
            await page.waitForLoadState('domcontentloaded');
            const skipGuidance = page.getByRole('button', {name: 'Skip guidance', exact: true});
            if (await skipGuidance.isVisible()) {
                await skipGuidance.click();
                await skipGuidance.waitFor({state: 'hidden'});
            }
        };

        const checkWorkflow = async (role, expectedRole) => {
            const workflow = page.locator('.forecast-workflow');
            assert.equal(await workflow.getAttribute('open'), null, 'Full workflow starts collapsed');
            assert.ok((await page.locator('.forecast-role-context > p').innerText()).includes(expectedRole));
            await workflow.locator('summary').click();
            assert.equal(await page.getByRole('navigation', {name: 'Forecasting steps'}).isVisible(), true);
            assert.equal(await workflow.locator('ol li small').count(), 5, 'Every step identifies its owner');
            assert.equal(await workflow.locator('a[aria-current="step"]').count(), 0, 'Navigation does not claim task progress');
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Open workflow fits ' + width);
            }
            await workflow.locator('summary').focus();
            await page.keyboard.press('Enter');
            assert.equal(await workflow.getAttribute('open'), null, 'Keyboard closes workflow');
            await page.setViewportSize({width: 1440, height: 900});
            if (process.env.FORECAST_SCREENSHOTS) {
                await page.locator('h1').click();
                await page.evaluate(() => window.scrollTo(0, 0));
                fs.mkdirSync(process.env.FORECAST_SCREENSHOTS, {recursive: true});
                for (const width of [1440, 390]) {
                    await page.setViewportSize({width, height: 900});
                    await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, role + '-forecast-' + width + '.png'), fullPage: true, animations: 'disabled'});
                }
                await page.setViewportSize({width: 1440, height: 900});
            }
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
        const adminStudioNav = page.locator('.forecast-studio-nav');
        assert.equal(await adminStudioNav.count(), 1, 'Administrator uses the shared forecasting studio navigation');
        assert.equal(await adminStudioNav.locator('a').count(), 4, 'Administrator sees four permitted forecasting destinations');
        for (const label of ['Data readiness', 'Model review', 'Demand forecast', 'Exceptions']) {
            assert.ok((await adminStudioNav.innerText()).includes(label), 'Administrator navigation includes ' + label);
        }
        assert.equal(await adminStudioNav.locator('a[aria-current="page"]').count(), 1, 'Administrator navigation marks the active forecasting page');
        assert.equal(await adminStudioNav.locator('a[aria-current="page"]').innerText(), 'Demand forecast', 'Demand Forecast is the active Admin destination');
        assert.equal(await adminStudioNav.locator('a[href*="ml_settings.php"]').count(), 0, 'Administrator navigation excludes Model Training');
        assert.equal(await adminStudioNav.locator('a[href*="forecast_import.php"]').count(), 0, 'Administrator navigation excludes history import');
        const related = page.locator('.forecast-related');
        assert.equal(await related.getAttribute('open'), null, 'Extra destinations start collapsed');
        await related.locator('summary').click();
        const navText = await page.locator('.forecasting-nav-chips').innerText();
        for (const label of ['Forecast Exceptions', 'Data Readiness', 'Model Review & Approval', 'Store Calendar', 'Promotions']) assert.ok(navText.includes(label));
        await related.locator('summary').click();
        assert.equal(await page.locator('.forecast-studio-nav a[href$="replenishment_queue.php"]').count(), 0, 'Administrator studio navigation excludes Inventory Manager buying actions');
        assert.equal(await page.locator('button[name="action"][value="generate"]').count(), 0, 'Demand Forecast has no redundant top generate action');
        const s1Heading = page.locator('#heading-usability');
        const s2Heading = page.locator('#heading-actions');
        assert.match(await s1Heading.innerText(), /Forecast status/);
        assert.match(await s2Heading.innerText(), /Forecast checks/);
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
        await checkWorkflow('inventory_manager', 'Inventory Manager');
        assert.match(await page.locator('#heading-usability').innerText(), /Forecast status/);
        assert.match(await page.locator('#heading-actions').innerText(), /Forecast checks/);
        assert.equal(await page.locator('#heading-performance').isVisible(), false, 'Technical details remain collapsed for Inventory Manager');

        // Generate Now button MUST NOT be present for Inventory Manager
        assert.equal(await page.locator('button[name="action"][value="generate"]').count(), 0,
            'Inventory Manager cannot see Generate Now mutation button');

        // Unpermitted Model Review & Approval button MUST NOT be present in nav chips
        assert.equal(await page.locator('.forecasting-nav-chips a:has-text("Model Review & Approval")').count(), 0,
            'Inventory Manager does not receive Administrator Model Review link');

        // Any action items show read-only explanations with Administrator responsible
        const imIssues = page.locator('.action-issue-card');
        await page.locator('.forecast-actions > summary').click();
        if (await imIssues.count() > 0) {
            const imText = await imIssues.first().innerText();
            assert.match(imText, /Administrator/);
        }

        fixture('forecast_workspace');
        await page.reload();
        assert.match(await page.locator('.forecast-status-content > p').innerText(), /Review expected sales and buying suggestions/);
        assert.equal(await page.locator('.forecast-actions').getAttribute('open'), null, 'Supporting forecast checks start collapsed');
        assert.equal(await page.locator('.forecast-bar-row').count(), 1, 'Usable demand produces a real comparison chart');
        assert.equal(await page.locator('.page-heading-actions').count(), 0, 'Demand Forecast has no redundant top action group');
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
            if (process.env.FORECAST_SCREENSHOTS) {
                for (const width of [1440, 390]) {
                    await page.setViewportSize({width, height: 900});
                    await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'admin-' + path.basename(route, '.php') + '-' + width + '.png'), fullPage: true, animations: 'disabled'});
                }
            }
            if (route.includes('data_readiness')) {
                await page.getByText('How training eligibility works', {exact: true}).click();
                eligibilityPolicy = await page.getByRole('region', {name: 'Training Eligibility policy', exact: true}).innerText();
            }
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, route + ' fits ' + width);
            }
        }
        await login('super_admin');
        for (const route of ['report/forecast_analytics.php', 'system_administrator/ml_settings.php', 'system_administrator/forecast_import.php', 'report/data_readiness.php', 'report/predictions.php', 'report/forecast_exceptions.php']) {
            const response = await page.goto(origin + '/components/' + route);
            assert.equal(response.status(), 200, route);
            const studio = page.getByRole('navigation', {name: 'Forecasting workspace'});
            assert.equal(await studio.locator('a').count(), 6, 'All forecasting destinations remain reachable');
            assert.equal(await studio.locator('[aria-current="page"]').count(), 1, 'Only the current page is highlighted');
            assert.ok((await studio.locator('[aria-current="page"]').getAttribute('href')).endsWith(route));
            assert.equal(await page.locator('.forecast-studio-caption').count(), 0, 'Forecast studio has no redundant process caption');
            if (await page.locator('.forecast-notice').count() > 0) {
                await page.waitForTimeout(3300);
                assert.equal(await page.locator('.forecast-notice').count(), 0, 'Forecast notices dismiss after three seconds');
            }
            if (route.includes('data_readiness')) {
                assert.equal(await page.locator('.forecast-readiness-visual meter').count(), 3);
                const row = page.locator('.readiness-catalog tbody tr').first();
                await page.setViewportSize({width: 1440, height: 900});
                assert.ok(await row.evaluate(el => el.getBoundingClientRect().height) <= 80, 'Closed readiness rows stay compact');
                const details = row.locator('details');
                assert.equal(await details.getAttribute('open'), null);
                await details.locator('summary').focus();
                await page.keyboard.press('Enter');
                assert.match(await details.innerText(), /Recent 28 days:|Three test periods:/);
                await page.keyboard.press('Enter');
                const rowHelp = row.locator('.info-tooltip-trigger');
                await rowHelp.focus();
                assert.equal(await row.locator('[role="tooltip"]').isVisible(), true);
                await page.keyboard.press('Escape');
                assert.equal(await row.locator('[role="tooltip"]').isVisible(), false);
            }

            for (const theme of ['light', 'dark']) {
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.equal(await studio.locator('[aria-current="page"]').evaluate(el => getComputedStyle(el).color), 'rgb(255, 255, 255)', 'Active navigation retains readable text in ' + theme);
                await page.locator('h1').click();
                for (const width of [1440, 640, 390, 320]) {
                    await page.setViewportSize({width, height: 900});
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, route + ' fits ' + width + ' in ' + theme);
                    if (process.env.FORECAST_SCREENSHOTS && [1440, 390].includes(width)) {
                        fs.mkdirSync(process.env.FORECAST_SCREENSHOTS, {recursive: true});
                        await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, path.basename(route, '.php') + '-' + theme + '-' + width + '.png'), fullPage: true, animations: 'disabled'});
                    }
                }
            }
            if (route.includes('forecast_analytics')) {
                assert.equal(await page.locator('#analytics-tab-actual').getAttribute('aria-selected'), 'true');
                await page.locator('#analytics-tab-trend').click();
                assert.equal(await page.locator('#trendChart').count(), 0, 'Missing recorded training errors never produce a sample trend');
                await page.locator('#analytics-tab-features').click();
                assert.equal(await page.locator('#featureChart').count(), 0, 'Missing weights never produce invented feature importance');
            }
            if (route.includes('ml_settings')) {
                const eligibility = page.locator('details').filter({has: page.locator('[aria-label="Training Eligibility policy"]')}).first();
                assert.equal(await eligibility.getAttribute('open'), null, 'Training policy starts collapsed');
                await eligibility.locator('summary').first().click();
                await page.getByText('Fixed model recipe & approval requirements', {exact: true}).click();
                assert.equal(await page.getByRole('region', {name: 'Training Eligibility policy', exact: true}).innerText(), eligibilityPolicy, 'Readiness and settings explain one effective policy');
                assert.match(await page.getByRole('region', {name: 'Effective model recipe', exact: true}).innerText(), /15 trees, maximum depth 8, random seed 42/);
                assert.equal(await page.locator('[name="min_training_days"], [name="preferred_training_days"], [name="min_nonzero_days"], [name="max_training_days"], [name="forecast_horizon_days"], [name="lower_percentile"], [name="upper_percentile"], [name="n_estimators"], [name="max_depth"]').count(), 0, 'Disconnected inputs remain removed');
                assert.equal(await page.locator('#schedule_weekly_retrain_day').inputValue(), 'Sunday');
                assert.match(await page.locator('#effective-training-schedule').innerText(), /Sunday at 1:00 AM, Philippine time \(Asia\/Manila\)/);
                assert.equal(await page.locator('[name="retrain_frequency_days"], [name="retrain_new_sales_records"], [name="schedule_daily_forecast_time"]').count(), 0, 'Only weekly training is editable');
                const scheduleForm = page.locator('form').filter({has: page.locator('[name="action"][value="save_schedule"]')});
                const scheduleUrl = page.url();
                const trainingStatus = await page.locator('.ml-job-status').innerText();
                const timeHelp = page.getByRole('button', {name: 'About training time', exact: true});
                await page.mouse.move(0, 0);
                await timeHelp.scrollIntoViewIfNeeded();
                await timeHelp.focus();
                await timeHelp.press('Enter');
                const scheduleTooltip = page.locator('#schedule_time_help');
                assert.equal(await scheduleTooltip.isVisible(), true);
                assert.equal(await scheduleTooltip.innerText(), 'Select a time from 12:00 AM to 11:59 PM, Philippine time (Asia/Manila). Prefer a time after Store close. Missed training runs catch up when the PC starts.');
                const timeRect = await scheduleTooltip.boundingBox();
                assert.ok(timeRect.x >= 0 && timeRect.x + timeRect.width <= 320);
                await timeHelp.press('Escape');
                assert.equal(await scheduleTooltip.isVisible(), false);
                await page.locator('#schedule_hour').focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.locator('#schedule_minute').evaluate(el => el === document.activeElement), true, 'Minute selection works by keyboard');
                for (const [hour, minute, period, display] of [
                    ['12', '00', 'AM', '12:00 AM'], ['12', '00', 'PM', '12:00 PM'], ['11', '59', 'PM', '11:59 PM'],
                ]) {
                    await page.locator('#schedule_weekly_retrain_day').selectOption('Wednesday');
                    await page.locator('#schedule_hour').selectOption(hour);
                    await page.locator('#schedule_minute').selectOption(minute);
                    await page.locator('#schedule_period').selectOption(period);
                    await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Save weekly schedule', exact: true}).click()]);
                    assert.match(await page.locator('main [role="status"]').first().innerText(), /Weekly schedule saved/);
                    await page.reload();
                    assert.equal(await page.locator('#effective-training-schedule').innerText(), `Every Wednesday at ${display}, Philippine time (Asia/Manila).`);
                    assert.equal(await page.locator('#schedule_hour').inputValue(), hour);
                    assert.equal(await page.locator('#schedule_period').inputValue(), period);
                    assert.equal(await page.locator('.ml-job-status').innerText(), trainingStatus, 'Saving does not queue training');
                }
                const scheduleValues = await scheduleForm.evaluate(form => Object.fromEntries(new FormData(form)));
                assert.equal((await page.context().request.post(scheduleUrl, {form: {...scheduleValues, csrf_token: ''}})).status(), 403, 'Schedule saves require CSRF');
                for (const invalid of [
                    {schedule_weekly_retrain_day: 'Funday'}, {schedule_hour: '0'}, {schedule_hour: '13'},
                    {schedule_minute: '60'}, {schedule_period: '24'},
                ]) {
                    const response = await page.context().request.post(scheduleUrl, {form: {...scheduleValues, ...invalid}});
                    assert.equal(response.status(), 200);
                    assert.match(await response.text(), /Schedule was not saved/);
                }
                await page.reload();
                assert.equal(await page.locator('#effective-training-schedule').innerText(), 'Every Wednesday at 11:59 PM, Philippine time (Asia/Manila).', 'Invalid saves preserve the effective schedule');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'Schedule controls fit mobile');
            }
        }
        for (const role of ['admin', 'inventory_manager', 'cashier']) {
            await login(role);
            const response = await page.context().request.post(origin + '/components/system_administrator/ml_settings.php', {form: {
                action: 'save_schedule', schedule_weekly_retrain_day: 'Friday', schedule_hour: '3', schedule_minute: '00', schedule_period: 'AM',
            }});
            assert.equal(response.status(), 403, role + ' cannot save a training schedule');
        }
        await login('super_admin');
        await page.goto(origin + '/components/system_administrator/ml_settings.php');
        assert.match(await page.locator('#effective-training-schedule').innerText(), /Wednesday at 11:59 PM/, 'Unauthorized saves preserve the schedule');
        await page.goto(origin + '/components/report/data_readiness.php');
        assert.equal(await page.locator('.forecast-studio-nav a[href*="ml_settings.php"]').count(), 1, 'Forecast studio links authorized users to training settings');
        fixture('forecast_replay_workspace');
        const replay = await page.goto(origin + '/components/report/forecast_replay.php');
        assert.equal(replay.status(), 200);
        assert.equal(await page.locator('.replay-bar-pair').count(), 28);
        assert.match(await page.locator('main p[role="status"]').innerText(), /Experimental preview only/);
        if (process.env.FORECAST_SCREENSHOTS) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'Replay fits ' + width);
                await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'replay-' + width + '.png'), fullPage: true, animations: 'disabled'});
            }
        }
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
