'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('GCash handover: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-handover-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => execFileSync(php, ['src/backend/tests/support/gcash_admin_layout_fixture.php', action, database], {env, encoding: 'utf8'});
const sql = code => execFileSync(php, [], {env: {...env, DB_NAME: database}, encoding: 'utf8', input: `<?php
    require 'src/backend/bootstrap/app.php';
    $config = require 'src/backend/config/database.php';
    if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $config['database'])) exit(1);
    $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET time_zone = '+08:00'");
    ${code}
`});
const state = () => JSON.parse(sql(`echo json_encode([
    'sessions' => $pdo->query('SELECT * FROM gcash_service_sessions ORDER BY session_id')->fetchAll(PDO::FETCH_ASSOC),
    'exchanges' => $pdo->query('SELECT * FROM gcash_transactions ORDER BY transaction_id')->fetchAll(PDO::FETCH_ASSOC),
    'adjustments' => $pdo->query('SELECT * FROM gcash_adjustments ORDER BY adjustment_id')->fetchAll(PDO::FETCH_ASSOC),
    'audit_count' => $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn(),
]);`));

(async () => {
    let server, browser;
    try {
        const {password} = JSON.parse(fixture('create'));
        sql(`$pdo->exec("INSERT INTO users (user_id,first_name,display_name,username,password_hash,role_id,must_change_password)
            SELECT 5,'Next','Next Cashier','theme_next',password_hash,4,0 FROM users WHERE user_id=4");
            $pdo->exec('INSERT INTO user_roles (user_id,role_id,is_primary) VALUES (5,4,1)');
            $pdo->exec("INSERT INTO registers (register_id,name) VALUES (2,'Next Register')");
            $pdo->exec('INSERT INTO cashier_shifts (shift_id,cashier_id,register_id,opening_cash) VALUES (2,5,2,100)');`);
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
            if (attempt === 99) throw new Error(logs);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch();
        const errors = [];
        const login = async username => {
            const page = await browser.newPage({viewport: {width: 1440, height: 900}});
            page.setDefaultTimeout(10000);
            page.on('pageerror', error => errors.push(error.message));
            await page.route('https://**/*', route => route.abort());
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill(username);
            await page.locator('#landing-login-password').fill(password);
            await page.getByRole('button', {name: 'Log in', exact: true}).click();
            await page.waitForURL(url => !url.searchParams.has('login'));
            await page.goto(origin + '/components/cashier/gcash.php');
            return page;
        };
        const outgoing = await login('theme_cashier');
        const incoming = await login('theme_next');
        const post = async (page, data, status = 200) => {
            const response = await page.request.post(origin + '/components/cashier/gcash_api.php', {
                headers: {'X-CSRF-Token': await page.locator('#gcash-workspace').getAttribute('data-csrf')}, data
            });
            assert.equal(response.status(), status, await response.text());
            return response.json();
        };
        const status = async page => (await (await page.request.get(origin + '/components/cashier/gcash_api.php?action=session_status')).json()).session;
        const cashOut = await post(outgoing, {action: 'record_cash_out', principal: 100, fee_method: 'Fee via GCash',
            outcome: 'Needs checking', confirmed_wallet: true, confirmed_cash: false, reference: '0000000000201',
            customer_name: 'Ben <Santos>', notes: 'Wallet received; cash payout pending'});
        const inherited = await post(outgoing, {action: 'record_cash_in', principal: 100, outcome: 'Needs checking',
            confirmed_cash: true, confirmed_wallet: false, notes: 'Earlier cashier follow-up'});
        const unrelated = await post(outgoing, {action: 'record_cash_in', principal: 100, outcome: 'Needs checking', notes: 'Other cashier follow-up'});
        const unassigned = await post(outgoing, {action: 'record_cash_in', principal: 100, outcome: 'Needs checking', notes: 'Unassigned follow-up'});
        sql(`$pdo->exec('UPDATE gcash_transactions SET session_id=1 WHERE transaction_id=${inherited.id}');
            $pdo->exec('UPDATE gcash_transactions SET responsible_user_id=1, responsible_role="admin" WHERE transaction_id=${unrelated.id}');
            $pdo->exec('UPDATE gcash_transactions SET responsible_user_id=NULL, responsible_role=NULL WHERE transaction_id=${unassigned.id}');`);
        const before = state();
        const initial = await status(outgoing);
        await post(outgoing, {action: 'handover_session', incoming_cashier_id: 2, counted_cash: 2100, checked_wallet: 4355}, 422);
        assert.deepEqual(state(), before, 'Invalid recipient leaves custody and follow-up untouched');
        sql(`$pdo->exec("CREATE TRIGGER fail_gcash_handover_audit BEFORE INSERT ON activity_log FOR EACH ROW BEGIN
            IF NEW.action = 'GCash session opened via handover' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handover audit failure'; END IF; END");`);
        await post(outgoing, {action: 'handover_session', incoming_cashier_id: 5, counted_cash: 2100, checked_wallet: 4355}, 503);
        assert.deepEqual(state(), before, 'Audit failure rolls back custody, follow-up and history');
        sql("$pdo->exec('DROP TRIGGER fail_gcash_handover_audit');");
        await outgoing.reload();
        // A failed preview must not permit a handover with an unseen list.
        await outgoing.route('**/gcash_api.php?action=follow_up', route => route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({error: 'Preview unavailable'})}));
        await outgoing.locator('#gcash-handover-session-btn').click();
        await outgoing.getByText('Could not load held exchanges. Close and reopen Hand over to try again.').waitFor();
        assert.equal(await outgoing.locator('#gcash-handover-submit').isDisabled(), true);
        await outgoing.locator('#gcash-handover-cancel').click();
        await outgoing.unroute('**/gcash_api.php?action=follow_up');
        await outgoing.locator('.rm-toast').first().waitFor({state: 'detached'});
        await outgoing.locator('#gcash-handover-session-btn').click();
        await outgoing.locator('#gcash-handover-held p').first().waitFor();
        const list = await outgoing.locator('#gcash-handover-held').innerText();
        assert.match(list, /Ben <Santos>/);
        assert.match(list, /Earlier cashier follow-up/);
        assert.doesNotMatch(list, /Other cashier follow-up|Unassigned follow-up/);
        assert.equal(await outgoing.locator('#gcash-handover-held input').count(), 0);
        for (const width of [1440, 768, 390]) {
            await outgoing.setViewportSize({width, height: 844});
            const bounds = await outgoing.locator('#gcash-handover-dialog').evaluate(node => {
                const rect = node.getBoundingClientRect();
                return {left: rect.left, right: rect.right, fits: node.scrollWidth <= node.clientWidth};
            });
            assert.equal(bounds.fits && bounds.left >= 0 && bounds.right <= width, true, 'Dialog fits ' + width);
            await outgoing.screenshot({path: path.join(output, 'handover-' + width + '.png')});
        }
        await outgoing.locator('#gcash-handover-incoming').selectOption('5');
        await outgoing.locator('#gcash-handover-cash').fill(String(initial.expected_service_cash));
        await outgoing.locator('#gcash-handover-wallet').fill(String(initial.expected_wallet_balance));
        const [handoverResponse] = await Promise.all([
            outgoing.waitForResponse(response => response.url().includes('/gcash_api.php') && response.request().method() === 'POST'),
            outgoing.locator('#gcash-handover-submit').click()
        ]);
        assert.equal(handoverResponse.status(), 200, await handoverResponse.text());
        const after = state();
        const owners = Object.fromEntries(after.exchanges.map(tx => [tx.transaction_id, tx.responsible_user_id]));
        assert.equal(owners[cashOut.id], 5);
        assert.equal(owners[inherited.id], 5, 'An exchange from an older session follows its assigned Cashier');
        assert.equal(owners[unrelated.id], 1);
        assert.equal(owners[unassigned.id], null);
        for (const tx of before.exchanges) {
            const saved = after.exchanges.find(row => row.transaction_id === tx.transaction_id);
            for (const key of ['cashier_id', 'session_id', 'service_cash_delta', 'wallet_delta']) assert.equal(saved[key], tx[key]);
        }
        await incoming.evaluate(() => window.dispatchEvent(new Event('focus')));
        await incoming.locator('#gcash-follow-up p').filter({hasText: '#' + cashOut.id + ' ·'}).waitFor();
        await post(outgoing, {action: 'resolve_needs_checking', transaction_id: cashOut.id, resolve_action: 'complete'}, 403);
        const incomingBefore = await status(incoming);
        await post(incoming, {action: 'resolve_needs_checking', transaction_id: cashOut.id, resolve_action: 'complete'});
        const incomingAfter = await status(incoming);
        assert.equal(incomingAfter.expected_service_cash, incomingBefore.expected_service_cash - 100);
        assert.equal(incomingAfter.expected_wallet_balance, incomingBefore.expected_wallet_balance, 'Previously received wallet transfer is not repeated');
        const saved = state();
        assert.deepEqual(saved.sessions.filter(row => row.status === 'closed'), after.sessions.filter(row => row.status === 'closed'));
        const original = saved.exchanges.find(row => row.transaction_id === cashOut.id);
        assert.equal(Number(original.service_cash_delta), 0, 'Original session movements remain unchanged after completion');
        const movement = saved.adjustments.find(row => row.transaction_id === cashOut.id);
        assert.equal(movement.session_id, incomingAfter.session_id);
        assert.equal(Number(movement.service_cash_delta), -100);
        assert.equal(Number(movement.wallet_delta), 0);
        const receipt = await incoming.request.get(origin + '/components/cashier/gcash_receipt.php?transaction_id=' + cashOut.id);
        assert.equal(receipt.status(), 200, 'Receiving Cashier can open the completed inherited receipt');
        await post(incoming, {action: 'resolve_needs_checking', transaction_id: cashOut.id, resolve_action: 'complete'}, 409);
        assert.deepEqual(state(), saved, 'Repeated completion does not move money again');
        await post(incoming, {action: 'handover_session', incoming_cashier_id: 4,
            counted_cash: incomingAfter.expected_service_cash, checked_wallet: incomingAfter.expected_wallet_balance});
        const back = state();
        assert.equal(back.exchanges.find(tx => tx.transaction_id === inherited.id).responsible_user_id, 4, 'Inherited follow-up survives another handover');
        await outgoing.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await outgoing.locator('#gcash-follow-up p').filter({hasText: '#' + inherited.id + ' ·'}).waitFor();
        const backBefore = await status(outgoing);
        await post(outgoing, {action: 'resolve_needs_checking', transaction_id: inherited.id, resolve_action: 'complete', reference: '0000000000202'});
        const backAfter = await status(outgoing);
        assert.equal(backAfter.expected_service_cash, backBefore.expected_service_cash, 'Earlier collected cash is not repeated');
        assert.equal(backAfter.expected_wallet_balance, backBefore.expected_wallet_balance - 100);
        assert.deepEqual(state().sessions.filter(row => row.status === 'closed'), back.sessions.filter(row => row.status === 'closed'));
        assert.deepEqual(errors, []);
        console.log('GCash handover: passed (automatic follow-up, inherited items, unrelated owners, rollback, remaining cash/wallet movements, receipt, repeat completion, responsive preview)');
        console.log('Evidence: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) {
            const exited = new Promise(resolve => server.once('exit', resolve));
            server.kill(); await exited;
        }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
