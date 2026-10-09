const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Responsive system browser: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-responsive-browser-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const widths = [320, 375, 425, 768, 1024, 1440];
const routes = {
    admin: ['administrator/dashboard', 'administrator/store_settings', 'administrator/registers',
        'administrator/shift_report', 'administrator/stock_issues', 'administrator/database_backup',
        'system_administrator/fiscal_periods', 'user_manager/user_manager', 'system_administrator/audit_logs',
        'invoice/sales', 'report/report_generation?report=inventory_valuation', 'inventory_management/promotions',
        'invoice/sales?tab=reversals&sale_id=1', 'invoice/legacy_reversals?sale_id=1'],
    super_admin: ['super_administrator/dashboard', 'system_administrator/system_settings',
        'user_manager/user_manager', 'system_administrator/audit_logs', 'system_administrator/backup_restore',
        'system_administrator/database_updates', 'system_administrator/emergency_access',
        'system_administrator/recovery_account', 'system_administrator/system_health', 'system_administrator/ml_settings', 'auth/workspace'],
    inventory_manager: ['inventory_management/inventory_overview', 'inventory_management/inventory_insights',
        'inventory_management/products', 'inventory_management/csv_import', 'inventory_management/reorder_planner',
        'inventory_management/replenishment_requests', 'inventory_management/stock_issues', 'inventory_management/suppliers',
        'inventory_management/inventory_counts', 'report/stock_receiving', 'invoice/purchase_orders',
        'report/predictions', 'report/forecast_exceptions', 'report/data_readiness', 'report/forecast_analytics',
        'inventory_management/replenishment_requests?status=approved'],
    cashier: ['cashier/dashboard', 'cashier/pos', 'cashier/shifts', 'cashier/history', 'cashier/refunds',
        'cashier/stock_issues', 'invoice/sales', 'auth/user_info', 'auth/preferences', 'auth/change_password',
        'cashier/findProduct', 'notification/notifications', 'cashier/refunds?sale_id=1'],
};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    const failures = [], coverage = [];
    try {
        const data = JSON.parse(fixture('create'));
        fixture('reports');
        fixture('responsive');
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
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 900}});
        // Network-independent checks exercise the application's local CSS and JavaScript.
        await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        const layout = async label => {
            const problems = await page.evaluate(() => {
                const width = document.documentElement.clientWidth;
                const result = [];
                const main = document.querySelector('.main-content, main');
                if (!main) return ['Missing application content'];
                if (document.documentElement.scrollWidth > width + 1) result.push('Page horizontal overflow ' + document.documentElement.scrollWidth + '/' + width);
                const rect = main.getBoundingClientRect();
                if (rect.left < -1 || rect.right > width + 1) result.push('Content outside viewport ' + JSON.stringify({left: rect.left, right: rect.right, width}));
                for (const el of main.querySelectorAll('input:not([type=hidden]), select, textarea, button, .tabs, .table-pagination, .pagination')) {
                    if (!el.getClientRects().length || getComputedStyle(el).visibility === 'hidden') continue;
                    const box = el.getBoundingClientRect();
                    if (box.left >= -1 && box.right <= width + 1) continue;
                    let scroller = el.parentElement;
                    while (scroller && scroller !== main && !/(auto|scroll)/.test(getComputedStyle(scroller).overflowX)) scroller = scroller.parentElement;
                    if (!scroller || scroller === main) result.push('Control outside viewport: ' + el.tagName + '#' + el.id + '.' + el.className + ' ' + Math.round(box.right));
                }
                return [...new Set(result)];
            });
            assert.deepEqual(problems, [], label);
        };
        const overlay = async selector => {
            const node = page.locator(selector).first();
            await page.waitForFunction(selector => document.querySelector(selector)?.classList.contains('open'), selector);
            await node.waitFor({state: 'visible'});
            await node.evaluate(el => {
                const panel = el.querySelector('.rm-modal,.rm-drawer,.user-modal,.user-drawer,.checkout-dialog,.command-palette');
                return Promise.all([el, panel].filter(Boolean).flatMap(node => node.getAnimations()).map(animation => animation.finished));
            });
            const issues = await node.evaluate(el => {
                const r = el.getBoundingClientRect(), w = document.documentElement.clientWidth, h = innerHeight;
                const issues = [];
                if (Math.abs(r.left) > 1 || Math.abs(r.top) > 1 || Math.abs(r.right - w) > 1 || Math.abs(r.bottom - h) > 1) issues.push('Backdrop outside viewport ' + JSON.stringify(r.toJSON()));
                const panel = el.querySelector('[role=dialog], .rm-modal, .rm-drawer, .user-modal, .user-drawer, .checkout-dialog');
                if (panel) {
                    const b = panel.getBoundingClientRect();
                    if (b.left < -1 || b.top < -1 || b.right > w + 1 || b.bottom > h + 1) issues.push('Panel outside viewport ' + JSON.stringify(b.toJSON()));
                    // The reserved native scrollbar strip does not participate in hit testing.
                    const x = Math.min(w - 24, b.right - 24), y = Math.min(h - 2, b.bottom - 8);
                    if (!el.contains(document.elementFromPoint(x, y))) issues.push('Panel covered by another layer');
                }
                if (!document.body.classList.contains('no-scroll')) issues.push('Background scroll is unlocked');
                return issues;
            });
            assert.deepEqual(issues, [], selector);
        };
        const close = async () => {
            const open = page.locator('.rm-modal-overlay.open,.rm-drawer-overlay.open,.user-modal-overlay.open,.user-drawer-overlay.open,.checkout-modal.open,.command-overlay.open');
            const nodes = await open.elementHandles();
            await page.keyboard.press('Escape');
            await page.waitForFunction(() => !document.querySelector('.rm-modal-overlay.open,.rm-drawer-overlay.open,.user-modal-overlay.open,.user-drawer-overlay.open,.checkout-modal.open,.command-overlay.open'));
            for (const node of nodes) await node.evaluate(el => Promise.all(el.getAnimations().map(animation => animation.finished)));
            assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), false, 'Closing restores page scrolling');
        };
        const sharedNavigation = async () => {
            await page.keyboard.press('Escape');
            const menu = page.locator('#menuToggle');
            if (await menu.isVisible()) {
                await menu.click();
                await page.locator('#sidebarOverlay').evaluate(el => Promise.all(el.getAnimations().map(animation => animation.finished)));
                const bounds = await page.locator('#sidebarOverlay').boundingBox();
                assert.equal(Math.round(bounds.width), await page.evaluate(() => innerWidth), 'Sidebar backdrop covers viewport');
                await page.locator('#sidebarOverlay').click({position: {x: bounds.width - 4, y: 80}});
                assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), false);
            }
            const account = page.locator('[data-account-menu-open]').filter({visible: true}).first();
            await account.click();
            await page.locator('#sidebarAccountMenu').evaluate(el => Promise.all(el.getAnimations().map(animation => animation.finished)));
            for (const submenu of ['.sidebar-workspace-switcher', '.sidebar-preferences-menu']) {
                await page.locator(submenu + ' > summary').click();
                await page.waitForFunction(selector => document.querySelector(selector).open && document.querySelector(selector + ' > summary').getAttribute('aria-expanded') === 'true', submenu);
                const panel = page.locator(submenu + ' > div');
                const box = await panel.boundingBox();
                assert(box.x >= 0 && box.y >= 0 && box.x + box.width <= await page.evaluate(() => innerWidth) + 1, 'Account submenu fits viewport');
                await page.locator(submenu + ' > summary').click();
            }
            await page.keyboard.press('Escape');
            await page.keyboard.press('Control+k'); await overlay('.command-overlay'); await close();
        };
        const actions = async route => {
            if (route === 'inventory_management/products') {
                await page.locator('#clear-filters').click();
                const first = page.getByRole('button', {name: 'First page', exact: true});
                if (await first.isEnabled()) await first.click();
                assert.equal(await page.locator('#product-table-body tr:visible').count(), 10, 'Mobile cards preserve pagination');
                await page.locator('.manage-product').filter({visible: true}).first().click();
                await overlay('#product-drawer');
                for (const tab of await page.locator('[data-product-tab]').all()) { await tab.click(); await overlay('#product-drawer'); }
                await close();
                await page.locator('#product-add-trigger').click();
                await page.locator('#add-product-btn').click(); await overlay('#add-product-modal'); await close();
                await page.locator('#column-button').click(); await overlay('#column-modal'); await close();
                await page.locator('#catalog-filter-toggle').click(); await layout('Products collapsed filters');
                await page.locator('#catalog-filter-toggle').click();
                const next = page.getByRole('button', {name: 'Next page', exact: true});
                await next.click(); await layout('Products pagination');
                assert.equal(await page.locator('#product-table-body tr:visible').count(), 10);
            }
            if (route === 'user_manager/user_manager') {
                await page.locator('#openUserModal').click(); await overlay('#userModalOverlay'); await close();
                const drawer = page.locator('.open-user-drawer').first();
                if (await drawer.count()) {
                    await drawer.click(); await overlay('#userDrawerOverlay');
                    for (const tab of await page.locator('[data-user-drawer-tab]').all()) { await tab.click(); await overlay('#userDrawerOverlay'); }
                    await close();
                }
            }
            if (route === 'report/forecast_analytics') {
                for (const tab of await page.locator('[data-analytics-tab]').all()) { await tab.click(); await layout('Forecast tab'); }
            }
            if (route === 'notification/notifications') {
                const unread = await page.locator('.btn-mark-read').count();
                assert(unread > 0, 'Representative unread notification present');
                await page.locator('.btn-mark-read').first().click();
                await page.waitForFunction(previous => document.querySelectorAll('.btn-mark-read').length === previous - 1 && !document.querySelector('[data-navigation-status]'), unread);
                await layout('Notification form submission');
            }
            if (route === 'cashier/pos') {
                await page.locator('[data-quick-add]').first().click();
                await page.locator('#void-sale-btn').click(); await overlay('#discard-modal'); await close();
                await page.locator('#hold-list button').filter({hasText: 'Discard'}).first().click();
                await overlay('#discard-modal'); await close();
                await page.locator('#checkout-button').click(); await overlay('#checkout-modal');
                await page.locator('#review-cash').fill('1000');
                await close();
                await layout('POS after checkout review');
                const wrap = page.locator('.cart-table-wrap');
                await wrap.evaluate(el => { el.scrollLeft = el.scrollWidth; });
                const bounds = await wrap.boundingBox();
                const remove = page.locator('.remove-item-btn').first();
                const button = await remove.boundingBox();
                assert(button.x >= bounds.x && button.x + button.width <= bounds.x + bounds.width, 'Cart actions reachable by horizontal scrolling');
                await remove.click();
                assert.equal(await page.locator('#cart-line-count').textContent(), '0');
            }
        };
        for (const [role, screens] of Object.entries(routes)) {
            await page.goto(origin + '/?login=1', {waitUntil: 'domcontentloaded'});
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            for (const screen of screens) {
                console.log('Responsive screen: ' + role + '/' + screen);
                const [route, query] = screen.split('?');
                const response = await page.goto(origin + '/components/' + route + '.php' + (query ? '?' + query : ''), {waitUntil: 'domcontentloaded'});
                if (response.status() !== 200) { failures.push({role, screen, error: 'HTTP ' + response.status()}); continue; }
                for (const width of widths) {
                    await page.setViewportSize({width, height: 900});
                    await page.waitForTimeout(60);
                    const label = role + '/' + screen + '/' + width;
                    try {
                        await layout(label);
                        if (screen === screens[0]) await sharedNavigation();
                        await actions(route);
                        assert.deepEqual(pageErrors.splice(0), [], 'Page actions must not throw JavaScript errors');
                        coverage.push(label);
                    } catch (error) {
                        failures.push({role, screen, width, error: error.message});
                        pageErrors.length = 0;
                        await page.screenshot({path: path.join(output, failures.length + '.png'), fullPage: true});
                        await page.evaluate(() => document.querySelectorAll('.open[aria-hidden=false]').forEach(el => window.RetailMindUI?.closeOverlay(el)));
                    }
                }
            }
            await page.goto(origin + '/components/auth/logout.php', {waitUntil: 'domcontentloaded'});
        }
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({widths, coverage, failures}, null, 2));
        fs.writeFileSync(path.join(output, 'server.log'), logs);
        console.log('Responsive browser artifacts: ' + output);
        assert.deepEqual(failures, [], 'Responsive screens and actions must pass');
        console.log('Responsive system browser: passed (' + coverage.length + ' real page/viewport checks across four roles)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
