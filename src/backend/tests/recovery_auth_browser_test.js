const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Recovery auth browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-recovery-browser-'));
const recoveryStoragePath = path.join(testDirectory, 'recovery');
fs.mkdirSync(recoveryStoragePath, { recursive: true });
const worktreeDir = path.resolve(__dirname, '../../..');
const env = { ...process.env, BACKUP_STORAGE_PATH: recoveryStoragePath };
const proofDirectory = path.join(os.tmpdir(), 'retailmind-284-311');
fs.mkdirSync(proofDirectory, { recursive: true });

const fixture = action => {
    const result = spawnSync(php, [path.resolve(__dirname, 'support/theme_fixture.php'), action, database], { cwd: worktreeDir, encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let browser, server;
    try {
        const fixtureData = JSON.parse(fixture('create'));
        const password = fixtureData.password || 'ThemeTest@2026';

        // Prepare pre-restore maintenance state via helper script
        const prepResult = spawnSync(php, ['-d', 'memory_limit=512M', path.resolve(__dirname, 'support/recovery_prep.php'), database, password], { cwd: worktreeDir, encoding: 'utf8', env });
        assert.equal(prepResult.status, 0, prepResult.stderr || prepResult.stdout);
        const recoveryInfo = JSON.parse(prepResult.stdout);

        // Find open port and start router
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        const serverLog = fs.openSync(path.join(testDirectory, 'server.log'), 'w');
        const phpErrorLog = path.join(testDirectory, 'php_errors.log');
        server = spawn(php, ['-d', 'memory_limit=512M', '-d', 'display_errors=1', '-d', 'error_log=' + phpErrorLog, '-S', '127.0.0.1:' + port, '-t', path.resolve(worktreeDir, 'src/frontend'), path.resolve(__dirname, 'support/theme_router.php')],
            { cwd: worktreeDir, env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: ['ignore', serverLog, serverLog] });

        for (let attempt = 0; attempt < 100; attempt++) {
            try {
                const res = await fetch(origin + '/components/backup/backup_status.php');
                if (res.ok || res.status === 200) break;
            } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        // 1. Verify ordinary route is blocked with 503 Service Unavailable
        const ordinaryRes = await fetch(origin + '/');
        assert.equal(ordinaryRes.status, 503, 'Ordinary request returns 503 while recovery is in progress');
        const ordinaryBody = await ordinaryRes.text();
        assert.match(ordinaryBody, /Database recovery is in progress/);

        // 2. Verify backup_status.php returns recovery status
        const statusRes = await fetch(origin + '/components/backup/backup_status.php');
        assert.equal(statusRes.status, 200, 'backup_status.php returns 200 during recovery');
        const statusJson = await statusRes.json();
        assert.equal(statusJson.paused, true, 'Status reports paused = true');
        assert.equal(statusJson.state, 'database_replacement', 'Status reports durable stage');

        // 3. Launch browser and test recovery UI
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({ reducedMotion: 'reduce' });
        const page = await context.newPage();

        const recoveryUrl = origin + '/components/backup/recovery.php';
        await page.goto(recoveryUrl);

        // Assert login card and stage badge render without database tables
        assert.equal(await page.locator('[data-recovery-card="login"]').count(), 1, 'Recovery login form renders');
        assert.equal(await page.locator('[data-recovery-status="paused"]').count(), 1, 'Maintenance badge displayed');
        assert.equal(await page.locator('[data-recovery-stage="database_replacement"]').count(), 1, 'Durable stage displayed');

        // Capture initial form screenshots
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Form fits viewport ' + width);
            await page.screenshot({ path: path.join(proofDirectory, `recovery-login-${width}.png`), fullPage: true });
        }

        // Test wrong password
        await page.locator('#recovery-username').fill('theme_admin');
        await page.locator('#recovery-password').fill('wrong_password');
        await page.locator('[data-recovery-submit="login"]').click();
        assert.match(await page.locator('[data-recovery-alert="error"]').innerText(), /Invalid recovery credentials/);

        // Test unrelated admin
        await page.locator('#recovery-username').fill('other_admin');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();
        assert.match(await page.locator('[data-recovery-alert="error"]').innerText(), /Invalid recovery credentials or operator not authorized/);

        // Test sealed recovery account
        await page.locator('#recovery-username').fill('recovery_primary');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();
        assert.match(await page.locator('[data-recovery-alert="error"]').innerText(), /Invalid recovery credentials or operator not authorized/);

        // Test authenticating as Initiating Administrator (theme_admin)
        await page.locator('#recovery-username').fill('theme_admin');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();

        // Assert Initiating Admin console
        assert.equal(await page.locator('[data-recovery-card="authenticated"]').count(), 1, 'Initiating admin console authenticated');
        assert.match(await page.locator('[data-recovery-card="authenticated"]').innerText(), /theme_admin/);
        assert.equal(await page.locator('[data-recovery-admin-notice]').count(), 1, 'Admin notice is displayed');
        assert.equal(await page.locator('[data-recovery-super-evidence]').count(), 0, 'Super admin evidence hidden from ordinary admin');

        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Admin console fits viewport ' + width);
            await page.screenshot({ path: path.join(proofDirectory, `recovery-admin-${width}.png`), fullPage: true });
        }

        // Sign out
        await page.locator('[data-recovery-submit="logout"]').click();
        assert.equal(await page.locator('[data-recovery-card="login"]').count(), 1, 'Signed out back to login form');

        // Test authenticating as Destination Super Administrator (theme_super_admin)
        await page.locator('#recovery-username').fill('theme_super_admin');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();

        // Assert Super Administrator console and role-restricted evidence
        assert.equal(await page.locator('[data-recovery-card="authenticated"]').count(), 1, 'Super admin console authenticated');
        assert.equal(await page.locator('[data-recovery-super-evidence]').count(), 1, 'Super admin evidence is visible');
        assert.equal(await page.locator('[data-recovery-submit="reopen"]').count(), 1, 'Reopen button visible to Super Admin');

        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Super Admin console fits viewport ' + width);
            await page.screenshot({ path: path.join(proofDirectory, `recovery-super-${width}.png`), fullPage: true });
        }

        // Test Super Admin reopening the Store
        page.on('dialog', async dialog => await dialog.accept());
        await page.locator('[data-recovery-submit="reopen"]').click();

        // Assert Store is reopened
        assert.equal(await page.locator('[data-recovery-status="online"]').count(), 1, 'Status changes to Store Online');
        assert.equal(await page.locator('[data-recovery-card="idle"]').count(), 1, 'Idle card displayed');

        console.log('Recovery auth browser test: passed (missing tables, pre-restore auth, role evidence, reopening, mobile/desktop fit)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        const stateFile = path.join(recoveryStoragePath, 'restore-state.json');
        if (fs.existsSync(stateFile)) {
            try { fs.unlinkSync(stateFile); } catch {}
        }
        fixture('cleanup');
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})();
