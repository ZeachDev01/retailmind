const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium, expect} = require('@playwright/test');

if (process.env.RUN_DB_TESTS !== '1') throw new Error('RUN_DB_TESTS=1 and disposable MySQL required');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-fiscal-navigation-'));
const output = path.resolve('output/playwright/fiscal-navigation');
fs.mkdirSync(output, {recursive: true});
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
function fixture(action) {
    const result = spawnSync('php', ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
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
        const page = await browser.newPage({baseURL: origin});
        await page.goto('/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => url.pathname.includes('/components/')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto('/components/system_administrator/fiscal_periods.php');
        await expect(page.getByRole('heading', {name: 'Fiscal Periods', exact: true})).toBeVisible();
        await page.screenshot({path: path.join(output, 'current.png'), fullPage: true});
        await expect(page.getByRole('navigation', {name: 'Store configuration sections'})).toHaveCount(0);
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                await expect(page.getByRole('switch', {name: /Automatic rollover/})).toBeVisible();
                await expect(page.locator('#period_name')).toBeVisible();
                await expect(page.locator('#start_date')).toBeVisible();
                await expect(page.locator('#end_date')).toBeVisible();
                const geometry = await page.locator('.fiscal-period-create').evaluate(element => {
                    const box = element.getBoundingClientRect();
                    return {left: box.left, right: box.right, viewport: innerWidth};
                });
                assert.ok(geometry.left >= 0 && geometry.right <= geometry.viewport, 'Fiscal period form fits viewport ' + width);
                await page.screenshot({path: path.join(output, theme + '-' + width + '.png'), fullPage: true});
            }
        }
        console.log('Fiscal period navigation: passed (no Settings sidebar, controls visible, desktop/mobile, light/dark)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
