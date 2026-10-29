'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Product photos: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-product-photos-'));
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, [path.join(__dirname, 'support/cashier_ui_fixture.php'), action, database], { encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

(async () => {
    let created = false, server, browser;
    const uploads = [];
    try {
        const data = fixture('create');
        created = true;
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { env: { ...process.env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        await page.route('https://**/*', route => route.fulfill({ body: '' }));
        const login = async role => {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', { name: 'Log in', exact: true }).click()]);
        };
        const loaded = async selector => {
            await page.locator(selector).waitFor();
            await page.waitForFunction(selector => {
                const image = document.querySelector(selector);
                return image?.complete && image.naturalWidth > 0;
            }, selector);
        };
        await login('inventory_manager');
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.evaluate(() => openProductDrawer(101));
        await page.locator('#product-edit-btn').click();
        await page.locator('#edit-product-image').setInputFiles({ name: 'product.png', mimeType: 'image/png', buffer: png });
        await loaded('#edit-product-photo-preview img');
        const saveResponse = page.waitForResponse(response => response.url().endsWith('/products.php') && response.request().method() === 'POST');
        await page.locator('#product-save-btn').click();
        const saved = await (await saveResponse).json();
        assert.equal(saved.success, true, JSON.stringify(saved));
        const photoUrl = saved.product.product_image;
        const filename = new URL(photoUrl, origin).searchParams.get('file');
        assert.match(filename, /^[a-f0-9]{32}\.png$/);
        uploads.push(filename);
        await page.locator('#product-edit-form').waitFor({ state: 'detached' });
        await loaded('#product-panel-info .product-photo-preview img');
        await loaded('tr[data-product-id="101"] .product-thumb img');
        await page.locator('#product-edit-btn').click();
        await loaded('#edit-product-photo-preview img');
        await page.screenshot({ path: path.join(output, 'inventory-edit.png') });
        await page.goto(origin + '/components/report/stock_receiving.php');
        await loaded('[data-product-id="101"] .receiving-card-media img');
        assert.equal((await context.request.get(origin + photoUrl)).status(), 200);
        assert.equal((await context.request.get(origin + photoUrl.replace(filename, 'missing.png'))).status(), 404);
        assert.equal((await context.request.get(origin + photoUrl.replace(filename, '..%2Fsecret.png'))).status(), 404);
        assert.equal((await context.request.get(origin + photoUrl.replace(filename, 'x.php'))).status(), 404);
        assert.equal((await fetch(origin + photoUrl)).status, 401, 'Anonymous image access is refused');

        await context.clearCookies();
        await login('cashier');
        await page.goto(origin + '/components/cashier/pos.php');
        await loaded('[data-product-id="101"] .quick-product-image img');
        const scan = await context.request.post(origin + '/components/cashier/pos.php', { form: {
            action: 'scan_lookup', csrf_token: await page.locator('input[name="csrf_token"]').first().inputValue(), code: 'CASHIER-101', quantities: '{}'
        } });
        assert.equal((await scan.json()).product.product_image, photoUrl, 'Server scan fallback must include the photo even outside the quick catalog');
        await page.locator('#sku-input').fill('CASHIER-101');
        await page.locator('#add-code-btn').click();
        await loaded('#cart-body .cart-product-image img');
        await page.reload();
        await loaded('#cart-body .cart-product-image img');
        assert.equal(await page.evaluate(async () => {
            const requests = (await Promise.all((await caches.keys()).map(async key => (await caches.open(key)).keys()))).flat();
            return requests.some(request => new URL(request.url).pathname.endsWith('/product_image.php'));
        }), false, 'Authenticated product photos must stay outside the static service-worker cache');
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({ width, height: 1024 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No page overflow at ' + width);
            await page.screenshot({ path: path.join(output, 'pos-' + width + '.png') });
        }
        await page.route('**/product_image.php?**', route => route.fulfill({ status: 404, body: '' }));
        await page.reload();
        await page.locator('[data-product-id="101"] .quick-product-image').scrollIntoViewIfNeeded();
        const fallback = page.locator('[data-product-id="101"] .quick-product-image i.bi-box-seam');
        await fallback.waitFor({ state: 'visible' });
        await page.locator('#cart-body .cart-product-image').scrollIntoViewIfNeeded();
        await page.locator('#cart-body .cart-product-image i.bi-box-seam').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#cart-item-count').innerText(), '1', 'Failed photos preserve the cart');
        await page.locator('[data-product-id="102"] .quick-product-image i.bi-box-seam').waitFor({ state: 'visible' });
        console.log('Product photos: passed (upload, inventory, detail/edit, receiving, POS card/cart, reload, placeholders, access checks). Screenshots: ' + output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        for (const filename of uploads) fs.unlinkSync(path.join(__dirname, '../storage/images', filename));
        if (created) fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
