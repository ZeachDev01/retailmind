const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {chromium} = require('playwright');

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-destination-'));
const destination = path.join(directory, 'Weekly');
fs.mkdirSync(destination);
fs.mkdirSync(path.join(destination, 'Subfolder'));
fs.writeFileSync(path.join(destination, 'hidden-file.txt'), 'Never listed');
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'private')};
const fixture = action => {
    const result = spawnSync('php', ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
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
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/backup_destination_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database, RM_BACKUP_REMOTE_STATE: path.join(directory, 'remote-state')}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch();
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(/\/components\//), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/system_administrator/system_settings.php?section=scheduled-backup';
        await page.goto(url);
        const dialog = page.locator('#backup-folder-dialog');
        const field = page.locator('#backup_destination_path');
        const csrf = await page.locator('#backup-schedule-form input[name="csrf_token"]').inputValue();
        assert.equal((await page.request.post(url, {form: {action: 'browse_backup_destination', path: directory}})).status(), 403);
        for (const blocked of [path.resolve('src'), env.BACKUP_STORAGE_PATH, directory + path.sep + '..']) {
            assert.equal((await page.request.post(url, {form: {action: 'browse_backup_destination', path: blocked, csrf_token: csrf}})).status(), 422);
        }
        const proof = '.impeccable/review/backup-destination';
        fs.mkdirSync(proof, {recursive: true});
        await page.waitForFunction(() => document.querySelector('#backup-folder-dialog')?.dataset.initialized === 'true');
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.locator('#backup-choose-folder').click();
                await dialog.waitFor({state: 'visible'});
                await page.waitForFunction(() => document.querySelector('#backup-folder-list').getAttribute('aria-busy') === 'false');
                await page.locator('#backup-folder-path').fill(destination);
                await page.getByRole('button', {name: 'Open', exact: true}).click();
                await page.getByRole('button', {name: 'Subfolder', exact: true}).waitFor();
                assert.equal(await page.getByRole('button', {name: 'hidden-file.txt', exact: true}).count(), 0);
                assert.equal(await page.locator('#backup-folder-use').isEnabled(), true);
                const geometry = await dialog.evaluate(node => {
                    const rect = node.getBoundingClientRect();
                    return {fits: rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight, overflow: node.scrollWidth > node.clientWidth, centered: Math.abs(rect.left + rect.width / 2 - innerWidth / 2) < 10};
                });
                assert.equal(geometry.fits, true, JSON.stringify(geometry));
                assert.equal(geometry.overflow, false);
                assert.equal(geometry.centered, true, JSON.stringify(geometry));
                await page.screenshot({path: path.join(proof, `${width}-${theme}.png`)});
                await page.locator('#backup-folder-use').click();
                assert.equal(await field.inputValue(), destination);
                await page.locator('#backup-choose-folder').click();
                await page.keyboard.press('Escape');
                assert.equal(await field.inputValue(), destination, 'Cancel preserves chosen destination');
                assert.equal(await page.locator('#backup-choose-folder').evaluate(node => node === document.activeElement), true);
            }
        }
        await page.setViewportSize({width: 1440, height: 900});
        await page.locator('#backup-choose-folder').click();
        await page.getByRole('button', {name: 'Subfolder', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('#backup-folder-status').textContent.includes('No subfolders'));
        await page.locator('#backup-folder-up').click();
        await page.getByRole('button', {name: 'Subfolder', exact: true}).waitFor();
        await page.getByRole('button', {name: 'Cancel', exact: true}).click();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Save schedule', exact: true}).click()]);
        assert.equal(await field.inputValue(), destination, 'Save persists the Store PC destination');
        await page.reload();
        assert.equal(await field.inputValue(), destination);
        await page.setExtraHTTPHeaders({'X-Forwarded-For': '192.168.1.20'});
        await page.reload();
        assert.equal(await page.locator('#backup-choose-folder').count(), 0, 'Another PC cannot open the selector');
        assert.equal(await field.getAttribute('readonly'), '', 'Another PC sees a read-only destination');
        await page.screenshot({path: path.join(proof, 'remote-readonly.png')});
        const remoteHeaders = {'X-Forwarded-For': '192.168.1.20'};
        assert.equal((await page.request.post(url, {headers: remoteHeaders, form: {action: 'browse_backup_destination', path: destination, csrf_token: csrf}})).status(), 403, 'Direct remote browse is rejected');
        assert.equal((await page.request.post(url, {headers: remoteHeaders, form: {action: 'save_backup_schedule', backup_schedule_weekday: '0', backup_schedule_time: '23:00', backup_destination_path: directory, csrf_token: csrf}})).status(), 403, 'Direct remote destination edits are rejected');
        await page.setExtraHTTPHeaders({});
        await page.reload();
        assert.equal(await field.inputValue(), destination, 'Rejected remote save preserves the destination');
        assert.equal(await page.locator('#backup-choose-folder').count(), 1, 'Store PC retains selection');
        const admin = await browser.newPage();
        await admin.goto(origin + '/?login=1');
        await admin.locator('#landing-login-username').fill('theme_admin');
        await admin.locator('#landing-login-password').fill(data.password);
        await Promise.all([admin.waitForURL(/\/components\//), admin.getByRole('button', {name: 'Log in', exact: true}).click()]);
        assert.equal((await admin.request.post(url, {form: {action: 'browse_backup_destination', path: destination, csrf_token: csrf}})).status(), 403);
        assert.deepEqual(errors, []);
        console.log('Backup destination HTTP/browser: PASS (selection, navigation, cancel, persistence, CSRF, role checks; desktop/mobile light/dark)');
        if (process.env.RUN_TESTSPRITE === '1') {
            fs.writeFileSync(path.join(directory, 'remote-state'), 'LAN peer');
            const planPath = path.join(directory, 'plan.json');
            fs.writeFileSync(planPath, JSON.stringify({projectId: 'abe16d0b-21f0-432e-8a86-72b1436c42fe', type: 'frontend', name: 'Scheduled Backup destination is read-only for a LAN request', description: 'Disposable database and synthetic accounts only. The test router models a LAN peer because the TestSprite tunnel strips origin metadata. Choosing folders must be unavailable. No backup runs, restores, key actions or setting changes.', planSteps: [
                {type: 'action', description: `Open /?login=1 and sign in as theme_super_admin with password ${data.password}. Navigate to /components/system_administrator/system_settings.php?section=scheduled-backup.`},
                {type: 'assertion', description: 'Verify Choose folder is absent, Backup Destination is read-only, and the hint says to use localhost on the Store PC to change it.'},
                {type: 'assertion', description: `Verify Backup Destination equals ${destination}. Do not change settings.`},
                {type: 'action', description: 'Reload the page.'},
                {type: 'assertion', description: 'Verify destination remains read-only and Choose folder remains absent. Do not run backups, restore or change any settings.'},
            ]}));
            const command = `testsprite test create --plan-from "${planPath}" --run --target-url ${origin} --wait --timeout 600 --output json`;
            await new Promise((resolve, reject) => {
                const task = spawn(command, {shell: true, stdio: 'inherit'});
                task.on('exit', code => code === 0 ? resolve() : reject(new Error('TestSprite exited ' + code)));
            });
        }
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
        // This generated directory is the only filesystem tree removed.
        assert(path.resolve(directory).startsWith(path.resolve(os.tmpdir()) + path.sep));
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
