const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Recovery reopen browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}

const worktreeDir = path.resolve(__dirname, '../../..');
const frontendDir = path.resolve(worktreeDir, 'src/frontend');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-reopen-browser-'));
const recoveryStoragePath = path.join(testDirectory, 'recovery');
fs.mkdirSync(recoveryStoragePath, { recursive: true });
const env = { ...process.env, BACKUP_STORAGE_PATH: recoveryStoragePath };
const proofDirectory = path.join(os.tmpdir(), 'retailmind-284-313');
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

        // Prepare pre-restore maintenance state with real safety copy via helper script
        const prepScript = path.resolve(__dirname, 'support/recovery_restart_prep.php');
        const prepResult = spawnSync(php, ['-d', 'memory_limit=512M', prepScript, database, password], { cwd: worktreeDir, encoding: 'utf8', env });
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
        const routerScript = path.resolve(__dirname, 'support/theme_router.php');
        server = spawn(php, ['-d', 'memory_limit=512M', '-d', 'display_errors=1', '-d', 'error_log=' + phpErrorLog, '-S', '127.0.0.1:' + port, '-t', frontendDir, routerScript],
            { cwd: worktreeDir, env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: ['ignore', serverLog, serverLog] });

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

        // Login as Initiating Administrator (theme_admin)
        await page.locator('#recovery-username').fill('theme_admin');
        await page.locator('#recovery-password').fill(password);
        await page.locator('[data-recovery-submit="login"]').click();

        // 3. Perform complete safety recovery
        await page.locator('#safety-passphrase').fill(recoveryInfo.safety_passphrase);
        await Promise.all([
            page.waitForNavigation(),
            page.locator('[data-recovery-submit="safety"]').click(),
        ]);

        // Assert stage transitions to operator_review_pending
        assert.equal(await page.locator('[data-recovery-stage="operator_review_pending"]').count(), 1, 'Stage is operator_review_pending');

        // 4. Assert review section and controlled previews rendered
        assert.equal(await page.locator('[data-recovery-card="review"]').count(), 1, 'Review card rendered');
        assert.equal(await page.locator('[data-recovery-check="stock"]').count(), 1, 'Stock check rendered');
        assert.equal(await page.locator('[data-recovery-check="receipt"]').count(), 1, 'Receipt check rendered');
        assert.equal(await page.locator('[data-recovery-check="upload"]').count(), 1, 'Upload check rendered');
        assert.equal(await page.locator('[data-recovery-check="attribution"]').count(), 1, 'Attribution check rendered');
        assert.equal(await page.locator('[data-recovery-preview="signin"]').count(), 1, 'Sign-in preview rendered');
        assert.equal(await page.locator('[data-recovery-preview="report"]').count(), 1, 'Report summary rendered');

        // Screenshot review pending layout in mobile & desktop viewports
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Review layout fits viewport ' + width);
            await page.screenshot({ path: path.join(proofDirectory, `reopen-review-pending-${width}.png`), fullPage: true });
        }

        // 5. Assert reopening blocked guidance appears before human checklist confirmations
        assert.equal(await page.locator('[data-recovery-alert="guidance"]').count(), 1, 'Guidance alert displayed when checks unconfirmed');

        // 6. Test wrong password for reopening
        page.on('dialog', async dialog => {
            await dialog.accept();
        });
        await page.locator('#reopen-operator-password').fill('WrongReopenPassword@999');
        await page.locator('#confirm-one-active').check();
        await page.locator('[data-recovery-submit="reopen"]').click();
        assert.match(await page.locator('[data-recovery-alert="error"]').innerText(), /password verification failed/i);

        // 7. Complete human checklist confirmations
        for (const chk of ['stock', 'receipt', 'upload', 'attribution']) {
            await page.locator(`[data-recovery-confirm="${chk}"]`).check();
        }
        await page.locator('[data-recovery-submit="checklist"]').click();
        assert.match(await page.locator('[data-recovery-alert="notice"]').innerText(), /checklist confirmations saved/i);

        // 8. Explicitly reopen store with fresh password and single-active-store confirmation
        await page.locator('#reopen-operator-password').fill(password);
        await page.locator('#confirm-one-active').check();
        await page.locator('[data-recovery-submit="reopen"]').click();

        // Assert store reopened
        assert.match(await page.locator('[data-recovery-alert="notice"]').innerText(), /Store access has been resumed/i);
        assert.equal(await page.locator('[data-recovery-status="online"]').count(), 1, 'Store is online');
        assert.equal(await page.locator('[data-recovery-reopened-evidence]').count(), 1, 'Reopening evidence rendered');

        // Screenshot reopened store
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 960 });
            await page.screenshot({ path: path.join(proofDirectory, `reopen-completed-${width}.png`), fullPage: true });
        }

        // 9. Verify ordinary request is admitted (200 or normal app response)
        const postReopenRes = await fetch(origin + '/');
        assert.notEqual(postReopenRes.status, 503, 'Ordinary requests no longer return 503');

        console.log('Recovery reopen browser: passed (review checks, checklist guidance, wrong password rejection, explicit reopen, responsive layout, evidence audit)');

    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        try {
            const stateFile = path.join(recoveryStoragePath, 'restore-state.json');
            if (fs.existsSync(stateFile)) fs.unlinkSync(stateFile);
        } catch {}
        try { fixture('cleanup'); } catch {}
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})();
