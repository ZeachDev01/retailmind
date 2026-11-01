const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator workflow guidance: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-adminguidance-'));
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
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(15000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        page.on('pageerror', error => console.error(error));

        const screenshots = 'output/playwright/admin-workflow-guidance';
        fs.mkdirSync(screenshots, {recursive: true});

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

        const initialInventory = JSON.parse(fixture('inventory_state'));

        // 1. First-visit Administrator dashboard
        await login('admin');
        await page.waitForURL('**/administrator/dashboard.php');

        const guidanceBanner = page.locator('#workspace-guidance');
        await guidanceBanner.waitFor();

        // Check contextual heading and copy on dashboard
        assert.match(await guidanceBanner.locator('h2').innerText(), /Administrator Workspace/);
        assert.match(await guidanceBanner.innerText(), /Store operations/);
        assert.match(await guidanceBanner.innerText(), /Needs Attention queue/);

        // Check Store setup checklist presence
        const checklist = guidanceBanner.locator('.store-setup-checklist');
        await checklist.waitFor();
        assert.match(await checklist.innerText(), /Store setup checklist/);

        // Check reconciled dashboard setup panel (store-onboarding-section)
        const dashboardSetup = page.locator('.store-onboarding-section');
        await dashboardSetup.waitFor();
        assert.match(await dashboardSetup.locator('h2').innerText(), /Administrator Workflows/);
        const setupText = await dashboardSetup.innerText();
        assert.match(setupText, /Store Calendar/);
        assert.match(setupText, /Staff Management/);
        assert.match(setupText, /Store Reports/);
        assert.match(setupText, /Forecast Exceptions/);
        assert.doesNotMatch(setupText, /Fiscal Period/);
        assert.match(setupText, /genuine sales over time/);
        assert.match(setupText, /Stock receiving does not establish sales history/i);

        // Verify quick-action links are permitted for Administrator
        const calendarLink = dashboardSetup.getByRole('link', {name: /Store Calendar/});
        assert.ok((await calendarLink.getAttribute('href')).includes('administrator/store_calendar.php'));
        const staffLink = dashboardSetup.getByRole('link', {name: /Staff Management/});
        assert.ok((await staffLink.getAttribute('href')).includes('user_manager/user_manager.php'));
        const reportsLink = dashboardSetup.getByRole('link', {name: /Store Reports/});
        assert.ok((await reportsLink.getAttribute('href')).includes('report/report_generation.php'));
        const forecastLink = dashboardSetup.getByRole('link', {name: /Forecast Exceptions/});
        assert.ok((await forecastLink.getAttribute('href')).includes('report/forecast_exceptions.php'));

        // Visual screenshot of dashboard guidance
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `dashboard-${theme}-${width}.png`)});
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // 2. Contextual guidance in individual Administrator workflows before skipping
        // 2a. Store Calendar (Store setup)
        await page.goto(origin + '/components/administrator/store_calendar.php');
        await page.waitForLoadState('domcontentloaded');
        const calGuidance = page.locator('#workspace-guidance');
        await calGuidance.waitFor();
        assert.match(await calGuidance.locator('h2').innerText(), /Store setup & calendar/);
        const calText = await calGuidance.innerText();
        assert.match(calText, /trading days and holiday closures/i);
        assert.match(calText, /separate from customer sales history|separate from genuine customer sales history/i);
        assert.match(calText, /Stock receiving does not establish customer demand history/i);
        assert.match(calText, /technical workflow/i);
        // Calendar setup confirmation button is active for Admin
        assert.equal(await calGuidance.getByRole('button', {name: 'Confirm calendar review'}).count(), 1);

        // 2b. Staff Management
        await page.goto(origin + '/components/user_manager/user_manager.php');
        await page.waitForLoadState('domcontentloaded');
        const userGuidance = page.locator('#workspace-guidance');
        await userGuidance.waitFor();
        assert.match(await userGuidance.locator('h2').innerText(), /Staff management/);
        assert.match(await userGuidance.innerText(), /operational staff accounts/i);
        assert.match(await userGuidance.innerText(), /active Workspace permissions/i);

        // 2c. Store Reports
        await page.goto(origin + '/components/report/report_generation.php');
        await page.waitForLoadState('domcontentloaded');
        const reportGuidance = page.locator('#workspace-guidance');
        await reportGuidance.waitFor();
        assert.match(await reportGuidance.locator('h2').innerText(), /Store reports/);
        assert.match(await reportGuidance.innerText(), /sales, gross profit/i);
        assert.match(await reportGuidance.innerText(), /CSV summaries/i);

        // 2d. Forecasting Oversight
        await page.goto(origin + '/components/report/forecast_exceptions.php');
        await page.waitForLoadState('domcontentloaded');
        const forecastGuidance = page.locator('#workspace-guidance');
        await forecastGuidance.waitFor();
        assert.match(await forecastGuidance.locator('h2').innerText(), /Forecasting oversight/);
        const fText = await forecastGuidance.innerText();
        assert.match(fText, /genuine customer sales history/i);
        assert.match(fText, /replenishment recommendations/i);
        assert.match(fText, /Nonfunctional approval actions are not advertised|does not advertise a nonfunctional approval action/i);
        // Check that page does not advertise a nonfunctional approval button
        assert.equal(await page.locator('button:has-text("Approve Model"), a:has-text("Approve Model")').count(), 0);

        // 3. Skip guidance and verify persisted preferences
        const skipBtn = forecastGuidance.getByRole('button', {name: 'Skip guidance', exact: true});
        await skipBtn.focus();
        assert.equal(await skipBtn.evaluate(el => document.activeElement === el), true);
        assert.notEqual(await skipBtn.evaluate(el => getComputedStyle(el).outlineStyle), 'none');

        await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/auth/help.php')),
            skipBtn.click(),
        ]);
        await page.waitForLoadState('domcontentloaded');

        // Verify guidance state in DB
        const guidanceRows = JSON.parse(fixture('guidance_state'));
        assert.equal(guidanceRows.some(r => r.user_id === 1 && r.workspace === 'admin' && r.status === 'skipped'), true);

        // Verify setup confirmations were NOT completed by skipping
        const setupRows = JSON.parse(fixture('setup_state'));
        assert.equal(setupRows.length, 0, 'Skipping guidance does not complete store setup confirmations');

        // Subsequent visits must show NO automatic guidance banner and NO dashboard onboarding panel
        await page.goto(origin + '/components/administrator/dashboard.php');
        await page.waitForLoadState('domcontentloaded');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No automatic guidance banner after skip');
        assert.equal(await page.locator('.store-onboarding-section').count(), 0, 'No redundant store-onboarding-section after skip');

        await page.goto(origin + '/components/administrator/store_calendar.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No guidance banner on calendar after skip');

        await page.goto(origin + '/components/user_manager/user_manager.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No guidance banner on staff after skip');

        await page.goto(origin + '/components/report/forecast_exceptions.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No guidance banner on forecast after skip');

        // Re-login: still quiet
        await login('admin');
        await page.waitForURL('**/administrator/dashboard.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No guidance prompt on re-login');
        assert.equal(await page.locator('.store-onboarding-section').count(), 0, 'No onboarding panel on re-login');

        // 4. Reopen through Help
        await page.goto(origin + '/components/auth/help.php');
        await page.waitForLoadState('domcontentloaded');

        // Verify Help renders all 5 Administrator workflows with links and detailed explanations
        const helpMain = page.locator('.main-content');
        const helpText = await helpMain.innerText();
        assert.match(helpText, /Store setup & calendar/);
        assert.match(helpText, /Staff management/);
        assert.match(helpText, /Store reports/);
        assert.match(helpText, /Forecasting oversight/);
        assert.match(helpText, /Store operations & exceptions/);

        // Check explanations in Help
        assert.match(helpText, /separate from customer sales history|separate from genuine customer sales history/i);
        assert.match(helpText, /Stock receiving does not establish customer demand history/i);
        assert.match(helpText, /technical workflow/i);
        assert.match(helpText, /Nonfunctional approval actions are not advertised|does not advertise a nonfunctional approval action/i);

        // Check links in Help match active permissions
        assert.equal(await helpMain.getByRole('link', {name: 'Review the Store calendar'}).count(), 1);
        assert.equal(await helpMain.getByRole('link', {name: 'Manage staff'}).count(), 1);
        assert.equal(await helpMain.getByRole('link', {name: 'Review Store Reports'}).count(), 1);
        assert.equal(await helpMain.getByRole('link', {name: 'Review forecast exceptions'}).count(), 1);
        assert.equal(await helpMain.getByRole('link', {name: 'Review Store operations'}).count(), 1);

        // Screenshot of Help page
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `help-${theme}-${width}.png`)});
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // Leaving Help does NOT restart automatic guidance
        await page.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Leaving Help does not re-enable automatic guidance');
        assert.equal(await page.locator('.store-onboarding-section').count(), 0, 'Leaving Help does not re-enable onboarding section');

        // 5. Verify guidance creates NO operational changes
        const currentInventory = JSON.parse(fixture('inventory_state'));
        assert.deepEqual(currentInventory, initialInventory, 'Guidance created no operational inventory changes');

        console.log('Administrator workflow guidance browser: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
