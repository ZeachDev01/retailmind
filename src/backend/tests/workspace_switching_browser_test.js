const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Workspace switching: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-workspace-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const mobile = process.argv.includes('--mobile');
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL('**/super_administrator/dashboard.php'), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
        await page.reload();
        await page.goto(origin + '/components/auth/logout.php');
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL('**/super_administrator/dashboard.php'), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        if (mobile) await page.setViewportSize({width: 375, height: 812});
        for (const [label, route] of [
            ['Administrator', 'administrator/dashboard'],
            ['Inventory', 'inventory_management/inventory_overview'],
            ['Cashier', 'cashier/pos'],
            ['Super Administrator', 'super_administrator/dashboard'],
        ]) {
            await page.locator(mobile ? '.admin-mobile-avatar' : '#sidebarProfile').click();
            await page.locator('.sidebar-workspace-switcher > summary').click();
            const [response] = await Promise.all([
                page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/auth/workspace.php')),
                page.locator('.sidebar-workspace-options').getByRole('button', {name: label, exact: true}).click(),
            ]);
            assert.equal(response.status(), 302, 'Workspace switch must redirect successfully after cached-page re-login; HTTP ' + response.status());
            await page.waitForURL('**/components/' + route + '.php');
            await page.reload();
            assert.equal(new URL(page.url()).pathname, '/components/' + route + '.php', label + ' workspace must persist');
            assert.equal((await page.locator('.sidebar-workspace-option.is-current > span').textContent()).trim(), label);
        }
        console.log('Workspace switching browser: passed (Super Administrator -> Administrator -> Inventory -> Cashier -> Super Administrator, including reload)');
    } finally {
        if (browser) await browser.close();
        if (server) {
            server.kill();
            await new Promise(resolve => server.once('exit', resolve));
        }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
