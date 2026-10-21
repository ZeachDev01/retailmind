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
                form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="csrf_token" value="current-session-token">');
                document.getElementById('product-category').innerHTML = '<option value="1">Groceries</option>';
                localStorage.setItem('retail_mind_add_product_state', JSON.stringify({
                    csrf_token: 'expired-session-token', action: 'wrong-action', product_name: 'Draft product',
                    category_id: '1', cost_price: '30', selling_price: '45', initial_stock_quantity: '0'
                }));
                localStorage.setItem('retail_mind_add_product_step', '5');
            });
            await page.addScriptTag({ content: wizard });
            await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
            assert.equal(await page.locator('[name="csrf_token"]').inputValue(), 'current-session-token', 'Restoring old draft must preserve current security token');
            assert.equal(await page.locator('[name="action"]').inputValue(), 'create', 'Draft must preserve create action');
            assert.equal(await page.locator('#product-name').inputValue(), 'Draft product', 'Draft product details are restored');
            await page.evaluate(() => setWizardStep(3));
            for (const [label, choices, selected] of [
                ['Base Unit', ['piece', 'bottle', 'can', 'sachet', 'stick', 'pack', 'bag', 'box', 'jar', 'tube', 'roll', 'pair'], 'bottle'],
                ['Receiving Unit', ['package', 'case', 'box', 'carton', 'pack', 'bundle', 'bag', 'sack', 'tray', 'crate', 'ream', 'pallet'], 'carton']
            ]) {
                const field = page.getByLabel(label, { exact: true });
                assert.equal(await field.evaluate(input => input.tagName), 'SELECT', `${label} uses the same native dropdown as Status`);
                const suggestions = await field.locator('option').evaluateAll(options => options.map(option => option.value));
                for (const choice of choices) assert.ok(suggestions.includes(choice), `${label} offers ${choice}`);
                await field.selectOption(selected);
            }
            await page.locator('#add-product-modal [data-close-modal]').first().click();
            const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('retail_mind_add_product_state')));
            assert.equal('csrf_token' in saved, false, 'Draft must exclude security token');
            assert.equal('product_image' in saved, false, 'Draft must exclude file input');
            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                setWizardStep(4);
            });
            assert.equal(await page.getByLabel('Starting Stock Expiration', { exact: true }).isVisible(), false, 'Zero starting stock hides its optional expiration');
            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                setWizardStep(4);
            });
            assert.equal(await page.locator('[name="starting_stock_mode"]').count(), 0, 'Stock entry has no mode buttons');
            assert.equal(await page.getByLabel('Sales Packs', { exact: true }).isVisible(), false, 'Piece-only product hides pack counts');
            await page.getByLabel('Starting pieces', { exact: true }).fill('74');
            await page.getByLabel('Units per supplier box', { exact: true }).fill('24');
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '74', 'Supplier box size does not change starting stock');
            assert.equal(await page.getByLabel('Starting Stock Expiration', { exact: true }).isVisible(), true, 'Positive stock exposes opening batch expiration');
            await page.getByLabel('Starting Stock Expiration', { exact: true }).fill('2027-02-28');
            const commonUnits = await page.evaluate(() => Object.fromEntries(new FormData(document.getElementById('add-product-form'))));
            assert.equal(commonUnits.base_unit, 'bottle');
            assert.equal(commonUnits.receiving_unit, 'carton');
            await page.evaluate(() => setWizardStep(3));
            await page.getByLabel('Base Unit', { exact: true }).selectOption({ label: 'Other…' });
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption({ label: 'Other…' });
            await page.locator('#wizard-next').click();
            assert.ok(await page.locator('[data-panel="3"]').evaluate(panel => panel.classList.contains('active')), 'Empty custom units prevent advancing');
            await page.getByLabel('Custom base unit', { exact: true }).fill('bar');
            await page.getByLabel('Custom receiving unit', { exact: true }).fill('basket');
            await page.locator('#add-product-modal [data-close-modal]').first().click();
            await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
            assert.equal(await page.getByLabel('Custom base unit', { exact: true }).inputValue(), 'bar', 'Custom base unit survives draft restoration');
            assert.equal(await page.getByLabel('Custom receiving unit', { exact: true }).inputValue(), 'basket', 'Custom receiving unit survives draft restoration');
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption('case');
            assert.equal(await page.getByLabel('Custom receiving unit', { exact: true }).isVisible(), false, 'Choosing a preset hides the custom field');
            const switchedUnits = await page.evaluate(() => new FormData(document.getElementById('add-product-form')).get('receiving_unit'));
            assert.equal(switchedUnits, 'case', 'Choosing a preset replaces the previous custom value');
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption({ label: 'Other…' });
            await page.getByLabel('Custom receiving unit', { exact: true }).fill('basket');
            await page.evaluate(() => {
                setWizardStep(5);
                window.addEventListener('submit', event => {
                    window.submittedProduct = Object.fromEntries(new FormData(event.target));
                    event.preventDefault();
                });
            });
            await page.locator('#wizard-save').click();
            const submitted = await page.evaluate(() => window.submittedProduct);
            assert.equal(submitted.csrf_token, 'current-session-token');
            assert.equal(submitted.action, 'create');
            assert.equal(submitted.product_name, 'Draft product');
            assert.equal(submitted.base_unit, 'bar', 'Custom base unit is submitted unchanged');
            assert.equal(submitted.receiving_unit, 'basket', 'Custom receiving unit is submitted unchanged');
            assert.equal(submitted.initial_stock_quantity, '74', 'Submitted form payload contains calculated 74 pieces');
            assert.equal(submitted.starting_packs, undefined, 'Piece-only product omits inactive pack fields');
            assert.equal(submitted.expiration_date, '2027-02-28', 'Starting expiration is submitted for the Opening Batch');
            await page.evaluate(() => clearFormState());
            assert.equal(await page.locator('[name="base_unit"]').inputValue(), 'piece', 'Reset restores default base unit');
            assert.equal(await page.locator('[name="receiving_unit"]').inputValue(), 'package', 'Reset restores default receiving unit');
            await page.evaluate(() => setWizardStep(4));
            assert.equal(await page.getByLabel('Starting Stock Expiration', { exact: true }).isVisible(), false, 'Reset to zero stock hides opening expiration');
            assert.equal(await page.getByLabel('Starting Stock Expiration', { exact: true }).inputValue(), '', 'Reset clears the old opening date');
            await page.evaluate(() => setWizardStep(3));
            assert.equal(await page.getByLabel('Base Unit', { exact: true }).inputValue(), 'piece');
            assert.equal(await page.getByLabel('Receiving Unit', { exact: true }).inputValue(), 'package');
            assert.equal(await page.getByLabel('Custom base unit', { exact: true }).isVisible(), false);
            assert.equal(await page.getByLabel('Custom receiving unit', { exact: true }).isVisible(), false);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Add Product unit suggestions, custom units, drafts, reset and submission (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
