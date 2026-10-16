const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('POS idempotent checkout: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-idempotent-checkout-'));
const env = { ...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery') };

const fixture = action => {
    const result = spawnSync(php, [path.join(__dirname, 'support/cashier_ui_fixture.php'), action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};

const queryDb = code => {
    const result = spawnSync(php, ['-r', `
        require 'src/backend/bootstrap/app.php';
        $c = require 'src/backend/config/database.php';
        $p = new PDO(sprintf('mysql:host=%s;port=%s;dbname=${database};charset=utf8mb4', $c['host'], $c['port']), $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ${code}
    `], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout.trim();
};

(async () => {
    let server, browser, context, created = false;
    try {
        const data = fixture('create');
        created = true;

        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: ['ignore', 'ignore', 'pipe'] });

        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({ headless: true });
        context = await browser.newContext();
        const page = await context.newPage();
        page.setDefaultTimeout(5000);

        await page.route('https://**/*', route => route.fulfill({ body: '' }));

        // 1. Authenticate Cashier
        const login = await page.goto(origin + '/?login=1');
        assert.equal(login.status(), 200, logs + await page.locator('body').innerText());
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', { name: 'Log in', exact: true }).click()]);

        await page.goto(origin + '/components/cashier/pos.php');

        const initialSales = Number(queryDb("echo (int)$p->query('SELECT COUNT(*) FROM sales')->fetchColumn();"));

        // Add CASHIER-101 to cart
        await page.locator('#sku-input').fill('CASHIER-101');
        await page.locator('#add-code-btn').click();
        await page.locator('#cart-body td strong.cart-item-name').waitFor();
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Item added to cart');

        await page.reload();
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Refresh restores the cart');
        await page.locator('#cart-body .qty-btn').last().click();
        assert.equal(await page.locator('#cart-item-count').innerText(), '2', 'Restored cart quantity can increase');
        await page.locator('#cart-body .qty-btn').first().click();
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Restored cart quantity can decrease');
        await page.locator('#cart-body .remove-item-btn').click();
        assert.equal(await page.locator('#cart-item-count').innerText(), '0', 'Restored cart item can be removed');
        await page.reload();
        assert.equal(await page.locator('#cart-item-count').innerText(), '0', 'Removed item stays removed after refresh');
        await page.locator('#sku-input').fill('CASHIER-101');
        await page.locator('#add-code-btn').click();
        await page.locator('#cart-body td strong.cart-item-name').waitFor();

        // --- SECTION 1: Offline check blocks checkout attempt ---
        await context.setOffline(true);
        await page.locator('#checkout-button').click();
        await page.locator('.rm-toast.error').waitFor();
        const offlineMsg = await page.locator('.rm-toast.error').innerText();
        assert.match(offlineMsg, /internet connection/i, 'Offline block message shown');
        assert.equal(await page.locator('#checkout-modal.open').count(), 0, 'Checkout modal blocked when offline');
        await context.setOffline(false);

        // --- SECTION 2: Idempotent checkout & retry with same attempt token ---
        await page.locator('#checkout-button').click();
        await page.locator('#checkout-modal.open').waitFor();
        await page.locator('#review-cash').fill('50');

        const paymentRequest = page.waitForRequest(request => request.method() === 'POST' && request.postData()?.includes('checkout_attempt='));
        await Promise.all([page.waitForNavigation(), page.locator('#confirm-checkout-button').click()]);
        const postData = (await paymentRequest).postData();
        const attempt = new URLSearchParams(postData).get('checkout_attempt');
        assert.ok(attempt && attempt.length === 32, '32-character attempt token transmitted: ' + attempt);

        await page.locator('#receiptModal[open] .sale-receipt').waitFor();
        assert.match(await page.locator('#receiptModal').innerText(), /Payment completed/);

        // Verify database: 1 sale record added, stock decremented by 1
        const salesCountAfterFirst = Number(queryDb("echo (int)$p->query('SELECT COUNT(*) FROM sales')->fetchColumn();"));
        const stockAfterFirst = Number(queryDb("echo (int)$p->query('SELECT quantity_on_hand FROM inventory WHERE product_id=101')->fetchColumn();"));
        assert.equal(salesCountAfterFirst, initialSales + 1, 'Exactly 1 sale committed');
        assert.equal(stockAfterFirst, 9, 'Inventory decremented once (10 -> 9)');

        // Retry same submission with identical attempt token via HTTP POST (simulating lost response or cashier double-submit)
        const retryResponse = await page.request.post(origin + '/components/cashier/pos.php', {
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            data: postData,
        });
        assert.equal(retryResponse.status(), 200, 'Retry returns HTTP 200');
        const retryJson = await retryResponse.json();
        assert.equal(retryJson.success, true, 'Retry outcome indicates success');
        assert.ok(retryJson.sale_id, 'Retry outcome includes committed sale_id');

        // Verify DB unchanged after retry (idempotency preserved)
        const salesCountAfterRetry = Number(queryDb("echo (int)$p->query('SELECT COUNT(*) FROM sales')->fetchColumn();"));
        const stockAfterRetry = Number(queryDb("echo (int)$p->query('SELECT quantity_on_hand FROM inventory WHERE product_id=101')->fetchColumn();"));
        assert.equal(salesCountAfterRetry, initialSales + 1, 'Sale count unchanged after retry with same attempt token');
        assert.equal(stockAfterRetry, 9, 'Inventory not decremented again on retry');

        // Dismiss receipt modal
        await page.getByRole('button', { name: 'Close Sale Receipt' }).click();

        // --- SECTION 3: Concurrent low-stock conflict modal & active cart retention ---
        // Build cart with 5 units of CASHIER-102 (currently has 10 units in DB)
        await page.locator('#sku-input').fill('CASHIER-102');
        await page.locator('#add-code-btn').click();
        await page.locator('#cart-body td strong.cart-item-name').waitFor();
        await page.evaluate(() => updateCartQty(102, 5));
        await page.waitForFunction(() => document.getElementById('cart-item-count')?.innerText === '5');

        // Simulate concurrent stock depletion in database (another register sold 8 units, leaving only 2)
        queryDb("$p->exec('UPDATE inventory SET quantity_on_hand=2 WHERE product_id=102');");

        // Cashier initiates checkout
        await page.locator('#checkout-button').click();

        // Server refuses with HTTP 409 Conflict, POS retains active cart and displays conflict modal
        await page.locator('#stock-conflict-modal.open').waitFor();
        assert.equal(await page.locator('#stock-conflict-modal.open').isVisible(), true, 'Stock conflict modal displayed');

        // Verify cart is preserved intact
        assert.equal(await page.locator('#cart-item-count').innerText(), '5', 'Active cart preserved on conflict');

        // Verify conflict details in modal
        const conflictContent = await page.locator('#stock-conflict-content').innerText();
        assert.match(conflictContent, /Household essentials with a longer product name 102|CASHIER-102/i, 'Product name shown in conflict modal');
        assert.match(conflictContent, /2/, 'Available stock (2) shown in conflict modal');

        // Cashier adjusts quantity directly in modal using "Set to 2" button
        await page.locator('.adjust-conflict-qty-btn').first().click();

        // Cashier proceeds with updated cart
        await page.locator('#confirm-conflict-adjustments-btn').click();

        // Checkout review modal opens with updated cart (2 units)
        await page.locator('#checkout-modal.open').waitFor();
        await page.locator('#review-cash').fill('100');

        const secondPaymentRequest = page.waitForRequest(request => request.method() === 'POST' && request.postData()?.includes('checkout_attempt='));
        await Promise.all([page.waitForNavigation(), page.locator('#confirm-checkout-button').click()]);
        await secondPaymentRequest;

        // Sale completes with updated quantity
        await page.locator('#receiptModal[open] .sale-receipt').waitFor();
        assert.match(await page.locator('#receiptModal').innerText(), /Payment completed/);

        // Verify DB: second sale recorded for 2 units, inventory depleted to 0
        const salesCountFinal = Number(queryDb("echo (int)$p->query('SELECT COUNT(*) FROM sales')->fetchColumn();"));
        const stock102Final = Number(queryDb("echo (int)$p->query('SELECT quantity_on_hand FROM inventory WHERE product_id=102')->fetchColumn();"));
        assert.equal(salesCountFinal, initialSales + 2, 'Second sale committed after conflict adjustment');
        assert.equal(stock102Final, 0, 'Inventory depleted to 0 (2 - 2 = 0)');

        await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
        await page.goto(origin + '/components/cashier/pos.php');
        await page.locator('#sku-input').fill('CASHIER-103');
        await page.locator('#add-code-btn').click();
        await page.locator('#cart-body td strong.cart-item-name').waitFor();
        // A failed/lost submission leaves a durable attempt without a committed sale.
        const cancelledId = await page.evaluate(() => {
            const state = JSON.parse(sessionStorage.getItem('retailmind.cart-workspace'));
            const id = 'e'.repeat(32);
            const fields = Object.fromEntries(new FormData(document.getElementById('checkout-form')));
            delete fields.csrf_token;
            delete fields.discount_approver_password;
            localStorage.setItem('retailmind.checkout.4', JSON.stringify({id, cart: state.cart, fields}));
            return id;
        });
        // Existing browsers can retain the pre-cancellation script in the service worker cache.
        const legacyCheckout = fs.readFileSync('src/frontend/assets/js/checkout-attempt.js', 'utf8')
            .replace(/    async cancel\(\)[\s\S]*?(?=    async save\()/, '');
        await page.evaluate(async source => {
            await navigator.serviceWorker.ready;
            const cache = await caches.open('retailmind-static-v2');
            await cache.put(new URL('/assets/js/checkout-attempt.js', location.origin),
                new Response(source, {headers: {'Content-Type': 'application/javascript'}}));
        }, legacyCheckout);
        await page.reload();
        await page.locator('#cancel-checkout-btn').waitFor({state: 'visible'});
        assert.equal(await page.evaluate(() => typeof CheckoutAttempt.prototype.cancel), 'function', 'POS must load the updated recovery script even with a legacy service worker cache');
        await page.locator('.remove-item-btn').click();
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Pending payment blocks cart deletion');
        await page.locator('[data-account-menu-open]:visible').first().click();
        await page.locator('a[href*="auth/logout.php"]').first().click();
        await page.locator('.rm-toast').filter({hasText: 'Recover checkout first'}).waitFor();
        await page.keyboard.press('Escape');

        const denied = await page.request.post(origin + '/components/cashier/pos.php', {
            form: {action: 'cancel_checkout', checkout_attempt: cancelledId,
                csrf_token: await page.locator('input[name="csrf_token"]').first().inputValue()}
        });
        assert.equal(denied.status(), 409, 'Cancellation requires an explicit no-outstanding-payment confirmation');
        await page.locator('#cancel-checkout-btn').click();
        await page.locator('.rm-cancel').click();
        assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.4')).id), cancelledId, 'Declining cancellation preserves recovery');

        await page.evaluate(() => document.querySelectorAll('.rm-toast').forEach(toast => toast.remove()));
        await page.setViewportSize({width: 390, height: 844});
        await page.locator('#checkout-recovery').scrollIntoViewIfNeeded();
        await page.screenshot({path: path.join(output, 'checkout-recovery-mobile.png'), fullPage: true});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Recovery controls fit mobile');
        await page.setViewportSize({width: 1365, height: 900});
        await page.locator('#checkout-recovery').scrollIntoViewIfNeeded();
        await page.screenshot({path: path.join(output, 'checkout-recovery-desktop.png'), fullPage: true});
        await page.locator('#cancel-checkout-btn').click();
        await page.locator('.rm-accept').click();
        await page.waitForFunction(() => localStorage.getItem('retailmind.checkout.4') === null);
        assert.equal(await page.evaluate(() => localStorage.getItem('retailmind.checkout.4')), null, 'Confirmed cancellation releases logout guard');
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Cancellation keeps cart available for editing');
        await page.reload();
        await page.locator('.remove-item-btn').click();
        await page.waitForFunction(() => document.getElementById('cart-item-count').innerText === '0');
        const late = new URLSearchParams(postData);
        late.set('checkout_attempt', cancelledId);
        late.set('checkout_attempt_id', cancelledId);
        const lateResponse = await page.request.post(origin + '/components/cashier/pos.php', {
            headers: {Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'}, data: late.toString()
        });
        assert.equal(lateResponse.status(), 409, 'Delayed payment submission is refused after cancellation');
        assert.equal(Number(queryDb("echo (int)$p->query('SELECT COUNT(*) FROM sales')->fetchColumn();")), salesCountFinal, 'Cancellation and late retry create no sale');
        await page.locator('[data-account-menu-open]:visible').first().click();
        await page.locator('a[href*="auth/logout.php"]').first().click();
        await page.waitForURL(url => !url.pathname.includes('/components/cashier/pos.php'));
        console.log('POS idempotent checkout browser test: passed (offline, idempotency, stock conflicts, recovered checkout cancellation, payment confirmation, cart removal, reload, logout, late retry)');
        console.log('Recovery screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (created) fixture('cleanup');
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
