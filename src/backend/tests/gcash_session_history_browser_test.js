'use strict';

const assert = require('node:assert/strict');
const {randomBytes} = require('node:crypto');
const {mkdtempSync, rmSync} = require('node:fs');
const {createServer} = require('node:net');
const {tmpdir} = require('node:os');
const {join} = require('node:path');
const {spawn, execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('GCash session history: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const temp = mkdtempSync(join(tmpdir(), 'rm-gcash-sessions-'));
const env = {...process.env, BACKUP_STORAGE_PATH: join(temp, 'recovery')};
const fixture = action => execFileSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});

(async () => {
    let server, browser;
    try {
        const {password} = JSON.parse(fixture('create'));
        execFileSync(php, [], {
            env: {...env, DB_NAME: database},
            input: `<?php
                require 'src/backend/bootstrap/app.php';
                $config = require 'src/backend/config/database.php';
                if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $config['database'])) exit(1);
                $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
                $pdo->exec("SET time_zone = '+08:00'");
                $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status, created_by)
                    VALUES ('Fixture period', '2020-01-01', '2099-12-31', 'open', 1)");
                $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status, closed_at, closed_by)
                    VALUES (2, 2, 1, 100, 'closed', '2026-10-22 00:01:00', 1)");
                $pdo->exec("INSERT INTO gcash_service_sessions
                    (shift_id, cashier_id, status, opened_at, closed_at, opening_service_cash, closing_service_cash, closed_by)
                    VALUES (2, 2, 'closed', '2026-10-21 23:59:00', '2026-10-22 00:01:00', 100, 100, 1)");
                $insert = $pdo->prepare("INSERT INTO gcash_service_sessions (shift_id, cashier_id, status, opened_at, closed_at)
                    VALUES (1, 4, 'closed', '2026-10-20 10:00:00', '2026-10-20 11:00:00')");
                for ($i = 0; $i < 11; $i++) $insert->execute();
            `
        });

        const listener = createServer();
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
        const login = async role => {
            const page = await browser.newPage({viewport: {width: 1440, height: 900}});
            page.setDefaultTimeout(10000);
            await page.route('https://**/*', route => route.abort());
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(password);
            await page.getByRole('button', {name: 'Log in', exact: true}).click();
            await page.waitForURL(url => !url.searchParams.has('login'));
            return page;
        };

        const cashier = await login('cashier');
        const submitSession = async action => {
            const [response] = await Promise.all([
                cashier.waitForResponse(response => response.url().includes('/gcash_api.php') && response.request().method() === 'POST'),
                cashier.locator('#gcash-' + action + '-submit').click()
            ]);
            assert.equal(response.ok(), true, await response.text());
        };
        await cashier.goto(origin + '/components/cashier/gcash.php?tab=sessions&session_cashier=2');
        const recent = cashier.locator('#panel-sessions');
        assert.equal(await recent.locator('tr[data-session-id]').count(), 11);
        assert.equal(await recent.locator('[data-session-id="1"]').count(), 0, 'Cashier scope overrides supplied user ID');
        const firstDates = recent.locator('tr[data-session-id]').first().locator('td').filter({has: cashier.locator('.gcash-session-date')});
        assert.deepEqual(await firstDates.locator('.gcash-session-date').allTextContents(), ['Oct 20, 2026', 'Oct 20, 2026']);
        assert.deepEqual(await firstDates.locator('small').allTextContents(), ['10:00 AM', '11:00 AM']);
        await recent.getByLabel('Entries per page').selectOption('10');
        await cashier.waitForURL(url => url.searchParams.get('session_length') === '10');
        await recent.getByRole('link', {name: 'Next'}).click();
        await cashier.waitForURL(url => url.searchParams.get('session_page') === '2');
        assert.match(await recent.locator('.rm-table-footer').innerText(), /Showing 11 to 11 of 11 entries/);
        const missingNumber = await cashier.request.post(origin + '/components/cashier/gcash_api.php', {
            headers: {'X-CSRF-Token': await cashier.locator('#gcash-workspace').getAttribute('data-csrf')},
            data: {action: 'open_session', opening_cash: 125, opening_wallet: 300}
        });
        assert.equal(missingNumber.status(), 422, 'Opening GCash requires the Store wallet number');
        await cashier.locator('#gcash-open-session-btn').click();
        await cashier.getByLabel('Store GCash number', {exact: true}).fill('09171234567');
        await cashier.locator('#gcash-open-cash').fill('125');
        await cashier.locator('#gcash-open-wallet').fill('300');
        await submitSession('open');
        await recent.locator('tr[data-session-id]').filter({hasText: '₱125.00'}).waitFor();
        assert.match(await recent.locator('tr[data-session-id]').first().innerText(), /09171234567/);
        assert.equal(await recent.locator('tr[data-session-id]').first().locator('td').nth(4).innerText(), '—', 'An open session has no closing time');

        const admin = await login('admin');
        await admin.goto(origin + '/components/administrator/gcash_overview.php');
        await admin.getByRole('tab', {name: 'Sessions', exact: true}).click();
        const sessions = admin.locator('#tab-sessions');
        await sessions.getByRole('heading', {name: 'GCash Sessions'}).waitFor();
        assert.match(await sessions.locator('.rm-table-footer').innerText(), /of 13 entries/);
        await sessions.getByLabel('Status').selectOption('open');
        await sessions.locator('.gcash-filter-actions [type="submit"]').click();
        await admin.waitForURL(url => url.searchParams.get('session_status') === 'open');
        assert.equal(await sessions.locator('tr[data-session-id]').count(), 1);
        await sessions.getByLabel('Status').selectOption('closed');
        await sessions.getByLabel('Cashier', {exact: true}).selectOption('2');
        await sessions.locator('[data-open-dialog="gcash-session-filter-dialog"]').click();
        await sessions.getByLabel('Start date').fill('2026-10-22');
        await sessions.getByLabel('End date').fill('2026-10-22');
        await sessions.locator('#gcash-session-filter-dialog [type="submit"]').click();
        await admin.waitForURL(url => url.searchParams.get('session_from') === '2026-10-22');
        assert.equal(await sessions.locator('tr[data-session-id]').count(), 1);
        assert.match(await sessions.locator('[data-session-id="1"]').innerText(), /₱100.00/);

        await cashier.locator('#tab-new-transaction').click();
        await cashier.locator('#gcash-close-session-btn').click();
        await cashier.locator('#gcash-close-cash').fill('125');
        await cashier.locator('#gcash-close-wallet').fill('300');
        await submitSession('close');
        await recent.locator('tr[data-session-id]').first().filter({hasText: /Closed[\s\S]*₱125.00[\s\S]*₱300.00/}).waitFor();
        assert.equal(await cashier.locator('#tab-sessions').getAttribute('aria-selected'), 'true');
        assert.deepEqual(await recent.locator('tr[data-session-id]').first().locator('td').allTextContents().then(cells => cells.slice(-2)), ['₱125.00', '₱300.00']);
        assert.match(await recent.locator('.rm-table-footer').innerText(), /Showing 1 to 10 of 12 entries/);
        assert.match(await recent.locator('tr[data-session-id]').first().innerText(), /09171234567/,
            'The closed session retains its Store wallet number');
        const closedSessionId = await recent.locator('tr[data-session-id]').first().getAttribute('data-session-id');
        await cashier.locator('#gcash-open-session-btn').click();
        await cashier.getByLabel('Store GCash number', {exact: true}).fill('+63 918 123 4567');
        await cashier.locator('#gcash-open-cash').fill('125');
        await cashier.locator('#gcash-open-wallet').fill('300');
        await submitSession('open');
        await recent.locator('tr[data-session-id]').first().filter({hasText: '09181234567'}).waitFor();
        assert.match(await recent.locator('[data-session-id="' + closedSessionId + '"]').innerText(), /09171234567/,
            'A later session wallet number does not rewrite the earlier session');

        for (const [page, panel] of [[cashier, recent], [admin, sessions]]) {
            await page.setViewportSize({width: 390, height: 844});
            const footer = await panel.locator('.rm-table-footer').boundingBox();
            assert.ok(footer.width > 0 && footer.x + footer.width <= 391, 'Standard footer fits the mobile workspace');
        }
        console.log('GCash session history: passed (cashier close and scope, admin filters, standard pagination and mobile footer)');
    } finally {
        if (browser) await browser.close();
        if (server) {
            const closed = new Promise(resolve => server.once('close', resolve));
            if (server.kill()) await closed;
        }
        fixture('cleanup');
        rmSync(temp, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
