const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Superadmin UI: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const admin = process.argv.includes('--admin');
const inventory = process.argv.includes('--inventory');
const label = inventory ? 'Inventory Manager UI' : admin ? 'Administrator UI' : 'Superadmin UI';
const output = fs.mkdtempSync(path.join(os.tmpdir(), admin ? 'rm-admin-ui-' : 'rm-superadmin-ui-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const screens = inventory ? [
    ...fs.readdirSync('src/frontend/components/inventory_management').filter(file => file.endsWith('.php') && file !== 'promotions.php').map(file => 'inventory_management/' + file.slice(0, -4)),
    'inventory_management/product_investigation?product_id=1',
    'inventory_management/print_barcodes?product_id=1',
    'invoice/purchase_orders', 'report/stock_receiving', 'invoice/sales?tab=transactions',
    'report/forecast_analytics', 'report/forecast_exceptions', 'report/predictions', 'report/data_readiness',
    'auth/user_info', 'auth/preferences', 'auth/change_password', 'auth/workspace', 'notification/notifications',
] : admin ? [
    'administrator/dashboard', 'administrator/registers', 'administrator/store_settings',
    'administrator/store_calendar', 'system_administrator/fiscal_periods',
    'administrator/database_backup', 'user_manager/user_manager',
    'system_administrator/audit_logs', 'invoice/sales', 'invoice/sales?tab=reversals&sale_id=1',
    'invoice/legacy_reversals?sale_id=1', 'inventory_management/promotions',
    'administrator/stock_issues', 'administrator/shift_report', 'report/forecast_analytics',
    'report/forecast_exceptions', 'report/data_readiness', 'report/report_generation',
    'report/report_generation?report=inventory_valuation', 'auth/user_info', 'auth/preferences',
    'auth/change_password', 'auth/workspace', 'notification/notifications',
    ...['sales_product', 'sales_category', 'sales_cashier', 'refunds', 'fast_moving', 'slow_moving',
        'low_stock', 'stock_movements', 'adjustments', 'forecast_actual',
        'gross_profit', 'expiration', 'count_variance'].map(report => 'report/report_generation?report=' + report),
    'administrator/stock_issues?detail=1',
    'report/predictions', 'inventory_management/replenishment_queue',
    'inventory_management/inventory_insights', 'inventory_management/products',
    'inventory_management/product_investigation?product_id=1',
] : [
    'super_administrator/dashboard', 'user_manager/user_manager',
    'system_administrator/audit_logs', 'system_administrator/recovery_account',
    'system_administrator/system_health', 'system_administrator/ml_settings',
    'administrator/reset_data', 'system_administrator/backup_restore',
    'system_administrator/system_settings', 'system_administrator/database_updates',
    'system_administrator/forecast_import', 'report/predictions',
    'report/forecast_analytics', 'report/forecast_exceptions', 'report/data_readiness',
    'auth/user_info', 'auth/preferences', 'auth/change_password',
    'auth/workspace', 'notification/notifications',
];
const sizes = [[320, 740], [375, 812], [768, 1024], [900, 600], [945, 1177], [1024, 768], [1440, 900]];
const liveCdn = process.argv.includes('--live-cdn');
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    const checks = [], errors = [], resourceErrors = [];
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
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 900}, hasTouch: true});
        if (!liveCdn) await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        page.setDefaultTimeout(5000);
        page.on('pageerror', error => errors.push(page.url() + ': ' + error.stack));
        page.on('requestfailed', request => {
            if (liveCdn && request.url().startsWith('https:')) resourceErrors.push(request.url() + ': ' + request.failure().errorText);
        });
        page.on('response', response => {
            if (liveCdn && response.url().startsWith('https:') && response.status() >= 400) resourceErrors.push(response.url() + ': HTTP ' + response.status());
        });
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill(inventory ? 'theme_inventory_manager' : admin ? 'theme_admin' : 'theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        for (const screen of screens.filter(screen => !process.env.UI_SCREENS || process.env.UI_SCREENS.split(',').includes(screen))) {
            console.log(label + ': ' + screen);
            const [route, query] = screen.split('?');
            const response = await page.goto(origin + '/components/' + route + '.php' + (query ? '?' + query : ''), {waitUntil: 'networkidle'});
            if (response.status() !== 200) {
                checks.push({screen, issues: ['HTTP ' + response.status()]});
                fs.writeFileSync(path.join(output, screen.replace(/[^a-z0-9_-]/gi, '-') + '-error.html'), await page.content());
                continue;
            }
            const stylesheet = await page.locator('link[href*="assets/css/style.css"]').first().getAttribute('href');
            if (!new URL(stylesheet, origin).searchParams.has('v')) checks.push({screen, issues: ['Stylesheet missing cache version']});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; document.documentElement.style.colorScheme = theme; }, theme);
                for (const [width, height] of sizes) {
                    await page.setViewportSize({width, height});
                    await page.evaluate(() => Promise.all(document.getAnimations().filter(animation => animation.effect.getTiming().iterations !== Infinity).map(animation => animation.finished.catch(() => {}))));
                    const issues = await page.evaluate(() => {
                        const width = document.documentElement.clientWidth;
                        const main = document.querySelector('.main-content, main');
                        const issues = [];
                        const visible = el => el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden';
                        const name = el => el.tagName.toLowerCase() + '#' + el.id + '.' + el.className;
                        const rgb = value => {
                            const channels = value.match(/[\d.]+/g).map(Number);
                            return value.startsWith('color(srgb') ? channels.map((channel, i) => i < 3 ? channel * 255 : channel) : channels;
                        };
                        const over = (a, b) => a.slice(0, 3).map((channel, i) => channel * (a[3] ?? 1) + b[i] * (1 - (a[3] ?? 1)));
                        const luminance = color => color.map(channel => {
                            const s = channel / 255;
                            return s <= .04045 ? s / 12.92 : ((s + .055) / 1.055) ** 2.4;
                        }).reduce((sum, channel, i) => sum + channel * [.2126, .7152, .0722][i], 0);
                        if (document.documentElement.scrollWidth > width + 1) issues.push('Page overflow');
                        if (document.querySelectorAll('main h1').length !== 1) issues.push('Expected one page h1');
                        for (const el of document.querySelectorAll('main input:not([type=hidden]), main select, main textarea, #commandSearch')) {
                            if (!el.labels?.length && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')) issues.push('Unlabelled field ' + name(el));
                        }
                        for (const el of document.querySelectorAll('main button, main a.btn, main summary, .admin-mobile-topbar button, .global-topbar button, .global-topbar a')) {
                            if (!visible(el)) continue;
                            const box = el.getBoundingClientRect();
                            if (!el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title')) issues.push('Unnamed control ' + name(el));
                            if (box.width < 43.9 || box.height < 43.9) issues.push('Small target ' + name(el) + ' ' + Math.round(box.width) + 'x' + Math.round(box.height));
                        }
                        for (const el of main.querySelectorAll('input:not([type=hidden]),select,textarea,button,.page-heading,.topbar,.form-grid')) {
                            if (!visible(el)) continue;
                            const box = el.getBoundingClientRect();
                            if (box.left >= -1 && box.right <= width + 1) continue;
                            let parent = el.parentElement;
                            while (parent && parent !== main && !/(auto|scroll)/.test(getComputedStyle(parent).overflowX)) parent = parent.parentElement;
                            if (!parent || parent === main) issues.push('Outside viewport ' + name(el));
                        }
                        for (const el of main.querySelectorAll('*')) {
                            if (!visible(el) || el.closest(':disabled, [aria-disabled="true"]') || ![...el.childNodes].some(node => node.nodeType === Node.TEXT_NODE && node.textContent.trim())) continue;
                            const style = getComputedStyle(el);
                            const layers = [];
                            let parent = el, unsupported = false;
                            while (parent) {
                                const paint = getComputedStyle(parent);
                                if (paint.backgroundImage !== 'none' || Number(paint.opacity) < 1) { unsupported = true; break; }
                                layers.push(rgb(paint.backgroundColor));
                                parent = parent.parentElement;
                            }
                            if (unsupported) continue;
                            const background = layers.reverse().reduce((base, layer) => over(layer, base), [255, 255, 255]);
                            const ink = over(rgb(style.color), background);
                            const a = luminance(ink), b = luminance(background);
                            const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
                            const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && parseInt(style.fontWeight) >= 700);
                            if (ratio < (large ? 3 : 4.5) - .01) issues.push('Text contrast ' + name(el) + ' ' + ratio.toFixed(2) + ': ' + el.textContent.trim().slice(0, 35));
                        }
                        const header = document.querySelector(innerWidth <= 900 ? '.admin-mobile-topbar' : '.global-topbar');
                        if (header) {
                            const controls = [...header.querySelectorAll('button,a')].filter(visible).map(el => ({el, rect: el.getBoundingClientRect()}));
                            for (let i = 0; i < controls.length; i++) {
                                const a = controls[i].rect;
                                if (a.left < -1 || a.right > width + 1) issues.push('Header outside viewport ' + name(controls[i].el));
                                for (const {el, rect: b} of controls.slice(i + 1)) {
                                    if (Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1) issues.push('Header overlap ' + name(el));
                                }
                            }
                        }
                        return [...new Set(issues)];
                    });
                    checks.push({screen, theme, width, height, issues});
                    if (!process.argv.includes('--no-screenshots') && [375, 768, 945, 1440].includes(width)) {
                        await page.screenshot({path: path.join(output, screen.replace(/[^a-z0-9_-]/gi, '-') + '-' + theme + '-' + width + '.png'), fullPage: true});
                    }
                }
            }
            const verify = async () => {
                await page.setViewportSize({width: 375, height: 812});
                const menu = page.locator('#menuToggle');
                if (await menu.count()) {
                    await menu.tap();
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), true, 'Drawer isolates background keyboard focus');
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('#appSidebar').evaluate(el => el.inert), true, 'Closed drawer is not keyboard-focusable');
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), false, 'Closing drawer restores background');
                    await menu.tap();
                    await page.locator('#sidebarOverlay').tap({position: {x: 375 - 24, y: 150}});
                    assert.equal(await page.locator('.main-content').evaluate(el => el.inert), false, 'Touching the backdrop restores background focus');
                    await page.locator('.admin-mobile-avatar').tap();
                    assert.equal(await page.locator('#sidebarAccountMenu').getAttribute('aria-hidden'), 'false', 'Mobile account menu opens');
                    await page.keyboard.press('Escape');
                    await page.locator('.mobile-header-search').tap();
                    await page.locator('#commandSearch').waitFor({state: 'visible'});
                    await page.waitForFunction(() => document.activeElement.id === 'commandSearch');
                    assert.equal(await page.locator('#commandSearch').evaluate(el => el === document.activeElement), true, 'Search receives keyboard focus');
                    await page.keyboard.press('Escape');
                }
                if (screen === 'super_administrator/dashboard') {
                    const issue = page.locator('.triage-item').nth(1);
                    await issue.tap();
                    assert.equal(await issue.getAttribute('aria-pressed'), 'true', 'Issue selection responds to touch');
                }
                if (screen === 'user_manager/user_manager') {
                    for (const opener of ['#openUserModal', '.open-user-drawer']) {
                        await page.locator(opener).first().tap();
                        await page.locator('.user-modal-overlay.open,.user-drawer-overlay.open').waitFor({state: 'visible'});
                        assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), true, 'User dialog locks background scrolling');
                        await page.keyboard.press('Escape');
                    }
                }
                if (screen === 'auth/change_password') {
                    const toggle = page.locator('[data-password-toggle]').first();
                    await toggle.tap();
                    assert.equal(await toggle.getAttribute('aria-pressed'), 'true', 'Password visibility responds to touch');
                    await toggle.tap();
                }
                if (screen === 'administrator/store_calendar') {
                    const date = page.locator('button.calendar-day-cell').last();
                    await date.tap();
                    assert.equal(await page.locator('#calendar_date').inputValue(), await date.getAttribute('data-date'), 'Calendar date selection responds to touch');
                    assert.equal(await date.getAttribute('aria-pressed'), 'true', 'Selected date is announced');
                    const first = page.locator('button.calendar-day-cell').first();
                    await first.focus();
                    await page.keyboard.press('Space');
                    assert.equal(await page.locator('#calendar_date').inputValue(), await first.getAttribute('data-date'), 'Calendar date selection works by keyboard');
                    await page.locator('#through_date').fill('2026-01-01');
                    await page.locator('#through_date').dispatchEvent('change');
                    assert.match(await page.locator('#range-preview-hint').textContent(), /cannot be before/, 'Invalid ranges explain recovery');
                }
                if (screen === 'inventory_management/promotions') {
                    for (const scope of ['product', 'category', 'all']) {
                        await page.locator('#scope').selectOption(scope);
                        assert.equal(await page.locator('#product-scope').isVisible(), scope === 'product');
                        assert.equal(await page.locator('#category-scope').isVisible(), scope === 'category');
                    }
                }
                if (screen === 'inventory_management/products' && admin) {
                    await page.locator('#product-search').fill('Theme Test Item');
                    await page.locator('.manage-product:visible').first().tap();
                    await page.locator('#product-drawer.open').waitFor({state: 'visible'});
                    await page.getByRole('tab', {name: 'Quantity', exact: true}).tap();
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('#bulk-category').count(), 0, 'Administrator catalog keeps inventory mutation controls unavailable');
                }
                if (screen === 'report/forecast_analytics') {
                    for (const tab of await page.locator('[data-analytics-tab]').all()) await tab.tap();
                }
                if (inventory && screen === 'inventory_management/inventory_overview') {
                    await page.locator('#overview-tab-fefo').tap();
                    assert.equal(await page.locator('#overview-tab-fefo').getAttribute('aria-selected'), 'true');
                    await page.keyboard.press('ArrowLeft');
                    assert.equal(await page.locator('#overview-tab-movements').getAttribute('aria-selected'), 'true');
                    await page.locator('[data-overview-view="all"]').tap();
                    assert.equal(await page.locator('#overview-product-view').isVisible(), true);
                    await page.locator('#overview-back').tap();
                    assert.equal(await page.locator('#overview-default-view').isVisible(), true);
                    assert.equal(await page.locator('#overview-product-view').isVisible(), false);
                }
                if (inventory && screen === 'inventory_management/inventory_insights') {
                    await page.locator('#tab-cycle-counts').tap();
                    assert.equal(await page.locator('#tab-cycle-counts').getAttribute('aria-selected'), 'true');
                    await page.keyboard.press('ArrowLeft');
                    assert.equal(await page.locator('#tab-product-risk').getAttribute('aria-selected'), 'true');
                }
                if (inventory && screen === 'inventory_management/products') {
                    await page.locator('#product-add-trigger').tap();
                    await page.locator('#add-product-btn').tap();
                    await page.locator('#add-product-modal.open').waitFor({state: 'visible'});
                    assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), true);
                    await page.locator('#wizard-next').tap();
                    assert.equal(await page.locator('[data-panel="1"]').isVisible(), true, 'Invalid form stays on Basic step');
                    await page.keyboard.press('Escape');
                    await page.locator('.manage-product').first().tap();
                    await page.locator('#product-drawer.open').waitFor({state: 'visible'});
                    for (const tab of await page.locator('[data-product-tab]').all()) await tab.tap();
                    await page.keyboard.press('Escape');
                }
                if (inventory && screen.startsWith('inventory_management/print_barcodes')) {
                    const size = await page.locator('#size').inputValue();
                    assert.equal(await page.locator('.barcode-page').evaluate(el => getComputedStyle(el).getPropertyValue('--label-width').trim()), size.split('x')[0] + 'mm');
                    if (screen.includes('product_id=1')) {
                        for (const size of ['40x25', '50x30', '60x40']) {
                            await page.goto(origin + '/components/inventory_management/print_barcodes.php?product_id=1&size=' + size);
                            for (const media of ['screen', 'print']) {
                                await page.emulateMedia({media});
                                const box = await page.locator('.barcode-label').first().boundingBox();
                                const [width, height] = size.split('x').map(Number);
                                assert.ok(Math.abs(box.width - width * 96 / 25.4) < 1, 'Selected barcode width survives ' + media);
                                assert.ok(Math.abs(box.height - height * 96 / 25.4) < 1, 'Selected barcode height survives ' + media);
                            }
                        }
                        await page.emulateMedia({media: 'screen'});
                    }
                }
                if (screen === 'report/data_readiness') {
                    assert.equal(await page.locator('.stat-card .value').first().textContent(), '66', 'Data Readiness counts fixture products rather than treating a query error as an empty catalog');
                }
            };
            try { await verify(); }
            catch (error) { checks.push({screen, issues: ['Interaction: ' + error.message]}); }
        }
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({liveCdn, checks, errors, resourceErrors}, null, 2));
        fs.writeFileSync(path.join(output, 'server.log'), logs);
        console.log(label + ' artifacts: ' + output);
        const failures = checks.filter(check => check.issues.length);
        console.log(JSON.stringify({checks: checks.length, failingChecks: failures.length, issues: [...new Set(failures.flatMap(check => check.issues))], errors, resourceErrors}, null, 2));
        if (!process.argv.includes('--inspect')) {
            assert.deepEqual(failures, [], label + ' pages must pass accessibility, target, header and layout checks');
            assert.deepEqual(errors, [], label + ' pages must not throw JavaScript errors');
            assert.deepEqual(resourceErrors, [], 'Live CDN assets must load successfully');
        }
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
