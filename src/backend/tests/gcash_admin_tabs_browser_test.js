'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') { console.log('GCash browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const run = (file, args) => {
    const result = spawnSync(php, [file, ...args], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const fixture = action => run('src/backend/tests/support/gcash_admin_layout_fixture.php', [action, database]);
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));

        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch (_) {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 900}});
        await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        const login = async (target, role) => {
            await target.goto(origin + '/?login=1');
            await target.locator('#landing-login-username').fill('theme_' + role);
            await target.locator('#landing-login-password').fill(data.password);
            await Promise.all([target.waitForURL(url => !url.search.includes('login=1')), target.getByRole('button', {name: 'Log in', exact: true}).click()]).catch(async error => { throw new Error(error.message + '\n' + await target.locator('body').innerText() + '\n' + logs.slice(-3000)); });
        };
        await login(page, 'admin');
        await page.evaluate(async () => {
            await navigator.serviceWorker.ready;
            if (!navigator.serviceWorker.controller) await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, {once: true}));
        });
        // Older cached scripts predate tab handlers; retain a sentinel at the old URL.
        await page.evaluate(async () => {
            const cache = await caches.open('retailmind-static-v2');
            await cache.put(new URL('/assets/js/gcash-admin-overview.js', location.href), new Response('window.legacyGCashScript = true;', {headers: {'Content-Type': 'text/javascript'}}));
        });
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(await page.evaluate(() => window.scrollY), 0, 'Opening overview keeps the page title visible');
        assert.equal(await page.locator('#gcash-coin-flip-trigger').count(), 0, 'Overview view toggle needs no separate flip control');
        assert.equal(await page.locator('[data-coin-switch]').count(), 0, 'Overview needs no duplicate view-switch shortcut');
        assert.equal(await page.locator('[data-coin-face]').count(), 0, 'Overview has one navigation level');
        for (const panel of ['[data-fragment="balances"]', '#gcash-outstanding-reviews', '#gcash-reporting-scope', '#gcash-completed-activity']) {
            assert.equal(await page.locator(panel).isVisible(), true, panel + ' is available without switching views');
        }
        assert.equal(await page.locator('.gcash-activity-row h2').count(), 1, 'Filters and totals have one Activity report heading');
        assert.equal(await page.getByRole('heading', {name: 'Activity report', exact: true}).isVisible(), true);
        assert.equal(await page.locator('#admin-cash-checked').isVisible(), true, 'Cashier and last actual check need no extra click');
        const serviceCash = await page.locator('#admin-expected-cash').textContent();
        assert.equal(await page.locator('#gcash-tab-overview').evaluate(tab => {
            const style = getComputedStyle(tab);
            return tab.classList.contains('active') && style.borderBottomWidth === '2px' && style.borderRadius === '0px';
        }), true, 'Administrator uses the shared GCash underline tabs');
        if (await page.locator('#coin-tab-activity').count() > 0 && !await page.locator('#gcash-completed-activity').isVisible()) {
            await page.locator('#coin-tab-activity').click();
        }
        await page.getByRole('button', {name: 'View transactions', exact: true}).click();
        assert.equal(await page.locator('#tab-transactions').isVisible(), true, 'Activity report links to its transaction history');
        await page.getByRole('tab', {name: 'Overview', exact: true}).click();
        await page.getByRole('tab', {name: 'Transactions'}).click();
        assert.equal(await page.locator('#tab-transactions').isVisible(), true, 'Transactions tab opens with an older GCash script cached');
        assert.equal(await page.evaluate(() => window.legacyGCashScript), undefined, 'Stale unversioned script is bypassed');
        await page.getByRole('tab', {name: 'Overview', exact: true}).click();
        if (await page.locator('#coin-tab-activity').count() > 0 && !await page.locator('#gcash-reporting-scope').isVisible()) {
            await page.locator('#coin-tab-activity').click();
        }
        await page.getByLabel('Reporting period', {exact: true}).selectOption('month_to_date');
        await page.locator('#btn-apply-filters').click();
        await page.waitForURL(url => url.searchParams.get('period') === 'month_to_date');
        assert.equal(await page.locator('#gcash-completed-activity').isVisible(), true, 'Filtered totals remain visible beside the report controls');
        assert.equal(await page.locator('#admin-expected-cash').textContent(), serviceCash, 'Report dates do not change service funds');
        assert.equal(await page.locator('#admin-cash-checked').isVisible(), true, 'Balance provenance remains visible after filtering');
        await page.getByRole('link', {name: 'Reset filters', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('tab') === 'overview' && !url.searchParams.has('period'));
        assert.equal(await page.getByLabel('Reporting period', {exact: true}).inputValue(), 'today', 'Reset restores Today without leaving Overview');
        await page.getByLabel('Start date', {exact: true}).fill('2099-01-01');
        await page.getByLabel('End date', {exact: true}).fill('2099-01-01');
        await page.locator('#btn-apply-filters').click();
        await page.waitForURL(url => url.searchParams.get('start_date') === '2099-01-01');
        assert.equal(await page.locator('.gcash-activity-empty').isVisible(), true, 'No completed exchanges shows the report empty state');
        assert.equal(await page.locator('.gcash-metrics-grid').isVisible(), false, 'Empty report omits zero metrics');
        for (const width of [320, 390, 768, 1024, 1440]) {
            await page.setViewportSize({width, height: 900});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Empty report fits ' + width + 'px in ' + theme);
                assert.equal(await page.locator('.gcash-activity-empty').evaluate(element => {
                    const bounds = element.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= innerWidth;
                }), true, 'Empty state stays inside the viewport');
                fs.mkdirSync('output/playwright/gcash-admin-empty', {recursive: true});
                await page.screenshot({path: 'output/playwright/gcash-admin-empty/' + theme + '-' + width + '.png', fullPage: true});
            }
        }
        await page.route('**/gcash_overview_fragments.php*', route => route.abort());
        await page.locator('#btn-apply-filters').click();
        await page.waitForFunction(() => document.querySelector('#gcash-filter-status').textContent.includes('unavailable'));
        assert.equal(await page.locator('.gcash-activity-empty').isVisible(), false, 'Failed loading is not an empty report');
        assert.equal(await page.locator('.gcash-metrics-grid').isVisible(), true, 'Failed report exposes unavailable totals');
        await page.unroute('**/gcash_overview_fragments.php*');
        await page.locator('#btn-reset-filters').click();
        await page.waitForURL(url => !url.searchParams.has('period'));
        assert.equal(await page.locator('.gcash-activity-empty').isVisible(), false, 'Reset restores the populated report');
        assert.equal(await page.locator('.gcash-metrics-grid').isVisible(), true);
        await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No page overflow at ' + width + 'px');
            for (const [name, panel] of [['Closing checks', 'reconciliation'], ['Unresolved exchanges', 'unresolved'], ['Overview', 'overview'], ['Transactions', 'transactions']]) {
                await page.getByRole('tab', {name}).click();
                assert.equal(await page.locator('#tab-' + panel).isVisible(), true, name + ' opens at ' + width + 'px');
            }
            await page.keyboard.press('Home');
            assert.equal(await page.locator('#tab-overview').isVisible(), true);
            await page.keyboard.press('End');
            await page.waitForURL(url => url.searchParams.get('tab') === 'money');
            await page.locator('#tab-money').waitFor({state: 'visible'});
            assert.equal(await page.locator('#tab-money').isVisible(), true);
            await page.getByRole('tab', {name: 'Overview', exact: true}).click();
            if (await page.locator('#coin-tab-activity').count() > 0 && !await page.locator('#gcash-reporting-scope').isVisible()) {
                await page.locator('#coin-tab-activity').click();
            }
            assert.equal(new URL(await page.locator('#btn-reset-filters').getAttribute('href'), origin).searchParams.get('tab'), 'overview', 'Reset preserves the current report tab');
            const reportGap = await page.evaluate(() => document.getElementById('gcash-completed-activity').getBoundingClientRect().top - document.getElementById('gcash-reporting-scope').getBoundingClientRect().bottom);
            assert.ok(Math.abs(reportGap) < 1, 'Filters and completed activity form one panel at ' + width + 'px');
            await page.evaluate(() => window.scrollTo(0, 0));
            fs.mkdirSync('output/playwright/gcash-admin-clarity', {recursive: true});
            for (const theme of ['light', 'dark']) {
                await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, theme + ' fits ' + width + 'px');
                await page.screenshot({path: 'output/playwright/gcash-admin-clarity/overview-' + theme + '-' + width + '.png', fullPage: true});
            }
            await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
        }
        await page.emulateMedia({reducedMotion: 'reduce'});
        fs.mkdirSync('.impeccable/review', {recursive: true});
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({width, height: 900});
            for (const [name, panel] of [['Overview', 'overview'], ['Transactions', 'transactions'], ['Closing checks', 'reconciliation'], ['Unresolved exchanges', 'unresolved'], ['Service Fund Movements', 'money']]) {
                await page.getByRole('tab', {name}).click();
                await page.locator('#tab-' + panel).waitFor({state: 'visible'});
                assert.equal(await page.locator('#tab-' + panel + ' .table-ghost-row').count(), 0, 'Reports show only actual rows');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, panel + ' fits ' + width);
                if (panel === 'reconciliation') {
                    assert.equal(await page.locator('#gcash-admin-diff-table th').count(), 8);
                    assert.equal(await page.locator('.gcash-balance-comparison').count(), 2);
                }
                if (panel === 'transactions') {
                    assert.equal(await page.locator('#gcash-admin-history-table th').count(), 9);
                    await page.locator('.gcash-tx-details').first().click();
                    assert.equal(await page.locator('#txDetailModal').isVisible(), true);
                    await page.keyboard.press('Escape');
                }
                if (panel === 'money') {
                    await page.reload();
                    await page.locator('#tab-money').waitFor({state: 'visible'});
                    assert.equal(await page.getByLabel('Session ID', {exact: true}).isVisible(), true);
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    assert.equal(await page.locator('#gcash-tab-money').evaluate(tab => {
                        const bounds = tab.getBoundingClientRect();
                        const nav = tab.parentElement.getBoundingClientRect();
                        return bounds.left >= nav.left && bounds.right <= nav.right;
                    }), true, 'Money tab stays visible after loading at ' + width);
                }
                await page.evaluate(async () => {
                    document.querySelectorAll('.gcash-table-wrap').forEach(element => element.scrollTo(0, 0));
                    window.scrollTo(0, 0);
                    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                });
                if (panel === 'money') await page.screenshot({path: '.impeccable/review/gcash-money-' + width + '-viewport.png'});
                await page.screenshot({path: '.impeccable/review/gcash-' + panel + '-' + width + '.png', fullPage: true});
                fs.mkdirSync('output/playwright/gcash-distill', {recursive: true});
                for (const theme of ['light', 'dark']) {
                    await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, panel + ' fits ' + width + ' in ' + theme);
                    assert.equal((await page.locator('#tab-' + panel).innerText()).includes('Not recorded'), false, 'No repetitive missing-value prose in ' + panel);
                    await page.screenshot({path: 'output/playwright/gcash-distill/' + panel + '-' + theme + '-' + width + '.png', fullPage: true});
                }
                await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
            }
        }
        fixture('cleanup');
        run('src/backend/tests/support/cashier_ui_fixture.php', ['create', database]);
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(await page.locator('.gcash-funds-panel .gcash-empty-state').isVisible(), true);
        fs.mkdirSync('output/playwright/gcash-empty-states', {recursive: true});
        for (const width of [1440, 1024, 768, 390, 320]) {
            await page.setViewportSize({width, height: 900});
            for (const [name, panel] of [['Overview', 'overview'], ['Transactions', 'transactions'], ['Closing checks', 'reconciliation'], ['Unresolved exchanges', 'unresolved'], ['Service Fund Movements', 'money']]) {
                await page.locator('#gcash-tab-' + panel).click();
                await page.locator('#tab-' + panel).waitFor({state: 'visible'});
                if (panel === 'money') {
                    await page.waitForLoadState('networkidle');
                    const help = page.getByRole('button', {name: 'Timestamp & correction rules'});
                    assert.equal(await help.evaluate(button => Boolean(button.closest('#tab-money .pos-panel-header'))), true, 'Rules sit inside the money panel header');
                    await help.focus();
                    await page.locator('#gcash-money-rules').waitFor({state: 'visible'});
                    assert.equal(await page.locator('#gcash-money-rules').evaluate(element => {
                        const bounds = element.getBoundingClientRect();
                        return bounds.left >= 0 && bounds.right <= innerWidth && bounds.top >= 0 && bounds.bottom <= innerHeight;
                    }), true, 'Rules tooltip stays inside the viewport');
                    await page.keyboard.press('Escape');
                    assert.equal(await page.locator('#gcash-money-rules').isVisible(), false, 'Escape dismisses rules');
                    await help.click();
                    assert.equal(await page.locator('#gcash-money-rules').isVisible(), true, 'Rules open on click');
                    await page.keyboard.press('Escape');
                }
                for (const theme of ['light', 'dark']) {
                    await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
                    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Empty ' + panel + ' fits ' + width);
                    const states = page.locator('#tab-' + panel + ' .gcash-empty-state:visible');
                    assert.ok(await states.count() > 0, panel + ' has an empty state');
                    for (const state of await states.all()) {
                        assert.equal(await state.evaluate(element => {
                            const bounds = element.getBoundingClientRect();
                            const wrap = element.closest('.gcash-table-wrap')?.getBoundingClientRect();
                            return bounds.left >= 0 && bounds.right <= innerWidth && element.scrollWidth <= element.clientWidth && (!wrap || Math.abs(bounds.width - wrap.width) < 2);
                        }), true, 'Empty content is fully visible without horizontal scrolling');
                        for (const button of await state.locator('.btn').all()) {
                            assert.ok((await button.boundingBox()).height >= 44, 'Empty-state actions retain touch target height');
                        }
                    }
                    await page.locator('#tab-' + panel).screenshot({path: 'output/playwright/gcash-empty-states/' + panel + '-' + theme + '-' + width + '.png'});
                }
            }
        }
        await page.setViewportSize({width: 1440, height: 1800});
        for (const panel of ['transactions', 'reconciliation', 'unresolved', 'money']) {
            await page.locator('#gcash-tab-' + panel).click();
            await page.locator('#tab-' + panel).waitFor({state: 'visible'});
            if (panel === 'money') await page.waitForLoadState('networkidle');
            await page.evaluate(() => window.scrollTo(0, 0));
            const gap = await page.locator('#tab-' + panel + ' > section').evaluate(element => {
                const workspace = document.getElementById('gcash-admin-workspace');
                return innerHeight - element.getBoundingClientRect().bottom - parseFloat(getComputedStyle(workspace).paddingBottom);
            });
            assert.ok(Math.abs(gap) < 2, panel + ' fills available viewport height; gap=' + gap);
            await page.locator('#tab-' + panel).screenshot({path: 'output/playwright/gcash-empty-states/viewport-' + panel + '-1440.png'});
        }
        await page.locator('#gcash-tab-transactions').click();
        await page.locator('#gcash-admin-history-body').getByRole('link', {name: 'Reset filters'}).click();
        await page.waitForURL(url => url.searchParams.get('tab') === 'transactions' && !url.searchParams.has('period'));
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('#tab-transactions .gcash-empty-state').isVisible(), true, 'Reset preserves the Transactions tab');
        await page.getByRole('tab', {name: 'Overview', exact: true}).click();
        await page.locator('#tab-overview').waitFor({state: 'visible'});
        await page.route('**/gcash_overview_fragments.php*', route => route.abort());
        await page.locator('#btn-apply-filters').click();
        await page.waitForFunction(() => document.querySelector('#gcash-filter-status').textContent.includes('unavailable'));
        assert.equal(await page.locator('.gcash-funds-panel .gcash-empty-state').isVisible(), false, 'Failed loading hides the missing-balance state');
        assert.equal(await page.locator('#gcash-outstanding-reviews [role="alert"]').isVisible(), true, 'Failed loading replaces the all-clear state');
        await page.unroute('**/gcash_overview_fragments.php*');
        await page.locator('#btn-apply-filters').click();
        await page.locator('.gcash-funds-panel .gcash-empty-state').waitFor({state: 'visible'});
        assert.deepEqual(errors, []);
        console.log('GCash cached-script tab regression: passed');
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
