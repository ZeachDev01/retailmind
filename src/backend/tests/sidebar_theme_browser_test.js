const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Sidebar theme browser: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-sidebar-theme-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery};
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
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        for (const role of ['admin', 'super_admin', 'inventory_manager', 'cashier']) {
            const context = await browser.newContext({baseURL: origin});
            const page = await context.newPage();
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            for (const mode of ['dark', 'light', 'system']) {
                await page.locator('[data-account-menu-open]').filter({visible: true}).last().click();
                await page.locator('.sidebar-preferences-menu > summary').click();
                await page.locator('.sidebar-theme-control > summary').click();
                const saved = page.waitForResponse(response => response.url().endsWith('/auth/theme.php') && response.request().method() === 'POST');
                await page.locator('.sidebar-theme-control [data-theme-mode="' + mode + '"]').click();
                assert.equal((await saved).status(), 200);
                await page.reload();
                assert.equal(await page.locator('.sidebar-theme-control [data-theme-current]').getAttribute('data-theme-current'), mode);
                const token = await page.evaluate(() => window.retailmindTheme.csrf);
                assert.equal((await context.request.post('/components/auth/theme.php', {form: {mode: 'blue', csrf_token: token}})).status(), 422);
                assert.equal((await context.request.post('/components/auth/theme.php', {form: {mode: 'light', csrf_token: 'bad'}})).status(), 403);
            }
            await context.close();
        }
        console.log('Sidebar theme browser: passed (four roles, current Preferences menu, Light/Dark/System persistence, invalid mode and CSRF rejection)');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
