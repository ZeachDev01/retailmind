const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    const start = source.indexOf('<div class="rm-modal-overlay" id="add-product-modal"');
    const end = source.indexOf('<div class="rm-modal-overlay" id="add-category-modal"', start);
    const markup = source.slice(start, end)
        .replace(/<\?php if \(\$productHandoff\): \?>[\s\S]*?<\?php endif; \?>/g, '')
        .replace(/value="<\?= \$productHandoff \? '' : '1' \?>"/, 'value="1"')
        .replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
    const wizard = source.slice(source.indexOf('// Product creation and validation'), source.indexOf('// Camera scan and internal barcode generation'))
        .replace(/<\?= json_encode\(app_url\('components\/report\/stock_receiving\.php'\)\) \?>/, '"/stock_receiving.php"');
    const photoPreview = source.slice(source.indexOf('// Product image preview'), source.lastIndexOf('</script>'));
    const css = fs.readFileSync(path.join(frontend, 'assets/css/style.css'), 'utf8') + source.match(/<style>([\s\S]*?)<\/style>/)[1];
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://localhost/products.php', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<html><head><style>${css}</style></head><body>${markup}</body></html>` }));
            await page.goto('http://localhost/products.php');
            assert.equal(await page.locator('#cost-price').evaluate(field => field.required), true, 'New products require explicit cost, including zero');
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
            await page.addScriptTag({ path: path.join(frontend, 'assets/js/inventory-drafts.js') });
            await page.addScriptTag({ content: wizard });
            await page.addScriptTag({ content: photoPreview });
            await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
            const opening = page.getByText('Opening stock', { exact: true });
            await opening.waitFor({ state: 'visible', timeout: 2000 });
            assert.equal(await page.getByText('0 pieces', { exact: true }).isVisible(), true);
            const printLabels = page.getByLabel('Open printable barcode labels after saving');
            const labelQuantity = page.getByLabel('Number of labels');
            assert.equal(await printLabels.isVisible(), true, 'Label printing is available with the barcode even with Opening stock collapsed');
            assert.equal(await labelQuantity.isVisible(), false);
            await printLabels.check();
            await labelQuantity.fill('2');
            await printLabels.uncheck();
            assert.equal(await labelQuantity.isVisible(), false);
            await printLabels.check();
            assert.equal(await labelQuantity.inputValue(), '2');
            await printLabels.uncheck();
            assert.equal(await labelQuantity.evaluate(field => new FormData(field.form).has(field.name)), false);
            await page.getByText('Brand, photo and variants', { exact: true }).click();
            const photo = page.getByLabel('Product photo', {exact:true});
            await photo.setInputFiles({name:'product.gif', mimeType:'image/gif', buffer:Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')});
            const preview = page.getByRole('img', {name:'Product photo preview'});
            await preview.waitFor({state:'visible'});
            await page.getByRole('button', {name:'Remove photo'}).click();
            assert.equal(await preview.isVisible(), false);
            assert.equal(await photo.inputValue(), '');
            assert.equal(await page.getByLabel('Variant Label').isVisible(), false);
            const family = page.locator('#parent-product-id');
            await family.evaluate(field => field.add(new Option('C2 Green Tea', '1')));
            await family.selectOption('1', { force: true });
            await page.getByLabel('Variant Label').fill('350 mL');
            await family.selectOption('', { force: true });
            assert.equal(await page.getByLabel('Variant Label').isVisible(), false);
            await family.selectOption('1', { force: true });
            assert.equal(await page.getByLabel('Variant Label').inputValue(), '350 mL');
            await family.selectOption('', { force: true });
            await page.getByText('Brand, photo and variants', { exact: true }).click();
            assert.equal(await page.locator('#add-product-modal .rm-modal-body').evaluate(body => body.scrollWidth <= body.clientWidth), true);
            assert.equal(await page.locator('[name="csrf_token"]').inputValue(), 'current-session-token', 'Restoring old draft must preserve current security token');
            assert.equal(await page.locator('[name="action"]').inputValue(), 'create', 'Draft must preserve create action');
            assert.equal(await page.locator('#product-name').inputValue(), '', 'Unowned legacy drafts cannot restore product values');
            await page.evaluate(() => {
                RetailMindInventoryDrafts.start({key:btoa(String.fromCharCode(...new Uint8Array(32))), user:'1', confirmed:false});
                document.dispatchEvent(new Event('DOMContentLoaded'));
            });
            await page.waitForFunction(() => document.querySelector('[name="operation_token"]'));
            await page.locator('#product-name').fill('Draft product');
            await page.locator('#cost-price').evaluate(field => { field.value = '30'; });
            await page.locator('#selling-price').evaluate(field => { field.value = '45'; });
            await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
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
            const saved = await page.evaluate(async () => {
                await RetailMindInventoryDrafts.keep(document.getElementById('add-product-form'));
                await RetailMindInventoryDrafts.flush();
                const storageKey = Object.keys(localStorage).find(key => key.endsWith(':add-product-form'));
                const stored = JSON.parse(localStorage.getItem(storageKey));
                const decode = value => Uint8Array.from(atob(value), char => char.charCodeAt(0));
                const key = await crypto.subtle.importKey('raw', new Uint8Array(32), 'AES-GCM', false, ['decrypt']);
                const plain = await crypto.subtle.decrypt({name:'AES-GCM',iv:decode(stored.iv)}, key, decode(stored.ciphertext));
                return JSON.parse(new TextDecoder().decode(plain));
            });
            assert.equal(saved.values.some(item => item.name === 'csrf_token'), false, 'Private draft excludes security token');
            assert.equal(saved.values.some(item => item.name === 'base_unit' && item.value === 'bottle'), true, 'Private draft captures unit settings');
            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; });
            });
            assert.equal(await page.getByLabel('Starting Stock Expiration (optional)', { exact: true }).isVisible(), false, 'Zero starting stock hides its optional expiration');
            const eligibility = page.getByLabel('Supplier Pullout Eligibility', {exact: true});
            assert.equal(await eligibility.isChecked(), false, 'Supplier Pullout defaults off');
            assert.equal(await eligibility.isVisible(), true, 'Eligibility stays beside expiry settings');
            assert.equal(await eligibility.evaluate(el => el.form.id), 'add-product-form');
            await eligibility.check();
            assert.equal(await eligibility.evaluate(el => new FormData(el.form).get('supplier_pullout_eligible')), '1');
            await eligibility.uncheck();
            assert.equal(await eligibility.evaluate(el => new FormData(el.form).has('supplier_pullout_eligible')), false);

            await page.evaluate(() => {
                RetailMindUI.openOverlay(document.getElementById('add-product-modal'));
                document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; });
            });
            assert.equal(await page.locator('[name="starting_stock_mode"]').count(), 0, 'Stock entry has no mode buttons');
            assert.equal(await page.getByLabel('Sales Packs', { exact: true }).isVisible(), false, 'Piece-only product hides pack counts');
            await page.getByLabel('Starting pieces', { exact: true }).fill('74');
            await page.getByLabel('Items per Receiving Package', { exact: true }).fill('24');
            await page.getByText('Receiving package', { exact: true }).click();
            assert.equal(await page.getByText('Carton of 24', { exact: true }).isVisible(), true);
            await page.getByText('Receiving package', { exact: true }).click();
            assert.equal(await page.getByLabel('Items per Receiving Package', { exact: true }).inputValue(), '24');
            assert.equal(await page.locator('#initial-stock-quantity').inputValue(), '74', 'Supplier box size does not change starting stock');
            assert.equal(await page.getByLabel('Starting Stock Expiration (optional)', { exact: true }).isVisible(), true, 'Positive stock exposes opening batch expiration');
            await page.getByLabel('Starting Stock Expiration (optional)', { exact: true }).fill('2027-02-28');
            assert.equal(await page.getByText('74 pieces', { exact: true }).isVisible(), true);
            if (process.env.IMPECCABLE_REVIEW_DIR) {
                fs.mkdirSync(process.env.IMPECCABLE_REVIEW_DIR, {recursive:true});
                await page.locator('#add-product-modal .rm-modal-body').evaluate(body => { body.scrollTop = body.scrollHeight; });
                await page.screenshot({path:path.join(process.env.IMPECCABLE_REVIEW_DIR, `product-options-${width}.png`)});
            }
            const commonUnits = await page.evaluate(() => Object.fromEntries(new FormData(document.getElementById('add-product-form'))));
            assert.equal(commonUnits.base_unit, 'bottle');
            assert.equal(commonUnits.receiving_unit, 'carton');
            await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
            await page.getByLabel('Base Unit', { exact: true }).selectOption({ label: 'Other…' });
            await page.getByLabel('Receiving Unit', { exact: true }).selectOption({ label: 'Other…' });
            await page.locator('#product-create-save').click();
            await page.waitForFunction(() => document.getElementById('product-base-unit-custom').getAttribute('aria-invalid') === 'true');
            assert.equal(await page.locator('#product-base-unit-custom').getAttribute('aria-invalid'), 'true', 'Empty custom unit prevents saving');
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
                document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; });
                window.addEventListener('submit', event => {
                    window.submittedProduct = Object.fromEntries(new FormData(event.target));
                    event.preventDefault();
                });
            });
            await page.locator('#product-create-save').click();
            await page.waitForFunction(() => window.submittedProduct);
            const submitted = await page.evaluate(() => window.submittedProduct);
            assert.equal(submitted.csrf_token, 'current-session-token');
            assert.equal(submitted.action, 'create');
            assert.equal(submitted.product_name, 'Draft product');
            assert.equal(submitted.base_unit, 'bar', 'Custom base unit is submitted unchanged');
            assert.equal(submitted.receiving_unit, 'basket', 'Custom receiving unit is submitted unchanged');
            assert.equal(submitted.initial_stock_quantity, '74', 'Submitted form payload contains calculated 74 pieces');
            assert.equal(submitted.starting_packs, undefined, 'Piece-only product omits inactive pack fields');
            assert.equal(submitted.expiration_date, '2027-02-28', 'Starting expiration is submitted for the Opening Batch');
            await page.evaluate(() => document.getElementById('add-product-form').reset());
            assert.equal(await page.locator('[name="base_unit"]').inputValue(), 'piece', 'Reset restores default base unit');
            assert.equal(await page.locator('[name="receiving_unit"]').inputValue(), 'package', 'Reset restores default receiving unit');
            await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
            assert.equal(await page.getByLabel('Starting Stock Expiration (optional)', { exact: true }).isVisible(), false, 'Reset to zero stock hides opening expiration');
            assert.equal(await page.getByLabel('Starting Stock Expiration (optional)', { exact: true }).inputValue(), '', 'Reset clears the old opening date');
            await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
            assert.equal(await page.getByLabel('Base Unit', { exact: true }).inputValue(), 'piece');
            assert.equal(await page.getByLabel('Receiving Unit', { exact: true }).inputValue(), 'package');
            assert.equal(await page.getByLabel('Custom base unit', { exact: true }).isVisible(), false);
            assert.equal(await page.getByLabel('Custom receiving unit', { exact: true }).isVisible(), false);
            const byPiece = page.getByRole('button', {name:'By piece', exact:true});
            const fromPack = page.getByRole('button', {name:'From purchase pack', exact:true});
            await fromPack.click();
            await page.locator('#create-pack-cost').fill('120');
            await page.locator('#create-purchase-pack-size').fill('12');
            await byPiece.click();
            assert.equal(await page.locator('#cost-price').inputValue(), '10.00');
            await fromPack.click();
            await page.locator('#create-pack-cost').fill('144');
            await byPiece.click();
            assert.equal(await page.locator('#cost-price').inputValue(), '12.00', 'Without manual entry, the latest calculated cost carries over');
            // Resume a real encrypted draft, including inactive fields and explicit stock forms.
            await page.evaluate(() => { delete document.getElementById('add-product-form').dataset.inventorySubmissionKind; });
            await page.locator('#cost-price').fill('12');
            await page.locator('[data-cost-mode="pack"]').click();
            await page.locator('#create-pack-cost').fill('120');
            await page.locator('#create-purchase-pack-size').fill('12');
            await page.locator('#selling-price').fill('15');
            await page.locator('#create-sell-packs').check();
            await page.locator('#create-pack-size').fill('6');
            await page.locator('#create-pack-price').fill('85');
            await page.locator('#create-pack-barcode').fill('PACK-DRAFT-6');
            await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
            await page.locator('#starting-packs-count').fill('5');
            await page.locator('#starting-pack-loose-count').fill('3');
            for (const enabled of [true, false]) {
                if (!enabled) {
                    await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
                    await page.locator('#create-sell-packs').uncheck();
                    await page.locator('[data-cost-mode="piece"]').click();
                    await page.locator('#cost-price').fill('11');
                    await page.evaluate(() => document.querySelectorAll('#add-product-form details').forEach(details => { details.open = true; }));
                    await page.locator('#initial-stock-quantity').fill('80');
                }
                await page.evaluate(async () => {
                    await RetailMindInventoryDrafts.keep(document.getElementById('add-product-form'));
                    await RetailMindInventoryDrafts.flush();
                });
                await page.reload();
                await page.addScriptTag({ path:path.join(frontend,'assets/js/ui.js') });
                await page.evaluate(() => {
                    window.existingProductNames = new Set(); window.existingBarcodes = new Set();
                    window.formatMoney = value => Number(value).toFixed(2);
                    document.getElementById('product-category').innerHTML = '<option value="1">Groceries</option>';
                });
                await page.addScriptTag({path:path.join(frontend,'assets/js/inventory-drafts.js')});
                await page.addScriptTag({content:wizard});
                await page.evaluate(() => {
                    RetailMindInventoryDrafts.start({key:btoa(String.fromCharCode(...new Uint8Array(32))),user:'1',confirmed:false});
                    document.dispatchEvent(new Event('DOMContentLoaded'));
                });
                await page.getByRole('button',{name:'Resume',exact:true}).click();
                await page.waitForFunction(() => document.getElementById('add-product-modal').classList.contains('open'));
                assert.equal(await page.locator('#create-cost-mode').inputValue(), enabled ? 'pack' : 'piece');
                assert.equal(await page.locator('#cost-price').inputValue(), enabled ? '10.00' : '11');
                assert.equal(await page.locator('#selling-price').inputValue(), '15');
                assert.equal(await page.locator('#create-sell-packs').isChecked(), enabled);
                assert.equal(await page.locator('#create-pack-size').inputValue(), '6');
                assert.equal(await page.locator('#create-purchase-pack-size').inputValue(), '12');
                assert.equal(await page.locator('#initial-stock-quantity').inputValue(), enabled ? '33' : '80', 'Inactive restored pack counts cannot replace piece stock');
                if (enabled) {
                    assert.equal(await page.locator('#starting-packs-count').inputValue(), '5');
                    assert.equal(await page.locator('#starting-pack-loose-count').inputValue(), '3');
                    await page.getByRole('button', {name:'By piece', exact:true}).click();
                    assert.equal(await page.getByLabel('Cost Price per Piece (₱) *', {exact:true}).inputValue(), '12', 'Pack-mode draft remembers the separately entered manual cost');
                    await page.getByRole('button', {name:'From purchase pack', exact:true}).click();
                }
            }
            await page.evaluate(async () => {
                const draft = {id:'add-product-form', formId:'add-product-form', context:{step:3}, values:[{name:'pack_size',value:'6'},{name:'cost_price',value:'11'}]};
                const key = await crypto.subtle.importKey('raw', new Uint8Array(32), 'AES-GCM', false, ['encrypt']);
                const iv = crypto.getRandomValues(new Uint8Array(12));
                const ciphertext = await crypto.subtle.encrypt({name:'AES-GCM',iv}, key, new TextEncoder().encode(JSON.stringify(draft)));
                const encode = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));
                localStorage.setItem('retailmind:inventory:v1:1:/products.php:add-product-form', JSON.stringify({iv:encode(iv),ciphertext:encode(ciphertext)}));
            });
            await page.reload();
            await page.addScriptTag({path:path.join(frontend,'assets/js/ui.js')});
            await page.evaluate(() => {
                window.existingProductNames = new Set(); window.existingBarcodes = new Set();
                window.formatMoney = value => Number(value).toFixed(2);
                document.getElementById('product-category').innerHTML = '<option value="1">Groceries</option>';
            });
            await page.addScriptTag({path:path.join(frontend,'assets/js/inventory-drafts.js')});
            await page.addScriptTag({content:wizard});
            await page.evaluate(() => {
                RetailMindInventoryDrafts.start({key:btoa(String.fromCharCode(...new Uint8Array(32))),user:'1',confirmed:false});
                document.dispatchEvent(new Event('DOMContentLoaded'));
            });
            await page.getByRole('button',{name:'Resume',exact:true}).click();
            await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Draft restored'));
            assert.equal(await page.locator('#create-sell-packs').isChecked(), true, 'Older private pack drafts infer sealed selling');
            assert.equal(await page.locator('#create-cost-mode').inputValue(), 'piece', 'Older drafts keep their explicitly entered unit cost');
            assert.equal(await page.locator('#cost-price').inputValue(), '11');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('PASS: Add Product unit suggestions, custom units, drafts, reset and submission (desktop/mobile)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
