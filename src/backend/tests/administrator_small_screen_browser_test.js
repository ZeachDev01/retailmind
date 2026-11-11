const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator small-screen layout: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
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
                .filter(el => {
                    const r = el.getBoundingClientRect();
                    if (r.left >= -1 && r.right <= innerWidth + 1) return false;
                    for (let parent = el.parentElement; parent && parent !== main; parent = parent.parentElement) {
                        if (/(auto|scroll)/.test(getComputedStyle(parent).overflowX)) return false;
                    }
                    return true;
                })
                .map(el => el.textContent.trim() || el.name));
            assert.deepEqual(clipped, [], label + ' controls fit');
        };
        await login('admin');
        const dismissGuidance = page.getByRole('button', {name:'Not now',exact:true});
        if (await dismissGuidance.isVisible()) await dismissGuidance.tap();
        const routes = ['administrator/dashboard.php', 'administrator/gcash_overview.php',
            'user_manager/user_manager.php', 'administrator/registers.php', 'administrator/store_settings.php',
            'administrator/store_calendar.php', 'system_administrator/fiscal_periods.php',
            'administrator/database_backup.php', 'system_administrator/audit_logs.php',
            'inventory_management/promotions.php', 'administrator/stock_issues.php',
            'report/data_readiness.php', 'administrator/model_review.php', 'report/predictions.php',
            'report/forecast_exceptions.php', 'administrator/shift_report.php', 'report/receiving_history.php',
            'invoice/sales.php', 'report/report_generation.php?report=gross_profit',
            'cashier/refund_corrections.php', 'notification/notifications.php'];
        for (const viewport of [{width:320,height:740}, {width:390,height:844}, {width:844,height:390}]) {
            await page.setViewportSize(viewport);
            for (const route of routes) {
                await visit(route);
                await fits(route + '/' + viewport.width);
                const header = await page.locator('.admin-mobile-topbar').boundingBox();
                assert(header.height <= 64, route + ': mobile header stays compact');
                const controls = await page.locator('.admin-mobile-topbar').evaluate(header =>
                    [...header.querySelectorAll('button, a')].filter(el => el.getClientRects().length)
                        .map(el => {const r = el.getBoundingClientRect(); return {name:el.ariaLabel, x:r.x, right:r.right, y:r.y, bottom:r.bottom, width:r.width, height:r.height};})
                        .sort((a,b) => a.x-b.x));
                for (const [index, control] of controls.entries()) {
                    assert(control.x >= 0 && control.right <= viewport.width, route + ': ' + control.name + ' fits');
                    assert(control.height >= 44, route + ': ' + control.name + ' is touch sized');
                    if (index) assert(controls[index-1].right <= control.x + 1, route + ': header controls do not overlap');
                }
                const heading = await page.locator('main h1').first().boundingBox();
                if (heading) assert(heading.y >= header.y + header.height, route + ': heading clears fixed header');
                assert.deepEqual(errors.splice(0), [], route + ': no JavaScript errors');
            }
            await visit('administrator/dashboard.php');
            await page.locator('.mobile-header-search').tap();
            const geometry = await page.locator('.command-search-row').evaluate(row => {
                const box = el => {const r = el.getBoundingClientRect(); return {x:r.x, y:r.y, right:r.right, width:r.width, height:r.height, cy:r.y+r.height/2};};
                return {icon:box(row.querySelector('img, i')), input:box(row.querySelector('input')), close:box(row.querySelector('button'))};
            });
            assert(Math.abs(geometry.icon.cy - geometry.input.cy) <= 2, 'Search icon and input share one row');
            assert(Math.abs(geometry.close.cy - geometry.input.cy) <= 2, 'Search close button aligns with input');
            assert(geometry.close.height >= 44 && geometry.close.width >= 44, 'Search close target is 44px');
            assert(geometry.icon.right < geometry.close.x, 'Search controls do not overlap');
            const lastResult = page.locator('#commandResults a').last();
            await lastResult.scrollIntoViewIfNeeded();
            const resultBox = await lastResult.boundingBox();
            assert(resultBox.y >= 0 && resultBox.y + resultBox.height <= viewport.height, 'Long search results remain reachable');
            assert(await page.locator('#commandSearch').evaluate(el => parseFloat(getComputedStyle(el).fontSize) >= 16), 'Search input avoids mobile focus zoom');
            await page.locator('#commandSearch').fill('GCash');
            assert(await page.getByRole('link', {name:/GCash Overview Store Operations/}).isVisible(), 'Search finds admin page');
            await page.screenshot({path:path.join(output, 'search-' + viewport.width + '.png')});
            await page.getByRole('button', {name:'Close search',exact:true}).tap();
            await page.locator('#menuToggle').tap();
            const lastLink = page.locator('#appSidebar .sidebar-nav a').last();
            await lastLink.scrollIntoViewIfNeeded();
            const lastBox = await lastLink.boundingBox();
            assert(lastBox.y >= 60 && lastBox.y + lastBox.height <= viewport.height + 1, 'Last menu link is reachable');
            await page.screenshot({path:path.join(output, 'menu-' + viewport.width + '.png')});
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), false);
            await page.screenshot({path:path.join(output, 'dashboard-' + viewport.width + '.png'),fullPage:true});
            if (viewport.width === 390) {
                const cards = await page.locator('.store-operations-headlines .stat-card').evaluateAll(cards => cards.slice(0,2).map(el => el.getBoundingClientRect().y));
                assert.equal(cards[0], cards[1], 'Store status uses two columns when phone width permits');
            }
        }
        await page.setViewportSize({width:1280,height:900});
        await visit('administrator/dashboard.php');
        await fits('Desktop dashboard');
        assert(await page.locator('.global-topbar').isVisible(), 'Desktop navigation remains available');
        console.log('Administrator small-screen layout: passed (21 routes; 320/390 portrait, 844 landscape; search/menu touch; desktop dashboard; artifacts ' + output + ')');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

