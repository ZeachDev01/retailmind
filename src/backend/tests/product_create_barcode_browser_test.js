'use strict';
const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { mkdtempSync, mkdirSync, rmSync } = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Add Product barcode lookup: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const storage = mkdtempSync(path.join(os.tmpdir(), 'rm-product-lookup-'));
const env = { ...process.env, RM_TEST_PRODUCT_CATALOG: '1', BACKUP_STORAGE_PATH: storage, RM_THEME_TEST_DATABASE: database };
const fixture = action => {
    const result = spawnSync('php', [path.join(__dirname, 'support/theme_fixture.php'), action, database], { encoding: 'utf8', env });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return JSON.parse(result.stdout || 'null');
};

(async () => {
    let server, browser;
    try {
        const data = fixture('create');
        fixture('open_fiscal_period');
        fixture('receiving_pack_config');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], { env, stdio: 'ignore' });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('PHP fixture server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const productsUrl = origin + '/components/inventory_management/products.php';
        const lookupUrl = origin + '/components/barcodeScanner/apiScanner/product_code_lookup.php';
        const signIn = async (page, role) => {
            page.setDefaultTimeout(5000);
            await page.route('https://**/*', route => route.fulfill({ body: '' }));
            await page.goto(origin + '/?login=1', { waitUntil: 'domcontentloaded' });
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(url => !url.search.includes('login=1'), { waitUntil: 'domcontentloaded' }),
                page.getByRole('button', { name: 'Log in', exact: true }).click()
            ]);
        };
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce' });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await signIn(page, 'inventory_manager');
            await page.goto(productsUrl);
            await page.locator('#product-add-trigger').click();
            await page.locator('#add-product-btn').click();
            const dialog = page.getByRole('dialog', { name: 'Add Product' });
            const type = dialog.getByLabel('Barcode type');
            const unit = dialog.getByLabel('Individual Item Barcode');
            const lookup = dialog.getByRole('button', { name: 'Look up', exact: true });
            const scan = dialog.getByRole('button', { name: 'Scan', exact: true });
            const save = dialog.getByRole('button', { name: 'Save Product', exact: true });
            const feedback = dialog.locator('#create-barcode-feedback');
            const details = dialog.getByLabel('Product Name');
            const [lookupRect, detailsRect, saveRect] = await Promise.all([type.boundingBox(), details.boundingBox(), save.boundingBox()]);
            assert.equal(await dialog.locator('form').count(), 1);
            if (width === 1440) {
                assert(lookupRect.x + lookupRect.width < detailsRect.x, 'Desktop places lookup beside the form');
            } else {
                assert(lookupRect.y + lookupRect.height < detailsRect.y, 'Mobile places lookup above details');
            }
            assert(saveRect.y + saveRect.height <= 900, 'The Save action remains in the viewport');
            await type.focus();
            for (const id of ['create-barcode-input', 'create-barcode-lookup', 'add-product-scan-btn']) {
                await page.keyboard.press('Tab');
                assert.equal(await page.evaluate(() => document.activeElement.id), id);
            }

            const requests = [];
            page.on('request', request => {
                if (request.url().startsWith(lookupUrl)) requests.push(new URL(request.url()).searchParams);
            });
            await details.fill('Keep these details');
            await unit.fill('0000012345678');
            assert.equal(requests.length, 0, 'Typed or pasted codes wait for Look up');
            const typed = page.waitForResponse(response => response.url().startsWith(lookupUrl));
            await lookup.click();
            assert.equal((await (await typed).json()).outcome, 'unknown');
            assert.equal(requests.at(-1).get('code'), '0000012345678');
            assert.equal(requests.at(-1).get('barcode_type'), 'unit_barcode');
            assert.equal(await details.inputValue(), 'Keep these details');

            await unit.fill('012345678905');
            await lookup.click();
            await dialog.getByRole('button', { name: 'Keep entered details' }).click();
            await feedback.getByText(/Open Food Facts match/).waitFor();
            assert.equal(await details.inputValue(), 'Keep these details');
            assert.equal(await dialog.getByLabel('Declared Size').inputValue(), '330 mL');
            await dialog.getByRole('button', { name: /Apply.*Fixture Tea/ }).click();
            assert.equal(await details.inputValue(), 'Fixture Tea');
            await details.fill('Keep these details');

            await unit.fill('THEME-ITEM');
            await lookup.click();
            await dialog.getByRole('button', { name: 'Keep entered details' }).click();
            assert.equal(await dialog.getByLabel('Declared Size').inputValue(), '');
            const manage = feedback.getByRole('link', { name: 'Manage Product' });
            await manage.waitFor();
            assert.match(await feedback.innerText(), /Theme Test Item.*Store SKU.*Active/s);
            if (process.env.IMPECCABLE_REVIEW_DIR) {
                mkdirSync(process.env.IMPECCABLE_REVIEW_DIR, { recursive: true });
                await page.screenshot({ path: path.join(process.env.IMPECCABLE_REVIEW_DIR, `add-product-barcode-${width}.png`) });
            }
            if (width === 1440) {
                const popup = page.waitForEvent('popup');
                await manage.click();
                const managePage = await popup;
                await managePage.locator('#product-drawer.open').waitFor();
                assert.match(await managePage.locator('#product-drawer').innerText(), /Theme Test Item/);
                await managePage.close();
                assert.equal(await details.inputValue(), 'Keep these details');
            }

            await type.selectOption('pack_barcode');
            const packInput = dialog.getByLabel('Sales Pack Barcode', { exact: true });
            const wedge = page.waitForResponse(response => response.url().startsWith(lookupUrl));
            await packInput.pressSequentially('THEME-PACK');
            await packInput.press('Enter');
            assert.equal((await (await wedge).json()).matched_code_kind, 'pack_barcode');
            assert.equal(requests.at(-1).get('barcode_type'), 'pack_barcode');
            assert.equal(await dialog.locator('#create-pack-barcode').inputValue(), 'THEME-PACK');

            await page.evaluate(() => {
                window.Html5Qrcode = class {
                    async start(_camera, _options, complete) { this.isScanning = true; window.finishCameraScan = complete; }
                    async stop() { this.isScanning = false; }
                };
            });
            await type.selectOption('case_barcode');
            await scan.click();
            await dialog.getByRole('button', { name: 'Stop scanning' }).waitFor();
            const camera = page.waitForResponse(response => response.url().startsWith(lookupUrl));
            await page.evaluate(() => window.finishCameraScan('THEME-CASE'));
            assert.equal((await (await camera).json()).matched_code_kind, 'case_barcode');
            assert.equal(requests.at(-1).get('barcode_type'), 'case_barcode');
            assert.equal(await dialog.locator('#create-receiving-barcode').inputValue(), 'THEME-CASE');
            await dialog.getByRole('button', { name: 'Stop scanning' }).waitFor({ state: 'hidden' });
            assert.deepEqual(errors, []);
            await page.close();
        }

        fixture('receiving_unavailable');
        const manager = await browser.newPage();
        await signIn(manager, 'inventory_manager');
        await manager.goto(productsUrl);
        await manager.locator('#product-add-trigger').click();
        await manager.locator('#add-product-btn').click();
        await manager.locator('#create-barcode-input').fill('THEME-BARCODE');
        await manager.getByRole('button', { name: 'Look up', exact: true }).click();
        const inactive = manager.locator('#create-barcode-feedback');
        await inactive.getByRole('link', { name: 'Manage Product' }).waitFor();
        assert.match(await inactive.innerText(), /Theme Test Item.*Individual Item.*Inactive/s);
        const unavailable = await manager.request.get(lookupUrl + '?code=THEME-BARCODE&context=receiving');
        const oldResult = await unavailable.json();
        assert.equal(oldResult.outcome, 'inactive');
        assert.equal(oldResult.product, null, 'Receiving still withholds inactive details');

        const category = await manager.locator('#product-category option').nth(1).textContent();
        await manager.locator('#product-category-search').fill(category);
        await manager.getByRole('option', { name: category, exact: true }).click();
        await manager.getByLabel('Product Name').fill('Duplicate inactive barcode test');
        await manager.locator('#cost-price').fill('2');
        await manager.locator('#selling-price').fill('5');
        await manager.getByRole('button', { name: 'Save Product', exact: true }).click();
        await manager.locator('#create-barcode-input[aria-invalid="true"]').waitFor();
        assert.equal(await manager.locator('#create-barcode-input').getAttribute('aria-invalid'), 'true');
        await Promise.all([
            manager.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            manager.locator('#add-product-form').evaluate(form => form.submit())
        ]);
        await manager.locator('#product-create-error').waitFor();
        assert.match(await manager.locator('#product-create-error').innerText(), /barcode.*already assigned/i);
        assert.equal(await manager.getByLabel('Product Name').inputValue(), 'Duplicate inactive barcode test');
        assert.equal(await manager.locator('#create-barcode-input').inputValue(), 'THEME-BARCODE');
        await manager.close();

        const admin = await browser.newPage();
        await signIn(admin, 'admin');
        const denied = await admin.request.get(lookupUrl + '?code=THEME-BARCODE&context=add_product&barcode_type=unit_barcode');
        assert.equal(denied.status(), 403);
        assert.equal((await denied.json()).product, undefined, 'An Administrator cannot obtain inactive details via Add Product');
        const ordinary = await admin.request.get(lookupUrl + '?code=THEME-BARCODE');
        assert.equal((await ordinary.json()).outcome, 'unknown', 'Ordinary lookup still excludes inactive Products');
        console.log('Add Product barcode lookup: desktop/mobile layout, manual lookup, wedge and camera scans, authorization and duplicate protection passed');
    } finally {
        try {
            if (browser) await browser.close();
            if (server) server.kill();
            fixture('cleanup');
        } finally {
            rmSync(storage, { recursive: true, force: true });
        }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
