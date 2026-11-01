const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Workspace onboarding: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-workspace-'));
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
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        page.on('pageerror', error => console.error(error));
        const screenshots = 'output/playwright/workspace-onboarding';
        fs.mkdirSync(screenshots, {recursive: true});
        const login = async role => {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await page.getByRole('button', {name: 'Log in', exact: true}).click();
            await page.waitForURL('**/components/**/dashboard.php');
        };
        const overview = page.locator('#workspace-guidance');
        const choose = async label => {
            await Promise.all([
                page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/auth/help.php')),
                page.getByRole('button', {name: label, exact: true}).click(),
            ]);
            await page.waitForLoadState('domcontentloaded');
            await page.reload();
            assert.equal(await overview.count(), 0, 'Saved choice stops automatic guidance');
        };
        const openHelp = async () => {
            if (await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden') !== 'false') {
                await page.locator('.admin-mobile-avatar:visible, #sidebarProfile:visible').click();
            }
            const help = page.locator('#sidebarAccountMenu').getByRole('link', {name: 'Help', exact: true});
            await help.focus();
            assert.equal(await help.evaluate(el => document.activeElement === el), true);
            await help.press('Enter');
            await page.waitForURL('**/auth/help.php');
            await page.getByRole('heading', {name: 'Workspace Help', exact: true}).waitFor();
            await page.waitForFunction(() => ['19.2px', '12px'].includes(getComputedStyle(document.querySelector('.workspace-guidance')).paddingTop));
            await page.keyboard.press('Escape');
            assert.equal(await overview.count(), 0, 'Help does not duplicate the automatic prompt');
        };
        const switchWorkspace = async label => {
            if (await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden') !== 'false') {
                await page.locator('.admin-mobile-avatar:visible, #sidebarProfile:visible').click();
            }
            await page.locator('.sidebar-workspace-switcher > summary').click();
            await page.locator('.sidebar-workspace-options').getByRole('button', {name: label, exact: true}).click();
            await page.waitForLoadState('domcontentloaded');
        };
        const beforeInventory = JSON.parse(fixture('inventory_state'));
        const beforeGovernance = JSON.parse(fixture('governance_state'));
        await login('admin');
        await overview.waitFor();
        for (const toast of await page.getByRole('button', {name: 'Dismiss notification'}).all()) await toast.click();
        assert.match(await overview.innerText(), /Administrator Workspace/);
        // Render all supported themes and narrow widths with reachable controls.
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                const skip = overview.getByRole('button', {name: 'Skip guidance', exact: true});
                await skip.focus();
                assert.equal(await skip.evaluate(el => document.activeElement === el), true);
                assert.notEqual(await skip.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
                const box = await skip.boundingBox();
                assert.ok(box.x >= 0 && box.x + box.width <= width, 'Skip fits viewport');
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow');
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `admin-${theme}-${width}.png`)});
            }
        }
        await overview.getByRole('button', {name: 'Finish overview', exact: true}).focus();
        await page.keyboard.press('Tab');
        assert.equal(await overview.getByRole('button', {name: 'Skip guidance', exact: true}).evaluate(el => document.activeElement === el), true, 'Tab reaches Skip');
        await page.keyboard.press('Tab');
        assert.equal(await overview.getByRole('button', {name: 'Not now', exact: true}).evaluate(el => document.activeElement === el), true, 'Tab reaches defer');
        await choose('Skip guidance');
        await openHelp();
        assert.match(await page.locator('.workspace-guidance').innerText(), /Administrator Workspace/);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Help fits narrow viewport');
        await page.screenshot({animations: 'disabled', path: path.join(screenshots, 'help-320.png'), fullPage: true});
        const saved = JSON.parse(fixture('guidance_state'));
        await page.reload();
        assert.deepEqual(JSON.parse(fixture('guidance_state')), saved, 'Reading Help never changes guidance state');
        await page.getByRole('link', {name: 'Review Store operations', exact: true}).click();
        await page.waitForURL('**/administrator/dashboard.php');
        assert.equal(await overview.count(), 0, 'Navigation respects skip');
        await page.goto(origin + '/components/auth/logout.php');
        await login('admin');
        assert.equal(await overview.count(), 0, 'Later login respects skip');
        await page.goto(origin + '/components/auth/logout.php');
        await page.setViewportSize({width: 1440, height: 900});
        await login('super_admin');
        assert.equal(await overview.count(), 0, 'Technical Workspace gets no operational guidance');
        await switchWorkspace('Administrator');
        await page.waitForURL('**/administrator/dashboard.php');
        await overview.waitFor();
        await choose('Finish overview');
        await openHelp();
        await choose('Skip guidance');
        assert.equal(JSON.parse(fixture('guidance_state')).find(row => row.user_id === 2 && row.workspace === 'admin').status, 'completed', 'Help cannot reset completion');
        await switchWorkspace('Inventory');
        await page.waitForURL('**/inventory_management/inventory_overview.php');
        await overview.waitFor();
        assert.match(await overview.innerText(), /Inventory Manager Workspace/);
        assert.doesNotMatch(await overview.innerText(), /Manage staff/);
        await page.setViewportSize({width: 320, height: 900});
        await page.screenshot({animations: 'disabled', path: path.join(screenshots, 'inventory-320.png')});
        await choose('Not now');
        await openHelp();
        assert.equal(await page.getByRole('link', {name: 'Review the Store calendar', exact: true}).count(), 0);
        await page.getByRole('link', {name: 'Review replenishment needs', exact: true}).click();
        await page.waitForURL('**/inventory_management/replenishment_queue.php');
        assert.equal(await overview.count(), 0, 'Deferred guidance stays quiet across navigation');
        await page.reload();
        assert.equal(await overview.count(), 0);
        await page.goto(origin + '/components/auth/logout.php');
        await page.setViewportSize({width: 1440, height: 900});
        await login('super_admin');
        await switchWorkspace('Inventory');
        await page.waitForURL('**/inventory_management/inventory_overview.php');
        assert.equal(await overview.count(), 0, 'Later login respects deferral');
        await switchWorkspace('Administrator');
        await page.waitForURL('**/administrator/dashboard.php');
        assert.equal(await overview.count(), 0, 'Workspace switching preserves completion');
        await openHelp();
        const csrf = await page.locator('.workspace-guidance-actions input[name="csrf_token"]').inputValue();
        const invalid = await page.request.post(origin + '/components/auth/help.php', {form: {csrf_token: csrf, guidance_status: 'reset'}, maxRedirects: 0});
        assert.equal(invalid.status(), 422);
        const foreign = await page.request.post(origin + '/components/auth/help.php', {form: {csrf_token: csrf, guidance_status: 'completed', user_id: '3', workspace: 'inventory_manager', return_to: 'https://example.test/'}, maxRedirects: 0});
        assert.equal(foreign.status(), 303);
        assert.equal(foreign.headers().location, '/components/auth/help.php', 'External redirects rejected');
        const missingCsrf = await page.request.post(origin + '/components/auth/help.php', {form: {guidance_status: 'completed'}, maxRedirects: 0});
        assert.equal(missingCsrf.status(), 403);
        const rows = JSON.parse(fixture('guidance_state'));
        assert.deepEqual(rows, [
            {user_id: 1, workspace: 'admin', status: 'skipped'},
            {user_id: 2, workspace: 'admin', status: 'completed'},
            {user_id: 2, workspace: 'inventory_manager', status: 'deferred'},
        ], 'State belongs only to authenticated account and active Workspace');
        assert.deepEqual(JSON.parse(fixture('inventory_state')), beforeInventory, 'Guidance never changes inventory');
        assert.deepEqual(JSON.parse(fixture('governance_state')), beforeGovernance, 'Guidance never confirms Store setup or changes authority');
        await switchWorkspace('Cashier');
        await page.waitForURL('**/cashier/pos.php');
        assert.equal(await overview.count(), 0);
        assert.equal((await page.request.get(origin + '/components/auth/help.php')).status(), 403, 'Assigned Administrator role does not grant Help in active Cashier Workspace');
        console.log('Workspace onboarding browser: passed');
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
