const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator mobile monitoring: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-admin-mobile-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/administrator_mobile_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('reports');
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
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch();
        const context = await browser.newContext({viewport: {width: 390, height: 844}, hasTouch: true, isMobile: true, reducedMotion: 'reduce'});
        await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        page.setDefaultTimeout(8000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const login = async role => {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        const visit = async route => {
            const response = await page.goto(origin + '/components/' + route);
            assert.equal(response.status(), 200, route);
        };
        const fits = async label => {
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), label + ' has no page overflow');
            const clipped = await page.locator('main').evaluate(main => [...main.querySelectorAll('a, button, input, select, summary')]
                .filter(el => el.getClientRects().length && !el.closest('[hidden], .table-wrap, .data-table-scroll, .gcash-table-wrap, .gcash-section-nav'))
                .filter(el => {const r = el.getBoundingClientRect(); return r.left < -1 || r.right > innerWidth + 1;})
                .map(el => el.textContent.trim() || el.name));
            assert.deepEqual(clipped, [], label + ' controls fit');
        };
        await login('admin');
        for (const viewport of [{width: 320, height: 740}, {width: 390, height: 844}, {width: 844, height: 390}]) {
            await page.setViewportSize(viewport);
            for (const route of ['administrator/dashboard.php', 'administrator/gcash_overview.php', 'notification/notifications.php',
                'report/report_generation.php?report=gross_profit', 'administrator/stock_issues.php']) {
                await visit(route);
                await fits(route + '/' + viewport.width);
                if (route === 'administrator/dashboard.php') {
                    const link = page.getByRole('link', {name: 'View Stock Issues', exact: true});
                    assert((await link.boundingBox()).height >= 44, 'Summary drill-down is touch sized');
                    await link.tap();
                    await page.waitForURL('**/administrator/stock_issues.php');
                    assert(await page.getByRole('heading', {name: /Stock Issue Oversight/}).isVisible());
                }
                if (route === 'administrator/gcash_overview.php') {
                    await page.getByRole('tab', {name: /Transactions/}).tap();
                    assert(await page.locator('#tab-transactions').isVisible());
                    await fits('GCash transactions/' + viewport.width);
                }
                if (route === 'notification/notifications.php') {
                    const review = page.getByRole('link', {name: 'Open Stock Issue', exact: true}).first();
                    assert(await review.isVisible(), 'Notification conditions offer drill-downs');
                    assert((await review.boundingBox()).height >= 44, 'Notification drill-down is touch sized');
                    await review.tap();
                    await page.waitForURL('**/administrator/stock_issues.php?detail=1');
                    assert.equal(new URL(page.url()).pathname, '/components/administrator/stock_issues.php', 'Administrator notification opens read-only oversight');
                    assert(await page.getByRole('heading', {name: /Stock Issue detail/}).isVisible(), 'Notification opens the referenced issue');
                }
                if (route === 'report/report_generation.php?report=gross_profit') {
                    assert(await page.locator('#at-a-glance-summary').isVisible());
                    assert(await page.locator('#glance-gross-sales').isVisible());
                }
                if (route === 'administrator/stock_issues.php') {
                    assert.equal(await page.locator('main form[method="post" i]').count(), 0, 'Oversight exposes no mutation form');
                    assert.equal((await context.request.post(origin + '/components/' + route, {form: {action: 'approve', adjustment_id: '1'}})).status(), 403, 'Oversight refuses mutations');
                    const detail = page.getByRole('link', {name: 'Open report', exact: true}).first();
                    assert(await detail.isVisible(), 'Issue details are reachable');
                    await detail.tap();
                    await page.waitForURL('**/administrator/stock_issues.php?detail=1');
                    assert(await page.getByRole('heading', {name: /Stock Issue detail/}).isVisible());
                    await fits('Stock Issue detail/' + viewport.width);
                }
                assert.deepEqual(errors.splice(0), [], route + ' runs without JavaScript errors');
            }
            await visit('administrator/dashboard.php');
            const menu = page.locator('#menuToggle');
            if (await menu.isVisible()) {
                assert((await menu.boundingBox()).height >= 44, 'Navigation toggle is touch sized');
                await menu.tap();
                assert(await page.locator('#sidebarOverlay').isVisible());
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), false);
            }
            await page.screenshot({path: path.join(output, 'administrator-' + viewport.width + '.png'), fullPage: true});
        }
        await page.goto(origin + '/components/auth/logout.php');
        for (const role of ['cashier', 'inventory_manager', 'super_admin']) {
            await login(role);
            if (role === 'super_admin') {
                await visit('auth/workspace.php');
                const workspace = page.locator('form.workspace-option').filter({has: page.locator('input[name="workspace"][value="super_admin"]')});
                const open = workspace.getByRole('button', {name: 'Open', exact: true});
                if (await open.count()) {
                    await open.click();
                    await page.waitForURL('**/super_administrator/dashboard.php');
                }
            }
            for (const route of ['administrator/dashboard.php', 'administrator/gcash_overview.php', 'administrator/gcash_overview_api.php', 'administrator/stock_issues.php']) {
                const expected = role === 'super_admin' && route.includes('gcash_overview') ? 200 : 403;
                assert.equal((await context.request.get(origin + '/components/' + route)).status(), expected, role + '/' + route + ' retains existing active Workspace authorization');
            }
            await page.goto(origin + '/components/auth/logout.php');
        }
        console.log('Administrator mobile monitoring: passed (Chromium touch emulation, 320/390 portrait and 844 landscape; artifacts ' + output + ')');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
