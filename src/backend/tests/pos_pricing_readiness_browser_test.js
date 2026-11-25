const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('POS pricing readiness: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const php = process.env.PHP_BINARY || 'php';
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
}
function query(code) {
    const result = spawnSync(php, ['-r', `require 'src/backend/bootstrap/app.php';
        $c = require 'src/backend/config/database.php';
        $p = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $argv[1]),
            $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); ${code}`, database], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout.trim();
}

(async () => {
    let server, browser, created = false;
    try {
        const data = fixture('create'); created = true;
        fixture('open_fiscal_period');
        query(`$p->exec("UPDATE products SET product_name='Unpriced milk',unit_price=0,cost_price=0,cost_known=0 WHERE product_id=1");
            $p->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,cost_known) VALUES
                (2,'MISSING','MISSING','Missing cost',25,0,0),
                (3,'FREE','FREE','Confirmed free goods',25,0,1),
                (4,'BATCH','BATCH','Recorded batch cost',25,0,0),
                (5,'FALLBACK','FALLBACK','Saved product cost',25,10,1)");
            $p->exec('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (2,12),(3,12),(4,12),(5,12)');
            $p->exec('UPDATE products SET pack_size=2,pack_price=45 WHERE product_id=4');
            $p->exec("INSERT INTO product_batches (product_id,batch_number,quantity,remaining_quantity,sealed_quantity,loose_quantity,cost_price,cost_known) VALUES (4,'KNOWN',12,12,2,8,12,1)");`);
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...process.env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'ignore']});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        await page.goto(origin + '/components/cashier/pos.php');
        await page.locator('.quick-product-card[data-product-id="1"]').waitFor();
        const csrf = await page.locator('#checkout-form [name=csrf_token]').inputValue();
        const scan = (code, form = null) => page.request.post(origin + '/components/cashier/pos.php', {
            form: {action: 'scan_lookup', code, csrf_token: csrf, quantities: '{}', ...(form ? {selling_form: form} : {})}
        });
        const cartItems = async () => JSON.parse(await page.locator('#cart-input').inputValue() || '[]');
        const zero = await scan('THEME-BARCODE');
        assert.equal(zero.status(), 409, 'Server refuses unpriced stock before adding to the cart');
        assert.match((await zero.json()).message, /selling price/i);
        const unknown = await scan('MISSING');
        assert.equal(unknown.status(), 409, 'Server refuses stock with no batch or confirmed Product cost');
        for (const id of [1, 2]) {
            const card = page.locator(`.quick-product-card[data-product-id="${id}"]`);
            await card.getByRole('button', {name: 'Pricing incomplete', exact: true}).waitFor();
            assert(await card.getByRole('button', {name: 'Pricing incomplete', exact: true}).isDisabled());
            assert(!/₱0\.00/.test(await card.innerText()), 'Unknown price is not presented as a free item');
        }
        for (const code of ['FREE', 'BATCH', 'FALLBACK']) {
            assert.equal((await scan(code)).status(), 200, 'Valid zero/batch/fallback costs remain addable');
        }
        const pack = await scan('BATCH', 'pack');
        assert.equal(pack.status(), 200, 'Explicit Sales Pack selection works without a separate pack barcode');
        assert.equal((await pack.json()).product.price, 45, 'Pack add uses the selling price, not purchase cost');
        await page.evaluate(() => addToCart(1, 'Unpriced milk', 0, 10));
        assert.deepEqual(await cartItems(), [], 'Direct add guard keeps zero-price products out of the cart');
        await page.evaluate(() => addQuickProduct(2));
        assert.deepEqual(await cartItems(), [], 'Direct quick-add cannot bypass missing cost');
        // A catalog card may have become stale since it rendered.
        query("$p->exec('UPDATE products SET cost_price=0,cost_known=0 WHERE product_id=5');");
        const [stale] = await Promise.all([
            page.waitForResponse(response => response.request().postData()?.includes('scan_lookup')),
            page.locator('.quick-product-card[data-product-id="5"] [data-quick-add]').click()
        ]);
        assert.equal(stale.status(), 409, 'Fresh server check blocks newly missing cost');
        assert.deepEqual(await cartItems(), [], 'Stale positive-price card cannot add a newly unpriced item');
        const output = path.resolve('output/pricing-safeguards'); fs.mkdirSync(output, {recursive: true});
        await page.locator('.rm-toast-close').evaluateAll(buttons => buttons.forEach(button => button.click()));
        for (const [width, theme] of [[1440, 'light'], [390, 'dark']]) {
            await page.setViewportSize({width, height: 1000});
            await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
            await page.screenshot({path: path.join(output, `pos-pricing-${width}-${theme}.png`), fullPage: true});
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No page-wide horizontal overflow');
            assert(await page.locator('.quick-product-add.pricing-incomplete').evaluateAll(buttons => buttons.every(button => button.scrollWidth <= button.clientWidth + 1)), 'Incomplete-pricing labels fit their controls');
        }
        assert.equal(query("echo $p->query('SELECT COUNT(*) FROM sales')->fetchColumn();"), '1', 'Only the original fixture sale exists');
        assert.equal(query("echo $p->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"), '10', 'Rejected additions preserve stock');
        assert.deepEqual(errors, []);
        console.log('POS pricing readiness: passed (disabled cards, scan/direct/quick guards, fresh cost validation, confirmed-zero/batch/fallback costs, no mutations, desktop/mobile)');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        if (created) fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
