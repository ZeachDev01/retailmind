const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Administrator low-stock notifications: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-admin-stock-notifications-'));
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/notification_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
// Query the disposable DB through the same configured connection as the fixture.
const query = sql => {
    const script = "require 'src/backend/bootstrap/app.php'; $c=require 'src/backend/config/database.php';"
        + " $p=new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname=" + database + "', $c['username'],$c['password']);"
        + " echo json_encode($p->query(base64_decode(getenv('RM_TEST_SQL')))->fetchAll(PDO::FETCH_ASSOC));";
    const result = spawnSync(php, ['-r', script], {encoding: 'utf8', env: {...env, RM_TEST_SQL: Buffer.from(sql).toString('base64')}});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout);
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.waitForFunction(() => document.activeElement.id === 'landing-login-username');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/notification/notifications.php';
        const submit = async button => Promise.all([page.waitForNavigation(), button.click()]);
        query('UPDATE inventory SET quantity_on_hand = 0 WHERE product_id = 1');
        fixture('workspace_check_stock');
        await page.goto(origin + '/components/administrator/dashboard.php');
        await submit(page.locator('.global-topbar .global-notification-button').first());
        assert.equal(new URL(page.url()).pathname, '/components/notification/notifications.php', 'Bell opens notifications');
        const stockWork = page.locator('.notification-required-item').filter({hasText: 'Theme Test Item'});
        assert.equal(await stockWork.count(), 1, 'Administrator sees active out-of-stock oversight');
        assert.equal(await stockWork.locator('a, [role="link"]').count(), 0, 'Administrator stock oversight is not clickable');
        assert.equal(await stockWork.locator('.notification-current').innerText(), '0', 'Plain stock alert retains quantity');
        const adminStock = page.locator('.notification-item[data-notification-type="low_stock"]');
        assert.equal(await adminStock.count(), 1);
        assert.equal(await adminStock.locator('a, [role="link"]').count(), 0, 'Administrator stock history has no inventory link');
        for (const item of [stockWork, adminStock]) {
            await item.locator('.notification-category').click();
            await page.waitForTimeout(150);
            assert.equal(new URL(page.url()).pathname, '/components/notification/notifications.php', 'Clicking administrator stock alerts stays on notifications');
        }
        console.log('Administrator low-stock notifications: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
