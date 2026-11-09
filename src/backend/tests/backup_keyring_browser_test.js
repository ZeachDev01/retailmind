'use strict';
// Recovery-key recommendation + Backup Keyring over real HTTP sessions (#460).
// Proves the banner follows server acknowledgement per account, the prompt is
// once per session, exports stream only key bytes under a fingerprint filename,
// and key custody changes stay visible without exposing key material.
const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {createHash, randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Backup keyring browser: skipped (set RUN_DB_TESTS=1; disposable database required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const testDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-backup-keyring-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(testDirectory, 'recovery')};
const proofDirectory = path.join(testDirectory, 'proof');
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const fingerprint = key => createHash('sha256').update(key).digest('hex');

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
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const login = async role => {
            const context = await browser.newContext({acceptDownloads: true, reducedMotion: 'reduce'});
            const page = await context.newPage();
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(/\/components\//), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            return {context, page};
        };
        const first = await login('admin');
        const superAdministrator = await login('super_admin');
        const adminUrl = origin + '/components/administrator/database_backup.php';
        const keyUrl = origin + '/components/backup/recovery_key.php';
        const banner = first.page.locator('[data-backup-key-recommendation]');
        const prompt = first.page.locator('[data-backup-key-dialog]');
        const csrf = async page => page.locator('input[name="csrf_token"]').first().inputValue();

        // Every signed-in backup screen starts without a recommendation.
        assert.equal((await first.page.goto(adminUrl)).status(), 200);
        assert.equal(await banner.count(), 0, 'No completed fingerprinted backup means no recommendation');

        // Capture a package; the newest completed backup now carries a fingerprint.
        await Promise.all([first.page.waitForNavigation(), first.page.locator('[data-backup-create]').click()]);
        assert.equal(await banner.count(), 1, 'The recommendation appears after the first fingerprinted backup');
        assert.equal(await prompt.count(), 1);
        assert.equal(await prompt.evaluate(dialog => dialog.open), true, 'The prompt opens once for this session');
        await first.page.keyboard.press('Escape');
        assert.equal(await prompt.evaluate(dialog => dialog.open), false);
        assert.equal(await banner.isVisible(), true, 'Dismissing the prompt keeps the persistent banner');
        await first.page.screenshot({path: path.join(proofDirectory, 'banner-1440.png'), fullPage: true});

        await first.page.reload();
        assert.equal(await prompt.evaluate(dialog => dialog.open), false, 'Reloading does not reopen the prompt in this session');
        assert.equal(await banner.isVisible(), true, 'The banner persists until the key is exported');

        // A wrong password neither leaks nor acknowledges.
        const wrong = await first.context.request.post(keyUrl, {
            form: {csrf_token: await csrf(first.page), key_action: 'export', key_fingerprint: await banner.getAttribute('data-fingerprint'), key_password: 'wrong-password'},
            maxRedirects: 0,
        });
        assert.equal(wrong.status(), 303);
        await first.page.goto(adminUrl);
        assert.equal(await banner.isVisible(), true, 'A failed password leaves the recommendation in place');
        assert.match(await first.page.locator('body').innerText(), /password is incorrect/i);

        // Export through the banner form: the stream is the only download path.
        const bannerButton = banner.getByRole('button', {name: 'Download recovery key', exact: true});
        await bannerButton.click();
        const authorization = first.page.getByRole('dialog');
        await authorization.getByLabel('Current login password').fill(data.password);
        const [download, exportResponse] = await Promise.all([
            first.page.waitForEvent('download'),
            first.page.waitForResponse(response => response.url() === keyUrl && response.request().method() === 'POST'),
            authorization.getByRole('button', {name: 'Download recovery key', exact: true}).click(),
        ]);
        assert.equal(exportResponse.status(), 200);
        assert.match(exportResponse.headers()['content-type'], /application\/octet-stream/);
        assert.match(exportResponse.headers()['cache-control'], /no-store/);
        assert.equal(exportResponse.headers()['x-content-type-options'], 'nosniff');
        const primaryKey = fs.readFileSync(await download.path(), 'utf8');
        assert.match(primaryKey, /^[a-f0-9]{64}\n$/, 'The download body is only the key and a newline');
        assert.match(download.suggestedFilename(), /^retailmind-recovery-[a-f0-9]{8}\.key$/, 'The filename carries only the fingerprint prefix');
        const primaryFingerprint = fingerprint(primaryKey.trim());
        assert.match(await banner.getAttribute('data-fingerprint'), new RegExp('^' + primaryFingerprint + '$'));
        await first.page.waitForTimeout(300);

        // The acknowledgement is personal: the exporter loses the banner, the other admin keeps it.
        await first.page.goto(adminUrl);
        assert.equal(await banner.count(), 0, 'A complete export acknowledges the key for that account');
        assert.equal((await superAdministrator.page.goto(origin + '/components/system_administrator/backup_restore.php')).status(), 200);
        assert.equal(await superAdministrator.page.locator('[data-backup-key-recommendation]').count(), 1, 'Another account still sees the recommendation');

        // The Keyring lists the primary and protects its deletion.
        await first.page.getByRole('tab', {name: 'Recovery key', exact: true}).click();
        const keyring = first.page.locator('.backup-keyring-table');
        assert.equal(await keyring.locator('tbody tr:not(.table-ghost-row)').count(), 1);
        const primaryRow = keyring.locator('tbody tr', {has: first.page.locator('code[title="' + primaryFingerprint + '"]')});
        assert.equal(await primaryRow.getByText('Primary', {exact: true}).count(), 1);
        assert.equal(await primaryRow.locator('button:disabled').count(), 1, 'The Primary Recovery Key cannot be deleted while primary');
        assert.equal(await primaryRow.getByText('Promote another key before deleting this one.', {exact: true}).isVisible(), true);

        // Import is additive and never replaces the destination primary.
        const importedKey = randomBytes(32).toString('hex');
        const importedFingerprint = fingerprint(importedKey);
        await first.page.locator('#recovery-key-file').setInputFiles({name: 'source.key', mimeType: 'application/octet-stream', buffer: Buffer.from(importedKey + '\n')});
        await first.page.getByRole('button', {name: 'Import recovery key', exact: true}).click();
        await first.page.getByRole('dialog').getByLabel('Current login password').fill(data.password);
        await Promise.all([first.page.waitForNavigation(), first.page.getByRole('dialog').getByRole('button', {name: 'Import recovery key', exact: true}).click()]);
        assert.match(await first.page.locator('body').innerText(), /Recovery key imported/i);
        assert.equal(await keyring.locator('tbody tr:not(.table-ghost-row)').count(), 2, 'Import adds a key to the Keyring');
        assert.equal(await keyring.locator('tbody').getByText('Imported', {exact: true}).count(), 1);
        const importedRow = keyring.locator('tbody tr', {has: first.page.locator('code[title="' + importedFingerprint + '"]')});
        assert.equal(await importedRow.getByRole('button', {name: 'Use for new backups', exact: true}).count(), 1);
        const pageHtml = await first.page.content();
        assert.equal(pageHtml.includes(importedKey), false, 'The page never renders key material');

        // Importing the same key again is idempotent.
        await first.page.locator('#recovery-key-file').setInputFiles({name: 'source.key', mimeType: 'application/octet-stream', buffer: Buffer.from(importedKey + '\n')});
        await first.page.getByRole('button', {name: 'Import recovery key', exact: true}).click();
        await first.page.getByRole('dialog').getByLabel('Current login password').fill(data.password);
        await Promise.all([first.page.waitForNavigation(), first.page.getByRole('dialog').getByRole('button', {name: 'Import recovery key', exact: true}).click()]);
        assert.match(await first.page.locator('body').innerText(), /already in this Backup Keyring/i);
        assert.equal(await keyring.locator('tbody tr:not(.table-ghost-row)').count(), 2);

        // Promotion archives the old primary, which still exports its exact key.
        await importedRow.getByRole('button', {name: 'Use for new backups', exact: true}).click();
        await first.page.getByRole('dialog').getByLabel('Current login password').fill(data.password);
        await Promise.all([first.page.waitForNavigation(), first.page.getByRole('dialog').getByRole('button', {name: 'Use for new backups', exact: true}).click()]);
        assert.match(await first.page.locator('body').innerText(), /now the Primary Recovery Key/i);
        const archivedRow = keyring.locator('tbody tr', {has: first.page.locator('code[title="' + primaryFingerprint + '"]')});
        assert.equal(await archivedRow.getByText('Archived', {exact: true}).count(), 1, 'The previous primary is archived, not deleted');
        assert.equal(await importedRow.getByText('Primary', {exact: true}).count(), 1);

        await archivedRow.getByRole('button', {name: 'Export key', exact: true}).click();
        await first.page.getByRole('dialog').getByLabel('Current login password').fill(data.password);
        const [archivedDownload, archivedResponse] = await Promise.all([
            first.page.waitForEvent('download'),
            first.page.waitForResponse(response => response.url() === keyUrl && response.request().method() === 'POST'),
            first.page.getByRole('dialog').getByRole('button', {name: 'Export key', exact: true}).click(),
        ]);
        assert.equal(archivedResponse.status(), 200);
        assert.match(archivedResponse.headers()['content-disposition'], new RegExp('retailmind-recovery-' + primaryFingerprint.slice(0, 8) + '\\.key'));
        assert.equal(fs.readFileSync(await archivedDownload.path(), 'utf8'), primaryKey, 'An archived key still exports its exact bytes');

        // Deleting needs the warning checkbox and the current password.
        await archivedRow.getByLabel('I understand backups encrypted with this key cannot be opened afterwards.').check();
        await archivedRow.getByRole('button', {name: 'Delete key', exact: true}).click();
        await first.page.getByRole('dialog').getByLabel('Current login password').fill(data.password);
        await Promise.all([first.page.waitForNavigation(), first.page.getByRole('dialog').getByRole('button', {name: 'Delete key', exact: true}).click()]);
        assert.match(await first.page.locator('body').innerText(), /removed from the Backup Keyring/i);
        assert.equal(await keyring.locator('tbody tr:not(.table-ghost-row)').count(), 1, 'Deleting removes only the chosen key');
        assert.equal(await keyring.locator('tbody tr', {has: first.page.locator('code[title="' + primaryFingerprint + '"]')}).count(), 0);

        // The Keyring fits both viewports without leaking key material.
        for (const width of [1440, 390]) {
            await first.page.setViewportSize({width, height: 960});
            await first.page.goto(adminUrl + '#recovery-key');
            assert(await first.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Keyring fits ' + width + 'px');
            assert.equal((await first.page.content()).includes(importedKey), false);
            await first.page.screenshot({path: path.join(proofDirectory, 'keyring-' + width + '.png'), fullPage: true});
        }

        // Transport guards: no GET, no CSRF-less mutation.
        const getResponse = await first.context.request.get(keyUrl, {maxRedirects: 0});
        assert.equal(getResponse.status(), 405);
        assert.equal(getResponse.headers()['allow'], 'POST');
        assert.equal((await first.context.request.post(keyUrl, {form: {key_action: 'export'}, maxRedirects: 0})).status(), 403);

        console.log('Backup keyring browser: passed (banner/modal once per session, per-account ack, additive import, promote/archive, export, guarded delete)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        const relative = path.relative(os.tmpdir(), testDirectory);
        assert(relative.startsWith('rm-backup-keyring-') && !relative.includes(path.sep), 'Cleanup must stay inside the generated test directory');
        fs.rmSync(testDirectory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
