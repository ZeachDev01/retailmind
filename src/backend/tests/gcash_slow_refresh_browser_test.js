'use strict';

const assert = require('node:assert/strict');
const {randomBytes} = require('node:crypto');
const {execFileSync, spawn} = require('node:child_process');
const {createServer} = require('node:net');
const {chromium, devices} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('GCash slow refresh: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}

const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const fixture = action => execFileSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8'});

(async () => {
    let server, browser;
    try {
        const {password} = JSON.parse(fixture('create'));
        execFileSync(php, [], {
            env: {...process.env, DB_NAME: database},
            input: `<?php
                require 'src/backend/bootstrap/app.php';
                $config = require 'src/backend/config/database.php';
                if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/D', $config['database'])) exit(1);
                $pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
                $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status, created_by)
                    VALUES ('Fixture period', '2020-01-01', '2099-12-31', 'open', 1)");
            `
        });
        const listener = createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...process.env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; ; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Fixture web server unavailable');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch();
        const page = await browser.newPage({...devices['Galaxy Tab S4']});
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.abort());
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(password);
        await page.getByRole('button', {name: 'Log in', exact: true}).click();
        await page.waitForURL(url => !url.searchParams.has('login'));
        await page.goto(origin + '/components/cashier/gcash.php?type=cash-in');
        await page.locator('#gcash-open-session-btn').click();
        await page.locator('#gcash-open-cash').fill('1000');
        await page.locator('#gcash-open-wallet').fill('5000');
        await page.locator('#gcash-open-submit').click();
        await page.locator('#gcash-session-status').filter({hasText: 'Active (You)'}).waitFor();
        const amount = page.locator('#gcash-amount');
        for (const [type, reference, refresh, matchesQuery] of [
            ['Cash-In', '0012345678910', 'history', query => query.has('page')],
            ['Cash-Out', '0012345678911', 'session status', query => query.get('action') === 'session_status'],
        ]) {
            await page.locator('#gcash-type').selectOption(type);
            await amount.fill('100');
            await page.locator('#gcash-reference').fill(reference);
            await page.locator('#gcash-confirm-wallet').check();
            await page.locator('#gcash-confirm-cash').check();

            let releaseRefresh;
            const refreshGate = new Promise(resolve => { releaseRefresh = resolve; });
            const refreshUrl = url => url.pathname.endsWith('/gcash_api.php') && matchesQuery(url.searchParams);
            await page.route(refreshUrl, async route => {
                await refreshGate;
                await route.continue();
            });
            try {
                const refreshRequest = page.waitForRequest(request => refreshUrl(new URL(request.url())));
                await page.locator('#gcash-complete-' + type.toLowerCase()).click();
                const receipt = page.locator('#receiptModal');
                await receipt.locator('.gcash-service-receipt').waitFor();
                await receipt.getByRole('button', {name: 'Close GCash Service Receipt'}).click();
                await refreshRequest;
                assert.equal(await receipt.isVisible(), false, 'Saved receipt closed');
                assert.equal(await amount.isEnabled(), true, type + ' form stays usable while ' + refresh + ' is still loading');
                await amount.fill('50');
            } finally {
                releaseRefresh();
                await page.unroute(refreshUrl);
            }
            await page.locator('#gcash-history-body tr').filter({hasText: reference}).waitFor({state: 'attached'});
            console.log('GCash ' + type + ' with slow ' + refresh + ': passed');
        }
    } finally {
        if (browser) await browser.close();
        if (server) {
            const closed = new Promise(resolve => server.once('close', resolve));
            if (server.kill()) await closed;
        }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
