'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('playwright');
const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
assert(!source.includes('Emergency Stock Adjustment'));
assert(!source.includes("$action === 'adjust'"), 'catalog no longer accepts the retired adjustment action');
const modal = source.slice(source.indexOf('<div class="rm-modal-overlay" id="pack-reconcile-modal"'), source.indexOf('<div class="rm-modal-overlay" id="bulk-category-modal"')).replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');
const drawer = source.slice(source.indexOf('        function renderProductDrawerQuantity('), source.indexOf("        document.addEventListener('error',"));
const correction = source.slice(source.indexOf('        function updateReconcilePreview('), source.indexOf('        function openProductDrawer('));
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'retailmind-stock-count-'));
(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport:{width,height:900}});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            const css = ['style', 'theme', 'inventory'].map(name => fs.readFileSync(path.join(frontend, `assets/css/${name}.css`), 'utf8')).join('\n');
            await page.route('http://product.test/', route => route.fulfill({contentType:'text/html',body:`<meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}${source.match(/<style>([\s\S]*?)<\/style>/)[1]}</style><div id="product-drawer" class="rm-drawer-overlay"><section role="dialog"><div id="product-panel-quantity"></div></section></div>${modal}`}));
            await page.goto('http://product.test/');
            await page.addScriptTag({path:path.join(frontend,'assets/js/ui.js')});
            await page.addScriptTag({content:`
                const canDirectAdjust = true;
                const safe = value => value ?? '';
                const escapeHtml = value => String(value).replace(/[&<>"']/g, '');
                const productsById = {};
                let currentDrawerProductId = 0;
                function openModal(id) { RetailMindUI.openOverlay(document.getElementById(id)); }
                function updateProductTableRow() {}
                function renderProductDrawerReadOnly(product) { renderProductDrawerQuantity(product); }
                window.RetailMindInventoryDrafts = {discard:async () => {}};
                window.confirmations = [];
                window.allowCorrection = true;
                RetailMindUI.confirm = async options => { confirmations.push(options); return allowCorrection; };
                window.requests = [];
                window.fetch = async (url, options) => {
                    requests.push(Object.fromEntries(options.body));
                    return {json:async () => ({success:true,product:{product_id:1,quantity_on_hand:Number(options.body.get('loose_pieces'))}})};
                };
                ${drawer}
                ${correction}
            `});
            async function open(product) {
                await page.evaluate(product => {
                    RetailMindUI.closeOverlay(document.getElementById('pack-reconcile-modal'));
                    renderProductDrawerQuantity(product);
                    document.getElementById('drawer-reconcile-pack').click();
                }, product);
                await page.getByRole('heading', {name:'Correct Stock Count'}).waitFor();
            }
            await open({product_id:1,sku:'PIECE',product_name:'Piece-only item',quantity_on_hand:40});
            assert.equal(await page.locator('#drawer-adjust-stock').count(),0);
            assert.equal(await page.locator('#reconcile-sealed-group').isVisible(),false);
            assert.equal(await page.getByLabel('Physical Pieces',{exact:true}).inputValue(),'40');
            await page.getByLabel('Physical Pieces',{exact:true}).fill('34');
            assert.match(await page.locator('#reconcile-calc-preview').textContent(),/Current stock: 40.*Counted total: 34.*Change: -6/);
            await page.getByLabel('Reason for correction').fill('Corrected initial quantity after recounting');
            await page.evaluate(() => { allowCorrection = false; });
            await page.getByRole('button',{name:'Save corrected count',exact:true}).click();
            assert.equal(await page.evaluate(() => requests.length),0,'cancelling confirmation saves nothing');
            await page.evaluate(() => { allowCorrection = true; });
            await page.getByRole('button',{name:'Save corrected count',exact:true}).click();
            assert.deepEqual(await page.evaluate(() => requests.map(r => [r.action,r.product_id,r.sealed_packs,r.loose_pieces])),[['reconcile_sales_pack_stock','1','0','34']]);
            assert.match(await page.evaluate(() => confirmations.at(-1).message),/Current stock: 40.*Counted total: 34.*Change: -6/);
            await open({product_id:2,sku:'PACK',product_name:'Biscuits',pack_size:12,quantity_on_hand:40,physical_sealed_packs:3,physical_loose_pieces:4});
            assert.equal(await page.getByLabel('Physical Sealed Packs').isVisible(),true);
            await page.getByLabel('Physical Sealed Packs').fill('2');
            await page.getByLabel('Physical Loose Pieces').fill('10');
            assert.match(await page.locator('#reconcile-calc-preview').textContent(),/Counted total: 34.*Change: -6/);
            await page.getByLabel('Physical Sealed Packs').fill('1.5');
            assert.equal(await page.locator('#pack-reconcile-form').evaluate(form => form.checkValidity()),false,'fractional packs cannot submit');
            await page.getByLabel('Physical Sealed Packs').fill('2');
            await page.getByLabel('Reason for correction').fill('Verified shelf count');
            for (const id of ['reconcile-sealed-packs','reconcile-loose-pieces','reconcile-reason']) {
                const bounds = await page.locator(`#${id}`).boundingBox();
                assert(bounds.x >= 0 && bounds.x + bounds.width <= width,`${width}: ${id} fits`);
            }
            await page.screenshot({path:path.join(output,`${width}-packs.png`)});
            await open({product_id:1,sku:'PIECE',product_name:'Piece-only item',quantity_on_hand:0});
            assert.equal(await page.locator('#reconcile-sealed-packs').inputValue(),'0','switching products clears sealed count');
            assert.equal(await page.getByLabel('Physical Pieces',{exact:true}).inputValue(),'0','zero-stock products can be corrected');
            await page.getByLabel('Physical Pieces',{exact:true}).fill('5');
            assert.match(await page.locator('#reconcile-calc-preview').textContent(),/Change: \+5/);
            await page.screenshot({path:path.join(output,`${width}-pieces.png`)});
            assert.deepEqual(errors,[]);
            await page.close();
        }
        console.log(`Stock count correction: passed; screenshots: ${output}`);
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
