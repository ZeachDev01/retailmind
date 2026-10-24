const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {chromium} = require('playwright');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
if (process.env.RUN_DB_TESTS !== '1') { console.log('Administrator minimum browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-admin-minimum-'));
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
        fixture('seed'); fixture('one');
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
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const route = '/components/user_manager/user_manager.php';
        await page.goto(route);
        const row = page.locator('tr').filter({hasText: 'Minimum Additional'});
        assert(await row.getByText('Last active Administrator', {exact: true}).isVisible());
        await row.getByRole('button', {name: 'Manage Minimum Additional'}).click();
        await page.locator('#drawerAdministratorMinimumHelp').waitFor({state: 'visible'});
        assert(await page.locator('#drawerRoles input[data-role="admin"]').isChecked());
        assert(await page.locator('#drawerRoles input[data-role="admin"]').isDisabled());
        assert(await page.locator('#drawerStatus option[value="disabled"]').isDisabled());
        assert(await page.locator('#drawerStatusButton').isEnabled(), 'Profile edits remain available');
        const before = JSON.parse(fixture('state'));
        const token = await page.locator('#userDrawerForm input[name="csrf_token"]').inputValue();
        for (const form of [
            {action: 'toggle', user_id: '101'},
            {action: 'update', user_id: '101', first_name: 'Must roll back', username: 'changed_minimum', 'role_ids[]': '4'},
            {action: 'update', user_id: '101', first_name: 'Must roll back', username: 'changed_minimum', 'role_ids[]': '1', status: 'disabled', new_password: 'ChangedPassword123'},
        ]) {
            const response = await context.request.post(route, {form: {...form, csrf_token: token}});
            assert((await response.text()).includes('Keep at least one active ordinary account with Administrator access.'), 'Forged request receives useful minimum rejection');
            assert.deepEqual(JSON.parse(fixture('state')), before, 'Denied combined save rolls back profile, roles, password, sessions and audits');
        }
        await page.locator('#drawerFirstName').fill('Updated');
        await Promise.all([page.waitForNavigation(), page.locator('#drawerStatusButton').click()]);
        const saved = JSON.parse(fixture('state')).users[1];
        assert.equal(saved.full_name, 'Updated Additional');
        assert(saved.roles.includes('admin'), 'Normal browser save retains protected access');
        console.log('Administrator minimum browser/HTTP: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup', 'theme_fixture.php');
        fs.rmSync(recovery, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
