'use strict';

const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { mkdtempSync, rmSync } = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { expect } = require('@playwright/test');
const { chromium } = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Add Product packaging: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const storage = mkdtempSync(path.join(os.tmpdir(), 'rm-product-packaging-'));
const env = { ...process.env, BACKUP_STORAGE_PATH: storage, RM_THEME_TEST_DATABASE: database, RM_TEST_PRODUCT_CATALOG: '1' };
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
        const manager = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
        async function signIn(page, role) {
            await page.route('https://**/*', route => route.fulfill({ body: '' }));
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await page.getByRole('button', { name: 'Log in', exact: true }).click();
            await page.waitForURL(url => !url.search.includes('login=1'));
        }
        await signIn(manager, 'inventory_manager');
        const productsUrl = origin + '/components/inventory_management/products.php';
        async function openAddProduct() {
            await manager.goto(productsUrl);
            await manager.locator('#product-add-trigger').click();
            await manager.locator('#add-product-btn').click();
        }
        await openAddProduct();
        const dialog = manager.getByRole('dialog', { name: 'Add Product' });
        const type = dialog.getByLabel('Barcode type');
        async function scan(field, code) {
            await field.pressSequentially(code);
            await field.press('Enter');
        }
        const cartonCode = '012345678905';
        const unitCode = '87654325';
        const packCode = '34567890';
        const internalCartonCode = '0001234567864';
        const carton = dialog.getByLabel('Receiving Carton Barcode', { exact: true });
        const cartonSize = dialog.getByLabel('Items per Receiving Package', { exact: true });
        const item = dialog.getByLabel('Individual Item Barcode', { exact: true });
        const feedback = dialog.locator('#create-barcode-feedback');
        await type.selectOption('case_barcode');
        await scan(carton, cartonCode);
        await expect(cartonSize).toBeFocused();
        await expect(feedback).toContainText('Enter the number of items per carton');
        await cartonSize.fill('24');
        await dialog.getByRole('button', { name: 'Enter or scan Individual Item', exact: true }).click();
        await expect(item).toBeFocused();
        await expect(item).toHaveValue('');
        await scan(item, unitCode);
        await expect(item).toHaveValue(unitCode);
        await expect(dialog.getByLabel('Product Name')).toHaveValue('Partial Fixture');
        await expect(dialog.getByLabel('Receiving Package Barcode (optional)', { exact: true })).toHaveValue(cartonCode);
        await expect(cartonSize).toHaveValue('24');
        await type.selectOption('pack_barcode');
        await scan(dialog.getByLabel('Sales Pack Barcode', { exact: true }), packCode);
        await expect(feedback).toContainText('Enter the Sales Pack size and price');
        await expect(dialog.locator('#create-pack-size')).toBeFocused();
        await dialog.locator('#create-pack-size').fill('6');
        await dialog.locator('#create-pack-price').fill('25');
        await dialog.locator('#create-opening-stock summary').click();
        await dialog.locator('#starting-packs-count').fill('2');
        await dialog.locator('#starting-pack-loose-count').fill('3');

        async function saveProduct(name) {
            const category = (await dialog.locator('#product-category option:not([value=""])').first().textContent()).trim();
            await dialog.getByRole('combobox', { name: 'Category *', exact: true }).fill(category);
            await manager.getByRole('option', { name: category, exact: true }).click();
            await dialog.getByLabel('Product Name').fill(name);
            await dialog.locator('#cost-price').fill('2');
            await dialog.locator('#selling-price').fill('5');
            await Promise.all([
                manager.waitForURL(url => url.searchParams.has('success')),
                dialog.getByRole('button', { name: 'Save Product', exact: true }).click()
            ]);
            return new URL(manager.url()).searchParams.get('success').split('Barcode: ')[1];
        }
        assert.equal(await saveProduct('Carton and Sales Pack Fixture'), unitCode);

        await openAddProduct();
        await type.selectOption('case_barcode');
        await scan(carton, internalCartonCode);
        await cartonSize.fill('12');
        const internalCode = await saveProduct('Carton with Generated Item Fixture');
        assert.match(internalCode, /^RM\d{14}$/);

        const receivingUrl = origin + '/components/report/stock_receiving.php';
        for (const [code, mode, quantity, units] of [
            [cartonCode, 'packages', '#received_packages', '48'],
            [packCode, 'packs', '#received_packs', '12'],
            [unitCode, 'units', '#received_qty', '2'],
            [internalCartonCode, 'packages', '#received_packages', '24'],
            [internalCode, 'units', '#received_qty', '2']
        ]) {
            await manager.goto(receivingUrl);
            const input = manager.locator('#product_search_scan');
            await input.fill(code);
            await input.press('Enter');
            await expect(manager.locator('#quantity_mode')).toHaveValue(mode);
            await expect(manager.locator(quantity)).toBeFocused();
            await manager.locator(quantity).fill('2');
            await expect(manager.locator('#summary_accepted')).toHaveText(units);
        }

        const cashier = await browser.newPage();
        await signIn(cashier, 'cashier');
        const posUrl = origin + '/components/cashier/pos.php';
        await cashier.goto(posUrl);
        const posScan = cashier.locator('#sku-input');
        async function scanAtCheckout(code) {
            await posScan.fill(code);
            await posScan.press('Enter');
        }
        const cart = cashier.locator('#cart-body');
        await scanAtCheckout(unitCode);
        const piece = cart.getByRole('row', { name: /Carton and Sales Pack Fixture Piece/ });
        await expect(piece).toContainText('₱5.00');
        await scanAtCheckout(packCode);
        const pack = cart.getByRole('row', { name: /Carton and Sales Pack Fixture Pack of 6/ });
        await expect(pack).toContainText('₱25.00');
        await scanAtCheckout(cartonCode);
        await expect(cashier.getByText(/Case barcodes are for receiving only/)).toBeVisible();
        await expect(cart.getByRole('row')).toHaveCount(2);
        console.log('Add Product packaging: routing, generation, saved checkout and receiving conversions passed');
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
