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
        const nav = await page.locator('.sidebar-nav a').allTextContents();
        assert.equal(nav.findIndex(label => label.trim() === 'GCash'), nav.findIndex(label => label.trim() === 'Point of Sale') + 1);
        const gcashNav = page.locator('.sidebar-nav').getByRole('link', {name: 'GCash', exact: true});
        const brandmark = gcashNav.locator('img');
        assert.equal(await brandmark.count(), 1, 'GCash navigation displays the brandmark');
        assert.equal(await brandmark.getAttribute('alt'), '', 'Link text supplies the accessible name');
        const logoResponse = await page.request.get(new URL(await brandmark.getAttribute('src'), page.url()).href);
        assert.equal(logoResponse.ok(), true, 'GCash brandmark is served locally');
        assert.match(logoResponse.headers()['content-type'], /image\/svg\+xml/);
        assert.equal(await brandmark.evaluate(image => image.complete && image.naturalWidth > 0), true, 'GCash SVG loads');

        const unavailableMessage = 'GCash records are unavailable. Try again later.';
        await page.route('**/gcash_api.php?action=session_status', route => route.fulfill({
            status: 503,
            contentType: 'application/json',
            body: JSON.stringify({success: false, message: unavailableMessage})
        }));
        await page.reload();
        await page.locator('.rm-toast.error').filter({hasText: unavailableMessage}).waitFor();
        assert.equal(await page.locator('#gcash-session-panel').isVisible(), false);
        assert.equal(await page.locator('#gcash-balances').isVisible(), true);
        assert.equal(await page.locator('#gcash-expected-cash').textContent(), '—');
        assert.match(await page.locator('#gcash-balances-message').textContent(), /Balances unavailable/);
        await page.unroute('**/gcash_api.php?action=session_status');
        await page.reload();
        await page.locator('#gcash-session-status').filter({hasText: /Closed|Active|Under control/}).waitFor();
        assert.equal(await page.locator('#gcash-session-panel').isVisible(), true);
        console.log('GCash unavailable toast and recovery: passed');

        const overviewPage = await context.newPage();
        overviewPage.on('pageerror', error => errors.push(error.message));
        await overviewPage.goto(origin + '/components/cashier/dashboard.php');
        const cashierTab = overviewPage.getByRole('tab', {name: 'Cashier', exact: true});
        const gcashTab = overviewPage.getByRole('tab', {name: 'GCash', exact: true});
        assert.equal(await cashierTab.getAttribute('aria-selected'), 'true');
        assert.equal(await overviewPage.locator('#panel-gcash').isVisible(), false);
        await cashierTab.focus();
        await overviewPage.keyboard.press('ArrowRight');
        await overviewPage.locator('#gcash-overview-status').filter({hasText: /^$/}).waitFor({state: 'attached'});
        assert.equal(await gcashTab.getAttribute('aria-selected'), 'true');
        assert.equal(await overviewPage.locator('#panel-cashier').isVisible(), false);
        await overviewPage.keyboard.press('Home');
        assert.equal(await cashierTab.getAttribute('aria-selected'), 'true');
        await overviewPage.route('**/gcash_api.php?action=overview', route => route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({success: false, message: 'GCash overview is unavailable. Try again later.'})}));
        await gcashTab.click();
        await overviewPage.getByRole('status').filter({hasText: 'GCash overview is unavailable. Try again later.'}).waitFor();
        await overviewPage.unroute('**/gcash_api.php?action=overview');
        const tabOverview = {click: async () => {
            await gcashTab.click();
            await overviewPage.locator('#gcash-overview-status').filter({hasText: /^$/}).waitFor({state: 'attached'});
        }};
        await tabOverview.click();
        assert.equal(await overviewPage.locator('#gcash-overview-expected-cash').textContent(), '₱0.00');
        assert.equal(await overviewPage.locator('#gcash-overview-expected-wallet').textContent(), '₱0.00');
        assert.equal(await overviewPage.locator('#gcash-overview-nc-count').textContent(), '0 open');
        assert.equal(await page.locator('#tab-overview').count(), 0);
        assert.equal(await page.locator('.cashier-topbar').count(), 0);
        const tabNewTx = page.locator('#tab-new-transaction');
        const tabHistory = page.locator('#tab-history');
        assert.equal(await tabNewTx.getAttribute('aria-selected'), 'true');
        await tabNewTx.focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await tabHistory.getAttribute('aria-selected'), 'true');
        await page.keyboard.press('ArrowRight');
        assert.equal(await tabNewTx.getAttribute('aria-selected'), 'true');
        await overviewPage.locator('#gcash-overview-action-cash-out').click();
        await overviewPage.waitForFunction(() => document.getElementById('gcash-type')?.value === 'Cash-Out');
        assert.equal(await overviewPage.locator('#gcash-type').inputValue(), 'Cash-Out');
        await overviewPage.goto(origin + '/components/cashier/dashboard.php#gcash');
        await overviewPage.locator('#gcash-overview-status').filter({hasText: /^$/}).waitFor({state: 'attached'});
        await page.goto(origin + '/components/cashier/gcash.php?type=cash-in');
        assert.equal(await page.locator('#gcash-type').inputValue(), 'Cash-In');
        const types = await page.locator('#gcash-type option').allTextContents();
        assert.deepEqual(types.map(t => t.trim()), ['Cash-In', 'Cash-Out']);

        // Ticket #239: Verify closed status, open responsibility session
        await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();
        assert.equal(await page.locator('#panel-new-transaction #gcash-balances').isVisible(), true);
        assert.match(await page.locator('#gcash-balances-message').textContent(), /Open GCash/);
        assert.equal(await page.locator('#gcash-open-session-btn').isVisible(), true);
        assert.equal(await page.locator('#gcash-cash-in-confirm-group').isVisible(), false, 'No zero-amount confirmations');
        assert.equal(await page.locator('#gcash-complete-cash-in').isDisabled(), true, 'Closed session cannot complete transactions');
        await page.locator('#gcash-open-session-btn').click();
        await page.locator('#gcash-open-cash').fill('1000');
        await page.locator('#gcash-open-wallet').fill('5000');
        await page.locator('#gcash-open-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Active (You)'}).waitFor();
        assert.equal(await page.locator('#gcash-close-session-btn').isVisible(), true);
        assert.equal(await page.locator('#gcash-expected-cash').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#gcash-expected-wallet').textContent(), '₱5,000.00');
        assert.equal(await page.locator('#gcash-balances').isVisible(), true);
        assert.match(await page.locator('#gcash-balances').textContent(), /Physical Service Cash/);
        assert.match(await page.locator('#gcash-balances').textContent(), /GCash App Wallet/);
        assert.doesNotMatch(await page.locator('#gcash-cash-checked').textContent(), /Invalid Date|Last check: —/);
        assert.equal(await page.locator('#gcash-projected-balances').isVisible(), false);

        // Ticket #243: Verify Overview expected balances update after opening session
        await tabOverview.click();
        await overviewPage.locator('#gcash-overview-expected-cash').filter({hasText: '₱1,000.00'}).waitFor();
        assert.equal(await overviewPage.locator('#gcash-overview-expected-cash').textContent(), '₱1,000.00');
        assert.equal(await overviewPage.locator('#gcash-overview-expected-wallet').textContent(), '₱5,000.00');
        assert.equal((await overviewPage.locator('#gcash-overview-cash-checked').textContent()).includes('Last actual check:'), true);
        await tabNewTx.click();

        assert.equal(await page.locator('#gcash-preview-empty').isVisible(), true);
        assert.equal(await page.locator('#gcash-review-type').textContent(), 'Cash-In');
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            await page.screenshot({path: path.join(output, 'gcash-empty-' + width + '.png'), fullPage: true});
        }
        await page.setViewportSize({width: 1440, height: 900});
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
        assert.equal(await page.locator('#gcash-projected-balances').isVisible(), false);
        await page.getByRole('button', {name: 'Save as Pending', exact: true}).click();
        assert.equal(await page.locator('#gcash-history-body tr').count(), 0, 'Invalid principal must not save');

        // Cash-In 1,000 preview
        await page.locator('#gcash-amount').fill('1000');
        await page.locator('#gcash-preview-content').waitFor();
        assert.equal(await page.locator('#gcash-projected-balances').isVisible(), false, 'Balance details start collapsed');
        await page.getByText('Balance details', {exact: true}).click();
        assert.equal(await page.locator('#gcash-projected-balances').isVisible(), true);
        assert.equal(await page.locator('#gcash-projected-wallet').textContent(), '₱4,000.00');
        assert.equal(await page.locator('#gcash-projected-cash').textContent(), '₱2,010.00');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#gcash-preview-empty').isVisible(), false);
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱1,010.00');
        assert.equal(await page.locator('#preview-wallet-transfer').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '+₱1,010.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '-₱1,000.00');
        assert.equal(await page.locator('#gcash-fee-method-group').isVisible(), false);
        await page.getByText('Balance details', {exact: true}).click();
        const checkSummary = async (labels, mode) => {
            const instructions = page.locator('#gcash-preview-instructions');
            assert.equal(await instructions.isVisible(), false, 'Detailed instructions stay collapsed until requested');
            await page.getByText('Exchange instructions', {exact: true}).click();
            assert.equal(await instructions.isVisible(), true, 'Detailed instructions remain available');
            await page.getByText('Exchange instructions', {exact: true}).click();
            assert.deepEqual(await page.locator('.gcash-preview-grid .gcash-preview-item:visible .gcash-preview-label').allTextContents(), labels);
            assert.equal(await page.locator('.gcash-preview-grid .gcash-preview-item:visible .gcash-preview-label .bi[aria-hidden="true"]').count(), labels.length, 'Every movement and fee has a decorative icon');
            for (const field of ['gcash-type', 'gcash-fee-method']) {
                assert.equal(await page.locator('label[for="' + field + '"] .bi[aria-hidden="true"]').count(), 1);
            }
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Summary has no overflow at ' + width);
                await page.locator('.gcash-review-panel').screenshot({path: path.join(output, 'gcash-summary-' + mode + '-' + width + '.png')});
            }
            await page.setViewportSize({width: 1440, height: 900});
        };
        await checkSummary(['Service fee · included in cash collected', 'Collect cash from customer', 'Send GCash to customer'], 'cash-in');

        // Cash-Out: Fee via GCash preview
        await page.locator('#gcash-type').selectOption('Cash-Out');
        assert.equal(await page.locator('#gcash-fee-method-group').isVisible(), true);
        assert.equal(await page.locator('#gcash-review-type').textContent(), 'Cash-Out');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱1,010.00');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱0.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱1,000.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱1,010.00');
        assert.equal(await page.locator('#gcash-projected-wallet').textContent(), '₱6,010.00');
        assert.equal(await page.locator('#gcash-projected-cash').textContent(), '₱0.00');
        await checkSummary(['Service fee · included in GCash received', 'Receive GCash from customer', 'Pay cash to customer'], 'cash-out-gcash-fee');

        // Cash-Out: Fee in cash preview
        await page.locator('#gcash-fee-method').selectOption('Fee in cash');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-customer-transfer').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱10.00');
        assert.equal(await page.locator('#preview-cash-payout').textContent(), '₱1,000.00');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱990.00');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱1,000.00');
        assert.equal(await page.locator('#gcash-projected-wallet').textContent(), '₱6,000.00');
        assert.equal(await page.locator('#gcash-projected-cash').textContent(), '₱10.00');
        await checkSummary(['Service fee · paid in cash', 'Receive GCash from customer', 'Collect cash fee from customer', 'Pay cash to customer'], 'cash-out-cash-fee');

        // 12,345 preview with 123.45 fee
        await page.locator('#gcash-amount').fill('12345');
        assert.equal(await page.locator('#preview-fee').textContent(), '₱123.45');
        assert.equal(await page.locator('#preview-counter-collect').textContent(), '₱123.45');
        assert.equal(await page.locator('#preview-net-cash').textContent(), '-₱12,221.55');
        assert.equal(await page.locator('#preview-net-wallet').textContent(), '+₱12,345.00');
        assert.equal(await page.locator('#gcash-projected-cash').textContent(), '-₱11,221.55');
        assert.equal(await page.locator('#gcash-expected-cash').textContent(), '₱1,000.00', 'Preview must not move funds');

        // Save valid Cash-In transaction
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('1000');
        await page.getByRole('button', {name: 'Save as Pending', exact: true}).click();
        await tabHistory.click();
        await page.locator('#gcash-history-body .gcash-status').filter({hasText: 'Pending'}).waitFor();
        await tabNewTx.click();
        await reference.fill('0012345678901'); await reference.press('Enter');
        await page.locator('#gcash-duplicate').waitFor();
        assert.equal(await page.locator('#gcash-save').isDisabled(), true);
        await tabHistory.click();
        await page.getByRole('button', {name: 'Verify / Reject', exact: true}).click();
        await page.locator('#gcash-review-note').fill('Matched authorized receiving account records');
        await page.getByRole('button', {name: 'Verify transaction', exact: true}).click();
        assert.equal(await page.locator('#gcash-review-dialog').isVisible(), true, 'Unconfirmed verification stays blocked');
        await page.locator('#gcash-confirmed').check();
        await page.getByRole('button', {name: 'Verify transaction', exact: true}).click();
        await page.locator('#gcash-history-body .gcash-status-verified').waitFor();
        await tabNewTx.click();
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
        await tabNewTx.click();
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
        await page.locator('#gcash-amount').fill('1001');
        assert.equal(await page.locator('#gcash-confirm-wallet').isChecked(), false, 'Changing amount resets wallet confirmation');
        assert.equal(await page.locator('#gcash-confirm-cash').isChecked(), false, 'Changing amount resets cash confirmation');
        assert.equal(await completeBtn.isDisabled(), true);
        await page.locator('#gcash-amount').fill('');
        assert.equal(await page.locator('#gcash-cash-in-confirm-group').isVisible(), false, 'Clearing amount hides confirmations');
        await page.locator('#gcash-amount').fill('1000');
        await page.locator('#gcash-confirm-wallet').check();
        await page.locator('#gcash-confirm-cash').check();
        await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
        await page.locator('.rm-toast').last().waitFor({state: 'detached'});
        await page.getByText('Balance details', {exact: true}).click();
        assert.equal(await page.locator('#preview-net-cash').isVisible(), true);
        await page.getByText('Balance details', {exact: true}).click();
        assert.equal(await page.locator('#preview-net-cash').isVisible(), false);
        const leftScroll = page.locator('.gcash-entry-scroll');
        for (const width of [1440, 1280, 390]) {
            await page.setViewportSize({width, height: 650});
            for (const selector of ['.gcash-entry-scroll', '.gcash-exchange-review']) {
                assert.equal(await page.locator(selector).evaluate(el => getComputedStyle(el).overflowY), 'visible', 'Panels use page scrolling at ' + width);
                assert.equal(await page.locator(selector).evaluate(el => el.scrollHeight <= el.clientHeight + 1), true, 'Panel content remains fully accessible at ' + width);
            }
        }
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            assert.equal(await page.locator('#gcash-balances').evaluate(section =>
                getComputedStyle(section).backgroundColor === getComputedStyle(document.getElementById('gcash-session-panel')).backgroundColor
            ), true, 'Balance cards use the workspace surface in ' + theme);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'GCash entry fits ' + theme + ' at ' + width);
                if (width < 1101) assert.equal(await leftScroll.evaluate(el => getComputedStyle(el).overflowY), 'visible', 'Panels use page scrolling');
                await page.evaluate(() => { window.scrollTo(0, 0); document.activeElement?.blur(); });
                await page.screenshot({path: path.join(output, 'gcash-entry-' + theme + '-' + width + '.png'), fullPage: true});
            }
        }
        await page.evaluate(() => document.documentElement.dataset.theme = 'light');
        await page.setViewportSize({width: 1440, height: 900});


        assert.equal(await page.locator('#gcash-print-receipt').isChecked(), false, 'Automatic printing defaults unchecked');
        await page.evaluate(() => { window.receiptPrints = 0; window.print = () => { window.receiptPrints++; throw new Error('Printer unavailable'); }; });
        await page.locator('#gcash-print-receipt').check();
        const completionResponse = page.waitForResponse(response => response.url().includes('gcash_api.php') && response.request().method() === 'POST');
        await completeBtn.click();
        const completedReceiptId = (await (await completionResponse).json()).receipt_id;
        assert.ok(completedReceiptId, 'Completion returns saved-receipt locator');
        await page.locator('#receiptModal .gcash-service-receipt').waitFor();
        await page.waitForFunction(() => window.receiptPrints === 1);
        const savedPaper = await page.locator('#receiptModal .gcash-service-receipt').innerText();
        assert.match(savedPaper, /Cash collected\s+₱1,010.00/);
        assert.match(savedPaper, /Wallet sent to customer\s+₱1,000.00/);
        assert.match(savedPaper, /0012345678910/);
        assert.doesNotMatch(savedPaper, /Change due|Service cash|Wallet balance|notes/i);
        assert.equal(await page.locator('#receiptModal .gcash-receipt-reference svg').getAttribute('aria-label'), 'Barcode 0012345678910');
        assert.equal(await page.locator('#saleReceiptTitle').evaluate(el => el === document.activeElement), true, 'Receipt heading receives focus');
        await page.locator('.rm-toast').filter({hasText: 'Printing could not start'}).waitFor();
        await page.evaluate(() => { window.print = () => { window.receiptPrints++; }; });
        await page.getByRole('button', {name: 'Print Receipt', exact: true}).click();
        assert.equal(await page.evaluate(() => window.receiptPrints), 2, 'Manual retry remains available');
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#gcash-amount').evaluate(el => el === document.activeElement), true, 'Close returns focus to ready GCash input');
        assert.equal(await page.locator('#gcash-print-receipt').isChecked(), false, 'Next completion starts unchecked');
        const exchangeBeforeReopen = await (await context.request.get(api + '?action=overview')).json();
        await page.evaluate(id => window.viewReceipt(id), completedReceiptId);
        assert.equal(await page.locator('#receiptModal .gcash-service-receipt').innerText(), savedPaper, 'Reopening reads the same saved receipt');
        assert.equal(await page.evaluate(() => window.receiptPrints), 2, 'Reopening never automatically prints');
        await page.getByRole('button', {name: 'Return to GCash', exact: true}).click();
        assert.deepEqual(await (await context.request.get(api + '?action=overview')).json(), exchangeBeforeReopen, 'Receipt reopening/printing never repost financial movements');

        await tabHistory.click();
        await page.locator('#gcash-history-body .gcash-status-completed').waitFor();

        const firstRow = page.locator('#gcash-history-body tr').first();
        assert.equal(await firstRow.locator('.gcash-status-completed').textContent(), 'Completed');
        assert.equal(await firstRow.getByRole('button', {name: 'Correct', exact: true}).isDisabled(), false, 'Completed record correction enabled');

        // Ticket #243: Verify Overview metrics after Cash-In
        await tabOverview.click();
        await overviewPage.locator('#gcash-metric-cash-in-principal').filter({hasText: '₱1,000.00'}).waitFor();
        assert.equal(await overviewPage.locator('#gcash-metric-fees-cash').textContent(), '₱10.00');

        // Ticket #240: Hold as Needs checking with partial movements
        await tabNewTx.click();
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('500');
        await page.locator('#gcash-needs-checking-btn').click();
        await page.locator('#gcash-needs-checking-dialog').waitFor();
        await page.locator('#gcash-nc-notes').fill('Customer paid cash, wallet transfer pending');
        await page.locator('#gcash-nc-cash-collected').check();
        await page.locator('#gcash-nc-wallet-sent').uncheck();
        await page.locator('#gcash-nc-submit').click();
        await tabHistory.click();
        await page.locator('#gcash-history-body .gcash-status-needs-checking').waitFor();

        // Ticket #243: Verify Needs checking displayed in Overview
        await tabOverview.click();
        await overviewPage.locator('#gcash-overview-nc-count').filter({hasText: '1 open'}).waitFor();
        const ncCard = overviewPage.locator('#gcash-overview-nc-list .gcash-nc-card').first();
        await ncCard.waitFor();
        assert.equal((await ncCard.textContent()).includes('Cash-In'), true);
        assert.equal((await ncCard.textContent()).includes('₱500.00'), true);
        assert.equal((await ncCard.textContent()).includes('₱10.00 (cash)'), true);
        assert.equal(await ncCard.getByRole('link', {name: 'Review in GCash', exact: true}).isVisible(), true);

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
        assert.equal(await page.evaluate(() => window.receiptPrints), 2, 'Failed completions never print');

        // Ticket #241: Cash-Out Fee via GCash in browser
        await tabNewTx.click();
        await page.locator('#gcash-type').selectOption('Cash-Out');
        await page.locator('#gcash-fee-method').selectOption('Fee via GCash');
        await page.locator('#gcash-amount').fill('1000');
        await reference.fill('0012345678920');
        await reference.press('Enter');
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();

        const completeOutBtn = page.locator('#gcash-complete-cash-out');
        assert.equal(await completeOutBtn.isVisible(), true);
        assert.equal(await completeOutBtn.isDisabled(), true);

        await page.locator('#gcash-confirm-wallet').check();
        assert.equal(await completeOutBtn.isDisabled(), true);
        await page.locator('#gcash-confirm-cash').check();
        assert.equal(await completeOutBtn.isDisabled(), false);

        await completeOutBtn.click();
        await tabHistory.click();
        const coGkRow = page.locator('#gcash-history-body tr', {hasText: '0012345678920'});
        await coGkRow.waitFor();
        assert.equal((await coGkRow.textContent()).includes('Fee: ₱10.00 (wallet)'), true);
        assert.equal((await coGkRow.textContent()).includes('Cash: -₱1,000.00 · Wallet: +₱1,010.00'), true);

        // Ticket #243: Overview metrics check after Fee via GCash Cash-Out
        await tabOverview.click();
        await overviewPage.locator('#gcash-metric-cash-out-principal').filter({hasText: '₱1,000.00'}).waitFor();
        assert.equal(await overviewPage.locator('#gcash-metric-fees-wallet').textContent(), '₱10.00');

        // Ticket #241: Cash-Out Fee in cash in browser
        await tabNewTx.click();
        await page.locator('#gcash-type').selectOption('Cash-Out');
        await page.locator('#gcash-fee-method').selectOption('Fee in cash');
        await page.locator('#gcash-amount').fill('1000');
        await reference.fill('0012345678921');
        await reference.press('Enter');
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();

        await page.locator('#gcash-confirm-wallet').check();
        await page.locator('#gcash-confirm-cash').check();
        await completeOutBtn.click();
        await tabHistory.click();
        const coCkRow = page.locator('#gcash-history-body tr', {hasText: '0012345678921'});
        await coCkRow.waitFor();
        assert.equal((await coCkRow.textContent()).includes('Fee: ₱10.00 (cash)'), true);
        assert.equal((await coCkRow.textContent()).includes('Cash: -₱990.00 · Wallet: +₱1,000.00'), true);

        // Ticket #243: Overview metrics check after Fee in cash Cash-Out
        await tabOverview.click();
        await overviewPage.locator('#gcash-metric-cash-out-principal').filter({hasText: '₱2,000.00'}).waitFor();
        assert.equal(await overviewPage.locator('#gcash-metric-fees-cash').textContent(), '₱20.00');

        // Ticket #241: Cash-Out Needs checking in browser
        await tabNewTx.click();
        await page.locator('#gcash-type').selectOption('Cash-Out');
        await page.locator('#gcash-fee-method').selectOption('Fee via GCash');
        await page.locator('#gcash-amount').fill('500');
        await page.locator('#gcash-needs-checking-btn').click();
        await page.locator('#gcash-needs-checking-dialog').waitFor();
        await page.locator('#gcash-nc-notes').fill('Customer sent 510 wallet transfer, cash drawer out of cash');
        await page.locator('#gcash-nc-wallet-sent').check();
        await page.locator('#gcash-nc-cash-collected').uncheck();
        await page.locator('#gcash-nc-submit').click();
        await tabHistory.click();
        const ncRow = page.locator('#gcash-history-body tr', {hasText: 'Customer sent 510 wallet transfer'});
        await ncRow.waitFor();

        // Ticket #243: Overview Needs checking count updated
        await tabOverview.click();
        await overviewPage.locator('#gcash-overview-nc-count').filter({hasText: '2 open'}).waitFor();

        for (const width of [1440, 390, 320]) {
            await overviewPage.setViewportSize({width, height: 900});
            await cashierTab.click();
            assert.equal(await overviewPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Cashier overview fits at ' + width);
            await overviewPage.screenshot({path: path.join(output, 'cashier-dashboard-' + width + '.png'), fullPage: true});
            await tabOverview.click();
            assert.equal(await overviewPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Cashier GCash overview fits at ' + width);
            await overviewPage.screenshot({path: path.join(output, 'cashier-overview-' + width + '.png'), fullPage: true});
        }
        // Ticket #241: API tests for record_cash_out
        const staleCoApi = await post({
            action: 'record_cash_out',
            outcome: 'Completed',
            principal: 1000,
            fee_method: 'Fee via GCash',
            reference: '0012345678922',
            confirmed_wallet: true,
            confirmed_cash: true,
            preview_fee: 5.00
        });
        assert.equal(staleCoApi.status(), 422, 'Stale preview fee rejected');

        const unconfirmedCoApi = await post({
            action: 'record_cash_out',
            outcome: 'Completed',
            principal: 1000,
            fee_method: 'Fee via GCash',
            reference: '0012345678923',
            confirmed_wallet: false,
            confirmed_cash: true
        });
        assert.equal(unconfirmedCoApi.status(), 422, 'Incomplete confirmation rejected');

        const insufficientCashApi = await post({
            action: 'record_cash_out',
            outcome: 'Completed',
            principal: 2000,
            fee_method: 'Fee via GCash',
            reference: '0012345678924',
            confirmed_wallet: true,
            confirmed_cash: true
        });
        assert.equal(insufficientCashApi.status(), 422, 'Insufficient service cash rejected');

        const dupCoRefApi = await post({
            action: 'record_cash_out',
            outcome: 'Completed',
            principal: 1000,
            fee_method: 'Fee via GCash',
            reference: '0012345678920',
            confirmed_wallet: true,
            confirmed_cash: true
        });
        assert.equal(dupCoRefApi.status(), 409, 'Duplicate reference rejected');

        // Ticket #239: Reconcile and close session
        await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
        await page.locator('#gcash-close-session-btn').click();
        await page.locator('#gcash-close-expected-cash').waitFor();
        assert.equal(await page.locator('#gcash-close-expected-cash').textContent(), '₱1,540.00');
        assert.equal(await page.locator('#gcash-close-expected-wallet').textContent(), '₱5,520.00');
        await page.locator('#gcash-close-cash').fill('1540');
        await page.locator('#gcash-close-wallet').fill('5520');
        await page.locator('#gcash-close-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();

        // Ticket #242: Session 2 - Funding movements, linked corrections, resolving needs checking, discrepancy waiver
        await page.locator('#gcash-open-session-btn').click();
        await page.locator('#gcash-open-cash').fill('1000');
        await page.locator('#gcash-open-wallet').fill('2000');
        await page.locator('#gcash-open-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Active (You)'}).waitFor();

        // 1. Funding Movement: Replenishment (Cash in 500)
        assert.equal(await page.locator('#gcash-funding-btn').isVisible(), true);
        await page.locator('#gcash-funding-btn').click();
        await page.locator('#gcash-funding-dialog').waitFor();
        await page.locator('#gcash-funding-type').selectOption('replenishment');
        await page.locator('#gcash-funding-fund-type').selectOption('cash');
        await page.locator('#gcash-funding-direction').selectOption('in');
        await page.locator('#gcash-funding-amount').fill('500');
        await page.locator('#gcash-funding-reason').fill('Replenish service cash drawer');
        await page.locator('#gcash-funding-approver').fill('theme_admin');
        await page.locator('#gcash-funding-password').fill(data.password);
        await page.locator('#gcash-funding-submit').click();
        await page.locator('#gcash-funding-dialog').waitFor({state: 'hidden'});

        // API tests for funding movement refusals
        const badAuthFunding = await post({
            action: 'funding_movement',
            movement_type: 'replenishment',
            fund_type: 'cash',
            direction: 'in',
            amount: 100,
            reason: 'Test refusal with bad pass',
            approver_username: 'theme_admin',
            approver_password: 'wrong_password'
        });
        assert.equal(badAuthFunding.status(), 403, 'Invalid admin password rejected');

        const excessFunding = await post({
            action: 'funding_movement',
            movement_type: 'withdrawal',
            fund_type: 'cash',
            direction: 'out',
            amount: 999999,
            reason: 'Withdraw more than available cash',
            approver_username: 'theme_admin',
            approver_password: data.password
        });
        assert.equal(excessFunding.status(), 422, 'Insufficient balance rejected');

        // 2. Needs checking: Cash-In 300, cash collected, wallet pending
        await tabNewTx.click();
        await page.locator('#gcash-type').selectOption('Cash-In');
        await page.locator('#gcash-amount').fill('300');
        await page.locator('#gcash-needs-checking-btn').click();
        await page.locator('#gcash-needs-checking-dialog').waitFor();
        await page.locator('#gcash-nc-notes').fill('Cash received at counter, wallet pending transfer');
        await page.locator('#gcash-nc-cash-collected').check();
        await page.locator('#gcash-nc-wallet-sent').uncheck();
        await page.locator('#gcash-nc-submit').click();
        await page.locator('#gcash-needs-checking-dialog').waitFor({state: 'hidden'});
        await tabHistory.click();
        await page.locator('#gcash-history-body .gcash-status-needs-checking').first().waitFor();

        const s2NcRow = page.locator('#gcash-history-body tr').first();
        assert.equal(await s2NcRow.locator('.gcash-status-needs-checking').textContent(), 'Needs checking');

        // Resolve Needs checking via dialog -> complete
        await s2NcRow.getByRole('button', {name: 'Resolve', exact: true}).click();
        await page.locator('#gcash-resolve-dialog').waitFor();
        await page.locator('#gcash-resolve-action').selectOption('complete');
        await page.locator('#gcash-resolve-reference').fill('0012345678999');
        await page.locator('#gcash-resolve-notes').fill('Wallet transfer confirmed in terminal');
        await page.locator('#gcash-resolve-submit').click();
        await page.locator('#gcash-resolve-dialog').waitFor({state: 'hidden'});
        await s2NcRow.locator('.gcash-status-completed').waitFor();

        // 3. Linked Correction on completed transaction (-5 cash delta)
        await s2NcRow.getByRole('button', {name: 'Correct', exact: true}).click();
        await page.locator('#gcash-correction-dialog').waitFor();
        await page.locator('#gcash-correction-cash-delta').fill('-5.00');
        await page.locator('#gcash-correction-wallet-delta').fill('0.00');
        await page.locator('#gcash-correction-reason').fill('Refund 5 pesos fee overcharge');
        await page.locator('#gcash-correction-approver').fill('theme_admin');
        await page.locator('#gcash-correction-password').fill(data.password);
        await page.locator('#gcash-correction-submit').click();
        await page.locator('#gcash-correction-dialog').waitFor({state: 'hidden'});

        // API tests for correction refusal (zero delta)
        const zeroCorrection = await post({
            action: 'linked_correction',
            transaction_id: 1,
            service_cash_delta: 0,
            wallet_delta: 0,
            reason: 'Zero delta correction',
            approver_username: 'theme_admin',
            approver_password: data.password
        });
        assert.equal(zeroCorrection.status(), 422, 'Zero delta correction rejected');

        // 4. Close Session 2 with discrepancy
        // Expected: cash = 1000 + 500 (funding) + 305 (cash-in: 300+5) - 5 (correction) = 1800.00
        // Expected wallet = 2000 - 300 = 1700.00
        await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
        await page.locator('#gcash-close-session-btn').click();
        await page.locator('#gcash-close-expected-cash').waitFor();
        assert.equal(await page.locator('#gcash-close-expected-cash').textContent(), '₱1,800.00');
        assert.equal(await page.locator('#gcash-close-expected-wallet').textContent(), '₱1,700.00');
        await page.locator('#gcash-close-cash').fill('1790');
        await page.locator('#gcash-close-wallet').fill('1700');
        await page.locator('#gcash-close-notes').fill('Missing 10 pesos coin');
        await page.locator('#gcash-close-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();

        // 5. Discrepancy Waiver button is visible on closed session with variance
        assert.equal(await page.locator('#gcash-waive-btn').isVisible(), true, 'Waive button visible on closed session with variance');
        await page.locator('#gcash-waive-btn').click();
        await page.locator('#gcash-waiver-dialog').waitFor();
        await page.locator('#gcash-waiver-notes').fill('Cash variance approved by store manager');
        await page.locator('#gcash-waiver-approver').fill('theme_admin');
        await page.locator('#gcash-waiver-password').fill(data.password);
        await page.locator('#gcash-waiver-submit').click();
        await page.locator('#gcash-waiver-dialog').waitFor({state: 'hidden'});
        await page.locator('#gcash-waive-btn').waitFor({state: 'hidden'});

        // Ticket #243: Legacy reference-only record distinction
        fixture('gcash_legacy');
        await tabHistory.click();
        assert.equal(await page.locator('#gcash-refresh').count(), 0, 'History omits only the Refresh button');
        assert.equal(await page.locator('#gcash-history-title').textContent(), 'Your transaction history');
        await page.locator('#gcash-history-action-cash-out').click();
        assert.equal(await page.locator('#gcash-type').inputValue(), 'Cash-Out');
        await tabHistory.click();
        await page.locator('#gcash-history-action-cash-in').click();
        assert.equal(await page.locator('#gcash-type').inputValue(), 'Cash-In');
        await tabHistory.click();
        const legacyRow = page.locator('#gcash-history-body tr', {hasText: '0012345678990'});
        await legacyRow.waitFor();
        assert.equal(await legacyRow.locator('.gcash-badge-legacy').textContent(), 'Legacy Reference');
        assert.equal((await legacyRow.textContent()).includes('Legacy reference record'), true);

        // Ticket #243: Responsive viewport tests across 3 tabs
        for (const width of [375, 768, 1440]) {
            await page.setViewportSize({width, height: 900});
            for (const t of [tabNewTx, tabHistory]) {
                await t.click();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'No page overflow at ' + width + ' on active tab');
            }
        }

        await page.screenshot({path: path.join(output, 'gcash.png'), fullPage: true});
        const adminContext = await browser.newContext();
        await adminContext.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const adminPage = await adminContext.newPage();
        adminPage.on('pageerror', error => errors.push(error.message));
        await login(adminPage, 'admin');

        // Ticket #244: Cashier API remains 403 forbidden for admin
        assert.equal((await adminContext.request.get(api)).status(), 403, 'Admin workspace must not access cashier API');
        assert.equal((await adminContext.request.post(api, {data: {action: 'save'}})).status(), 403);

        // Ticket #244: Sidebar navigation contains GCash Overview
        await adminPage.goto(origin + '/components/administrator/dashboard.php');
        const adminNav = await adminPage.locator('.sidebar-nav a').allTextContents();
        assert.equal(adminNav.some(label => label.trim() === 'GCash Overview'), true, 'Sidebar has GCash Overview link for Administrator');

        // Ticket #244: Access granted to Administrator GCash Overview
        const overviewRes = await adminPage.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(overviewRes.status(), 200, 'Admin can access GCash Overview');

        // Overview tabs expose one task at a time and support keyboard navigation.
        assert.equal(await adminPage.getByRole('tab').count(), 4);
        const balancesTab = adminPage.getByRole('tab', {name: 'Balances & Summary'});
        assert.equal(await balancesTab.getAttribute('aria-selected'), 'true');
        assert.equal(await adminPage.locator('#tab-transactions').isVisible(), false);
        await balancesTab.focus();
        await adminPage.keyboard.press('ArrowRight');
        assert.equal(await adminPage.locator('#tab-transactions').isVisible(), true);
        await adminPage.keyboard.press('End');
        assert.equal(await adminPage.locator('#tab-unresolved').isVisible(), true);
        await adminPage.keyboard.press('Home');
        assert.equal(await adminPage.locator('#tab-overview').isVisible(), true);

        // Verify Current Expected Balances top section
        const expectedCashEl = adminPage.locator('#admin-expected-cash');
        const expectedWalletEl = adminPage.locator('#admin-expected-wallet');
        await expectedCashEl.waitFor();
        await expectedWalletEl.waitFor();
        const initialExpectedCash = await expectedCashEl.textContent();
        assert.equal(initialExpectedCash.startsWith('₱'), true, 'Expected cash is formatted with peso sign');

        // Verify Filtered Activity Metrics cards
        const cashInMetricEl = adminPage.locator('#admin-metric-cash-in-principal');
        await cashInMetricEl.waitFor();

        await adminPage.getByRole('tab', {name: 'Transactions & Filters'}).click();

        // Verify Date Filtering with Philippine reporting-day boundaries
        await adminPage.locator('#filter-start-date').fill('2026-01-01');
        await adminPage.locator('#filter-end-date').fill('2026-01-02');
        await Promise.all([
            adminPage.waitForURL(url => url.search.includes('start_date=2026-01-01')),
            adminPage.locator('#btn-apply-filters').click()
        ]);
        assert.equal(await adminPage.locator('#admin-metric-cash-in-principal').textContent(), '₱0.00', 'Past date filter has zero cash-in activity');
        // Critical: Current Expected Balances remain unaffected by historical date filter
        assert.equal(await adminPage.locator('#admin-expected-cash').textContent(), initialExpectedCash, 'Current expected balances unaffected by historical date filter');

        // Reset filter
        await Promise.all([
            adminPage.waitForURL(url => !url.search.includes('start_date=2026-01-01')),
            adminPage.locator('#btn-reset-filters').click()
        ]);

        await adminPage.getByRole('tab', {name: 'Reconciliation Log'}).click();
        // Reconciliation Differences Section
        const diffTable = adminPage.locator('#gcash-admin-diff-table');
        await diffTable.waitFor();
        assert.equal(await diffTable.isVisible(), true, 'Reconciliation differences section is displayed');
        const diffText = await diffTable.textContent();
        assert.equal(diffText.includes('Waived') || diffText.includes('Variance') || diffText.includes('Session'), true, 'Reconciliation table contains session differences');

        await adminPage.getByRole('tab', {name: 'Unresolved Activity'}).click();
        // Unresolved Activities Section
        const ncTable = adminPage.locator('#gcash-admin-nc-table');
        await ncTable.waitFor();
        assert.equal(await ncTable.isVisible(), true, 'Unresolved activities section is displayed');

        await adminPage.getByRole('tab', {name: 'Transactions & Filters'}).click();
        // Store-wide Transaction History Table & Legacy reference distinction
        const historyTable = adminPage.locator('#gcash-admin-history-table');
        await historyTable.waitFor();
        const historyContent = await historyTable.textContent();
        assert.equal(historyContent.includes('Legacy reference'), true, 'Legacy reference distinction badge visible in history');

        // Responsive Viewport Verification (375px mobile, 768px tablet, 1440px desktop)
        for (const width of [375, 768, 1440]) {
            await adminPage.setViewportSize({width, height: 900});
            assert.equal(await adminPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'No page overflow at ' + width + ' on admin GCash overview');
        }

        await adminPage.screenshot({path: path.join(output, 'gcash-admin-overview.png'), fullPage: true});
        assert.deepEqual(errors, []);
        const integration = run('src/backend/tests/gcash_transaction_integration.php', [database]).trim();
        assert.equal(integration, 'GCash MySQL integration: passed');
        console.log(integration);
        const receiptUrl = origin + '/components/cashier/gcash_receipt.php?transaction_id=' + completedReceiptId;
        const reopened = await context.request.get(receiptUrl);
        assert.equal(reopened.status(), 200, 'Own saved receipt accessible after Cashier Shift closes');
        assert.match(await reopened.text(), /data-paper-width-mm="80"/, 'Closed shift uses existing 80 mm fallback');
        assert.equal((await adminContext.request.get(receiptUrl)).status(), 200, 'Administrator reads Store-wide saved receipts');
        assert.equal((await context.request.get(origin + '/components/cashier/gcash_receipt.php?transaction_id=999999999')).status(), 404);
        const otherContext = await browser.newContext();
        const otherPage = await otherContext.newPage();
        await otherPage.goto(origin + '/?login=1');
        await otherPage.locator('#landing-login-username').fill('cashier2');
        await otherPage.locator('#landing-login-password').fill(data.password);
        await Promise.all([otherPage.waitForURL(url => !url.search.includes('login=1')), otherPage.getByRole('button', {name: 'Log in', exact: true}).click()]);
        assert.equal((await otherContext.request.get(receiptUrl)).status(), 404, 'Cashier cannot directly retrieve another Cashier receipt');
        await otherContext.close();
        const inventoryContext = await browser.newContext();
        const inventoryPage = await inventoryContext.newPage();
        await login(inventoryPage, 'inventory_manager');
        assert.equal((await inventoryContext.request.get(receiptUrl)).status(), 403, 'Active inventory Workspace cannot retrieve a receipt');
        await inventoryContext.close();
        const anonymous = await browser.newContext();
        assert.equal((await anonymous.request.get(receiptUrl, {maxRedirects: 0})).status(), 302, 'Receipt requires authentication');
        await anonymous.close();

        console.log('GCash browser: passed (Cashier workflow, Administrator oversight, date filtering, differences, responsive layout)');
        console.log('Screenshots: ' + path.join(output, 'gcash.png') + ', ' + path.join(output, 'gcash-admin-overview.png'));
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
