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
        await login(page, 'cashier');
        await page.goto(origin + '/components/cashier/gcash.php');
        const api = origin + '/components/cashier/gcash_api.php';
        const post = async data => {
            const response = await context.request.post(api, {
                headers: {'X-CSRF-Token': await page.locator('#gcash-workspace').getAttribute('data-csrf')}, data
            });
            assert.equal(response.status(), 200, await response.text());
            return response.json();
        };
        const held = await post({action: 'record_cash_in', principal: 100, outcome: 'Needs checking',
            confirmed_cash: false, confirmed_wallet: false, notes: ''});
        await post({action: 'resolve_needs_checking', transaction_id: held.id, resolve_action: 'cancel', notes: '', reason: ''});
        const history = await (await context.request.get(api + '?action=history')).json();
        assert.equal(history.transactions.find(tx => tx.transaction_id === held.id).verification_status, 'Rejected',
            'A held exchange without money movements can be cancelled without resolution notes');
        const blankOut = await post({action: 'record_cash_out', principal: 100, outcome: 'Needs checking',
            confirmed_cash: false, confirmed_wallet: false, notes: ''});
        await post({action: 'resolve_needs_checking', transaction_id: blankOut.id, resolve_action: 'cancel', notes: '', reason: ''});
        const cashHeld = await post({action: 'record_cash_in', principal: 100, outcome: 'Needs checking',
            confirmed_cash: true, confirmed_wallet: false, notes: 'Wallet transfer could not be confirmed'});
        await post({action: 'record_return', transaction_id: cashHeld.id, returned_cash: 105, returned_wallet: 0,
            fee_returned: 5, evidence: 'Cash return acknowledged', reason: '',
            operation_token: randomBytes(24).toString('hex'),
            approver_username: 'theme_admin', approver_password: data.password});
        const returnedHistory = await (await context.request.get(api + '?action=history')).json();
        assert.equal(returnedHistory.transactions.find(tx => tx.transaction_id === cashHeld.id).verification_status, 'Rejected',
            'An evidenced and approved full return does not require extra resolution notes');
        for (const invalid of [{customer_name: 'x'.repeat(101)}, {customer_name: []},
            {customer_gcash_number: '123'}, {customer_gcash_number: []}]) {
            const refused = await context.request.post(api, {
                headers: {'X-CSRF-Token': await page.locator('#gcash-workspace').getAttribute('data-csrf')},
                data: {action: 'record_cash_in', principal: 100, outcome: 'Needs checking',
                    notes: 'Waiting for customer', ...invalid}
            });
            assert.equal(refused.status(), 422, 'Invalid optional customer details are rejected at the API');
        }
        const identified = await post({action: 'record_cash_in', principal: 100, outcome: 'Needs checking',
            confirmed_cash: true, confirmed_wallet: false, notes: 'Waiting for wallet confirmation',
            customer_name: 'Ana <Reyes>', customer_gcash_number: '09171234567'});
        const identifiedHistory = await (await context.request.get(api + '?action=history')).json();
        const saved = identifiedHistory.transactions.find(tx => tx.transaction_id === identified.id);
        assert.equal(saved.customer_name, 'Ana <Reyes>', 'Held exchange history retains the supplied customer name');
        assert.equal(saved.customer_gcash_number, '09171234567', 'Held exchange history retains the recipient GCash number');
        await page.reload();
        await page.locator('#gcash-follow-up p').filter({hasText: '#' + identified.id + ' ·'}).getByRole('button').click();
        const resolve = page.locator('#gcash-resolve-dialog');
        for (const name of ['Take photo', 'Choose photo']) {
            const button = resolve.getByRole('button', {name, exact: true});
            assert.equal((await button.innerText()).trim(), '', name + ' uses an icon with an accessible name');
        }
        assert.equal(await resolve.getByText('Ana <Reyes>', {exact: true}).count(), 1,
            'Resolve shows the saved customer name as text');
        assert.match(await resolve.innerText(), /09171234567/);
        assert.match(await resolve.innerText(), /Waiting for wallet confirmation/);
        await page.locator('#gcash-resolve-cancel').click();
        await page.locator('#gcash-type').selectOption('Cash-Out');
        await page.locator('#gcash-amount').fill('100');
        await page.locator('#gcash-confirm-wallet').check();
        await page.locator('#gcash-needs-checking-btn').click();
        assert.equal(await page.locator('#gcash-nc-wallet-sent').isChecked(), true, 'Hold carries the checked wallet movement');
        assert.equal(await page.locator('#gcash-nc-cash-collected').isChecked(), false, 'Hold keeps unconfirmed cash unchecked');
        await page.getByLabel('Customer name (optional)', {exact: true}).fill('Ben Santos');
        await page.getByLabel('Sender GCash number (optional)', {exact: true}).fill('09181234567');
        await page.locator('#gcash-nc-notes').fill('Wallet received; cash payout pending');
        await page.locator('#gcash-nc-cancel').click();
        await page.locator('#gcash-needs-checking-btn').click();
        assert.equal(await page.locator('#gcash-nc-customer').inputValue(), 'Ben Santos', 'Back retains customer');
        assert.equal(await page.locator('#gcash-nc-number').inputValue(), '09181234567', 'Back retains number');
        assert.equal(await page.locator('#gcash-nc-notes').inputValue(), 'Wallet received; cash payout pending', 'Back retains reason');
        const [heldResponse] = await Promise.all([
            page.waitForResponse(response => response.url().includes('/gcash_api.php') && response.request().method() === 'POST'),
            page.locator('#gcash-nc-submit').click()
        ]);
        assert.equal(heldResponse.status(), 200, await heldResponse.text());
        const heldOut = await heldResponse.json();
        await page.reload();
        await page.locator('#gcash-follow-up p').filter({hasText: '#' + heldOut.id + ' ·'}).getByRole('button').click();
        assert.match(await resolve.innerText(), /Sender GCash number/);
        assert.match(await resolve.innerText(), /Ben Santos/);
        assert.match(await resolve.innerText(), /09181234567/);
        assert.match(await resolve.innerText(), /Wallet received; cash payout pending/);
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({width, height: 844});
            assert.equal(await resolve.evaluate(node => node.scrollWidth <= node.clientWidth), true, 'Resolve fits width ' + width);
            await page.screenshot({path: path.join(output, 'resolve-' + width + '.png'), fullPage: true});
        }
        await page.locator('#gcash-resolve-cancel').click();
        const legacy = (await (await context.request.get(api + '?action=history')).json()).transactions
            .find(tx => tx.verification_status === 'Needs checking' && !tx.customer_name && !tx.customer_gcash_number);
        await page.locator('#gcash-follow-up p').filter({hasText: '#' + legacy.transaction_id + ' ·'}).getByRole('button').click();
        assert.equal(await resolve.getByText('Not recorded', {exact: true}).count(), 2,
            'Held exchanges without customer details remain available for resolution');
        const receiptPage = await context.newPage();
        await receiptPage.setContent('<div style="background:white;color:black;padding:40px;font:32px Arial;width:800px">GCash receipt<br>Ref No. 0012345678901</div>');
        const photo = {name: 'receipt.png', mimeType: 'image/png', buffer: await receiptPage.locator('div').screenshot()};
        await receiptPage.close();
        const [photoPicker] = await Promise.all([
            page.waitForEvent('filechooser'),
            resolve.getByRole('button', {name: 'Choose photo', exact: true}).click()
        ]);
        await photoPicker.setFiles(photo);
        await resolve.getByLabel('Suggested reference', {exact: true}).waitFor({timeout: 30000});
        assert.equal(await resolve.getByLabel('Suggested reference', {exact: true}).inputValue(), '0012345678901',
            'Receipt OCR preserves leading zeroes in the suggested reference');
        assert.equal(await page.locator('#gcash-resolve-reference').inputValue(), '', 'Photo requires digit review before use');
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({width, height: 844});
            assert.equal(await resolve.evaluate(node => node.scrollWidth <= node.clientWidth), true, 'Photo review fits width ' + width);
            await page.screenshot({path: path.join(output, 'resolve-photo-' + width + '.png'), fullPage: true});
        }
        await resolve.getByRole('button', {name: 'Use reference', exact: true}).click();
        assert.equal(await page.locator('#gcash-resolve-reference').inputValue(), '0012345678901');
        assert.equal(await page.locator('#gcash-reference').inputValue(), '', 'Resolve photo leaves new transaction reference untouched');
        assert.equal(await page.locator('#gcash-camera-file').getAttribute('capture'), 'environment');
        const [cameraPicker] = await Promise.all([
            page.waitForEvent('filechooser'),
            resolve.getByRole('button', {name: 'Take photo', exact: true}).click()
        ]);
        await cameraPicker.setFiles(photo);
        await resolve.getByLabel('Suggested reference', {exact: true}).waitFor({timeout: 30000});
        await page.keyboard.press('Escape');
        await resolve.waitFor({state: 'hidden'});
        await page.waitForFunction(() => !document.getElementById('gcash-photo-preview').hasAttribute('src'));
        assert.equal(await page.locator('#gcash-photo-preview').getAttribute('src'), null, 'Closing Resolve clears the photo');
        await page.locator('#gcash-follow-up p').filter({hasText: '#' + legacy.transaction_id + ' ·'}).getByRole('button').click();
        assert.equal(await page.locator('#gcash-camera').isVisible(), false, 'Reopening Resolve does not retain a previous photo');
        await page.locator('#gcash-resolve-reference').fill('0012345678901');
        const [completed] = await Promise.all([
            page.waitForResponse(response => response.url().includes('/gcash_api.php') && response.request().method() === 'POST'),
            page.locator('#gcash-resolve-submit').click()
        ]);
        assert.equal(completed.status(), 200, await completed.text());
        const completedHistory = await (await context.request.get(api + '?action=history')).json();
        assert.equal(completedHistory.transactions.find(tx => tx.transaction_id === legacy.transaction_id).reference_number,
            '0012345678901', 'The API retains the entered reference including its leading zeroes');
        assert.equal(completedHistory.transactions.find(tx => tx.transaction_id === legacy.transaction_id).verification_status,
            'Completed', 'Legacy held exchanges complete with blank resolution notes and customer details');
        const completedStatus = await (await context.request.get(api + '?action=session_status')).json();
        assert.equal(completedStatus.session.expected_service_cash, 2715,
            'Completing the held exchange does not collect its already recorded cash twice');
        assert.equal(completedStatus.session.expected_wallet_balance, 3855,
            'Completing the held exchange records only its remaining wallet transfer');
        await page.getByRole('button', {name: 'Close GCash Service Receipt', exact: true}).click();
        const [newPhotoPicker] = await Promise.all([
            page.waitForEvent('filechooser'), page.locator('#gcash-photo-open').click()
        ]);
        await newPhotoPicker.setFiles(photo);
        await page.getByLabel('Suggested reference', {exact: true}).waitFor({timeout: 30000});
        assert.equal(await page.locator('.gcash-reference-field #gcash-photo-preview').isVisible(), true,
            'New Transaction can still read photos after Resolve used the shared reader');
        await page.locator('#gcash-ocr-candidate').fill('0012345678902');
        await page.getByRole('button', {name: 'Use reference', exact: true}).click();
        assert.equal(await page.locator('#gcash-reference').inputValue(), '0012345678902');
        assert.deepEqual(errors, []);
        console.log('GCash held exchange: passed (confirmations, customer details, optional notes, receipt OCR, choose/take photo, cleanup, legacy completion, responsive dialog)');
        console.log('Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
