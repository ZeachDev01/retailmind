'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
    const between = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
    assert.ok(!/Html5Qrcode|start-scanner|scannerResult|scanner-area/.test(source), 'camera UI, dependency and handlers are removed');
    const markup = source.slice(source.indexOf('<div class="pos-grid">'), source.indexOf('</main>'))
        .replace(/<\?[\s\S]*?\?>/g, '');
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ hasTouch: true });
        await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body class="cashier-page cashier-pos-page"><div class="app-shell"><aside class="sidebar"></aside><main class="main-content">${markup}</main></div></body>`);
        for (const file of ['style.css', 'invoices.css', 'cashier.css', 'sale-receipt.css']) {
            await page.addStyleTag({ path: `src/frontend/assets/css/${file}` });
        }
        const icons = 'src/frontend/assets/vendor/bootstrap-icons/';
        await page.addStyleTag({ content: fs.readFileSync(`${icons}bootstrap-icons.min.css`, 'utf8').replace(/url\([^)]*\.woff2[^)]*\)/, `url(data:font/woff2;base64,${fs.readFileSync(`${icons}fonts/bootstrap-icons.woff2`).toString('base64')})`) });
        await page.addScriptTag({ content: `
            let cart = {};
            const posShiftOpen = true, lowStockFallback = 5;
            const skuInput = document.getElementById('sku-input');
            const quickCategoryFilters = document.getElementById('quick-category-filters');
            const quickProductGrid = document.getElementById('quick-product-grid');
            const quickProducts = Array.from({length: 24}, (_, i) => ({product_id: i + 1, name: i ? 'Peanut Butter 340g' : 'Long Grain Rice 1kg', price: 125, quantity_on_hand: 50, sku: 'GROCERY-001', category_name: 'Grocery', ...(i === 0 ? {pack_size: 12, pack_price: 1400, sealed_packs: 4} : {})}));
            const quickProductById = new Map(quickProducts.map(p => [p.product_id, p]));
            const checkoutButton = document.getElementById('checkout-button'), holdSaleButton = document.getElementById('hold-sale-btn'), voidSaleButton = document.getElementById('void-sale-btn'), clearCartButton = document.getElementById('clear-cart-btn');
            function getLowStockThreshold() { return 0; }
            function getDiscountAmount() { return 0; }
            function getNetTotal() { return getCartTotal(); }
            function getCartItemCount() { return Object.values(cart).reduce((n, item) => n + item.qty, 0); }
            function updatePaymentFields() {}
            function persistCart() {}
            function cartChangesAllowed() { return true; }
            function showCartMessage() {}
            ${between('function money(', 'function addQuickProduct(')}
            ${between('function updateQuickProductAvailability(', 'function getDiscountAmount(')}
            ${between('function renderCart(', 'function updatePaymentFields(')}
            ${between('function updateCartQty(', 'async function clearCart(')}
            renderQuickProducts();
            function populateCart() { cart = Object.fromEntries(quickProducts.slice(0, 8).map(p => [p.product_id, {name: p.name, qty: 1, price: p.price, stock: 50, sku: p.sku}])); renderCart(); }
            populateCart();
        ` });
        for (const viewport of [{ width: 768, height: 1024 }, { width: 820, height: 1180 }, { width: 834, height: 1194 }, { width: 1024, height: 768 }, { width: 1180, height: 820 }, { width: 1194, height: 834 }, { width: 1366, height: 1024 }, { width: 1440, height: 900 }, { width: 390, height: 844 }]) {
            await page.setViewportSize(viewport);
            for (const fullscreen of [false, true]) {
                await page.evaluate(fullscreen => document.body.classList.toggle('pos-fullscreen', fullscreen), fullscreen);
                const product = await page.locator('.pos-workspace').boundingBox();
                const checkout = await page.locator('.checkout-panel').boundingBox();
                const label = `${viewport.width}x${viewport.height}, fullscreen=${fullscreen}`;
                for (const compact of [false, true]) {
                    await page.locator('#quick-product-grid').evaluate((el, compact) => el.classList.toggle('compact', compact), compact);
                    for (const [piece, pack] of [[0, 0], [1, 0], [0, 1], [1, 1], [2, 4]]) {
                        await page.evaluate(({piece, pack}) => {
                            cart = {'1:piece': {qty: piece}, '1:pack': {qty: pack}};
                            updateQuickProductAvailability();
                        }, {piece, pack});
                        const action = page.locator('.quick-product-action').first();
                        assert.ok(await action.evaluate(el => el.scrollWidth <= el.clientWidth + 1), `${label}: pack controls fit (compact=${compact}, piece=${piece}, pack=${pack})`);
                        for (const button of await action.locator('button').all()) {
                            const box = await button.boundingBox();
                            assert.ok(box.width >= 44 && box.height >= 44, `${label}: pack and piece targets are at least 44px`);
                        }
                        if (pack === 0) {
                            assert.ok(await action.locator('[data-selling-form="pack"]').evaluate(el => {
                                const range = document.createRange();
                                range.selectNodeContents(el.lastChild);
                                return range.getClientRects().length === 1 && el.scrollWidth <= el.clientWidth;
                            }), `${label}: Pack of 12 stays on one line`);
                        }
                        if (pack === 4) {
                            assert.equal(await action.locator('[data-selling-form="pack"]').isDisabled(), true, `${label}: sealed pack limit still disables add`);
                        }
                    }
                }
                await page.locator('#quick-product-grid').evaluate(el => el.classList.remove('compact'));
                await page.evaluate(() => populateCart());
                for (const selector of ['.cart-table-wrap', '.pos-workspace', '.checkout-panel']) {
                    assert.ok(await page.locator(selector).evaluate(el => el.scrollWidth <= el.clientWidth + 1), `${label}: ${selector} has no horizontal overflow`);
                }
                const header = await page.locator('.cart-table th').nth(1).boundingBox();
                const quantity = await page.locator('.cart-actions').first().boundingBox();
                assert.ok(Math.abs(header.x + header.width / 2 - quantity.x - quantity.width / 2) < 2, `${label}: quantity aligns with heading`);
                if (process.env.POS_LAYOUT_CAPTURE) {
                    await page.screenshot({ path: `${process.env.TEMP}/pos-${viewport.width}-${fullscreen}.png` });
                }
                await page.evaluate(() => { cart = {}; renderCart(); });
                const empty = await page.locator('.cart-empty-cell').boundingBox();
                const table = await page.locator('.cart-table-wrap').boundingBox();
                assert.ok(empty.x >= table.x && empty.x + empty.width <= table.x + table.width, `${label}: empty state fits pane`);
                if (process.env.POS_LAYOUT_CAPTURE) await page.screenshot({ path: `${process.env.TEMP}/pos-empty-${viewport.width}-${fullscreen}.png` });
                await page.evaluate(() => populateCart());
                if (viewport.width >= 768) {
                    assert.ok(product.x + product.width <= checkout.x + 1, `${label}: products stay left of checkout`);
                    assert.ok(Math.abs(product.y - checkout.y) < 1, `${label}: panes align at the top`);
                    assert.ok(checkout.x + checkout.width <= viewport.width, `${label}: checkout fits viewport`);
                    assert.ok(checkout.y + checkout.height <= viewport.height, `${label}: checkout fits height`);
                    for (const selector of ['.pos-workspace', '.checkout-panel']) {
                        assert.equal(await page.locator(selector).evaluate(el => getComputedStyle(el).overflowY), 'auto', `${label}: ${selector} scrolls independently`);
                    }
                    await page.locator('#checkout-button').scrollIntoViewIfNeeded();
                    const button = await page.locator('#checkout-button').boundingBox();
                    assert.ok(button.y >= 0 && button.y + button.height <= viewport.height, `${label}: checkout remains reachable`);
                    await page.locator('.checkout-panel').evaluate(el => { el.scrollTop = 0; });
                } else {
                    assert.ok(checkout.y + checkout.height <= product.y + 1, `${label}: phone keeps checkout-first stack`);
                }
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${label}: no page overflow`);
            }
        }
        await page.setViewportSize({width: 768, height: 1024});
        await page.locator('.qty-btn').nth(1).tap();
        assert.equal(await page.locator('.qty-field').first().inputValue(), '2', 'touch increment updates quantity');
        await page.locator('.remove-item-btn').first().tap();
        assert.equal(await page.locator('.qty-field').count(), 7, 'touch remove deletes one row');
        console.log('POS layout: passed (iPad portrait/landscape, desktop, phone, fullscreen, independent scrolling, checkout access)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
