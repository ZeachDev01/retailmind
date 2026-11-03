const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Workspace Help confirmations: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-help-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const fixture = action => {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
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
        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        page.setDefaultTimeout(5000);
        for (const [role, label, task] of [
            ['admin', 'Confirm calendar review', 'store_calendar'],
            ['inventory_manager', 'Confirm product setup', 'products'],
        ]) {
            await page.goto(origin + '/components/auth/logout.php');
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            await page.goto(origin + '/components/auth/help.php');
            const taskItem = page.locator('.setup-task-item').filter({has: page.getByRole('button', {name: label, exact: true})});
            await taskItem.getByRole('button', {name: label, exact: true}).click();
            await page.locator('.setup-task-item', {hasText: role === 'admin' ? 'Store calendar review' : 'Products and opening stock'})
                .locator('.setup-task-status', {hasText: 'Confirmed'}).waitFor();
            assert.ok(JSON.parse(fixture('setup_state')).some(row => row.task_key === task && row.confirmed_workspace === role), role + ' confirmation persists');
            await page.reload();
            assert.equal(await page.getByRole('button', {name: label, exact: true}).count(), 0, role + ' confirmation survives reload');
            console.log(role + ': Help confirmation passed');
        }
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
