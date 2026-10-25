const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Recovery restart browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-restart-browser-'));
const recoveryStoragePath = path.join(testDirectory, 'recovery');
fs.mkdirSync(recoveryStoragePath, { recursive: true });
const env = { ...process.env, BACKUP_STORAGE_PATH: recoveryStoragePath };
const proofDirectory = path.join(os.tmpdir(), 'retailmind-284-312');
fs.mkdirSync(proofDirectory, { recursive: true });

const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let browser, server;
    try {
        const fixtureData = JSON.parse(fixture('create'));
        const password = fixtureData.password || 'ThemeTest@2026';

        // Prepare pre-restore maintenance state with real safety copy via helper script
        const prepResult = spawnSync(php, ['-d', 'memory_limit=512M', 'src/backend/tests/support/recovery_restart_prep.php', database, password], { encoding: 'utf8', env });
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
        server = spawn(php, ['-d', 'memory_limit=512M', '-d', 'display_errors=1', '-d', 'error_log=' + phpErrorLog, '-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: ['ignore', serverLog, serverLog] });

        for (let attempt = 0; attempt < 100; attempt++) {
            try {
                const res = await fetch(origin + '/components/backup/backup_status.php');
                if (res.ok || res.status === 200) break;
            } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        // 1. Verify ordinary route is blocked with 503
        const ordinaryRes = await fetch(origin + '/');
        assert.equal(ordinaryRes.status, 503, 'Ordinary request returns 503 while recovery active');

        // 2. Launch browser and open recovery console
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({ reducedMotion: 'reduce' });
        const page = await context.newPage();

        const recoveryUrl = origin + '/components/backup/recovery.php';
        await page.goto(recoveryUrl);

        // Login as Super Administrator (theme_super_admin)
        await page.locator('#recovery-username').fill('theme_super_admin');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();

        // 3. Assert recovery actions cards rendered
        assert.equal(await page.locator('[data-recovery-actions]').count(), 1, 'Recovery actions section rendered');
        assert.equal(await page.locator('[data-recovery-card="safety"]').count(), 1, 'Safety recovery card displayed');
        assert.equal(await page.locator('[data-recovery-card="incoming"]').count(), 1, 'Incoming restart card displayed');

        // Capture viewports screenshots
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Recovery actions fit viewport ' + width);
            await page.screenshot({ path: path.join(proofDirectory, `recovery-actions-${width}.png`), fullPage: true });
        }

        // 4. Test wrong passphrase for safety recovery
        await page.locator('#safety-passphrase').fill('WrongPassphrase1234');
        await page.locator('[data-recovery-submit="safety"]').click();
        assert.match(await page.locator('[data-recovery-alert="error"]').innerText(), /passphrase/i);

        // 5. Execute valid safety recovery via browser form
        await page.locator('#safety-passphrase').fill(recoveryInfo.safety_passphrase);
        await Promise.all([
            page.waitForNavigation(),
            page.locator('[data-recovery-submit="safety"]').click(),
        ]);

        if (await page.locator('[data-recovery-alert="error"]').count() > 0) {
            console.error('RECOVERY ERROR:', await page.locator('[data-recovery-alert="error"]').innerText());
        }

        // Assert success notice and stage transition to operator_review_pending
        assert.match(await page.locator('[data-recovery-alert="notice"]').innerText(), /complete safety recovery finished successfully/i);
        assert.equal(await page.locator('[data-recovery-stage="operator_review_pending"]').count(), 1, 'Stage is operator_review_pending');

        // Screenshot after recovery
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            await page.screenshot({ path: path.join(proofDirectory, `recovery-review-pending-${width}.png`), fullPage: true });
        }

        // 6. Explicitly reopen store as super admin
        page.on('dialog', async dialog => {
            await dialog.accept();
        });
        await page.locator('[data-recovery-submit="reopen"]').click();

        // Assert store reopened
        assert.match(await page.locator('[data-recovery-alert="notice"]').innerText(), /Store access has been resumed/i);
        assert.equal(await page.locator('[data-recovery-status="online"]').count(), 1, 'Store is online');

        console.log('Recovery restart browser: passed (browser actions, safety recovery, responsive layout, reopen)');

    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        // Unpause to allow cleanup
        try {
            const stateFile = path.join(recoveryStoragePath, 'restore-state.json');
            if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
        } catch {}
        try { fixture('cleanup'); } catch {}
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})();
