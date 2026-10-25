const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Cashier checkout receipt: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-checkout-receipt-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const fixture = action => {
    const result = spawnSync(php, [path.join(__dirname, 'support/cashier_ui_fixture.php'), action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};

(async () => {
    let server, browser, created = false;
    try {
        const data = fixture('create');
        created = true;
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
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.addInitScript(() => { window.printCalls = 0; window.print = () => window.printCalls++; });
        page.setDefaultTimeout(5000);
        await page.route('https://**/*', route => route.fulfill({body: ''}));
        const login = await page.goto(origin + '/?login=1');
        assert.equal(login.status(), 200, logs + await page.locator('body').innerText());
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/cashier/pos.php');
        await page.locator('#sku-input').fill('CASHIER-101');
        await page.locator('#add-code-btn').click();
        await page.locator('#checkout-button').click();
        await page.locator('#review-cash').fill('50');
        assert.equal(await page.getByRole('checkbox', {name: 'Print receipt automatically'}).isChecked(), false);
        const paymentRequest = page.waitForRequest(request => request.method() === 'POST' && request.postData()?.includes('checkout_attempt='));
        await Promise.all([page.waitForNavigation(), page.locator('#confirm-checkout-button').click()]);
        const attempt = new URLSearchParams((await paymentRequest).postData()).get('checkout_attempt');
        assert.equal(new URL(page.url()).pathname, '/components/cashier/pos.php', 'Payment stays in cashier POS');
        await page.locator('#receiptModal[open] .sale-receipt').waitFor();
        assert.equal(await page.evaluate(() => window.printCalls), 0, 'unchecked checkout does not print');
        assert.match(await page.locator('#receiptModal').innerText(), /Payment completed/);
        const receiptUrl = page.url();
        assert.equal(await page.locator('#receiptsTable').count(), 0, 'Checkout does not show Receipt History');
        await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
        assert.equal(await page.locator('#receiptModal[open]').count(), 0);
        assert.equal(await page.locator('#sku-input').evaluate(el => document.activeElement === el), true, 'Closing receipt returns focus to cashier scanner');
        assert.equal(await page.locator('#cart-item-count').innerText(), '0', 'Paid cart is cleared');
        await page.reload();
        assert.equal(await page.locator('#receiptModal[open]').count(), 0, 'Reload does not reopen dismissed receipt');
        await page.goto(receiptUrl);
        await page.locator('#receiptModal[open] .sale-receipt').waitFor();
        assert.doesNotMatch(await page.locator('#receiptModal').innerText(), /Payment completed/, 'Revisiting receipt does not announce another payment');
        await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
        await page.evaluate(id => localStorage.setItem('retailmind.checkout.4', JSON.stringify({id, fields: {}, cart: {}})), attempt);
        await page.reload();
        await page.locator('#receiptModal[open] .sale-receipt').waitFor();
        assert.equal(new URL(page.url()).pathname, '/components/cashier/pos.php', 'Lost-response recovery also stays on POS');
        assert.equal(await page.evaluate(() => localStorage.getItem('retailmind.checkout.4')), null, 'Recovery clears the committed attempt');
        await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
        await page.locator('#sku-input').fill('CASHIER-101');
        await page.locator('#add-code-btn').click();
        await page.locator('#checkout-button').click();
        await page.locator('#review-cash').fill('50');
        const autoPrint = page.getByRole('checkbox', {name: 'Print receipt automatically'});
        assert.equal(await autoPrint.isChecked(), false, 'new sale starts unchecked');
        await autoPrint.check();
        for (const width of [1440, 390]) {
            await page.setViewportSize({width, height: 900});
            await page.screenshot({path: path.join(output, `auto-print-sale-${width}.png`)});
            assert.equal(await autoPrint.isVisible(), true);
            assert.equal(await page.locator('#checkout-modal').evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
        }
        await Promise.all([page.waitForNavigation(), page.locator('#confirm-checkout-button').click()]);
        await page.waitForFunction(() => window.printCalls === 1);
        assert.equal(await page.locator('.receipt-print-root .receipt-print-area').getAttribute('data-paper-width-mm'), '80');
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        await page.reload();
        await page.locator('#receiptModal[open]').waitFor();
        assert.equal(await page.evaluate(() => window.printCalls), 0, 'refresh does not print again');
        assert.equal(await page.getByRole('button', {name: 'Print to Store Printer', exact: true}).isVisible(), true, 'Saved receipt offers Store PC delivery');
        await page.getByRole('button', {name: 'Print Receipt', exact: true}).click();
        assert.equal(await page.evaluate(() => window.printCalls), 1, 'manual retry remains available');
        console.log('Cashier payment → saved receipt on POS → close/reload: passed');
        console.log('Sale opt-in auto-print, unchecked default, refresh and manual retry: passed; screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (created) fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
