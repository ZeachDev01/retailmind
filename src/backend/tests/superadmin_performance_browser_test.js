const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Superadmin performance: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-superadmin-performance-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const screens = [
    'super_administrator/dashboard', 'user_manager/user_manager',
    'system_administrator/audit_logs', 'system_administrator/recovery_account',
    'system_administrator/system_health', 'system_administrator/ml_settings',
    'administrator/reset_data', 'system_administrator/backup_restore',
    'system_administrator/system_settings', 'system_administrator/database_updates',
    'system_administrator/forecast_import', 'report/predictions',
    'report/forecast_analytics', 'report/forecast_exceptions', 'report/data_readiness',
    'auth/user_info', 'auth/preferences', 'auth/change_password',
    'auth/workspace', 'notification/notifications',
];
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('reports');
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
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({serviceWorkers: 'block'});
        // A slow external host must not hold up the application's HTML parser.
        await context.route('https://**/*', async route => {
            await new Promise(resolve => setTimeout(resolve, 1000));
            await route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'});
        });
        const page = await context.newPage();
        const errors = [], failedAssets = [], externalRequests = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => { if (response.status() >= 400) failedAssets.push(response.url()); });
        page.on('request', request => { if (request.url().startsWith('https:')) externalRequests.push(request.url()); });
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const results = [];
        externalRequests.length = 0;
        for (const screen of screens) {
            const response = await page.goto(origin + '/components/' + screen + '.php', {waitUntil: 'domcontentloaded'});
            assert.equal(response.status(), 200, screen);
            const timing = await page.evaluate(() => {
                const nav = performance.getEntriesByType('navigation')[0];
                return {serverMs: Math.round(nav.responseStart - nav.requestStart), readyMs: Math.round(nav.domContentLoadedEventStart - nav.startTime),
                    blockingScripts: Array.from(document.scripts).filter(script => script.src.startsWith('https:') && !script.defer && !script.async && script.type !== 'module').map(script => script.src)};
            });
            results.push({screen, ...timing});
            console.log(JSON.stringify(results.at(-1)));
        }
        assert.deepEqual(results.filter(result => result.blockingScripts.length), [], 'External scripts block Superadmin page readiness on slow connections');
        assert.deepEqual(externalRequests, [], 'Superadmin pages must load without external asset hosts');
        await page.goto(origin + '/components/system_administrator/audit_logs.php');
        assert.equal(await page.evaluate(() => DataTable.isDataTable('#auditLogsTable')), true);
        await page.evaluate(() => { window.originalDataTable = window.DataTable; });
        await page.evaluate(() => RetailMindNavigation.navigate('../auth/preferences.php'));
        await page.evaluate(() => RetailMindNavigation.navigate('../system_administrator/audit_logs.php'));
        assert.equal(await page.evaluate(() => window.DataTable === window.originalDataTable && DataTable.isDataTable('#auditLogsTable')), true, 'Vendor libraries stay loaded and audit filters reinitialize across navigation');
        assert.equal(await page.evaluate(async () => (await document.fonts.load('16px bootstrap-icons')).length > 0), true, 'Local icon font loads');
        await page.evaluate(async () => {
            const alert = Swal.fire({title: 'Performance check', timer: 1});
            await alert;
        });
        assert.deepEqual(errors, [], 'Pages and navigation must not throw JavaScript errors');
        assert.deepEqual(failedAssets, [], 'Local assets must load successfully');
        console.log('Superadmin performance: passed (20 routes, no external assets, audit filters, icons, alerts, persistent vendor libraries)');
    } finally {
        await browser?.close();
        if (server) {
            server.kill();
            await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve));
        }
        fixture('cleanup');
        fs.rmSync(output, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
