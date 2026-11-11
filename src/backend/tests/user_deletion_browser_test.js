const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {chromium} = require('playwright');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
if (process.env.RUN_DB_TESTS !== '1') { console.log('User deletion browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-user-deletion-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery, SESSION_SAVE_PATH: path.join(recovery, 'sessions'), RM_THEME_TEST_DATABASE: database};
const fixture = (action, file = 'administrator_minimum_fixture.php') => {
    const result = spawnSync(php, ['src/backend/tests/support/' + file, action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const {password} = JSON.parse(fixture('create', 'theme_fixture.php'));
        fixture('seed'); fixture('deletion');
        const cookie = JSON.parse(fixture('legacy_session'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env, stdio: ['ignore', 'ignore', 'pipe']});
        let errors = ''; server.stderr.on('data', data => { errors += data; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            assert(attempt < 99, errors);
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({baseURL: origin});
        const page = await context.newPage();
        page.on('pageerror', error => { throw error; });
        const route = '/components/user_manager/user_manager.php';
        const legacy = await browser.newContext({baseURL: origin});
        await legacy.addCookies([{...cookie, url: origin}]);
        assert.equal((await legacy.request.get(route, {maxRedirects: 0})).status(), 200, 'Pre-existing imported-account session has access');
        await page.goto('/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(route);
        const state = () => JSON.parse(fixture('state'));
        const open = async name => {
            await page.getByRole('button', {name: 'Manage ' + name, exact: true}).click();
            await page.locator('#drawerFirstName').waitFor({state: 'visible'});
        };
        for (const username of ['theme_admin', 'theme_super_admin']) {
            if (username === 'theme_super_admin') {
                await context.clearCookies();
                await page.goto('/?login=1');
                await page.locator('#landing-login-username').fill(username);
                await page.locator('#landing-login-password').fill(password);
                await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
                await page.goto(route);
            }
            assert.equal(await page.locator('#drawerDeleteButton, #drawerDeleteForm').count(), 0);
            const csrf = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
            const before = state();
            const response = await context.request.post(route, {form: {action: 'delete', user_id: '100', confirm_delete: '1', csrf_token: csrf}});
            assert.match(await response.text(), /Accounts cannot be deleted/);
            assert.deepEqual(state(), before);
        }
        await page.goto(route);
        await open('Minimum Primary');
        assert(await page.locator('#drawerFirstName').isEnabled());
        assert(await page.locator('#drawerProfileImage').isEnabled());
        await page.locator('#drawerFirstName').fill('Edited Administrator');
        await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
        assert.equal(state().users[0].first_name, 'Edited Administrator');
        await open('Edited Administrator');
        await page.locator('#drawerTabSecurity').click();
        assert(await page.locator('#drawerPassword').isEnabled());
        await page.locator('#drawerPassword').fill('ReplacementPassword123');
        await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
        assert.equal(Number(state().users[0].must_change_password), 1);
        await open('Edited Administrator');
        await page.locator('#drawerTabSecurity').click();
        const beforeRevoke = Number(state().users[0].session_version);
        await Promise.all([page.waitForNavigation(), page.locator('#drawerRevokeButton').click()]);
        assert.equal(Number(state().users[0].session_version), beforeRevoke + 1);
        for (const status of ['disabled', 'active']) {
            await open('Edited Administrator');
            await page.locator('#drawerTabStatus').click();
            await page.locator('#drawerStatus').selectOption(status);
            await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
            assert.equal(state().users[0].status, status);
            assert.equal(Number(state().users[0].must_change_password), 1);
        }
        for (const viewport of [{width: 390, height: 844}, {width: 1280, height: 800}]) {
            await page.setViewportSize(viewport);
            await open('Edited Administrator');
            assert(await page.locator('#drawerStatusButton').isVisible());
            assert.equal(await page.locator('#drawerDeleteButton').count(), 0);
            await page.locator('#cancelUserDrawer').click();
        }
        console.log('Super Administrator management and no-delete browser/HTTP: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup', 'theme_fixture.php');
        fs.rmSync(recovery, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
