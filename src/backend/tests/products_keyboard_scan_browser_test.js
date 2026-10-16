'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const source = fs.readFileSync('src/frontend/components/inventory_management/products.php', 'utf8');
const start = source.indexOf('        let productKeyboardCode');
const script = source.slice(start, source.indexOf('        let productScanner', start))
    .replace(/<\?= json_encode\(app_url\('components\/barcodeScanner\/apiScanner\/product_code_lookup.php'\)\) \?>/, "'/lookup'");
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent('<button id="idle">Products</button><input id="search"><input id="barcode" maxlength="50"><div id="add" class="rm-modal-overlay"></div>');
        await page.addScriptTag({ content: `
            const barcodeInput = document.getElementById('barcode'), productModalOverlay = document.getElementById('add');
            const productData = [{ product_id: 1 }];
            let wizardStep = 2, opened = [], notices = [], requests = [];
            const RetailMindUI = { toast: message => notices.push(message) };
            function openProductDrawer(id) { opened.push(id); }
            window.fetch = async url => {
                requests.push(url);
                return { ok: true, json: async () => url.endsWith('123456')
                    ? { success: true, outcome: 'match', product: { product_id: 1 } }
                    : { success: true, outcome: 'unknown' } };
            };
            ${script}
        ` });
        const scan = async (code = '123456', delay = 5) => {
            await page.keyboard.type(code, { delay });
            await page.keyboard.press('Enter');
            await page.evaluate(() => productScanQueue);
        };
        await page.focus('#idle');
        await scan();
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => opened.length), 1, 'scan opens once and clears buffer');
        await scan();
        assert.equal(await page.evaluate(() => opened.length), 2, 'continuous scanning works');
        await scan('999999');
        assert.match(await page.evaluate(() => notices.at(-1)), /not found/);
        await scan('123456', 100);
        assert.equal(await page.evaluate(() => requests.length), 3, 'normal typing is ignored');
        await page.focus('#search');
        await scan();
        assert.equal(await page.inputValue('#search'), '123456');
        assert.equal(await page.evaluate(() => requests.length), 3, 'search remains editable');
        await page.focus('#idle');
        await page.evaluate(() => productModalOverlay.classList.add('open'));
        await scan();
        assert.equal(await page.inputValue('#barcode'), '123456', 'barcode step receives scan without focus');
        await page.evaluate(() => { wizardStep = 3; barcodeInput.value = ''; });
        await scan();
        assert.equal(await page.inputValue('#barcode'), '', 'other wizard steps are protected');
        assert.equal(await page.evaluate(() => requests.length), 3);
        console.log('Products keyboard scanning: passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
