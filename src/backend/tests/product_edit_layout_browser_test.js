const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    const tabs = source.slice(source.indexOf('function setProductDrawerTab('), source.indexOf("        document.querySelectorAll('[data-product-tab]').forEach(tab => {", source.indexOf("document.getElementById('product-drawer-footer').hidden")));
    const editor = source.slice(source.indexOf('function enterProductEditMode()'), source.indexOf('async function cancelProductEdit()'));
    const css = ['style', 'theme', 'inventory'].map(name => fs.readFileSync(path.join(frontend, `assets/css/${name}.css`), 'utf8')).join('\n') + source.match(/<style>([\s\S]*?)<\/style>/)[1];
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'product-edit-layout-'));
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [1440, 390]) {
            for (const theme of ['light', 'dark']) {
                const page = await browser.newPage({ viewport: { width, height: 900 } });
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.setContent(`<html data-theme="${theme}"><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body class="products-page"><div class="rm-drawer-overlay open" aria-hidden="false"><aside class="rm-drawer"><header class="rm-drawer-header"><h2 id="product-drawer-title"></h2><button id="product-edit-btn">Edit product</button></header><div class="rm-drawer-body" id="product-drawer-body"><section id="product-panel-info" data-product-panel="info"></section><section data-product-panel="quantity" hidden></section><section data-product-panel="actions" hidden></section></div><footer id="product-drawer-footer" hidden></footer></aside></div></body></html>`);
                await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
                await page.addScriptTag({ content: `
                    const canEditProduct = true, currentDrawerProductId = 1, csrfToken = 'test-token';
                    let isEditingProduct = false, isDrawerDirty = false;
                    const productsById = {1: {product_id:1, product_name:'All Purpose Flour 1kg', parent_product_id:2, variant_label:'1kg', brand:'Store Brand', category_id:1, unit_price:50, cost_price:30, status:'active', units_per_package:12, minimum_order_quantity:1, reorder_level:10, safety_stock:5, base_unit:'bag', receiving_unit:'case'}};
                    const categoriesData = [{category_id:1, category_name:'Pantry'}];
                    const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
                    const safe = (value, fallback) => value || fallback;
                    const safeLink = () => '';
                    const formatMoney = value => '₱' + Number(value).toFixed(2);
                    ${tabs}
                    const cancelProductEdit = () => { window.cancelled = true; };
                    const saveProductCatalogDetails = event => { event.preventDefault(); window.saved = Object.fromEntries(new FormData(event.target)); };
                    ${editor}

                ` });
                for (const tab of ['info', 'quantity', 'actions', 'info']) {
                    await page.evaluate(tab => setProductDrawerTab(tab), tab);
                    assert.equal(await page.locator('#product-edit-btn').isVisible(), tab === 'info', 'Edit product is available only on Info');
                }
                await page.evaluate(() => enterProductEditMode());
                for (const tab of ['quantity', 'actions', 'info']) {
                    await page.evaluate(tab => setProductDrawerTab(tab), tab);
                    assert.equal(await page.locator('#product-edit-btn').isVisible(), false, 'Active editing keeps the entry button hidden');
                }
                assert.equal(await page.locator('#product-edit-form fieldset').count(), 4);
                assert.equal(await page.getByLabel('Product Image').count(), 1, 'Upload has an accessible label');
                assert.equal(await page.locator('#edit-cost-price').getAttribute('readonly'), null);
                assert.equal(await page.locator('#edit-cost-price').inputValue(), '30.00');
                await page.locator('#edit-cost-price').fill('60');
                assert.equal(await page.locator('#selling-price-warning').isVisible(), true, 'Cost edit updates below-cost warning');
                await page.locator('#edit-cost-price').fill('10');
                assert.equal(await page.locator('#selling-price-warning').isVisible(), false, 'Warning clears when cost falls');
                for (const position of [0, 700, 10000]) {
                    await page.locator('#product-drawer-body').evaluate((el, top) => { el.scrollTop = top; }, position);
                    const save = await page.locator('#product-save-btn').boundingBox();
                    assert(save.y >= 0 && save.y + save.height <= 900, `${width}/${theme}: Save stays visible`);
                    assert.equal(await page.locator('#product-drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth), true, 'No horizontal overflow');
                }
                await page.locator('#edit-cost-price').fill('30');
                await page.locator('#edit-selling-price').fill('20');
                await page.locator('#edit-selling-price').press('Tab');
                assert.equal(await page.locator('#selling-price-warning').isVisible(), true);
                await page.locator('#product-save-btn').click();
                assert.equal(await page.evaluate(() => window.saved.selling_price), '20');
                assert.equal(await page.evaluate(() => window.saved.cost_price), '30');
                assert.equal(await page.evaluate(() => window.saved.csrf_token), 'test-token');
                await page.locator('#product-cancel-btn').click();
                assert.equal(await page.evaluate(() => window.cancelled), true);
                await page.locator('#product-drawer-body').evaluate(el => { el.scrollTop = 0; });
                await page.screenshot({ path: path.join(output, `${width}-${theme}.png`) });
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log(`Product edit layout: passed (desktop/mobile, light/dark, sticky actions, labels, warning, form submission and cancel). Screenshots: ${output}`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

