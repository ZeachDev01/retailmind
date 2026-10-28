'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Integration #326 browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const run = (file, args) => {
    const result = spawnSync(php, [file, ...args], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const fixture = action => run('src/backend/tests/support/integration_326_fixture.php', [action, database]);
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
            await context.clearCookies();
            await target.goto(origin + '/?login=1');
            await target.locator('#landing-login-username').fill('theme_' + role);
            await target.locator('#landing-login-password').fill(data.password);
            await Promise.all([target.waitForURL(url => !url.search.includes('login=1')), target.getByRole('button', {name: 'Log in', exact: true}).click()]).catch(async error => { throw new Error(error.message + '\n' + await target.locator('body').innerText() + '\n' + logs.slice(-3000)); });
        };
        await login(page, 'admin');
        await page.goto(origin + '/components/cashier/refund_corrections.php');
        await page.getByLabel('Refund ID', {exact: true}).fill('50');
        await page.locator('[name="correction_type"]').selectOption('combined');
        await page.locator('[name="inventory_manager_id"]').selectOption('3');
        await page.locator('[name="product_id"]').selectOption('1');
        await page.locator('[name="quantity"]').fill('1');
        await page.locator('[name="cashier_id"]').selectOption('4');
        await page.getByLabel('Cash correction (PHP)', {exact: true}).fill('10');
        await page.getByLabel('Investigation and approved correction').fill('Incorrect refund amount; customer returned PHP 10 in actual cash');
        await page.getByLabel('I approve these exact changes and the responsible people.').check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Approve correction', exact: true}).click()]);
        assert.match(await page.locator('main').innerText(), /Reviewed—correction outstanding/);
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Refund correction page fits viewport');
            await page.screenshot({path: path.join(output, `refund-corrections-${width}.png`), fullPage: true});
        }
        await page.setViewportSize({width: 1440, height: 900});
        // Administrator can approve but cannot execute the Cashier's exact task.
        const rejected = await page.evaluate(async () => {
            const csrf = document.querySelector('[name="csrf_token"]').value;
            const res = await fetch(location.href, {method: 'POST', body: new URLSearchParams({csrf_token: csrf, action: 'cash', review_id: '1', note: 'Attempt forbidden execution'})});
            return res.text();
        });
        assert.match(rejected, /Only the Cashier workspace/);
        await login(page, 'cashier');
        await page.goto(origin + '/components/cashier/refund_corrections.php');
        await page.locator('main details summary').click();
        await page.getByLabel('Actual movement evidence and reason').fill('Customer returned PHP 10 to the open drawer');
        await page.getByLabel('I confirm the exact approved money movement occurred in my open drawer.').check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Record drawer correction', exact: true}).click()]);
        assert.match(await page.locator('main').innerText(), /No correction tasks found/);
        await login(page, 'inventory_manager');
        await page.goto(origin + '/components/cashier/refund_corrections.php');
        await page.locator('main details summary').click();
        await page.getByLabel('Actual movement evidence and reason').fill('Physically checked returned Product; add one unit');
        await page.getByLabel('I confirm the exact approved stock change was physically checked.').check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Record stock correction', exact: true}).click()]);
        assert.match(await page.locator('main').innerText(), /No correction tasks found/);
        await login(page, 'cashier');
        await page.goto(origin + '/components/cashier/gcash.php');
        await page.getByRole('button', {name: 'Review / record return'}).waitFor();
        await page.getByRole('button', {name: 'Move service funds', exact: true}).click();
        await page.getByLabel('Amount (PHP)', {exact: true}).fill('50');
        await page.getByLabel('Reason (min 5 characters)').fill('Actual owner funding received');
        await page.locator('#gcash-funding-form [name="approver_username"]').fill('theme_admin');
        await page.locator('#gcash-funding-form [name="approver_password"]').fill(data.password);
        assert.equal(await page.locator('#gcash-funding-form').evaluate(form => form.checkValidity()), false, 'Receipt confirmation cannot be skipped');
        await page.locator('#gcash-funding-receipt').check();
        const fundingResponse = page.waitForResponse(response => response.url().includes('gcash_api.php') && response.request().postDataJSON()?.action === 'funding_movement');
        await page.getByRole('button', {name: 'Record Movement', exact: true}).click();
        assert.equal((await fundingResponse).status(), 200);
        const cancelled = await page.evaluate(async () => {
            const main = document.getElementById('gcash-workspace');
            const response = await fetch(main.dataset.api, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': main.dataset.csrf}, body: JSON.stringify({action: 'resolve_needs_checking', transaction_id: 50, resolve_action: 'cancel', reason: 'Customer has not repaid wallet funds'})});
            return {status: response.status, body: await response.json()};
        });
        assert.equal(cancelled.status, 422);
        assert.match(cancelled.body.message, /return evidence/);
        await page.getByRole('button', {name: 'Review / record return'}).click();
        await page.locator('#gcash-resolve-action').selectOption('return');
        await page.locator('#gcash-resolve-form [name="returned_wallet"]').fill('40');
        await page.locator('#gcash-resolve-form [name="evidence"]').fill('Actual wallet receipt 326 partial');
        await page.locator('#gcash-resolve-form [name="notes"]').fill('Customer returned PHP 40 to service wallet');
        await page.locator('#gcash-resolve-approver').fill('theme_admin');
        await page.locator('#gcash-resolve-password').fill(data.password);
        await page.setViewportSize({width: 390, height: 900});
        await page.screenshot({path: path.join(output, 'actual-return-mobile.png'), fullPage: true});
        const returnResponse = page.waitForResponse(response => response.url().includes('gcash_api.php') && response.request().postDataJSON()?.action === 'record_return');
        await page.locator('#gcash-resolve-submit').click();
        assert.equal((await returnResponse).status(), 200);
        const replay = await page.evaluate(async body => {
            const main = document.getElementById('gcash-workspace');
            return (await fetch(main.dataset.api, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': main.dataset.csrf}, body: JSON.stringify(body)})).json();
        }, (await returnResponse).request().postDataJSON());
        assert.equal(replay.remaining_obligation, 60, 'Retry reuses the committed return instead of posting it twice');

        await page.waitForFunction(() => document.getElementById('gcash-follow-up').textContent.includes('wallet PHP 60'));
        await login(page, 'admin');
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=money');
        assert.match(await page.locator('#tab-money').innerText(), /Cash-In/);
        assert.match(await page.locator('#tab-money').innerText(), /replenishment/);
        await page.locator('details summary', {hasText: 'Emergency handover'}).click();
        await page.getByLabel('Counted service cash (PHP)', {exact: true}).fill('550');
        await page.getByLabel('Checked wallet balance (PHP)', {exact: true}).fill('840');
        await page.locator('[name="reason"]').fill('Absent controlling Cashier; service stopped after physical checks');
        await page.getByLabel('I checked both balances and confirm this emergency custody change.').check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Confirm handover or stop', exact: true}).click()]);
        assert.match(await page.locator('#gcash-session-status').innerText(), /No active session/);
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=reconciliation');
        const missingChecks = await page.evaluate(async () => {
            const main = document.getElementById('gcash-admin-workspace');
            return (await fetch(main.dataset.api, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': main.dataset.csrf}, body: JSON.stringify({action: 'mark_reviewed', session_id: 1, waiver_notes: 'One note cannot stand in for two physical checks'})})).status;
        });
        assert.equal(missingChecks, 400);
        assert.equal(await page.locator('#admin-waiver-form [type="checkbox"][required]').count(), 2, 'Independent physical checks are required');
        await login(page, 'cashier');
        await page.goto(origin + '/components/cashier/gcash.php');
        await page.getByRole('button', {name: 'Accept this follow-up'}).click();
        await page.getByRole('button', {name: 'Review / record return'}).waitFor();
        assert.equal(await page.getByRole('button', {name: 'Accept this follow-up'}).count(), 0, 'Individual acceptance assigns pending work after session closure');
        await login(page, 'super_admin');
        const deniedPage = await page.goto(origin + '/components/cashier/refund_corrections.php');
        assert.equal(deniedPage.status(), 403, 'Technical Workspace cannot operate refund corrections');
        assert.deepEqual(errors, []);
        console.log('Integration #326 browser: passed (approval/execution roles, receipt confirmation, actual partial return, money history, emergency stop, explicit closing checks, desktop/mobile)');
        console.log('Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
