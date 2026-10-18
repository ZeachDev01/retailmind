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
        const reference = page.locator('#gcash-reference');
        await reference.fill('Reference No.: 0012 345 678901');
        await reference.press('Enter');
        await page.getByText('Reference captured. Review the number and amount; payment remains unverified.', {exact: true}).waitFor();
        assert.equal(await reference.inputValue(), '0012345678901');
        assert.equal(await page.locator('#gcash-history-body tr').count(), 0, 'Enter must never save');
        await page.locator('#gcash-amount').fill('125.50');
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
