const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium, expect} = require('@playwright/test');

if (process.env.RUN_DB_TESTS !== '1') throw new Error('RUN_DB_TESTS=1 and disposable MySQL required');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-fiscal-closing-'));
const output = path.resolve('output/playwright/fiscal-closing');
fs.mkdirSync(output, {recursive: true});
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
function fixture(action, helper = 'fiscal_closing_fixture.php') {
    const result = spawnSync('php', ['src/backend/tests/support/' + helper, action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create', 'theme_fixture.php'));
        fixture('setup');
        server = spawn('php', ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}});
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.on('exit', code => reject(new Error('PHP server exited: ' + code)));
            server.stderr.on('data', chunk => {
                const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:(\d+)/);
                if (match) resolve('http://127.0.0.1:' + match[1]);
            });
        });
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({baseURL: origin});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(15000);
        const statusURL = '/components/auth/fiscal_closing_status.php';
        const alert = page.locator('.swal2-popup');
        const login = async role => {
            await page.goto('/components/auth/logout.php');
            await page.goto('/?login=1');
            await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => url.pathname.includes('/components/')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        assert.equal((await context.request.get(statusURL, {maxRedirects: 0})).status(), 401);
        await login('cashier');
        await expect(alert).toContainText('Fiscal period ends tomorrow');
        await expect(alert).toContainText('GCash session');
        const own = await (await context.request.get(statusURL)).json();
        assert.equal(own.reminders.length, 2);
        for (const bell of await page.locator('.global-notification-button').all()) await expect(bell).toHaveAttribute('aria-label', /unread/);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 1280]) {
                await page.setViewportSize({width, height: 900});
                const geometry = await alert.evaluate(element => ({width: element.getBoundingClientRect().width, viewport: innerWidth}));
                assert.ok(geometry.width <= geometry.viewport, 'Closing alert fits viewport');
                await page.screenshot({path: path.join(output, 'reminder-' + theme + '-' + width + '.png'), fullPage: true});
            }
        }
        await page.getByRole('button', {name: 'Later', exact: true}).click();
        await page.reload();
        await expect(alert).toBeHidden();
        await page.goto('/components/cashier/gcash.php');
        await expect(alert).toBeHidden();
        const key = 'retailmind.fiscal-closing.4.cashier';
        await page.evaluate(key => localStorage.setItem(key, '2000-01-01'), key);
        fixture('lock');
        await page.reload();
        await expect(alert).toBeHidden();
        fixture('unlock');
        await page.evaluate(() => { const dialog = document.createElement('dialog'); dialog.id = 'test-dialog'; document.body.append(dialog); dialog.showModal(); document.dispatchEvent(new Event('visibilitychange')); });
        await page.waitForTimeout(250);
        await expect(alert).toBeHidden();
        await page.evaluate(() => document.getElementById('test-dialog').remove());
        await expect(async () => {
            await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
            await expect(alert).toContainText('Fiscal period ends tomorrow', {timeout: 1000});
        }).toPass({timeout: 10000});
        await page.getByRole('button', {name: 'Review closing tasks'}).click();
        await page.waitForURL('**/notification/notifications.php?category=fiscal_period');
        await expect(page.locator('.main-content')).toContainText('Reconcile and close your GCash session');
        fixture('expired');
        await page.goto('/components/cashier/gcash.php');
        await page.locator('#gcash-close-session-btn').click();
        await page.locator('#gcash-close-cash').fill('100');
        await page.locator('#gcash-close-wallet').fill('100');
        await page.locator('#gcash-close-submit').click();
        await expect(page.locator('#gcash-open-session-btn')).toBeVisible();
        const remaining = await (await context.request.get(statusURL)).json();
        assert.equal(remaining.reminders.length, 1, 'Actual GCash closure clears only the GCash reminder');
        await login('inventory_manager');
        await expect(alert).toContainText('Fiscal period has ended');
        await expect(alert).toContainText('Inventory Count');
        await page.getByRole('button', {name: 'Review closing tasks'}).click();
        await page.waitForURL('**/notification/notifications.php?category=fiscal_period');
        await page.getByRole('button', {name: 'Review Inventory Counts', exact: true}).click();
        await page.waitForURL('**/inventory_management/inventory_counts.php');
        assert.equal((await context.request.post(statusURL)).status(), 405);
        fixture('resolved');
        assert.equal((await (await context.request.get(statusURL)).json()).reminders.length, 0);
        await page.reload();
        await expect(alert).toBeHidden();
        assert.deepEqual(errors, []);
        console.log('Fiscal closing browser: passed (roles, daily popup, bell, lock/dialog deferral, responsive themes, real GCash close with posting blocked)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup', 'theme_fixture.php');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
