const assert = require('node:assert/strict');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Recovery Account removal: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        server = spawn(php, ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...process.env, RM_THEME_TEST_DATABASE: database}});
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.on('exit', code => reject(new Error('PHP server exited: ' + code)));
            server.stderr.on('data', chunk => {
                const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:(\d+)/);
                if (match) resolve(match[0]);
            });
        });
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_super_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([
            page.waitForURL(url => !url.search.includes('login=1'), {waitUntil: 'domcontentloaded'}),
            page.getByRole('button', {name: 'Log in', exact: true}).click(),
        ]);
        const retiredPage = await page.request.get(origin + '/components/system_administrator/recovery_account.php');
        assert.equal(retiredPage.status(), 404, 'Retired Recovery Account page must be unavailable');
        const dashboard = await page.goto(origin + '/components/super_administrator/dashboard.php', {waitUntil: 'domcontentloaded'});
        assert.equal(dashboard.status(), 200, 'Super Administrator dashboard must still render');
        assert.equal(await page.locator('.readiness-ledger').count(), 1, 'Dashboard status must load successfully');
        assert.equal(await page.locator('a[href*="system_administrator/recovery_account.php"]').count(), 0,
            'Dashboard and sidebar must not link to the retired page');
        assert.equal(await page.locator('.ledger-item').filter({hasText: 'Recovery Account'}).count(), 0,
            'Dashboard must not offer Recovery Account status');
        assert.equal(await page.getByText('Recovery access', {exact: true}).count(), 0,
            'Dashboard must not offer the retired recovery readiness summary');
        console.log('Recovery Account removal browser test: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
