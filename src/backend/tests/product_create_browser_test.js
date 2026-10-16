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
            await page.route('http://product.test/', route => route.fulfill({ contentType: 'text/html', body: `<html><head><style>${css}</style></head><body>${markup}</body></html>` }));
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
            await page.locator('#add-product-modal [data-close-modal]').first().click();
            const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('retail_mind_add_product_state')));
            assert.equal('csrf_token' in saved, false, 'Draft must exclude security token');
            assert.equal('product_image' in saved, false, 'Draft must exclude file input');
            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                setWizardStep(4);
                const modeRadio = document.querySelector('input[name="starting_stock_mode"][value="packages"]');
                modeRadio.checked = true;
                modeRadio.dispatchEvent(new Event('change'));
                const units = document.getElementById('units-per-package');
                units.value = '24';
                units.dispatchEvent(new Event('input'));
                const pkgs = document.getElementById('starting-packages-count');
                pkgs.value = '3';
                pkgs.dispatchEvent(new Event('input'));
                const loose = document.getElementById('starting-loose-count');
                loose.value = '2';
                loose.dispatchEvent(new Event('input'));
            });
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '74', '3 packages of 24 plus 2 loose must equal 74');
            assert.ok((await page.locator('#stock-package-calc-preview').textContent()).includes('74'), 'Live preview displays calculated 74 pieces');
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
            assert.equal(submitted.initial_stock_quantity, '74', 'Submitted form payload contains calculated 74 pieces');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Add Product saves restored drafts with current security token (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
