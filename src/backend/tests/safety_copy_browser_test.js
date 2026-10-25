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
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-safety-browser-'));
const env = { ...process.env, BACKUP_STORAGE_PATH: path.join(testDirectory, 'recovery') };
const proofDirectory = path.join(os.tmpdir(), 'retailmind-284-310');
fs.mkdirSync(proofDirectory, { recursive: true });
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
        const superAdministrator = await login('super_admin');
        const cashier = await login('cashier');
        const urls = ['/components/administrator/database_backup.php', '/components/system_administrator/backup_restore.php'];
        const secret = 'Independent safety recovery phrase 2026';
        for (const [index, actor] of [administrator, superAdministrator].entries()) {
            const url = origin + urls[index];
            await actor.page.goto(url);
            assert.equal(await actor.page.getByLabel('Separate destination safety passphrase').count(), 1, 'Both administrator screens expose complete safety capture');
            for (const width of [390, 1440]) {
                await actor.page.setViewportSize({ width, height: 960 });
                const button = await actor.page.locator('[data-backup-create]').boundingBox();
                const panel = await actor.page.locator('[data-backup-create]').locator('xpath=ancestor::section').boundingBox();
                assert(button.x >= panel.x && button.x + button.width <= panel.x + panel.width, 'Backup action remains inside its panel');
                await actor.page.screenshot({ path: path.join(proofDirectory, `safety-form-${index}-${width}.png`), fullPage: true });
            }
            const operation = await actor.page.locator('input[name="safety_operation"]').inputValue();
            assert.equal((await actor.context.request.post(url, { form: { action: 'safety', safety_operation: operation, safety_password: data.password, safety_passphrase: secret } })).status(), 403, 'Safety capture requires CSRF');
            const submit = async password => {
                await actor.page.locator('#safety-password').fill(password);
                await actor.page.locator('#safety-passphrase').fill(secret);
                await Promise.all([actor.page.waitForNavigation(), actor.page.locator('[data-safety-create]').click()]);
            };
            await submit('wrong-password');
            assert.match(await actor.page.locator('[data-safety-copy]').innerText(), /password is incorrect/);
            await submit(data.password);
            assert.match(await actor.page.locator('[data-safety-copy]').innerText(), /Safety copy verified/);
            const href = await actor.page.locator('[data-safety-download]').getAttribute('href');
            const download = await actor.context.request.get(new URL(href, origin).href);
            assert.equal(download.status(), 200);
            assert.equal((await download.body()).subarray(0, 8).toString(), 'RMSTORE1');
            assert.equal((await actor.context.request.get(new URL(href, origin).href)).status(), 200, 'Safety download never deletes the original');
            const other = index === 0 ? superAdministrator : administrator;
            assert.equal((await other.context.request.get(new URL(href, origin).href)).status(), 404, 'Another administrator cannot download requester-bound safety material');
            assert.equal((await cashier.context.request.get(new URL(href, origin).href)).status(), 403, 'Cashier cannot download safety artifacts');
            for (const width of [390, 1440]) {
                await actor.page.setViewportSize({ width, height: 960 });
                assert(await actor.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'Safety form fits supported viewport');
                await actor.page.screenshot({ path: path.join(proofDirectory, `safety-${index}-${width}.png`), fullPage: true });
            }
            const html = await actor.page.content();
            assert(!html.includes(secret) && !html.includes(data.password), 'Credentials never echoed in response');
        }
        console.log('Safety copy browser: passed (both screens, credentials, CSRF, requester download, retained fallback, mobile/desktop)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        const relative = path.relative(os.tmpdir(), testDirectory);
        assert(relative.startsWith('rm-safety-browser-') && !relative.includes(path.sep), 'Cleanup must stay inside the generated test directory');
        fs.rmSync(testDirectory, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
