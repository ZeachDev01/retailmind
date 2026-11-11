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
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=money');
        assert.equal(await page.getByRole('tab', {name: 'Service Fund Movements', exact: true}).getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#gcash-money-table tbody tr:not(.table-ghost-row)').count(), 2, 'Administrator sees fund movements by every recorder');
        await page.getByLabel('Records', {exact: true}).selectOption('all');
        await Promise.all([page.waitForURL(url => url.searchParams.get('movement_scope') === 'all'), page.getByRole('button', {name: 'Load movements', exact: true}).click()]);
        assert.ok(await page.locator('#gcash-money-table tbody tr:not(.table-ghost-row)').count() > 2, 'Complete money history remains accessible');
        await page.getByLabel('Records', {exact: true}).selectOption('funds');
        await Promise.all([page.waitForURL(url => url.searchParams.get('movement_scope') === 'funds'), page.getByRole('button', {name: 'Load movements', exact: true}).click()]);
        await page.getByRole('tab', {name: 'Overview', exact: true}).click();
        const cashierContext = await browser.newContext();
        const cashierPage = await cashierContext.newPage();
        await login(cashierPage, 'cashier');
        await cashierPage.goto(origin + '/components/cashier/gcash.php');
        await cashierPage.getByRole('tab', {name: 'Service Fund Movements', exact: true}).click();
        await cashierPage.locator('#gcash-funds-body tr').waitFor();
        assert.equal(await cashierPage.locator('#gcash-funds-body tr').count(), 1, 'Cashier sees only own recorded fund movements');
        const ownResponse = await cashierContext.request.get(origin + '/components/cashier/gcash_api.php?action=fund_movements&recorded_by=1');
        const ownData = await ownResponse.json();
        assert.equal(ownData.movements.length, 1, 'Client cannot request another recorder through the cashier endpoint');
        assert.equal(ownData.movements[0].amount, 100);
        for (const width of [1440, 768, 390]) {
            await cashierPage.setViewportSize({width, height: 900});
            assert.equal(await cashierPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Cashier fund history fits ' + width);
            await cashierPage.waitForFunction(() => {
                const tab = document.getElementById('tab-funds');
                const bounds = tab.getBoundingClientRect(), nav = tab.parentElement.getBoundingClientRect();
                return bounds.left >= nav.left - 1 && bounds.right <= nav.right + 1 && tab.scrollWidth <= tab.clientWidth;
            });
            await cashierPage.screenshot({path: path.join(output, 'cashier-funds-' + width + '.png'), fullPage: true});
        }
        const posted = await cashierContext.request.post(origin + '/components/cashier/gcash_api.php', {
            headers: {'X-CSRF-Token': await cashierPage.locator('#gcash-workspace').getAttribute('data-csrf')},
            data: {action: 'funding_movement', movement_type: 'replenishment', fund_type: 'cash', direction: 'in', amount: 25,
                source_destination: 'Store counter', reason: '', receipt_confirmed: true,
                approver_username: 'theme_admin', approver_password: data.password}
        });
        assert.equal(posted.status(), 200, 'Cashier can record routine cash funding without a reason or invented evidence');
        const savedMovement = await posted.json();
        assert.equal(savedMovement.success, true);
        await cashierPage.getByRole('tab', {name: 'History', exact: true}).click();
        await cashierPage.getByRole('tab', {name: 'Service Fund Movements', exact: true}).click();
        await cashierPage.waitForFunction(() => document.querySelectorAll('#gcash-funds-body tr').length === 2);
        await cashierContext.close();
        await page.getByRole('tab', {name: 'Service Fund Movements', exact: true}).click();
        await page.waitForFunction(() => document.querySelectorAll('#gcash-money-table tbody tr:not(.table-ghost-row)').length === 3);
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=overview&session_id=999');
        await page.getByRole('tab', {name: 'Service Fund Movements', exact: true}).click();
        await page.waitForURL(url => url.searchParams.get('tab') === 'money' && !url.searchParams.has('session_id'));
        assert.equal(await page.locator('#gcash-money-table tbody tr:not(.table-ghost-row)').count(), 3, 'Opening fund movements clears stale session restrictions');
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Admin fund history fits ' + width);
            await page.waitForFunction(() => {
                const tab = document.getElementById('gcash-tab-money');
                const bounds = tab.getBoundingClientRect(), nav = tab.parentElement.getBoundingClientRect();
                return bounds.left >= nav.left - 1 && bounds.right <= nav.right + 1;
            });
            await page.screenshot({path: path.join(output, 'admin-funds-' + width + '.png'), fullPage: true});
        }
        assert.deepEqual(errors, []);
        console.log('GCash fund movements integration: passed (all recorders, own records, endpoint authorization, full history, stale filters, responsive layout)');
        console.log('Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
