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
        await login(page, 'cashier');
        await page.goto(origin + '/components/cashier/gcash.php');
        await page.getByText('No transactions on this page.', {exact: true}).waitFor();
        const nav = await page.locator('.sidebar-nav a').allTextContents();
        assert.equal(nav.findIndex(label => label.trim() === 'GCash'), nav.findIndex(label => label.trim() === 'Point of Sale') + 1);
        const types = await page.locator('#gcash-type option').allTextContents();
        assert.deepEqual(types.map(t => t.trim()), ['Cash-In', 'Cash-Out']);

        // Ticket #239: Verify closed status, open responsibility session
        await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();
        assert.equal(await page.locator('#gcash-open-session-btn').isVisible(), true);
        await page.locator('#gcash-open-session-btn').click();
        await page.locator('#gcash-open-cash').fill('1000');
        await page.locator('#gcash-open-wallet').fill('5000');
        await page.locator('#gcash-open-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Active (You)'}).waitFor();
        assert.equal(await page.locator('#gcash-close-session-btn').isVisible(), true);

        const reference = page.locator('#gcash-reference');
        await reference.fill('Reference No.: 0012 345 678901');
        await reference.press('Enter');
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();
        assert.equal(await reference.inputValue(), '0012345678901');
        assert.equal(await page.locator('#gcash-history-body tr').count(), 0, 'Enter must never save');

        // Fractional-peso rejected, preview hidden
        await page.locator('#gcash-amount').fill('125.50');
        await page.getByText('Enter a whole-peso principal amount from 1 to 50,000. Principal excludes the fee.').waitFor();
        assert.equal(await page.locator('#gcash-preview-content').isVisible(), false);
        await page.getByRole('button', {name: 'Save as Pending', exact: true}).click();
        assert.equal(await page.locator('#gcash-history-body tr').count(), 0, 'Invalid principal must not save');

        // Cash-In 1,000 preview
        await page.locator('#gcash-amount').fill('1000');
        await page.locator('#gcash-preview-content').waitFor();
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱1,010.00');
        assert.equal(await page.locator('#preview-wallet-transfer').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '+₱1,010.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '-₱1,000.00');
        assert.equal(await page.locator('#gcash-fee-method-group').isVisible(), false);

        // Cash-Out: Fee via GCash preview
        await page.locator('#gcash-type').selectOption('Cash-Out');
        assert.equal(await page.locator('#gcash-fee-method-group').isVisible(), true);
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱1,010.00');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱1,000.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱1,010.00');

        // Cash-Out: Fee in cash preview
        await page.locator('#gcash-fee-method').selectOption('Fee in cash');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱990.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱1,000.00');

        // 12,345 preview with 123.45 fee
        await page.locator('#gcash-amount').fill('12345');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱123.45');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱123.45');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱12,221.55');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱12,345.00');

        // Save valid Cash-In transaction
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('1000');
        await page.getByRole('button', {name: 'Save as Pending', exact: true}).click();
        await page.locator('#gcash-history-body .gcash-status').filter({hasText: 'Pending'}).waitFor();
        await reference.fill('0012345678901'); await reference.press('Enter');
        await page.locator('#gcash-duplicate').waitFor();
        assert.equal(await page.locator('#gcash-save').isDisabled(), true);
        await page.getByRole('button', {name: 'Verify / Reject', exact: true}).click();
        await page.locator('#gcash-review-note').fill('Matched authorized receiving account records');
        await page.getByRole('button', {name: 'Verify transaction', exact: true}).click();
        assert.equal(await page.locator('#gcash-review-dialog').isVisible(), true, 'Unconfirmed verification stays blocked');
        await page.locator('#gcash-confirmed').check();
        await page.getByRole('button', {name: 'Verify transaction', exact: true}).click();
        await page.locator('#gcash-history-body .gcash-status-verified').waitFor();
        const token = await page.locator('#gcash-workspace').getAttribute('data-csrf');
        const api = origin + '/components/cashier/gcash_api.php';
        const post = (body, csrf = token) => context.request.post(api, {headers: {'X-CSRF-Token': csrf}, data: body});

        // API preview checks (GET and POST)
        const getPreview1k = await (await context.request.get(api + '?action=preview&type=Cash-In&principal=1000')).json();
        assert.equal(getPreview1k.success, true);
        assert.equal(getPreview1k.fee, 10);
        assert.equal(getPreview1k.counter_collect, 1010);
        assert.equal(getPreview1k.net_service_cash, 1010);

        const getPreview12k = await (await context.request.get(api + '?action=preview&type=Cash-Out&principal=12345&fee_method=Fee+in+cash')).json();
        assert.equal(getPreview12k.success, true);
        assert.equal(getPreview12k.fee, 123.45);
        assert.equal(getPreview12k.net_service_cash, -12221.55);

        const postPreview = await (await post({action: 'preview', type: 'Cash-Out', principal: '1000', fee_method: 'Fee via GCash'})).json();
        assert.equal(postPreview.success, true);
        assert.equal(postPreview.customer_transfer, 1010);

        assert.equal((await post({action: 'preview', type: 'Cash-In', principal: '10.50'})).status(), 422);
        assert.equal((await post({action: 'preview', type: 'Payment', principal: '1000'})).status(), 422);
        assert.equal((await post({action: 'preview', type: 'Cash-In', principal: '50001'})).status(), 422);
        assert.equal((await post({action: 'preview', type: 'Cash-In', principal: '0'})).status(), 422);

        assert.equal((await post({action: 'save', reference: '0012345678902', amount: '1', type: 'Payment'}, 'bad')).status(), 403);
        assert.equal((await post({action: 'save', reference: [], amount: '1', type: 'Payment'})).status(), 422);
        const results = await Promise.all([post({action: 'save', reference: '0012345678902', amount: '1', type: 'Cash-Out'}), post({action: 'save', reference: '0012345678902', amount: '1', type: 'Cash-Out'})]);
        assert.deepEqual(results.map(result => result.status()).sort(), [200, 409]);
        // Deterministic camera/OCR; images remain local and do not require physical hardware.
        await page.evaluate(() => {
            Object.defineProperty(navigator.mediaDevices, 'getUserMedia', {configurable: true, value: async () => { throw new DOMException('Camera permission denied', 'NotAllowedError'); }});
        });
        await page.locator('#gcash-camera-open').click();
        await page.getByText('Camera permission denied Manual entry is available.', {exact: true}).waitFor();
        await page.locator('#gcash-camera-close').click();
        await page.evaluate(() => {
            Object.defineProperty(navigator.mediaDevices, 'getUserMedia', {configurable: true, value: async () => {
                const canvas = document.createElement('canvas'); canvas.width = 640; canvas.height = 480;
                canvas.getContext('2d').fillRect(0, 0, 640, 480);
                window.gcashTestStream = canvas.captureStream(5);
                return window.gcashTestStream;
            }});
            window.Tesseract = {createWorker: async () => ({recognize: async () => ({data: {text: 'Reference No. 0012345678903'}}), terminate: async () => { window.gcashWorkerStopped = true; }})};
        });
        await page.locator('#gcash-camera-open').click();
        await page.getByText('Camera ready. Capture the reference label and number.', {exact: true}).waitFor();
        await page.locator('#gcash-capture').click();
        await page.locator('#gcash-ocr-review').waitFor();
        assert.equal(await reference.inputValue(), '0012345678901', 'OCR suggestion must await confirmation');
        assert.equal(await page.locator('#gcash-ocr-candidate').inputValue(), '0012345678903');
        assert.equal(await page.evaluate(() => gcashTestStream.getTracks().every(track => track.readyState === 'ended')), true);
        await page.locator('#gcash-ocr-candidate').fill('0012345678904');
        await page.locator('#gcash-ocr-use').click();
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();
        assert.equal(await reference.inputValue(), '0012345678904', 'Cashier can correct OCR');
        assert.equal(await page.evaluate(() => gcashWorkerStopped), true);
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'No page overflow at ' + width);
        }

        // Ticket #240: Complete Cash-In with accountable movements
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('1000');
        await reference.fill('0012345678910');
        await reference.press('Enter');
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();

        const completeBtn = page.locator('#gcash-complete-cash-in');
        assert.equal(await completeBtn.isVisible(), true);
        assert.equal(await completeBtn.isDisabled(), true);

        await page.locator('#gcash-confirm-wallet').check();
        assert.equal(await completeBtn.isDisabled(), true, 'Requires both confirmations');

        await page.locator('#gcash-confirm-cash').check();
        assert.equal(await completeBtn.isDisabled(), false, 'Both confirmations enable Complete Cash-In');

        await completeBtn.click();
        await page.locator('#gcash-history-body .gcash-status-completed').waitFor();

        const firstRow = page.locator('#gcash-history-body tr').first();
        assert.equal(await firstRow.locator('.gcash-status-completed').textContent(), 'Completed');
        assert.equal(await firstRow.getByRole('button', {name: 'Correct', exact: true}).isDisabled(), true, 'Completed record correction disabled');

        // Ticket #240: Hold as Needs checking with partial movements
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('500');
        await page.locator('#gcash-needs-checking-btn').click();
        await page.locator('#gcash-needs-checking-dialog').waitFor();
        await page.locator('#gcash-nc-notes').fill('Customer paid cash, wallet transfer pending');
        await page.locator('#gcash-nc-cash-collected').check();
        await page.locator('#gcash-nc-wallet-sent').uncheck();
        await page.locator('#gcash-nc-submit').click();
        await page.locator('#gcash-history-body .gcash-status-needs-checking').waitFor();

        // Ticket #240: API tests for record_cash_in
        const staleApi = await post({
            action: 'record_cash_in',
            outcome: 'Completed',
            principal: 1000,
            reference: '0012345678911',
            confirmed_wallet: true,
            confirmed_cash: true,
            preview_fee: 5.00
        });
        assert.equal(staleApi.status(), 422, 'Stale totals rejected');

        const unconfirmedApi = await post({
            action: 'record_cash_in',
            outcome: 'Completed',
            principal: 1000,
            reference: '0012345678912',
            confirmed_wallet: false,
            confirmed_cash: true
        });
        assert.equal(unconfirmedApi.status(), 422, 'Incomplete confirmation rejected');

        const dupRefApi = await post({
            action: 'record_cash_in',
            outcome: 'Completed',
            principal: 1000,
            reference: '0012345678910',
            confirmed_wallet: true,
            confirmed_cash: true
        });
        assert.equal(dupRefApi.status(), 409, 'Duplicate reference rejected');

        // Ticket #239: Reconcile and close session
        await page.locator('#gcash-close-session-btn').click();
        await page.locator('#gcash-close-expected-cash').waitFor();
        assert.equal(await page.locator('#gcash-close-expected-cash').textContent(), '₱3,530.00');
        assert.equal(await page.locator('#gcash-close-expected-wallet').textContent(), '₱3,000.00');
        await page.locator('#gcash-close-cash').fill('3530');
        await page.locator('#gcash-close-wallet').fill('3000');
        await page.locator('#gcash-close-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();

        await page.screenshot({path: path.join(output, 'gcash.png'), fullPage: true});
        const adminContext = await browser.newContext();
        await adminContext.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const adminPage = await adminContext.newPage();
        await login(adminPage, 'admin');
        assert.equal((await adminContext.request.get(api)).status(), 403, 'Admin workspace must not access cashier API');
        assert.equal((await adminContext.request.post(api, {data: {action: 'save'}})).status(), 403);
        assert.deepEqual(errors, []);
        const integration = run('src/backend/tests/gcash_transaction_integration.php', [database]).trim();
        assert.equal(integration, 'GCash MySQL integration: passed');
        console.log(integration);
        console.log('GCash browser: passed (HID, duplicates, pending verification, CSRF, permissions, OCR confirmation, responsive layout)');
        console.log('Screenshot: ' + path.join(output, 'gcash.png'));
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
