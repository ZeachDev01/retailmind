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
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });

    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://product.test/', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<html><head><style>${css}</style></head><body>${markup}</body></html>` }));
            await page.goto('http://product.test/');
            await page.addScriptTag({ path: path.join(frontend, 'assets/js/ui.js') });

            await page.evaluate(() => {
                window.existingProductNames = new Set();
                window.existingBarcodes = new Set();
                window.formatMoney = value => Number(value).toFixed(2);
                const form = document.getElementById('add-product-form');
                form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="csrf_token" value="test-token-246">');
                document.getElementById('product-category').innerHTML = '<option value="1">Biscuits</option>';
            });
            await page.addScriptTag({ content: wizard });
            await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));

            // Panel 1: Name and Category
            await page.locator('#product-name').fill('Chocolate Biscuits 12s');
            await page.locator('#product-category').selectOption('1');
            await page.locator('#wizard-next').click();

            // Panel 2: Barcodes & Identifiers (optional fields, can proceed directly)
            assert.ok(await page.locator('[data-panel="2"]').evaluate(p => p.classList.contains('active')), 'Step 2 active');
            await page.locator('#wizard-next').click();

            // Panel 3: Pricing & Units & Sales Pack configuration
            assert.ok(await page.locator('[data-panel="3"]').evaluate(p => p.classList.contains('active')), 'Step 3 active');
            await page.locator('#cost-price').fill('8.00');
            await page.locator('#selling-price').fill('10.00');

            assert.ok(await page.locator('#create-pack-size').isVisible(), 'Pack size input is visible');
            assert.ok(await page.locator('#create-pack-barcode').isVisible(), 'Pack barcode input is visible');
            assert.ok(await page.locator('#create-pack-price').isVisible(), 'Pack price input is visible');

            // Test incomplete pack configuration validation: pack size without barcode/price
            await page.locator('#create-pack-size').fill('12');
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="3"]').evaluate(p => p.classList.contains('active')), 'Incomplete pack configuration blocks step 3 advance');

            // Fill barcode, but invalid price (pack price >= pack_size * selling_price: 12 * 10 = 120)
            await page.locator('#create-pack-barcode').fill('BISCUIT-12PK');
            await page.locator('#create-pack-price').fill('125.00');
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="3"]').evaluate(p => p.classList.contains('active')), 'Excessive pack price blocks step 3 advance');

            // Valid pack price: 100.00 (< 120.00)
            await page.locator('#create-pack-price').fill('100.00');
            await page.locator('#wizard-next').click();

            // Panel 4: Stock Mode & Starting Stock
            assert.ok(await page.locator('[data-panel="4"]').evaluate(p => p.classList.contains('active')), 'Step 4 is now active');

            // Verify sales pack option exists and switch mode
            const packsModeRadio = page.locator('input[name="starting_stock_mode"][value="packs"]');
            assert.ok(await packsModeRadio.isVisible(), 'Sales pack starting stock option is visible');

            // Prior pieces quantity was 0, ensure switching to packs does NOT infer packs
            await packsModeRadio.check();
            assert.ok(['0', ''].includes(await page.locator('#starting-packs-count').inputValue()), 'Packs count starts at 0 or empty (never inferred)');
            assert.ok(['0', ''].includes(await page.locator('#starting-pack-loose-count').inputValue()), 'Loose count starts at 0 or empty');

            // Enter 5 packs of 12 plus 3 loose pieces
            await page.locator('#starting-packs-count').fill('5');
            await page.locator('#starting-pack-loose-count').fill('3');

            // Assert calculated total is 63 Base Units
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '63', '5 packs of 12 + 3 loose = 63 Base Units');
            const previewText = await page.locator('#stock-sales-pack-calc-preview').textContent();
            assert.ok(previewText.includes('63'), 'Calc preview reflects 63 Base Units');

            // Advance to Panel 5: Review
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="5"]').evaluate(p => p.classList.contains('active')), 'Step 5 review is active');

            // Intercept form submit and assert payload
            await page.evaluate(() => {
                window.addEventListener('submit', event => {
                    window.submittedProduct = Object.fromEntries(new FormData(event.target));
                    event.preventDefault();
                });
            });
            await page.locator('#wizard-save').click();

            const submitted = await page.evaluate(() => window.submittedProduct);
            assert.equal(submitted.product_name, 'Chocolate Biscuits 12s');
            assert.equal(submitted.pack_size, '12');
            assert.equal(submitted.pack_barcode, 'BISCUIT-12PK');
            assert.equal(submitted.pack_price, '100.00');
            assert.equal(submitted.starting_stock_mode, 'packs');
            assert.equal(submitted.starting_packs, '5');
            assert.equal(submitted.starting_loose, '3');
            assert.equal(submitted.initial_stock_quantity, '63');

            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Sales pack configuration wizard, non-inferred starting stock, and submission (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
