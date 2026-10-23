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
const fixture = action => run('src/backend/tests/support/cashier_ui_fixture.php', [action, database]);
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
            await Promise.all([target.waitForURL(url => !url.search.includes('login=1')), target.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        await login(page, 'admin');
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(await page.locator('#admin-expected-cash').textContent(), 'Not recorded');
        assert.equal(await page.locator('#admin-expected-wallet').textContent(), 'Not recorded');
        const missingEvidence = (await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json()).recorded_balances;
        assert.equal(missingEvidence.service_cash, null);
        assert.equal(missingEvidence.wallet_balance, null);
        assert.equal(run('src/backend/tests/gcash_transaction_integration.php', [database]).trim(), 'GCash MySQL integration: passed');
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        const evidence = (await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json()).recorded_balances;
        assert.equal(evidence.state, 'last_counted');
        assert.equal(evidence.session_status, 'closed');
        assert.equal(evidence.cashier_id, 4);
        assert.ok(evidence.checked_at);
        assert.equal(await page.locator('#admin-cash-state').textContent(), 'Last counted');
        assert.equal(await page.locator('#admin-wallet-state').textContent(), 'Last counted');
        assert.ok((await page.locator('#admin-cash-checked').textContent()).includes(evidence.cashier_name));
        assert.ok((await page.locator('#admin-cash-checked').textContent()).includes(evidence.checked_at_formatted));
        const recordedCash = await page.locator('#admin-expected-cash').textContent();
        assert.equal(await page.getByRole('tab').count(), 4, 'Overview has four task tabs');
        const balances = page.getByRole('tab', {name: 'Balances & Summary'});
        assert.equal(await balances.getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#tab-transactions').isVisible(), false);
        await balances.focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('#tab-transactions').isVisible(), true);
        await page.keyboard.press('End');
        assert.equal(await page.locator('#tab-unresolved').isVisible(), true);
        await page.keyboard.press('Home');
        assert.equal(await page.locator('#tab-overview').isVisible(), true);
        await page.getByRole('tab', {name: 'Transactions & Filters'}).click();
        const search = page.getByRole('searchbox', {name: 'Search transactions'});
        await search.fill('no-such-gcash-reference');
        await page.getByText('No transactions found for the selected filter criteria.').waitFor();
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 0);
        assert.equal(await page.locator('#admin-expected-cash').textContent(), recordedCash, 'Search does not change recorded funds');
        await search.fill('');
        await page.locator('#gcash-admin-history-body tr[data-transaction]').first().waitFor();
        // A delayed filter response preserves a tab chosen while it was loading.
        let releaseFilter;
        const filterGate = new Promise(resolve => { releaseFilter = resolve; });
        await page.route('**/gcash_overview.php?**', async route => {
            const response = await route.fetch();
            await filterGate;
            await route.fulfill({response});
        });
        const requestStarted = page.waitForRequest(request => request.url().includes('search=no-such-gcash-reference'));
        await search.fill('no-such-gcash-reference');
        await requestStarted;
        await page.getByRole('tab', {name: 'Balances & Summary'}).click();
        releaseFilter();
        await page.locator('#gcash-filter-status').filter({hasText: '0 matching transactions'}).waitFor({state: 'attached'});
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'overview');
        await page.unroute('**/gcash_overview.php?**');
        await page.getByRole('tab', {name: 'Transactions & Filters'}).click();
        await search.fill('');
        await page.locator('#gcash-admin-history-body tr[data-transaction]').first().waitFor();
        await page.getByRole('button', {name: /^Cash-In \(/}).click();
        await page.waitForFunction(() => [...document.querySelectorAll('#gcash-admin-history-body tr[data-transaction]')].every(row => JSON.parse(row.dataset.transaction).transaction_type === 'Cash-In'));
        assert.ok(await page.locator('#gcash-admin-history-body tr[data-transaction]').count() > 0);
        await page.getByRole('button', {name: 'Details', exact: true}).first().click();
        const detail = page.getByRole('dialog', {name: 'Transaction Breakdown'});
        await detail.waitFor();
        assert.ok((await detail.textContent()).includes('GCash Service Cash'));
        await page.keyboard.press('Escape');
        assert.equal(await detail.isVisible(), false);
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', {name: 'Export Report'}).click();
        const download = await downloadPromise;
        const csv = fs.readFileSync(await download.path(), 'utf8');
        assert.ok(csv.includes('Cash-In'));
        assert.equal(csv.includes('Cash-Out'), false, 'Export respects type filter');
        await page.getByRole('button', {name: /^All \(/}).click();
        await search.fill('888888888');
        await page.getByRole('status').filter({hasText: '55 matching transactions'}).waitFor();
        const allDownloadPromise = page.waitForEvent('download');
        await page.getByRole('link', {name: 'Export Report'}).click();
        const allDownload = await allDownloadPromise;
        const allCsv = fs.readFileSync(await allDownload.path(), 'utf8');
        assert.equal(allCsv.trim().split('\n').length, 56, 'CSV exports all 55 matching rows plus the header');
        await page.getByRole('link', {name: 'Next', exact: true}).click();
        await page.waitForURL(url => url.search.includes('page=2'));
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 5);
        await page.getByRole('link', {name: 'Reset', exact: true}).click();
        await page.waitForURL(url => !url.search.includes('search='));
        const historyRegion = page.getByRole('region', {name: 'Store-wide transaction history', exact: true});
        assert.equal(await historyRegion.evaluate(region => region.clientHeight <= 600 && region.scrollHeight > region.clientHeight), true, 'Long history scrolls within a bounded, keyboard-accessible region');
        assert.equal(await historyRegion.getAttribute('tabindex'), '0');
        const adminApi = origin + '/components/administrator/gcash_overview_api.php';
        const token = await page.locator('#gcash-admin-workspace').getAttribute('data-csrf');
        assert.equal((await context.request.post(adminApi, {data: {action: 'resolve_exchange', transaction_id: 1}})).status(), 403, 'Resolution requires CSRF protection');
        assert.equal((await context.request.post(adminApi, {headers: {'X-CSRF-Token': token}, data: {action: 'resolve_exchange', transaction_id: 1, confirmed_wallet: false, confirmed_cash: true}})).status(), 400, 'Resolution requires both confirmations');
        await page.getByRole('tab', {name: 'Reconciliation Log'}).click();
        const waive = page.locator('.admin-btn-waive').first();
        await waive.click();
        const waiver = page.getByRole('dialog', {name: 'Waive Closing Discrepancy'});
        await waiver.waitFor();
        await waiver.getByLabel('Reason for waiver (5–500 characters)').fill('Confirmed count reviewed by Administrator');
        await waiver.getByRole('button', {name: 'Confirm Waiver'}).click();
        await waiver.waitFor({state: 'hidden'});
        await page.getByRole('tab', {name: 'Unresolved Activity'}).click();
        const verify = page.getByRole('button', {name: 'Verify & Clear', exact: true}).first();
        await verify.click();
        const resolve = page.getByRole('dialog', {name: 'Verify & Clear Exchange'});
        await resolve.waitFor();
        await resolve.getByLabel('GCash reference number').fill('9999999999988');
        await resolve.getByLabel('Wallet transfer confirmed in the GCash app').check();
        await resolve.getByLabel('Physical service cash exchange confirmed').check();
        const pendingBefore = Number(await page.locator('#admin-nc-count').textContent());
        await resolve.getByRole('button', {name: 'Verify & Clear', exact: true}).click();
        await resolve.waitFor({state: 'hidden'});
        await page.waitForFunction(previous => Number(document.getElementById('admin-nc-count').textContent) < previous, pendingBefore);
        assert.equal(Number(await page.locator('#admin-nc-count').textContent()), pendingBefore - 1);
        await page.getByRole('tab', {name: 'Balances & Summary'}).click();
        fs.mkdirSync('.impeccable/review', {recursive: true});
        for (const [width, name] of [[1440, 'desktop'], [390, 'mobile']]) {
            await page.setViewportSize({width, height: 1080});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, name + ' has no page overflow');
            await page.screenshot({path: '.impeccable/review/' + name + '.png', fullPage: true});
            for (const [tabName, suffix] of [['Transactions & Filters', 'transactions'], ['Reconciliation Log', 'reconciliation'], ['Unresolved Activity', 'unresolved']]) {
                await page.getByRole('tab', {name: tabName}).click();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, name + ' ' + suffix + ' has no page overflow');
                await page.screenshot({path: '.impeccable/review/' + name + '-' + suffix + '.png', fullPage: true});
            }
            await page.getByRole('tab', {name: 'Balances & Summary'}).click();
        }
        assert.deepEqual(errors, []);
        console.log('GCash overview browser: passed');
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
