const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Backup package browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const backupPassphrase = 'Reset package passphrase 2026';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-backup-package-'));
const env = { ...process.env, BACKUP_STORAGE_PATH: path.join(testDirectory, 'recovery') };
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-d', 'memory_limit=512M', '-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const login = async role => {
            const context = await browser.newContext({ reducedMotion: 'reduce' });
            const page = await context.newPage();
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(/\/components\//), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
            return { context, page };
        };
        const administrator = await login('admin');
        const secondAdministrator = await login('admin');
        const superAdministrator = await login('super_admin');
        const manager = await login('inventory_manager');
        const cashier = await login('cashier');
        const adminUrl = origin + '/components/administrator/database_backup.php';
        const superUrl = origin + '/components/system_administrator/backup_restore.php';
        const downloadUrl = origin + '/components/backup/backup_download.php';
        for (const actor of [manager, cashier]) {
            assert.equal((await actor.page.goto(adminUrl)).status(), 403, 'Operational workspaces cannot create backups');
            assert.equal((await actor.context.request.get(downloadUrl + '?token=invalid')).status(), 403, 'Operational workspaces cannot download backups');
        }
        assert.equal((await administrator.page.goto(superUrl)).status(), 403, 'Administrator cannot view restricted preflight');
        assert.equal((await administrator.page.goto(adminUrl)).status(), 200);
        assert.equal(await administrator.page.getByLabel('New backup passphrase', { exact: true }).count(), 1);
        assert.equal((await administrator.context.request.post(adminUrl, { form: { action: 'backup', backup_passphrase: backupPassphrase } })).status(), 403, 'Creation needs CSRF');
        await administrator.page.locator('#backup-passphrase').fill(backupPassphrase);
        await Promise.all([administrator.page.waitForNavigation(), administrator.page.locator('[data-backup-create]').click()]);
        assert.equal(await administrator.page.locator('[data-backup-download]').count(), 1, await administrator.page.locator('body').innerText());
        const href = await administrator.page.locator('[data-backup-download]').getAttribute('href');
        assert(href, await administrator.page.locator('body').innerText());
        assert.equal((await superAdministrator.context.request.get(new URL(href, origin).href)).status(), 404, 'Another user cannot claim the package');
        const download = await administrator.context.request.get(new URL(href, origin).href);
        assert.equal(download.status(), 200);
        assert.match(download.headers()['content-type'], /application\/octet-stream/);
        const packageBytes = await download.body();
        assert.equal(packageBytes.subarray(0, 8).toString(), 'RMSTORE1');
        assert(!packageBytes.includes(Buffer.from('theme_admin')), 'Stolen package must not expose usernames');
        assert.equal((await administrator.context.request.get(new URL(href, origin).href)).status(), 404, 'Delivered temporary package is removed');
        await superAdministrator.page.goto(superUrl);
        assert.equal(await superAdministrator.page.getByLabel('Current destination login password').count(), 1);
        assert.equal(await superAdministrator.page.getByLabel("This package's original backup passphrase").count(), 1);
        assert.equal(await superAdministrator.page.locator('#backup-file').getAttribute('accept'), '.rmpkg');
        for (const width of [390, 1440]) {
            await superAdministrator.page.setViewportSize({ width, height: 960 });
            assert(await superAdministrator.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Preflight fits viewport');
        }
        const submit = async (loginPassword, passphrase, bytes = packageBytes, filename = 'store.rmpkg') => {
            await superAdministrator.page.goto(superUrl);
            const csrf = await superAdministrator.page.locator('input[name="csrf_token"]').first().inputValue();
            return superAdministrator.context.request.post(superUrl, { multipart: { action: 'preflight', csrf_token: csrf,
                restore_password: loginPassword, backup_passphrase: passphrase,
                backup_file: { name: filename, mimeType: 'application/octet-stream', buffer: bytes } } });
        };
        assert.match(await (await submit('wrong-password', backupPassphrase)).text(), /password is incorrect/);
        assert.match(await (await submit(data.password, 'Incorrect package passphrase 2026')).text(), /passphrase|authenticate|decrypt/i);
        assert.match(await (await submit(data.password, backupPassphrase, Buffer.from('DROP TABLE users;'), 'old.sql')).text(), /encrypted|rmpkg|package/i);
        const checked = await submit(data.password, backupPassphrase);
        assert.equal(checked.status(), 200);
        assert.match(await checked.text(), /No Store records or files were replaced/);
        const identityUrl = origin + '/components/system_administrator/identity_reconciliation.php';
        assert.equal((await administrator.page.goto(identityUrl)).status(), 403, 'Administrator cannot reconcile privileged identities');
        assert.equal((await manager.page.goto(identityUrl)).status(), 403);
        assert.equal((await superAdministrator.context.request.post(identityUrl, { form: { action: 'review' } })).status(), 403, 'Identity choices need CSRF');
        await superAdministrator.page.goto(identityUrl);
        const fillIdentityCredentials = async () => {
            await superAdministrator.page.getByLabel('Encrypted Store package', { exact: true }).setInputFiles({ name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: packageBytes });
            await superAdministrator.page.getByLabel('Current destination login password').fill(data.password);
            await superAdministrator.page.getByLabel("This package's original backup passphrase").fill(backupPassphrase);
        };
        await fillIdentityCredentials();
        await Promise.all([superAdministrator.page.waitForNavigation(), superAdministrator.page.getByRole('button', { name: 'Inspect people and conflicts' }).click()]);
        assert.equal(await superAdministrator.page.getByRole('heading', { name: 'Destination people', exact: true }).count(), 1, await superAdministrator.page.locator('body').innerText());
        assert.match(await superAdministrator.page.locator('body').innerText(), /share numeric ID/);
        for (const id of [1, 2, 3, 4]) {
            assert.equal(await superAdministrator.page.locator('#resolution-' + id).inputValue(), '', 'Matching ID never preselects a link');
            await superAdministrator.page.locator('#resolution-' + id).selectOption('link');
            await superAdministrator.page.locator('#destination-' + id).selectOption(String(id));
        }
        await fillIdentityCredentials();
        await Promise.all([superAdministrator.page.waitForNavigation(), superAdministrator.page.getByRole('button', { name: 'Review resolutions' }).click()]);
        assert.match(await superAdministrator.page.getByRole('status').innerText(), /ready for destination security approval/);
        assert(!await superAdministrator.page.locator('body').innerHTML().then(html => html.includes('$2y$') || html.includes('$argon2')), 'Review never emits password verifiers');
        for (const width of [390, 1440]) {
            await superAdministrator.page.setViewportSize({ width, height: 960 });
            assert(await superAdministrator.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Identity review fits viewport');
        }
        fixture('identity_historical');
        await manager.page.goto(identityUrl);
        assert(!manager.page.url().endsWith('/identity_reconciliation.php'), 'Roleless Historical Actor loses an existing session');
        const historicalContext = await browser.newContext();
        const historicalPage = await historicalContext.newPage();
        await historicalPage.goto(origin + '/?login=1');
        await historicalPage.locator('#landing-login-username').fill('theme_inventory_manager');
        await historicalPage.locator('#landing-login-password').fill(data.password);
        await Promise.all([historicalPage.waitForNavigation(), historicalPage.getByRole('button', { name: 'Log in', exact: true }).click()]);
        assert(!historicalPage.url().includes('/components/'), 'Historical Actor cannot authenticate with the former password');
        const historicalCsrf = await historicalPage.locator('input[name="csrf_token"]').first().inputValue();
        await historicalContext.request.post(origin + '/components/auth/forgot_password.php', { form: { csrf_token: historicalCsrf, identity: 'historical@example.test' } });
        assert.equal(fixture('identity_reset_count').trim(), '0', 'Historical Actor cannot request a reset token');
        await historicalContext.close();
        await superAdministrator.page.goto(superUrl);
        await superAdministrator.page.locator('#backup-passphrase').fill(backupPassphrase);
        await Promise.all([superAdministrator.page.waitForNavigation(), superAdministrator.page.locator('[data-backup-create]').click()]);
        assert.equal(await superAdministrator.page.locator('[data-backup-download]').count(), 1, await superAdministrator.page.locator('body').innerText());
        assert.equal((await secondAdministrator.page.goto(adminUrl)).status(), 200, 'Preflight keeps destination users and sessions usable');
        const workspaceUrl = origin + '/components/auth/workspace.php';
        const selectWorkspace = async workspace => {
            await superAdministrator.page.goto(workspaceUrl);
            const csrf = await superAdministrator.page.locator('input[name="csrf_token"]').first().inputValue();
            const response = await superAdministrator.context.request.post(workspaceUrl, { form: { csrf_token: csrf, workspace } });
            assert.equal(response.status(), 200);
        };
        await selectWorkspace('cashier');
        assert.equal((await superAdministrator.page.goto(adminUrl)).status(), 403, 'An assigned administrator role cannot bypass the active Cashier Workspace');
        assert.equal((await superAdministrator.context.request.get(downloadUrl + '?token=invalid')).status(), 403);
        await selectWorkspace('admin');
        assert.equal((await superAdministrator.page.goto(adminUrl)).status(), 200, 'Secondary Administrator membership can use its own Workspace');
        await superAdministrator.page.locator('#backup-passphrase').fill(backupPassphrase);
        await Promise.all([superAdministrator.page.waitForNavigation(), superAdministrator.page.locator('[data-backup-create]').click()]);
        assert.equal(await superAdministrator.page.locator('[data-backup-download]').count(), 1, 'Secondary Administrator membership can capture packages');
        fixture('backup_stale_session');
        await secondAdministrator.page.goto(adminUrl);
        assert(!secondAdministrator.page.url().endsWith('/components/administrator/database_backup.php'), 'Stale sessions cannot view backup history or creation');
        const stale = await administrator.context.request.get(downloadUrl + '?token=invalid', { maxRedirects: 0 });
        assert.equal(stale.status(), 302, 'Stale sessions cannot download packages');
        console.log('Encrypted package browser: passed (both screens, role denial, CSRF, private download, distinct credentials, read-only preflight)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        const relative = path.relative(os.tmpdir(), testDirectory);
        assert(relative.startsWith('rm-backup-package-') && !relative.includes(path.sep), 'Cleanup must stay inside the generated test directory');
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
