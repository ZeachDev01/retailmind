const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium, expect} = require('@playwright/test');

if (process.env.RUN_DB_TESTS !== '1') throw new Error('RUN_DB_TESTS=1 and disposable MySQL required');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-shift-reminder-'));
const output = path.resolve('output/playwright/shift-reminders');
fs.mkdirSync(output, {recursive: true});
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
function fixture(action, helper = 'theme_fixture.php') {
    const result = spawnSync('php', ['src/backend/tests/support/' + helper, action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
const state = action => fixture(action, 'shift_reminder_fixture.php');
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        state('overdue');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let i = 0; i < 100; i++) {
            try { if ((await fetch(origin)).ok) break; } catch (_) {}
            if (i === 99) throw new Error('Test server unavailable');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({baseURL: origin});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const login = async role => {
            await page.goto('/components/auth/logout.php');
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => url.pathname.includes('/components/')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        await login('admin');
        await expect(page.locator('#open-cashier-shifts')).toContainText('Theme Register');
        const statusURL = '/components/auth/shift_status.php';
        assert.equal((await context.request.get(statusURL)).status(), 200);
        await page.goto('/components/administrator/store_settings.php?section=shift-reminders');
        const form = page.locator('form').filter({has: page.locator('[name="action"][value="save_shift_reminders"]')});
        await expect(form.locator('#shift_reminder_enabled')).toHaveValue('0');
        const links = await page.locator('.settings-sidebar a').allTextContents();
        assert.match(links[links.findIndex(text => text.includes('Attention thresholds')) + 1], /Shift reminders/);
        await form.locator('#shift_reminder_enabled').selectOption('1');
        await form.locator('#shift_reminder_time').fill('20:00');
        await form.getByRole('button', {name: 'Save reminders'}).click();
        await expect(page.locator('.alert.tag-success')).toContainText('Shift reminders saved');
        await page.reload();
        await expect(page.locator('#shift_reminder_time')).toHaveValue('20:00');
        const csrf = await page.locator('[name="csrf_token"]').first().inputValue();
        const invalid = await context.request.post('/components/administrator/store_settings.php?section=shift-reminders',
            {form: {csrf_token: csrf, action: 'save_shift_reminders', shift_reminder_enabled: '1', shift_reminder_time: '24:61'}});
        assert.match(await invalid.text(), /Choose a valid reminder time/);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 1280]) {
                await page.setViewportSize({width, height: 900});
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No page overflow');
                await page.screenshot({path: path.join(output, 'settings-' + theme + '-' + width + '.png'), fullPage: true});
            }
        }
        await login('cashier');
        const alert = page.locator('.swal2-popup');
        await expect(alert).toContainText('Close your shift');
        await page.waitForTimeout(400);
        const own = await (await context.request.get(statusURL)).json();
        assert.equal(own.shift_id, 1);
        assert.ok(own.due_at < own.now);
        assert.equal((await context.request.get('/components/administrator/store_settings.php?section=shift-reminders')).status(), 403);
        await page.screenshot({path: path.join(output, 'cashier-reminder.png'), fullPage: true});
        await page.getByRole('button', {name: 'Later', exact: true}).click();
        const key = 'retailmind.shift-reminder.4.1';
        const snooze = await page.evaluate(key => Number(localStorage.getItem(key)), key);
        assert.ok(snooze >= own.now + 600 && snooze < own.now + 620, 'Later snoozes for ten minutes');
        await page.reload();
        await expect(alert).toBeHidden();
        // Exercise page replacement, not a full reload, and verify reminder initialization survives it.
        await page.evaluate(() => RetailMindUI.navigate('/components/cashier/dashboard.php'));
        await page.waitForURL('**/cashier/dashboard.php');
        await page.route('**/auth/shift_status.php', async route => {
            const response = await route.fetch();
            const status = await response.json();
            await route.fulfill({response, json: {...status, now: status.now + 601}});
        });
        await page.evaluate(key => localStorage.removeItem(key), key);
        state('lock');
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForTimeout(300);
        await expect(alert).toBeHidden();
        state('unlock');
        await page.evaluate(() => { const dialog = document.createElement('dialog'); dialog.id = 'test-dialog'; document.body.append(dialog); dialog.showModal(); document.dispatchEvent(new Event('visibilitychange')); });
        await page.waitForTimeout(300);
        await expect(alert).toBeHidden();
        await page.evaluate(() => { document.getElementById('test-dialog').remove(); document.dispatchEvent(new Event('visibilitychange')); });
        await expect(alert).toContainText('Close your shift');
        await page.getByRole('button', {name: 'Close shift', exact: true}).click();
        await page.waitForURL('**/cashier/shifts.php?tab=close');
        const stillOpen = await (await context.request.get(statusURL)).json();
        assert.equal(stillOpen.shift_id, 1, 'Reminder does not close shift');
        await page.unroute('**/auth/shift_status.php');
        await login('admin');
        state('lock');
        await page.goto('/components/administrator/dashboard.php');
        await expect(page.locator('#open-cashier-shifts')).toContainText('Locked');
        await page.locator('#open-cashier-shifts').screenshot({path: path.join(output, 'open-shifts.png')});
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 1280]) {
                await page.setViewportSize({width, height: 900});
                const geometry = await page.locator('#open-cashier-shifts').evaluate(element => ({
                    width: element.getBoundingClientRect().width, viewport: innerWidth, overflow: document.documentElement.scrollWidth > innerWidth + 1,
                }));
                assert.ok(geometry.width <= geometry.viewport && !geometry.overflow, 'Shift list fits viewport');
                await page.locator('#open-cashier-shifts').screenshot({path: path.join(output, 'open-shifts-' + theme + '-' + width + '.png')});
            }
        }
        state('close');
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await expect(page.locator('#open-cashier-shifts')).toContainText('No open cashier shifts');
        await page.route('**/auth/shift_status.php', route => route.fulfill({status: 503, json: {error: 'Unavailable'}}));
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await expect(page.locator('#open-cashier-shifts')).toContainText('Open shifts unavailable');
        assert.deepEqual(errors, [], 'No browser errors');
        console.log('Shift reminder browser: settings persistence, validation, role access, due alert, snooze, navigation, lock/dialog deferral, closing link, live overview, failure state and responsive themes passed.');
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
