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
        const csrf = await page.locator('#drawerDeleteForm input[name="csrf_token"]').inputValue();
        const open = async name => {
            await page.getByRole('button', {name: 'Manage ' + name, exact: true}).click();
            await page.locator('#drawerFirstName').waitFor({state: 'visible'});
        };
        assert(await page.locator('tr').filter({hasText: 'Theme Test super_admin'}).getByText('Protected', {exact: true}).isVisible());
        await open('Theme Test admin');
        assert(await page.locator('#drawerDeleteButton').isDisabled());
        assert.match(await page.locator('#drawerDeleteHelp').textContent(), /own account/);
        await page.locator('#closeUserDrawer').click();
        await open('Minimum Primary');
        assert(await page.locator('#drawerDeleteButton').isEnabled(), 'Ordinary Administrator target is eligible');
        const before = state();
        await page.locator('#drawerUsername').fill('unsaved_other_identity');
        page.once('dialog', async dialog => { assert.match(dialog.message(), /minimum_primary_100/); assert.doesNotMatch(dialog.message(), /unsaved_other_identity/); await dialog.dismiss(); });
        await page.locator('#drawerDeleteButton').click();
        assert.deepEqual(state(), before, 'Cancelling deletion preserves account and audits');
        for (const viewport of [{width: 1280, height: 900}, {width: 390, height: 844}]) {
            await page.setViewportSize(viewport);
            await page.locator('#drawerDeleteButton').scrollIntoViewIfNeeded();
            assert(await page.locator('#drawerDeleteButton').isVisible());
            assert(await page.locator('#drawerDeleteHelp').isVisible());
            await page.screenshot({path: path.join(os.tmpdir(), 'retailmind-delete-' + viewport.width + '.png')});
        }
        const unconfirmed = await context.request.post(route, {form: {action: 'delete', user_id: '100', csrf_token: csrf}});
        assert.match(await unconfirmed.text(), /Confirm permanent account deletion first/);
        assert.deepEqual(state(), before);
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation(), page.locator('#drawerDeleteButton').click()]);
        assert(await page.getByText('User permanently deleted. Credentials and access have been removed.', {exact: true}).isVisible());
        assert.equal(await page.getByRole('button', {name: 'Manage Minimum Primary', exact: true}).count(), 0);
        const deniedSession = await legacy.request.get(route, {maxRedirects: 0});
        assert.equal(deniedSession.status(), 302);
        assert.match(deniedSession.headers().location, /session=invalid/, 'Existing session loses access immediately');
        const reset = await legacy.request.get('/components/auth/reset_password.php?token=imported-reset-token');
        assert.match(await reset.text(), /invalid|expired/i, 'Deleted password reset credential cannot be used');
        const login = await legacy.newPage();
        await login.goto('/?login=1');
        await login.locator('#landing-login-username').fill('minimum_primary_100');
        await login.locator('#landing-login-password').fill('ImportedPassword123');
        await Promise.all([login.waitForNavigation(), login.getByRole('button', {name: 'Log in', exact: true}).click()]);
        assert.match(await login.content(), /Invalid username or password/i, 'Deleted credentials cannot authenticate');
        await legacy.close();
        await open('Minimum Additional');
        assert(await page.locator('#drawerDeleteButton').isEnabled(), 'Disabled account can be deleted');
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation(), page.locator('#drawerDeleteButton').click()]);
        assert.equal(state().users.length, 0);
        await page.locator('#openUserModal').click();
        await page.locator('#createFirstName').fill('History');
        await page.locator('#createLastName').fill('Linked');
        await page.locator('#createUsernameInput').fill('history_linked');
        await page.locator('#createUserPassword').fill('CreatedPassword123');
        await page.locator('#createUserForm input[name="role_ids[]"][value="4"]').check();
        await Promise.all([page.waitForNavigation(), page.locator('#createUserSubmit').click()]);
        await open('History Linked');
        assert(await page.locator('#drawerDeleteButton').isDisabled());
        assert.match(await page.locator('#drawerDeleteHelp').textContent(), /retained business or audit history.*Disable it instead/);
        const historyId = await page.locator('#drawerDeleteUserId').inputValue();
        const rejected = await context.request.post(route, {form: {action: 'delete', user_id: historyId, confirm_delete: '1', csrf_token: csrf}});
        assert.match(await rejected.text(), /retained business or audit history/);
        await page.locator('#drawerTabStatus').click();
        assert(await page.locator('#drawerStatus').isEnabled(), 'History-linked account can still be disabled');
        await page.locator('#drawerStatus').selectOption('disabled');
        await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
        assert(await page.getByText('User account updated successfully.', {exact: true}).isVisible());
        for (const target of ['1', '2']) {
            const response = await context.request.post(route, {form: {action: 'delete', user_id: target, confirm_delete: '1', csrf_token: csrf}});
            assert.doesNotMatch(await response.text(), /User permanently deleted\. Credentials/);
        }
        console.log('User deletion browser/HTTP: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup', 'theme_fixture.php');
        fs.rmSync(recovery, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
