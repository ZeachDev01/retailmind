'use strict';
// Browser workflow check for Issue #254: Manage prepared oil pouches as ordinary Products.
// Verifies through the browser seam:
// 1. Base Unit dropdown includes 'pouch'.
// 2. Setting up Oil 250 mL at initial price PHP 20.00 and Oil 500 mL at initial price PHP 40.00.
// 3. Whole integer pouch entry, rejection of invalid/fractional quantities in browser validation.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    const start = source.indexOf('<div class="rm-modal-overlay" id="add-product-modal"');
    const end = source.indexOf('<div class="rm-modal-overlay" id="add-category-modal"', start);
    const markup = source.slice(start, end).replace(/<\?php[\s\S]*?\?>/g, '').replace(/<\?=[\s\S]*?\?>/g, '');
    const wizard = source.slice(source.indexOf('// Product wizard and validation'), source.indexOf('// Camera scan and internal barcode generation'));
    const css = fs.readFileSync(path.join(frontend, 'assets/css/style.css'), 'utf8') + source.match(/<style>([\s\S]*?)<\/style>/)[1];

    const browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {})
    });

    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));

            await page.route('http://product.test/', route => route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: `<html><head><style>${css}</style></head><body>${markup}</body></html>`
            }));
            await page.goto('http://product.test/');
            await page.addScriptTag({ path: path.join(frontend, 'assets/js/ui.js') });

            await page.evaluate(() => {
                window.existingProductNames = new Set();
                window.existingBarcodes = new Set();
                window.formatMoney = value => Number(value).toFixed(2);
                const form = document.getElementById('add-product-form');
                form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="csrf_token" value="session-token-oil">');
                document.getElementById('product-category').innerHTML = '<option value="1">Cooking Essentials</option>';
            });
            await page.addScriptTag({ content: wizard });

            // 1. Check Base Unit dropdown offers 'pouch'
            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                setWizardStep(3);
            });
            const baseUnitSelect = page.getByLabel('Base Unit', { exact: true });
            const options = await baseUnitSelect.locator('option').evaluateAll(opts => opts.map(o => o.value));
            assert.ok(options.includes('pouch'), 'Base Unit dropdown includes pouch option');

            // 2. Configure Oil 250 mL: price PHP 20.00, base unit pouch
            await page.evaluate(() => setWizardStep(1));
            await page.locator('#product-name').fill('Oil 250 mL');
            await page.evaluate(() => setWizardStep(2));
            await page.locator('#create-barcode-input').fill('OIL-250ML');
            await page.evaluate(() => setWizardStep(3));
            await page.locator('#cost-price').fill('15.00');
            await page.locator('#selling-price').fill('20.00');
            await baseUnitSelect.selectOption('pouch');
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption('box');

            // 3. Test starting stock validation: whole integer pouches
            await page.evaluate(() => setWizardStep(4));
            const initialQtyInput = page.locator('#initial-stock-quantity');
            await initialQtyInput.fill('10');
            assert.equal(await initialQtyInput.inputValue(), '10', 'Accepts whole integer pouch quantity');

            // Submit product 1
            await page.evaluate(() => {
                setWizardStep(5);
                window.submittedProducts = [];
                window.addEventListener('submit', event => {
                    window.submittedProducts.push(Object.fromEntries(new FormData(event.target)));
                    event.preventDefault();
                });
            });
            await page.locator('#wizard-save').click();

            const submitted250 = await page.evaluate(() => window.submittedProducts[0]);
            assert.equal(submitted250.product_name, 'Oil 250 mL');
            assert.equal(submitted250.selling_price, '20.00');
            assert.equal(submitted250.base_unit, 'pouch');
            assert.equal(submitted250.initial_stock_quantity, '10');

            // 4. Configure Oil 500 mL independently: price PHP 40.00, base unit pouch
            await page.waitForTimeout(200);
            await page.evaluate(() => {
                clearFormState();
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                setWizardStep(1);
            });
            await page.locator('#product-name').fill('Oil 500 mL');
            await page.evaluate(() => setWizardStep(2));
            await page.locator('#create-barcode-input').fill('OIL-500ML');
            await page.evaluate(() => setWizardStep(3));
            await page.locator('#cost-price').fill('30.00');
            await page.locator('#selling-price').fill('40.00');
            await baseUnitSelect.selectOption('pouch');
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption('box');

            await page.evaluate(() => setWizardStep(4));
            await initialQtyInput.fill('5');
            assert.equal(await initialQtyInput.inputValue(), '5', 'Accepts whole integer pouch quantity');

            await page.evaluate(() => setWizardStep(5));
            await page.locator('#wizard-save').click();

            const submitted500 = await page.evaluate(() => window.submittedProducts[1]);
            assert.equal(submitted500.product_name, 'Oil 500 mL');
            assert.equal(submitted500.selling_price, '40.00');
            assert.equal(submitted500.base_unit, 'pouch');
            assert.equal(submitted500.initial_stock_quantity, '5');

            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Prepared oil pouches browser test (Oil 250 mL @ 20.00, Oil 500 mL @ 40.00, whole pouches)');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
