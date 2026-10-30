const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Encrypted restore browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
async function runScenario(initiator) {
    const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
    const php = process.env.PHP_BINARY || 'php';
    const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-encrypted-restore-'));
    const env = { ...process.env, BACKUP_STORAGE_PATH: path.join(testDirectory, 'recovery') };
    const passphrase = 'Incoming backup passphrase 2026';
    const safetyPassphrase = 'Independent safety passphrase 2026';
    const fixture = action => {
        const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], { encoding: 'utf8', env });
        assert.equal(result.status, 0, result.stderr || result.stdout);
        return result.stdout;
    };
    let browser, server;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('reports');
        fixture('receipts');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        // Reproduce hosts ignoring per-directory PHP memory settings.
        const log = fs.openSync(path.join(testDirectory, 'server.log'), 'w');
        server = spawn(php, ['-d', 'memory_limit=128M', '-S', origin.slice(7), '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: ['ignore', log, log] });
        fs.closeSync(log);
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const login = async role => {
            const context = await browser.newContext();
            const page = await context.newPage();
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(/\/components\//), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
            return { context, page };
        };
        const admin = await login(initiator);
        const superAdmin = await login('super_admin');
        const manager = await login('inventory_manager');
        const adminUrl = origin + '/components/administrator/database_backup.php';
        const restoreUrl = origin + '/components/backup/restore.php';
        assert.equal((await manager.context.request.post(restoreUrl)).status(), 403);
        assert.equal((await admin.context.request.post(restoreUrl)).status(), 403, 'Restore rejects missing CSRF');
        await admin.page.goto(adminUrl);
        await admin.page.locator('#backup-passphrase').fill(passphrase);
        await Promise.all([admin.page.waitForNavigation(), admin.page.locator('[data-backup-create]').click()]);
        const href = await admin.page.locator('[data-backup-download]').getAttribute('href');
        assert(href, await admin.page.locator('body').innerText());
        const download = await admin.context.request.get(new URL(href, origin).href);
        assert.equal(download.status(), 200);
        const bytes = await download.body();
        assert.equal(bytes.subarray(0, 8).toString(), 'RMSTORE1');
        const start = async password => {
            await admin.page.goto(adminUrl);
            const csrf = await admin.page.locator('input[name="csrf_token"]').first().inputValue();
            return admin.context.request.post(restoreUrl, { maxRedirects: 0, multipart: {
                csrf_token: csrf, confirm_replacement: '1', restore_password: password,
                backup_passphrase: passphrase, safety_passphrase: safetyPassphrase,
                backup_file: { name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: bytes }
            } });
        };
        await start(data.password);
        assert(!fs.existsSync(path.join(env.BACKUP_STORAGE_PATH, 'restore-state.json')), 'Missing approval leaves destination untouched');
        const identityUrl = origin + '/components/system_administrator/identity_reconciliation.php';
        await superAdmin.page.goto(identityUrl);
        const fill = async () => {
            await superAdmin.page.locator('#identity-package').setInputFiles({ name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: bytes });
            await superAdmin.page.locator('#identity-password').fill(data.password);
            await superAdmin.page.locator('#identity-passphrase').fill(passphrase);
        };
        await fill();
        await Promise.all([superAdmin.page.waitForNavigation(), superAdmin.page.getByRole('button', { name: 'Inspect people and conflicts' }).click()]);
        for (const id of [1, 2, 3, 4]) {
            await superAdmin.page.locator('#resolution-' + id).selectOption('link');
            await superAdmin.page.locator('#destination-' + id).selectOption(String(id));
        }
        await fill();
        await Promise.all([superAdmin.page.waitForNavigation(), superAdmin.page.getByRole('button', { name: 'Review resolutions', exact: true }).click()]);
        await fill();
        await Promise.all([superAdmin.page.waitForNavigation(), superAdmin.page.getByRole('button', { name: 'Approve reconciliation and record preparation evidence' }).click()]);
        assert.match(await superAdmin.page.locator('body').innerText(), /Reconciliation approved/);
        await start('Wrong destination password');
        assert(!fs.existsSync(path.join(env.BACKUP_STORAGE_PATH, 'restore-state.json')), 'Wrong password leaves Store open');
        await admin.page.goto(adminUrl);
        for (const width of [390, 1440]) {
            await admin.page.setViewportSize({ width, height: 960 });
            assert(await admin.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Restore form fits viewport');
        }
        await admin.page.locator('#incoming-package').setInputFiles({ name: 'store.rmpkg', mimeType: 'application/octet-stream', buffer: bytes });
        await admin.page.locator('#incoming-password').fill(data.password);
        await admin.page.locator('#incoming-passphrase').fill(passphrase);
        await admin.page.locator('#replacement-safety-passphrase').fill(safetyPassphrase);
        await admin.page.locator('input[name="confirm_replacement"]').check();
        await Promise.all([admin.page.waitForURL(/\/backup\/recovery\.php/, { timeout: 120000 }), admin.page.locator('[data-start-restore]').click()]);
        const state = JSON.parse(fs.readFileSync(path.join(env.BACKUP_STORAGE_PATH, 'restore-state.json')));
        assert.equal(state.stage, 'operator_review_pending', await admin.page.locator('body').innerText());
        assert.equal((await admin.context.request.get(origin + '/')).status(), 503, 'Store remains paused for verification');
        assert(fs.existsSync(path.join(env.BACKUP_STORAGE_PATH, 'safety-' + state.safety_operation_id, 'safety.rmpkg')));
        await admin.page.locator('#recovery-username').fill('theme_' + initiator);
        await admin.page.locator('#recovery-password').fill(data.password);
        await Promise.all([admin.page.waitForNavigation(), admin.page.locator('[data-recovery-submit="login"]').click()]);
        for (const key of ['stock', 'receipt', 'upload', 'attribution']) await admin.page.locator(`[data-recovery-confirm="${key}"]`).check();
        await Promise.all([admin.page.waitForNavigation(), admin.page.locator('[data-recovery-submit="checklist"]').click()]);
        admin.page.on('dialog', dialog => dialog.accept());
        await admin.page.locator('#reopen-operator-password').fill(data.password);
        await admin.page.locator('#confirm-one-active').check();
        await Promise.all([admin.page.waitForNavigation(), admin.page.locator('[data-recovery-submit="reopen"]').click()]);
        assert.equal(await admin.page.locator('[data-recovery-status="online"]').count(), 1, await admin.page.locator('body').innerText());
        assert.notEqual((await fetch(origin)).status, 503);
        assert.equal((await superAdmin.context.request.get(identityUrl, { maxRedirects: 0 })).status(), 302, 'Restore invalidates prior sessions');
        const signedInAgain = await login('admin');
        assert.equal((await signedInAgain.page.goto(adminUrl)).status(), 200, 'Preserved destination credentials work after reopening');
        console.log('Encrypted restore browser: passed for ' + initiator + ' (128 MB runtime, capture, private download, approval, role/CSRF/password rejection, initial replacement, safety checkpoint, review, reopen, fresh login)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        const statePath = path.join(env.BACKUP_STORAGE_PATH, 'restore-state.json');
        if (fs.existsSync(statePath)) fs.unlinkSync(statePath);
        fixture('cleanup');
        const relative = path.relative(os.tmpdir(), testDirectory);
        assert(relative.startsWith('rm-encrypted-restore-') && !relative.includes(path.sep));
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
}
(async () => {
    for (const role of ['admin', 'super_admin']) await runScenario(role);
})().catch(error => { console.error(error); process.exitCode = 1; });
