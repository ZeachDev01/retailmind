const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Store setup confirmations: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-setup-'));
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
        page.setDefaultTimeout(8000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        page.on('pageerror', error => console.error(error));

        const screenshots = 'output/playwright/store-setup-confirmations';
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

        const logout = async () => {
            await page.goto(origin + '/components/auth/logout.php');
        };

        const switchWorkspace = async label => {
            if (await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden') !== 'false') {
                await page.locator('.admin-mobile-avatar:visible, #sidebarProfile:visible').click();
            }
            await page.locator('.sidebar-workspace-switcher > summary').click();
            await page.locator('.sidebar-workspace-options').getByRole('button', {name: label, exact: true}).click();
            await page.waitForLoadState('domcontentloaded');
        };

        const openHelp = async () => {
            if (await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden') !== 'false') {
                await page.locator('.admin-mobile-avatar:visible, #sidebarProfile:visible').click();
            }
            const help = page.locator('#sidebarAccountMenu').getByRole('link', {name: 'Help', exact: true});
            await help.click();
            await page.waitForURL('**/auth/help.php');
            await page.getByRole('heading', {name: 'Workspace Help', exact: true}).waitFor();
        };

        // 1. Initial State & Role-specific visibility (Administrator)
        await login('admin');
        const overview = page.locator('#workspace-guidance');
        await overview.waitFor();
        const checklist = overview.locator('.store-setup-checklist');
        await checklist.waitFor();

        // Check progress display
        assert.match(await checklist.innerText(), /0 of 2 tasks confirmed/);

        // Administrator sees:
        // - Products task: owned by Inventory Manager, link to Set up Products, awaiting Inventory Manager (no confirm button)
        // - Calendar task: owned by Administrator, link to Review Store calendar, button "Confirm calendar review"
        const productTask = checklist.locator('.setup-task-item', {hasText: 'Products and opening stock'});
        assert.match(await productTask.innerText(), /Inventory Manager/);
        assert.equal(await productTask.getByRole('link', {name: 'Set up Products', exact: true}).count(), 1);
        assert.equal(await productTask.getByRole('button', {name: 'Confirm product setup'}).count(), 0, 'Admin cannot confirm product setup');
        assert.match(await productTask.innerText(), /Awaiting Inventory Manager/);

        const calendarTask = checklist.locator('.setup-task-item', {hasText: 'Store calendar review'});
        assert.match(await calendarTask.innerText(), /Administrator/);
        assert.equal(await calendarTask.getByRole('link', {name: 'Review Store calendar', exact: true}).count(), 1);
        assert.equal(await calendarTask.getByRole('button', {name: 'Confirm calendar review', exact: true}).count(), 1);

        // 2. Responsive layout & Themes inspection
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                const confirmBtn = calendarTask.getByRole('button', {name: 'Confirm calendar review', exact: true});
                await confirmBtn.focus();
                assert.equal(await confirmBtn.evaluate(el => document.activeElement === el), true);
                assert.notEqual(await confirmBtn.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow at ' + width + 'px');
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `admin-checklist-${theme}-${width}.png`)});
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // 3. Prove first records do NOT auto-complete tasks
        // Existing product from seed does NOT complete tasks
        let setupRows = JSON.parse(fixture('setup_state'));
        assert.equal(setupRows.length, 0, 'Existing seeded Product does NOT auto-complete tasks');

        // 3a. Add a store calendar entry
        await calendarTask.getByRole('link', {name: 'Review Store calendar', exact: true}).click();
        await page.waitForURL('**/administrator/store_calendar.php');
        const dayCell = page.locator('.calendar-day-cell').first();
        await dayCell.waitFor();
        await dayCell.click();
        await page.locator('#calendar-editor').waitFor();
        await page.locator('#status').selectOption('holiday');
        await page.locator('#save-status-btn').click();
        await page.waitForLoadState('domcontentloaded');

        // Check DB: store_setup_confirmations is still empty!
        setupRows = JSON.parse(fixture('setup_state'));
        assert.equal(setupRows.length, 0, 'First calendar entry does NOT auto-complete calendar setup task');

        // 3b. Add a product in Add Product
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.waitForLoadState('domcontentloaded');
        // Existing products present, check DB again
        setupRows = JSON.parse(fixture('setup_state'));
        assert.equal(setupRows.length, 0, 'Visiting products does NOT auto-complete product setup task');

        // 4. Server-side security & CSRF enforcement
        await page.goto(origin + '/components/administrator/dashboard.php');
        await overview.waitFor();
        const csrfToken = await page.locator('.setup-task-confirm-form input[name="csrf_token"]').inputValue();

        // 4a. Incorrect workspace: Admin attempts to confirm 'products'
        const forbiddenProductConfirm = await page.request.post(origin + '/components/auth/help.php', {
            form: {csrf_token: csrfToken, confirm_task: 'products', return_to: '/components/administrator/dashboard.php'},
            maxRedirects: 0,
        });
        assert.equal(forbiddenProductConfirm.status(), 403, 'Admin cannot confirm product setup (server-side authority check)');
        assert.equal(JSON.parse(fixture('setup_state')).length, 0, 'State unchanged on 403');

        // 4b. Invalid task key
        const invalidTaskConfirm = await page.request.post(origin + '/components/auth/help.php', {
            form: {csrf_token: csrfToken, confirm_task: 'non_existent_task'},
            maxRedirects: 0,
        });
        assert.equal(invalidTaskConfirm.status(), 422, 'Unknown task key rejected with 422');
        assert.equal(JSON.parse(fixture('setup_state')).length, 0, 'State unchanged on 422');

        // 4c. Missing CSRF
        const missingCsrfConfirm = await page.request.post(origin + '/components/auth/help.php', {
            form: {confirm_task: 'store_calendar'},
            maxRedirects: 0,
        });
        assert.equal(missingCsrfConfirm.status(), 403, 'Missing CSRF rejected with 403');
        assert.equal(JSON.parse(fixture('setup_state')).length, 0, 'State unchanged without CSRF');

        // 5. Multi-role account authority check
        await logout();
        await login('super_admin'); // User 2 has admin, inventory_manager, cashier
        await switchWorkspace('Administrator');
        await page.waitForURL('**/administrator/dashboard.php');
        const adminCsrf = await page.locator('.setup-task-confirm-form input[name="csrf_token"]').inputValue();
        // In Administrator workspace, attempting products fails with 403
        const multiRoleAdminAttempt = await page.request.post(origin + '/components/auth/help.php', {
            form: {csrf_token: adminCsrf, confirm_task: 'products'},
            maxRedirects: 0,
        });
        assert.equal(multiRoleAdminAttempt.status(), 403, 'Multi-role user in Administrator workspace cannot confirm products');

        // Switch to Inventory Workspace
        await switchWorkspace('Inventory');
        await page.waitForURL('**/inventory_management/inventory_overview.php');
        const invOverview = page.locator('#workspace-guidance');
        await invOverview.waitFor();
        const invChecklist = invOverview.locator('.store-setup-checklist');
        await invChecklist.waitFor();

        // 6. Role-specific view in Inventory Workspace
        // Calendar review belongs only to the Administrator checklist.
        const invCalendarTask = invChecklist.locator('.setup-task-item', {hasText: 'Store calendar review'});
        assert.equal(await invCalendarTask.count(), 0, 'Inventory Manager does not see calendar review');

        // In Inventory workspace, attempting calendar fails with 403
        const invCsrf = await page.locator('.setup-task-confirm-form input[name="csrf_token"]').inputValue();
        const multiRoleInvAttempt = await page.request.post(origin + '/components/auth/help.php', {
            form: {csrf_token: invCsrf, confirm_task: 'store_calendar'},
            maxRedirects: 0,
        });
        assert.equal(multiRoleInvAttempt.status(), 403, 'Multi-role user in Inventory workspace cannot confirm store calendar');

        // 7. Explicit confirmation of Product Setup by Inventory Manager
        const invProductTask = invChecklist.locator('.setup-task-item', {hasText: 'Products and opening stock'});
        assert.equal(await invProductTask.getByRole('button', {name: 'Confirm product setup', exact: true}).count(), 1);

        await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/auth/help.php')),
            invProductTask.getByRole('button', {name: 'Confirm product setup', exact: true}).click(),
        ]);
        await page.waitForLoadState('domcontentloaded');

        // Observe updated progress in Inventory Workspace
        const updatedInvTask = page.locator('#workspace-guidance .setup-task-item', {hasText: 'Products and opening stock'});
        await updatedInvTask.locator('.setup-task-status:has-text("Confirmed")').waitFor();
        assert.match(await page.locator('#workspace-guidance .store-setup-checklist').innerText(), /1 of 1 tasks confirmed/);
        assert.match(await updatedInvTask.innerText(), /Confirmed/);
        assert.equal(await page.locator('#workspace-guidance .setup-task-item', {hasText: 'Store calendar review'}).count(), 0);

        // Verify DB persistence
        setupRows = JSON.parse(fixture('setup_state'));
        assert.deepEqual(setupRows, [
            {task_key: 'products', confirmed_by_user_id: 2, confirmed_workspace: 'inventory_manager'}
        ], 'Product confirmation persisted in store_setup_confirmations');

        // 8. Observe shared progress in Administrator Workspace & across navigation / login
        await switchWorkspace('Administrator');
        await page.waitForURL('**/administrator/dashboard.php');
        await overview.waitFor();
        assert.match(await checklist.innerText(), /1 of 2 tasks confirmed/, 'Administrator observes shared progress');
        assert.match(await checklist.locator('.setup-task-item', {hasText: 'Products and opening stock'}).innerText(), /Confirmed/);
        const adminCalTask = checklist.locator('.setup-task-item', {hasText: 'Store calendar review'});
        assert.equal(await adminCalTask.getByRole('button', {name: 'Confirm calendar review', exact: true}).count(), 1);

        // Navigate away and return
        await page.goto(origin + '/components/administrator/shift_report.php');
        await page.goto(origin + '/components/administrator/dashboard.php');
        await overview.waitFor();
        assert.match(await checklist.innerText(), /1 of 2 tasks confirmed/, 'Progress preserved across navigation');

        // Logout and login again
        await logout();
        await login('admin'); // User 1
        await overview.waitFor();
        assert.match(await checklist.innerText(), /1 of 2 tasks confirmed/, 'Progress preserved for separate user on login');

        // 9. Confirm second task -> All tasks finished!
        const admin1CalendarTask = page.locator('#workspace-guidance .setup-task-item', {hasText: 'Store calendar review'});
        await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/auth/help.php')),
            admin1CalendarTask.getByRole('button', {name: 'Confirm calendar review', exact: true}).click(),
        ]);
        await page.waitForLoadState('domcontentloaded');

        // 10. Verify completion message and automatic reminder stoppage
        const completionNotice = page.locator('#store-setup-completion');
        await completionNotice.waitFor();
        assert.match(await completionNotice.innerText(), /Store setup complete/);
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Automatic guidance prompt stops when setup is complete');

        // Refresh: completion notice goes away, automatic reminder stays quiet
        await page.reload();
        assert.equal(await page.locator('#store-setup-completion').count(), 0, 'Completion notice appears once');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Automatic reminder quiet on refresh');

        // Navigation: stays quiet
        await page.goto(origin + '/components/administrator/shift_report.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0);

        // Later login: stays quiet
        await logout();
        await login('admin');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Admin later login stays quiet');

        await logout();
        await login('inventory_manager');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Inventory manager later login stays quiet');

        // 11. Help remains available and reopening Help does not alter state
        await openHelp();
        const helpChecklist = page.locator('.store-setup-checklist');
        await helpChecklist.waitFor();
        assert.match(await helpChecklist.innerText(), /2 of 2 tasks confirmed/);
        assert.match(await helpChecklist.innerText(), /Setup complete/);
        assert.equal(await helpChecklist.locator('.setup-task-status:has-text("Confirmed")').count(), 2);

        // Reopening/reloading Help does not change completion state
        await page.reload();
        assert.deepEqual(JSON.parse(fixture('setup_state')).map(r => r.task_key), ['products', 'store_calendar']);

        // Verify ML / forecasting remains completely untouched
        const invState = JSON.parse(fixture('inventory_state'));
        assert.equal(invState.forecast_runs.length, 0);
        assert.equal(invState.stock_predictions.length, 0);

        console.log('Store setup confirmations browser: passed');
    } finally {
        if (browser) await browser.close();
        if (server) {
            server.kill();
            await new Promise(resolve => server.once('exit', resolve));
        }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
