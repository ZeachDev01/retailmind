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

        const legacyReports = fs.readFileSync('src/frontend/assets/css/reports.css', 'utf8').split('.analytics-empty-state {')[0];
        await page.route('**/assets/css/reports.css', route => route.fulfill({contentType: 'text/css', body: legacyReports}));
        await login('super_admin');
        await page.evaluate(async legacy => {
            await navigator.serviceWorker.ready;
            const cache = await caches.open('retailmind-static-v2');
            await cache.put(new URL('/assets/css/reports.css', location.origin), new Response(legacy, {headers: {'Content-Type': 'text/css'}}));
        }, legacyReports);
        await page.emulateMedia({reducedMotion: 'no-preference'});
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            for (const source of ['system_administrator/forecast_import.php', 'system_administrator/ml_settings.php', 'report/predictions.php']) {
                await page.goto(origin + '/components/' + source);
                await page.locator('.forecast-studio-nav a[href$="/forecast_analytics.php"]').click();
                await page.waitForURL('**/forecast_analytics.php');
                await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
                assert.ok(await page.locator('link[rel="stylesheet"]').evaluateAll(links => links.some(link => new URL(link.href).pathname.endsWith('/assets/css/reports.css') && new URL(link.href).searchParams.has('v'))), 'Analytics uses versioned CSS even with an older service-worker cache');
                for (const tab of ['actual', 'features', 'trend', 'best', 'attention', 'actual']) {
                    await page.locator('#analytics-tab-' + tab).click();
                    const empty = page.locator('#analytics-panel-' + tab + ' .analytics-empty-state');
                    const geometry = await empty.locator('.analytics-empty-art').evaluate(el => {
                        const art = el.getBoundingClientRect();
                        const icon = el.querySelector('.analytics-empty-icon').getBoundingClientRect();
                        return {width: art.width, height: art.height, x: icon.x + icon.width / 2 - art.x - art.width / 2, y: icon.y + icon.height / 2 - art.y - art.height / 2, glyph: getComputedStyle(el.querySelector('i'), '::before').content};
                    });
                    assert.equal(geometry.width, 144, 'Empty artwork keeps its width after navigation from ' + source);
                    assert.equal(geometry.height, 112, 'Empty artwork keeps its height');
                    assert.ok(Math.abs(geometry.x) < 2 && Math.abs(geometry.y) < 2, 'Empty icon stays centered: ' + JSON.stringify(geometry));
                    assert.ok(!['none', 'normal'].includes(geometry.glyph), 'Empty icon glyph survives navigation');
                    assert.equal(await empty.locator('.analytics-empty-shape-front').evaluate(el => el.getAnimations().some(animation => animation.playState === 'running')), false, 'Empty artwork stays assembled during the workspace fade');
                    if (process.env.FORECAST_SCREENSHOTS && source.includes('ml_settings') && tab !== 'actual') {
                        fs.mkdirSync(process.env.FORECAST_SCREENSHOTS, {recursive: true});
                        await empty.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'empty-navigation-' + tab + '-' + width + '.png')});
                    }
                }
            }
        }
        await page.unroute('**/assets/css/reports.css');
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.goto(origin + '/components/report/forecast_analytics.php');
        const emptyShape = page.locator('#analytics-panel-actual .analytics-empty-shape-front');
        assert.equal(await emptyShape.evaluate(el => getComputedStyle(el).animationName), 'none', 'Reduced motion keeps geometric art still');
        await page.emulateMedia({reducedMotion: 'no-preference'});
        assert.equal(await emptyShape.evaluate(el => getComputedStyle(el).animationName), 'none', 'Empty artwork remains assembled with normal motion');
        await page.emulateMedia({reducedMotion: 'reduce'});
        for (const theme of ['light', 'dark']) {
            await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                for (const tab of ['actual', 'features', 'trend', 'best', 'attention']) {
                    await page.locator('#analytics-tab-' + tab).click();
                    const panel = page.locator('#analytics-panel-' + tab);
                    const empty = panel.locator('.analytics-empty-state');
                    assert.equal(await empty.isVisible(), true, tab + ' explains missing evidence');
                    assert.ok((await empty.locator('h3').innerText()).length > 0);
                    const description = await empty.locator('p').innerText();
                    assert.ok(description.length > 0 && description.length <= 100, 'Empty-state subtext stays concise');
                    assert.equal(await empty.locator('.analytics-empty-art').getAttribute('aria-hidden'), 'true', 'Decorative shapes stay out of the accessibility tree');
                    assert.match(await empty.getByRole('link').getAttribute('href'), /components\/(report|system_administrator)\//);
                    assert.equal(await panel.locator('canvas').count(), 0, 'Empty panels never invent chart data');
                    assert.equal(await empty.evaluate(el => el.scrollWidth <= el.clientWidth), true, tab + ' empty state fits ' + width);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Empty analytics fits ' + width);
                    if (process.env.FORECAST_SCREENSHOTS && theme === 'light' && width !== 320) {
                        fs.mkdirSync(process.env.FORECAST_SCREENSHOTS, {recursive: true});
                        await panel.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, `analytics-empty-${tab}-${width}.png`)});
                    }
                }
            }
        }
        for (const label of ['Historical model checks · 7, 14 & 28 days', 'Recent prediction logs']) {
            const details = page.locator('details').filter({has: page.getByText(label, {exact: true})});
            await details.locator('summary').click();
            assert.equal(await details.locator('.analytics-empty-state').isVisible(), true, label + ' has an empty state');
        }
        await page.evaluate(() => document.documentElement.dataset.theme = 'light');
        await page.setViewportSize({width: 1440, height: 900});

        await page.goto(origin + '/components/report/forecast_exceptions.php');
        const exceptionTable = page.getByRole('region', {name: 'Forecast exceptions queue'});
        assert.equal(await exceptionTable.locator('thead th').count(), 5, 'Exceptions use five focused columns');
        const exceptionHelp = exceptionTable.locator('.info-tooltip-trigger').first();
        assert.ok((await exceptionTable.locator('.readiness-status-line > span').first().innerText()).length < (await exceptionTable.locator('.tooltip-box').first().innerText()).length, 'Rows show a short reason while retaining the full explanation');
        await exceptionHelp.focus();
        assert.equal(await exceptionTable.locator('.tooltip-box').first().isVisible(), true);
        assert.match(await exceptionTable.locator('.tooltip-box').first().innerText(), /Next step:/);
        await exceptionHelp.press('Escape');
        const reasonValue = await page.locator('#filter-reason option').nth(1).getAttribute('value');
        await page.locator('#filter-reason').selectOption(reasonValue);
        await page.getByRole('button', {name: 'Filter', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('reason') === reasonValue);
        await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
        assert.equal(await page.locator('#filter-reason').inputValue(), reasonValue, 'Reason filtering retains the exact backend value');
        await page.locator('#filter-search').fill('NoExceptionProductMatches');
        await page.getByRole('button', {name: 'Filter', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('search') === 'NoExceptionProductMatches');
        await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
        assert.match(await exceptionTable.innerText(), /No matches/);
        assert.equal(await exceptionTable.locator('tbody td').getAttribute('colspan'), '5');
        await page.getByRole('link', {name: 'Clear', exact: true}).click();
        await page.waitForURL(url => !url.search);
        await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
        if (process.env.FORECAST_SCREENSHOTS) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Populated exceptions fit ' + width);
                await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'exceptions-populated-' + width + '.png'), fullPage: true});
            }
        }

        await page.setViewportSize({width: 1440, height: 900});
        await page.mouse.move(0, 0);
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
        const checks = page.locator('.forecast-actions');
        if (await checks.getAttribute('open') === null) await s2Heading.click();
        assert.equal(await checks.locator('.action-issue-card .status-badge').count(), 0, 'Checks use readable rows instead of sentence badges');
        assert.ok((await checks.locator('.forecast-check-copy strong').allTextContents()).every(title => title.length < 65), 'Check titles stay concise');
        const issueHelp = checks.locator('.info-tooltip-trigger').first();
        assert.equal(await checks.locator('.forecast-check-title .info-tooltip-trigger').count(), await checks.locator('.action-issue-card').count(), 'Each issue keeps its help beside its title');
        assert.ok((await issueHelp.boundingBox()).width >= 44, 'Inline issue help retains its touch target');
        await issueHelp.focus();
        assert.equal(await checks.locator('.tooltip-box').first().isVisible(), true, 'Full check details remain keyboard accessible');
        await issueHelp.press('Escape');
        assert.equal(await checks.locator('.tooltip-box').first().isVisible(), false, 'Check details dismiss with Escape');
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
        await page.waitForURL(url => url.searchParams.get('search') === 'NonexistentProductQuery12345');
        await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
        assert.match(await page.locator('.empty-state').innerText(), /No matching products found|No Forecasts Available/);

        // Clear search
        await page.locator('a:has-text("Clear")').click();
        await page.waitForURL(url => !url.searchParams.has('search'));
        await page.waitForFunction(() => document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');

        // Check responsive layout across viewports and themes
        await checks.evaluate(el => el.open = true);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
            for (const width of [1440, 640, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.waitForTimeout(50);
                assert.equal(await page.locator('h1').isVisible(), true, `Heading visible on width ${width}px theme ${theme}`);
                assert.equal(await s1Heading.isVisible(), true, `Section 1 visible on width ${width}px theme ${theme}`);
                assert.equal(await s2Heading.isVisible(), true, `Section 2 visible on width ${width}px theme ${theme}`);
                assert.equal(await page.locator('main').evaluate(el => document.documentElement.scrollWidth <= window.innerWidth), true, `No page overflow at ${width}px in ${theme}`);
                await issueHelp.evaluate(el => el.blur());
                await issueHelp.focus();
                const tooltipBounds = await checks.locator('.tooltip-box').first().boundingBox();
                assert.ok(tooltipBounds.x >= 0 && tooltipBounds.x + tooltipBounds.width <= width, 'Issue help fits ' + width + 'px');
                await issueHelp.press('Escape');
                if (process.env.FORECAST_SCREENSHOTS && [1440, 390].includes(width)) {
                    await checks.evaluate(el => el.open = true);
                    await checks.locator('.forecast-check-copy strong').first().waitFor({state: 'visible'});
                    await checks.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, `forecast-checks-${theme}-${width}.png`)});
                }
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
        await page.addInitScript(() => {
            const start = document.startViewTransition.bind(document);
            document.startViewTransition = callback => {
                const transition = start(callback);
                window.forecastTransition = {started: true};
                transition.ready.then(() => {
                    window.forecastTransition.duration = getComputedStyle(document.documentElement, '::view-transition-group(forecast-active-tab)').animationDuration;
                    window.forecastTransition.text = {
                        group: getComputedStyle(document.documentElement, '::view-transition-group(workspace-content)').animationName,
                        old: getComputedStyle(document.documentElement, '::view-transition-old(workspace-content)').display,
                        incoming: getComputedStyle(document.documentElement, '::view-transition-new(workspace-content)').animationName,
                        oldTab: getComputedStyle(document.documentElement, '::view-transition-old(forecast-active-tab)').display,
                    };
                }).catch(() => { window.forecastTransition.failed = true; });
                window.forecastTransitionFinished = transition.finished;
                return transition;
            };
        });
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            await page.emulateMedia({reducedMotion: 'no-preference'});
            await page.goto(origin + '/components/system_administrator/forecast_import.php');
            await page.evaluate(() => { window.forecastDocument = document; });
            await page.route('**/data_readiness.php', async route => {
                await new Promise(resolve => setTimeout(resolve, 250));
                await route.continue();
            });
            for (const file of ['data_readiness.php', 'ml_settings.php', 'forecast_analytics.php', 'predictions.php', 'forecast_exceptions.php', 'forecast_import.php']) {
                await page.evaluate(() => { window.forecastTransition = null; });
                const navTop = await page.locator('.forecast-studio-nav').evaluate(el => el.getBoundingClientRect().top);
                await page.locator('.forecast-studio-nav a[href$="/' + file + '"]').click();
                if (file === 'data_readiness.php') {
                    assert.equal(await page.locator('[data-navigation-status][role="status"]').evaluate(el => getComputedStyle(el).position), 'fixed', 'Loading feedback does not shift the page');
                    assert.equal(await page.locator('.forecast-studio-nav').evaluate(el => el.getBoundingClientRect().top), navTop, 'Forecast navigation stays in place while loading');
                    assert.equal(await page.locator('#rm-nav-progress').isVisible(), true, 'Pending forecast switching shows the blue loading line');
                }
                await page.waitForURL('**/' + file);
                await page.waitForFunction(() => window.forecastTransition);
                assert.equal(await page.evaluate(() => window.forecastTransition.started), true, 'Page transition starts for ' + file);
                await page.waitForFunction(() => window.forecastTransition?.duration || window.forecastTransition?.failed);
                assert.equal(await page.evaluate(() => window.forecastTransition.duration), '0.24s', 'Navigation animates the active forecast tab at ' + width);
                assert.deepEqual(await page.evaluate(() => window.forecastTransition.text), {group: 'none', old: 'none', incoming: 'workspace-text-enter', oldTab: 'none'}, 'Text fades in without overlapping old text or stretching its layout');
                if (process.env.FORECAST_SCREENSHOTS && file === 'data_readiness.php') {
                    await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'forecast-transition-' + width + '.png')});
                }
                await page.evaluate(() => window.forecastTransitionFinished);
                assert.equal(await page.evaluate(() => document === window.forecastDocument), true, 'Forecast navigation preserves the document');
                const icons = await page.locator('.forecast-studio-nav i').evaluateAll(elements => elements.map(el => ({content: getComputedStyle(el, '::before').content, font: getComputedStyle(el, '::before').fontFamily})));
                assert.ok(icons.every(icon => icon.content !== 'none' && icon.content !== 'normal' && icon.font.includes('bootstrap-icons')), 'Forecast icons remain drawn after ' + file + ': ' + JSON.stringify(icons));
            }
            await page.unroute('**/data_readiness.php');
            await page.emulateMedia({reducedMotion: 'reduce'});
            await page.evaluate(() => { window.forecastTransition = null; });
            await page.locator('.forecast-studio-nav a[href$="/data_readiness.php"]').click();
            await page.waitForURL('**/data_readiness.php');
            assert.equal(await page.locator('.forecast-studio-nav').evaluate(el => getComputedStyle(el).viewTransitionName), 'none', 'Reduced motion keeps navigation still');
            assert.equal(await page.evaluate(() => window.forecastTransition), null, 'Reduced motion opts out of page transitions');
        }
        await page.evaluate(() => { window.savedForecastTransition = document.startViewTransition; document.startViewTransition = undefined; });
        await page.locator('.forecast-studio-nav a[href$="/ml_settings.php"]').click();
        await page.waitForURL('**/ml_settings.php');
        await page.locator('#schedule_weekly_retrain_day').waitFor();
        assert.equal(await page.locator('.main-content [data-navigation-status][role="alert"]').count(), 0, 'Navigation still works without View Transitions');
        await page.evaluate(() => { document.startViewTransition = window.savedForecastTransition; });
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
                const details = row.locator('[data-readiness-details]');
                await details.focus();
                await page.keyboard.press('Enter');
                const drawer = page.getByRole('dialog', {name: 'Product data checks'});
                await drawer.waitFor({state: 'visible'});
                assert.match(await drawer.innerText(), /Recent 28 days/);
                assert.match(await drawer.innerText(), /Three test periods/);
                assert.equal(await drawer.locator('h3').first().innerText(), await row.locator('strong').first().innerText());
                const bounds = await drawer.boundingBox();
                const overlayBounds = await page.locator('#readiness-drawer').boundingBox();
                assert.ok(Math.abs(bounds.x + bounds.width - overlayBounds.x - overlayBounds.width) < 2, 'Details drawer aligns with the right edge of its overlay');
                assert.ok(await row.evaluate(el => el.getBoundingClientRect().height) <= 80, 'Opening details does not expand the table row');
                await drawer.getByRole('button', {name: 'Close data checks'}).focus();
                await page.keyboard.press('Tab');
                assert.equal(await drawer.getByRole('button', {name: 'Close data checks'}).evaluate(el => el === document.activeElement), true, 'Keyboard focus stays in the drawer');
                if (process.env.FORECAST_SCREENSHOTS) await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'readiness-drawer-desktop.png'), animations: 'disabled'});
                await page.keyboard.press('Escape');
                await drawer.waitFor({state: 'hidden'});
                assert.equal(await details.evaluate(el => el === document.activeElement), true, 'Closing returns focus to Details');
                const productRows = page.locator('.readiness-catalog tbody tr');
                const secondRow = await productRows.count() > 1 ? productRows.nth(1) : row;
                await page.locator('#readiness-drawer-body').evaluate(el => el.textContent = 'Stale evidence');
                await secondRow.locator('[data-readiness-details]').click();
                assert.equal(await drawer.locator('h3').first().innerText(), await secondRow.locator('strong').first().innerText(), 'Opening Details replaces stale evidence with the selected product');
                await page.setViewportSize({width: 390, height: 844});
                const mobileBounds = await drawer.boundingBox();
                assert.ok(mobileBounds.x >= 0 && mobileBounds.x + mobileBounds.width <= await page.evaluate(() => document.documentElement.clientWidth) + 1, 'Drawer fits mobile width');
                assert.equal(await drawer.evaluate(el => el.scrollWidth <= el.clientWidth), true, 'Drawer content has no horizontal overflow');
                if (process.env.FORECAST_SCREENSHOTS) await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, 'readiness-drawer-mobile.png'), animations: 'disabled'});
                await drawer.getByRole('button', {name: 'Close data checks'}).click();
                await drawer.waitFor({state: 'hidden'});
                await page.setViewportSize({width: 1440, height: 900});
                await details.click();
                await page.locator('#readiness-drawer').click({position: {x: 10, y: 10}});
                await drawer.waitFor({state: 'hidden'});
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
                    if (route.includes('ml_settings') && width === 1440) {
                        const fields = await page.locator('.ml-schedule-grid select').evaluateAll(elements => elements.map(el => el.getBoundingClientRect()));
                        assert.ok(Math.abs(fields[0].top - fields[1].top) < 1, 'Weekday and hour fields align despite the help button');
                        assert.ok(Math.abs(fields[2].top - fields[3].top) < 1, 'Minute and period fields align');
                    }
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
        for (const [role, destination] of [
            ['admin', 'administrator/gcash_overview.php'],
            ['super_admin', 'system_administrator/system_health.php'],
            ['inventory_manager', 'inventory_management/products.php'],
            ['cashier', 'cashier/dashboard.php'],
        ]) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                await page.emulateMedia({reducedMotion: 'no-preference'});
                await login(role);
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; window.workspaceDocument = document; window.forecastTransition = null; }, width === 390 ? 'dark' : 'light');
                await page.locator('#appSidebar a[href$="/' + destination + '"]').evaluate(link => link.click());
                await page.waitForURL('**/components/' + destination);
                await page.waitForFunction(() => window.forecastTransition?.text && document.querySelector('.main-content')?.getAttribute('aria-busy') === 'false');
                await page.evaluate(() => window.forecastTransitionFinished);
                assert.equal(await page.evaluate(() => document === window.workspaceDocument), true, role + ' retains its workspace shell');
                assert.equal(await page.evaluate(() => window.forecastTransition.text.incoming), 'workspace-text-enter', role + ' uses the shared text fade at ' + width);
                await page.locator('#rm-nav-progress').waitFor({state: 'detached'});
                assert.equal(await page.locator('#rm-nav-progress').count(), 0, role + ' clears the loading line after navigation');
                if (process.env.FORECAST_SCREENSHOTS) await page.screenshot({path: path.join(process.env.FORECAST_SCREENSHOTS, role + '-workspace-' + width + '.png')});
            }
        }
        console.log('PASS: Forecast landing browser test passed cleanly!');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})();
