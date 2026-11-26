'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const script = fs.readFileSync('src/frontend/assets/js/stock-issue-form.js', 'utf8');
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent(`<button id="idle">Stock issues</button><form id="stock-issue-form" data-products-api="/products?scope=stock_issues">
            <input id="product-search" list="stock-issue-products"><datalist id="stock-issue-products"></datalist><input id="product_id" type="hidden">
            <input id="quantity" type="number"><textarea id="explanation"></textarea>
            <select id="form" name="form"><option value="piece">Pieces</option><option id="form-option-pack" value="pack">Packs</option></select><select id="category"><option value="damaged">Damaged</option></select><div id="type-info"></div>
            <div id="product-results"></div><div id="selected-product" hidden>
            <span id="selected-product-name"></span><span id="selected-product-code"></span>
            <span id="selected-product-stock"></span></div><button type="button" id="clear-product">Change</button></form>`);
        await page.addScriptTag({path: 'src/frontend/assets/js/ui.js'});
        await page.evaluate(() => RetailMindUI.initPageContent());
        await page.addScriptTag({ content: `
            let requests = [];
            window.fetch = async (url, options) => {
                requests.push(url);
                const product = { product_id: 1, name: 'Milk', sku: 'MILK', barcode: '123456', pack_barcode: 'PACK-MILK', pack_size: 6, quantity_on_hand: 4 };
                return { ok: true, json: async () => ({ success: true, products: url.includes('999999') ? []
                    : url.includes('222222') ? [{ ...product, barcode: '222222' }, { ...product, barcode: '222222', product_id: 2 }] : [product] }) };
            };
            ${script}
        ` });
        const scan = async (code = '123456', delay = 5) => {
            await page.keyboard.type(code, { delay });
            await page.keyboard.press('Enter');
            await page.waitForTimeout(30);
        };
        await page.focus('#idle');
        await scan();
        assert.equal(await page.inputValue('#product_id'), '1', 'scan automatically selects product');
        assert.equal(await page.textContent('#selected-product-name'), 'Milk');
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => requests.length), 1, 'buffer cannot replay');
        await scan('999999');
        assert.equal(await page.inputValue('#product_id'), '', 'unknown scan clears old selection');
        assert.match(await page.textContent('#product-results'), /No active product/);
        await scan('222222');
        assert.equal(await page.inputValue('#product_id'), '', 'ambiguous scan does not pick first match');
        assert.equal(await page.locator('.rm-search-popup [role="option"]').count(), 2);
        await page.locator('.rm-search-popup [role="option"]').first().click();
        assert.equal(await page.inputValue('#product_id'), '1', 'dropdown choice preserves product selection');
        await page.focus('#idle');
        await scan('123456', 100);
        assert.equal(await page.evaluate(() => requests.length), 3, 'normal typing is ignored');
        await page.focus('#explanation');
        await scan();
        assert.equal(await page.inputValue('#explanation'), '123456\n');
        assert.equal(await page.evaluate(() => requests.length), 3, 'explanation is protected');
        await page.focus('#product-search');
        await scan();
        assert.equal(await page.inputValue('#product_id'), '1', 'focused scanner selects once');
        assert.equal(await page.inputValue('#product-search'), '');
        assert.equal(await page.evaluate(() => requests.length), 4);
        await page.locator('#product-search').fill('Milk');
        await page.waitForFunction(() => document.querySelector('#stock-issue-products option'));
        await page.locator('.rm-search-popup [role="option"]').first().click();
        assert.equal(await page.inputValue('#product_id'), '1', 'name search selects through dropdown');
        await page.locator('#product-search').fill('MILK');
        await page.locator('#product-search').press('Enter');
        await page.waitForTimeout(30);
        assert.equal(await page.inputValue('#product_id'), '1', 'SKU Enter selects product');
        await page.locator('#product-search').fill('PACK-MILK');
        await page.locator('#product-search').press('Enter');
        await page.waitForTimeout(30);
        assert.equal(await page.inputValue('#form'), 'pack', 'focused pack barcode retains sealed-pack selection');
        await page.locator('#product-search').fill('222222');
        await page.waitForFunction(() => document.querySelectorAll('#stock-issue-products option').length === 2);
        await page.locator('#product-search').press('ArrowDown');
        await page.locator('#product-search').press('Enter');
        assert.equal(await page.inputValue('#product_id'), '1', 'keyboard dropdown selection is not overwritten by Enter lookup');
        const beforeDisabled = await page.evaluate(() => requests.length);
        await page.focus('#idle');
        await page.evaluate(() => document.getElementById('product-search').disabled = true);
        await scan();
        assert.equal(await page.evaluate(() => requests.length), beforeDisabled, 'no-shift gate is preserved');
        console.log('Stock issues keyboard scanning: passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
