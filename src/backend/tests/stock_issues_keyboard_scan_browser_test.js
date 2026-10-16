'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const source = fs.readFileSync('src/frontend/components/cashier/stock_issues.php', 'utf8');
const start = source.indexOf('        const productsApiUrl');
const script = source.slice(start, source.indexOf('        function filterByStatus', start))
    .replace(/<\?= json_encode\(\$productsApiUrl\) \?>/, "'/products'");
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent(`<button id="idle">Stock issues</button><form id="stock-issue-form">
            <input id="barcode-input"><input id="product-search"><input id="product_id" type="hidden">
            <input id="quantity" type="number"><textarea id="explanation"></textarea>
            <select id="category"><option value="damaged">Damaged</option></select><div id="type-info"></div>
            <div id="product-results"></div><div id="selected-product" hidden>
            <span id="selected-product-name"></span><span id="selected-product-code"></span>
            <span id="selected-product-stock"></span></div><button type="button" id="clear-product">Change</button></form>`);
        await page.addScriptTag({ content: `
            let requests = [];
            window.fetch = async (url, options) => {
                requests.push(url);
                const product = { product_id: 1, name: 'Milk', sku: 'MILK', quantity_on_hand: 4 };
                return { ok: true, json: async () => ({ success: true, products: url.includes('999999') ? []
                    : url.includes('222222') ? [product, { ...product, product_id: 2 }] : [product] }) };
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
        assert.equal(await page.locator('#product-results button').count(), 2);
        await scan('123456', 100);
        assert.equal(await page.evaluate(() => requests.length), 3, 'normal typing is ignored');
        await page.focus('#explanation');
        await scan();
        assert.equal(await page.inputValue('#explanation'), '123456\n');
        assert.equal(await page.evaluate(() => requests.length), 3, 'explanation is protected');
        await page.focus('#barcode-input');
        await scan();
        assert.equal(await page.inputValue('#product_id'), '1', 'focused scanner selects once');
        assert.equal(await page.inputValue('#barcode-input'), '');
        assert.equal(await page.evaluate(() => requests.length), 4);
        await page.focus('#idle');
        await page.evaluate(() => barcodeInput.disabled = true);
        await scan();
        assert.equal(await page.evaluate(() => requests.length), 4, 'no-shift gate is preserved');
        console.log('Stock issues keyboard scanning: passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
