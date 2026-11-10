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
            assert.equal(await page.getByRole('combobox', {name: 'Parent Product Family', exact: true}).inputValue(), 'Standalone product', 'Standalone is visible as the default parent family');
            await page.locator('#product-category').selectOption('1', { force: true });
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
            assert.equal((await page.locator('#pack-price-calc-preview').textContent()).trim(), '', 'No pack comparison before a pack size is entered');

            // Pack purchase cost divides into the per-item cost; markup fills the selling price and suggests a pack price.
            assert.ok(await page.locator('#create-pack-cost').isVisible(), 'Pack cost input is visible');
            await page.locator('#create-pack-size').fill('12');
            await page.locator('#create-pack-cost').fill('120.00');
            assert.equal(await page.locator('#cost-price').inputValue(), '10.00', 'Pack cost divides into the per-item cost');
            await page.locator('#create-pack-markup').fill('25');
            const costCalc = await page.locator('#pack-price-suggested').textContent();
            assert.ok(costCalc.includes('₱10.00'), 'Suggested maths shows the per-item cost');
            assert.ok(costCalc.includes('× 12'), 'Suggested maths shows the items per pack');
            assert.ok(costCalc.includes('₱150.00'), 'Suggested pack price adds the markup to the pack cost');
            assert.equal(await page.locator('#selling-price').inputValue(), '12.50', 'Markup fills the per-item selling price');
            assert.equal(await page.locator('#create-pack-price').inputValue(), '', 'Markup never fills the Sales Pack Price');
            await page.locator('#cost-price').fill('9.50');
            await page.locator('#create-pack-markup').fill('20');
            assert.equal(await page.locator('#cost-price').inputValue(), '9.50', 'Markup never overwrites a manual per-item cost');
            await page.locator('#cost-price').fill('8.00');
            await page.locator('#selling-price').fill('10.00');
            await page.locator('#create-pack-cost').fill('');
            await page.locator('#create-pack-markup').fill('');

            // Test incomplete pack configuration validation: pack size without barcode/price
            await page.locator('#create-pack-size').fill('12');
            assert.ok((await page.locator('#pack-price-calc-preview').textContent()).includes('12 × ₱10.00 = ₱120.00'), 'Comparison shows the individual-items total');
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="3"]').evaluate(p => p.classList.contains('active')), 'Incomplete pack configuration blocks step 3 advance');

            // Fill barcode, but invalid price (pack price >= pack_size * selling_price: 12 * 10 = 120)
            await page.locator('#create-pack-barcode').fill('BISCUIT-12PK');
            await page.locator('#create-pack-price').fill('125.00');
            assert.ok((await page.locator('#pack-price-calc-preview').textContent()).includes('must be below ₱120.00'), 'Comparison warns when the pack price kills the discount');
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="3"]').evaluate(p => p.classList.contains('active')), 'Excessive pack price blocks step 3 advance');

            // Valid pack price: 100.00 (< 120.00)
            await page.locator('#create-pack-price').fill('100.00');
            const packCalc = await page.locator('#pack-price-calc-preview').textContent();
            assert.ok(packCalc.includes('₱8.33'), 'Comparison shows the pack price per piece');
            assert.ok(packCalc.includes('₱20.00'), 'Comparison shows the pack saving');
            assert.ok(packCalc.includes('16.7%'), 'Comparison shows the pack discount percentage');
            await page.locator('#selling-price').fill('12.00');
            assert.ok((await page.locator('#pack-price-calc-preview').textContent()).includes('₱144.00'), 'Comparison follows a change in the selling price');
            await page.locator('#selling-price').fill('10.00');
            await page.locator('#wizard-next').click();

            // Panel 4: starting stock follows Sales Pack configuration automatically.
            assert.ok(await page.locator('[data-panel="4"]').evaluate(p => p.classList.contains('active')), 'Step 4 is now active');

            assert.equal(await page.locator('[name="starting_stock_mode"]').count(), 0, 'No stock-mode selection is needed');
            assert.ok(await page.getByLabel('Sales Packs', { exact: true }).isVisible(), 'Sales Pack count is shown automatically');
            assert.equal(await page.getByLabel('Starting pieces', { exact: true }).isVisible(), false, 'Equivalent total is not editable');
            assert.ok(['0', ''].includes(await page.locator('#starting-packs-count').inputValue()), 'Packs count starts at 0 or empty (never inferred)');
            assert.ok(['0', ''].includes(await page.locator('#starting-pack-loose-count').inputValue()), 'Loose count starts at 0 or empty');

            // Enter 5 packs of 12 plus 3 loose pieces
            await page.locator('#starting-packs-count').fill('5');
            await page.locator('#starting-pack-loose-count').fill('3');

            // Assert calculated total is 63 Base Units
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '63', '5 packs of 12 + 3 loose = 63 Base Units');
            const previewText = await page.locator('#stock-sales-pack-calc-preview').textContent();
            assert.ok(previewText.includes('63'), 'Calc preview reflects 63 Base Units');
            assert.ok(previewText.includes('1 Sales Pack = 12'), 'Preview explains the Sales Pack size');
            await page.getByLabel('Items per Receiving Package', { exact: true }).fill('144');
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '63', 'Supplier box size stays separate');

            // Closing and reopening preserves explicit sealed and loose counts.
            await page.locator('#add-product-modal [data-close-modal]').first().click();
            await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
            assert.equal(await page.locator('#starting-packs-count').inputValue(), '5');
            assert.equal(await page.locator('#starting-pack-loose-count').inputValue(), '3');
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '63');

            // Invalid visible counts and overflow must block review.
            for (const invalid of ['-1', '1.5', '2147483647']) {
                await page.locator('#starting-packs-count').fill(invalid);
                await page.locator('#wizard-next').click();
                assert.ok(await page.locator('[data-panel="4"]').evaluate(p => p.classList.contains('active')), `${invalid} pack count blocks review`);
            }
            await page.locator('#starting-packs-count').fill('5');
            await page.locator('#starting-pack-loose-count').fill('1.5');
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="4"]').evaluate(p => p.classList.contains('active')), 'Fractional loose count blocks review');
            await page.locator('#starting-pack-loose-count').fill('3');

            assert.equal(await page.locator('#stock-sales-packs-wrapper').evaluate(el => el.closest('.form-group').classList.contains('invalid')), false, 'Correcting counts clears the stock error');
            await page.screenshot({ path: path.join(process.env.TEMP || '.', `retailmind-starting-stock-${width}.png`), fullPage: true });
            assert.ok(await page.locator('#add-product-modal .rm-modal').evaluate(el => el.scrollWidth <= el.clientWidth), 'Modal has no horizontal overflow');

            // Removing the pack configuration keeps the total as pieces; enabling it never invents sealed stock.
            await page.evaluate(() => setWizardStep(3));
            await page.locator('#create-pack-size').fill('');
            await page.locator('#create-pack-barcode').fill('');
            await page.locator('#create-pack-price').fill('');
            await page.locator('#wizard-next').click();
            assert.equal(await page.getByLabel('Starting pieces', { exact: true }).inputValue(), '63');
            await page.evaluate(() => setWizardStep(3));
            await page.locator('#create-pack-size').fill('12');
            await page.locator('#create-pack-barcode').fill('BISCUIT-12PK');
            await page.locator('#create-pack-price').fill('100.00');
            await page.locator('#wizard-next').click();
            assert.equal(await page.locator('#starting-packs-count').inputValue(), '0');
            assert.equal(await page.locator('#starting-pack-loose-count').inputValue(), '63');
            await page.locator('#starting-packs-count').fill('5');
            await page.locator('#starting-pack-loose-count').fill('3');

            // Advance to Panel 5: Review
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="5"]').evaluate(p => p.classList.contains('active')), 'Step 5 review is active');
            assert.equal(await page.locator('[name="print_after_create"]').isChecked(), false, 'Barcode labels stay closed by default');

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
            assert.equal(submitted.starting_stock_mode, undefined);
            assert.equal(submitted.starting_packs, '5');
            assert.equal(submitted.starting_loose, '3');
            assert.equal(submitted.initial_stock_quantity, '63');
            assert.equal(submitted.print_after_create, undefined, 'Unchecked label printing is not submitted');

            await page.evaluate(() => {
                document.getElementById('add-product-form').reset();
                syncStartingStockFields();
                updatePackPriceCalc();
            });
            await page.evaluate(() => setWizardStep(4));
            assert.equal(await page.getByLabel('Starting pieces', { exact: true }).inputValue(), '0', 'Reset returns to piece-only entry');

            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Sales pack configuration wizard, non-inferred starting stock, and submission (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
