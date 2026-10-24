const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {chromium} = require('playwright');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Administrator management browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-admin-management-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery, RM_THEME_TEST_DATABASE: database};
const fixture = (action, file = 'administrator_minimum_fixture.php') => {
    const result = spawnSync(php, ['src/backend/tests/support/' + file, action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const {password} = JSON.parse(fixture('create', 'theme_fixture.php'));
        fixture('seed'); fixture('management');
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
        page.on('pageerror', error => console.error(error));
        await page.goto('/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const route = '/components/user_manager/user_manager.php';
        await page.goto(route);
        const state = () => JSON.parse(fixture('state'));
        const row = page.locator('tr').filter({hasText: 'Minimum Additional'});
        assert.equal(await row.getByText('Protected', {exact: true}).count(), 0);
        assert(await page.locator('tr').filter({hasText: 'Theme Test super_admin'}).getByText('Protected', {exact: true}).isVisible());
        await page.locator('#openUserModal').click();
        await page.locator('#createUsernameInput').fill('created_administrator');
        await page.locator('#createFirstName').fill('Created');
        await page.locator('#createLastName').fill('Administrator');
        await page.locator('#createUserPassword').fill('CreatedPassword123');
        await page.locator('#createUserForm input[name="role_ids[]"][value="1"]').check();
        assert.equal(await page.locator('#createUserForm input[name="role_ids[]"][value="2"]').count(), 0);
        await Promise.all([page.waitForNavigation(), page.locator('#createUserSubmit').click()]);
        assert(await page.getByText('User created successfully in the Store.', {exact: true}).isVisible());
        assert(await page.locator('tr').filter({hasText: 'Created Administrator'}).getByRole('button', {name: 'Manage Created Administrator'}).isVisible());
        const open = async () => {
            await row.getByRole('button', {name: /Manage/}).click();
            await page.locator('#drawerFirstName').waitFor({state: 'visible'});
        };
        const save = async () => {
            await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
            assert(await page.getByText('User account updated successfully.', {exact: true}).isVisible());
        };
        await open();
        await page.locator('#drawerFirstName').fill('Edited');
        await save();
        assert.equal(state().users[1].full_name, 'Edited Additional');
        // Keep the locator stable after editing the display name.
        const editedRow = page.locator('tr').filter({hasText: 'Edited Additional'});
        const reopen = async () => {
            await editedRow.getByRole('button', {name: 'Manage Edited Additional'}).click();
            await page.locator('#drawerFirstName').waitFor({state: 'visible'});
        };
        await reopen();
        await page.locator('#drawerTabSecurity').click();
        assert(await page.locator('#drawerPasswordResetHelp').isVisible());
        await page.locator('#drawerPassword').fill('ReplacedPassword123');
        const beforeReset = state().users[1];
        await save();
        assert.equal(Number(state().users[1].session_version), Number(beforeReset.session_version) + 1);
        assert.equal(Number(state().users[1].must_change_password), 1);
        for (const status of ['disabled', 'active']) {
            await reopen();
            await page.locator('#drawerTabStatus').click();
            await page.locator('#drawerStatus').selectOption(status);
            await save();
            assert.equal(state().users[1].status, status);
        }
        await reopen();
        await page.locator('#drawerRoles input[data-role="admin"]').uncheck();
        const beforeDemotion = state().users[1];
        await save();
        assert.deepEqual(state().users[1].roles, ['cashier']);
        assert.equal(Number(state().users[1].session_version), Number(beforeDemotion.session_version) + 1);
        await reopen();
        await page.locator('#drawerRoles input[data-role="admin"]').check();
        await save();
        assert(state().users[1].roles.includes('admin'), 'Staff promotion through Users');
        const token = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
        const beforeForbidden = state();
        for (const form of [
            {action: 'toggle', user_id: '2'},
            {action: 'update', user_id: '100', first_name: 'Forbidden', username: 'minimum_primary', 'role_ids[]': '2'},
            {action: 'toggle', user_id: '1'},
            {action: 'update', user_id: '1', first_name: 'Forbidden', username: 'theme_admin', 'role_ids[]': '4'},
        ]) {
            const response = await context.request.post(route, {form: {...form, csrf_token: token}});
            assert(!(await response.text()).includes('User account updated successfully.'));
            assert.deepEqual(state(), beforeForbidden, 'Forbidden requests preserve target state and audits');
        }
        for (const role of ['cashier', 'inventory_manager']) {
            const other = await browser.newContext({baseURL: origin});
            const login = await other.newPage();
            await login.goto('/?login=1');
            await login.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await login.locator('#landing-login-username').fill('theme_' + role);
            await login.locator('#landing-login-password').fill(password);
            await Promise.all([login.waitForNavigation(), login.getByRole('button', {name: 'Log in', exact: true}).click()]);
            const csrf = await login.locator('input[name="csrf_token"]').first().inputValue();
            const before = state();
            for (const form of [
                {action: 'create', first_name: 'Forbidden', username: 'forbidden_' + role, password: 'ForbiddenPassword123', 'role_ids[]': '1'},
                {action: 'update', user_id: '100', first_name: 'Forbidden', username: 'minimum_primary', new_password: 'ForbiddenPassword123', 'role_ids[]': '4'},
                {action: 'toggle', user_id: '100'},
                {action: 'revoke_sessions', user_id: '100'},
                {action: 'delete', user_id: '100', confirm_delete: '1'},
            ]) {
                const response = await other.request.post(route, {form: {...form, csrf_token: csrf}, maxRedirects: 0});
                assert.equal(response.status(), 403, role + ' Workspace denies Users mutations despite assigned Administrator access');
                assert.deepEqual(state(), before);
            }
            await other.close();
        }
        console.log('Administrator management browser/HTTP: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup', 'theme_fixture.php');
        fs.rmSync(recovery, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
