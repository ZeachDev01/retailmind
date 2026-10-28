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
            await Promise.all([target.waitForURL(url => !url.search.includes('login=1')), target.getByRole('button', {name: 'Log in', exact: true}).click()]).catch(async error => { throw new Error(error.message + '\n' + await target.locator('body').innerText() + '\n' + logs.slice(-3000)); });
        };
        await login(page, 'admin');
        await page.evaluate(async () => {
            await navigator.serviceWorker.ready;
            if (!navigator.serviceWorker.controller) await new Promise(resolve => navigator.serviceWorker.addEventListener('controllerchange', resolve, {once: true}));
        });
        // Older cached scripts predate tab handlers; retain a sentinel at the old URL.
        await page.evaluate(async () => {
            const cache = await caches.open('retailmind-static-v2');
            await cache.put(new URL('/assets/js/gcash-admin-overview.js', location.href), new Response('window.legacyGCashScript = true;', {headers: {'Content-Type': 'text/javascript'}}));
        });
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        await page.getByRole('tab', {name: 'Transactions & Filters'}).click();
        assert.equal(await page.locator('#tab-transactions').isVisible(), true, 'Transactions tab opens with an older GCash script cached');
        assert.equal(await page.evaluate(() => window.legacyGCashScript), undefined, 'Stale unversioned script is bypassed');
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            for (const [name, panel] of [['Reconciliation Log', 'reconciliation'], ['Unresolved Activity', 'unresolved'], ['Balances & Summary', 'overview'], ['Transactions & Filters', 'transactions']]) {
                await page.getByRole('tab', {name}).click();
                assert.equal(await page.locator('#tab-' + panel).isVisible(), true, name + ' opens at ' + width + 'px');
            }
            await page.keyboard.press('Home');
            assert.equal(await page.locator('#tab-overview').isVisible(), true);
            await page.keyboard.press('End');
            await page.waitForURL(url => url.searchParams.get('tab') === 'money');
            await page.locator('#tab-money').waitFor({state: 'visible'});
            assert.equal(await page.locator('#tab-money').isVisible(), true);
        }
        assert.deepEqual(errors, []);
        console.log('GCash cached-script tab regression: passed');
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
