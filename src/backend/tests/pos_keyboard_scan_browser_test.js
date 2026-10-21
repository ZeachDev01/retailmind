'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
function between(start, end) {
    return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
}

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent(`<input id="sku"><input id="search"><input id="payment" type="number">
            <textarea id="note"></textarea><button id="idle">Cart</button>
            <div class="checkout-modal" aria-modal="true"></div><div id="result"></div>`);
        await page.addScriptTag({ content: `
            let cart = {}, renders = 0, stock = 3, requests = [], messages = [], scans = [];
            const skuInput = document.getElementById('sku');
            const barcodeApiUrl = '/pos', csrfToken = 'fixture';
            const cartWorkspace = { context: { locked: false }, busy: false };
            const posShiftOpen = true, checkoutSubmitting = false, checkoutAttempt = { pending: false };
            function showCartMessage(message, type) { messages.push({ message, type }); }
            function showRecentScan(product, code) { scans.push({ product, code }); }
            function renderCart() { renders++; }
            function getLowStockThreshold() { return 0; }
            function escapeHtml(value) { return value; }
            window.fetch = async (url, options) => {
                const input = Object.fromEntries(options.body); requests.push(input);
                await new Promise(resolve => setTimeout(resolve, 20));
                const qty = JSON.parse(input.quantities)['1:piece'] || 0;
                return { json: async () => input.code !== '123456' ? { success: false, message: 'Product not found.' }
                    : qty >= stock ? { success: false, message: 'Insufficient stock.' }
                    : { success: true, product: { product_id: 1, product_name: 'Milk', unit_price: 12,
                        quantity_on_hand: stock, sku: 'MILK', barcode: '123456' } } };
            };
            ${between('async function fetchProductByCode(', 'function updateCartQty(')}
            ${between("skuInput.addEventListener('keydown'", "checkoutForm.addEventListener('submit'")}
        ` });
        const scan = async (code = '123456', delay = 5) => {
            await page.keyboard.type(code, { delay });
            await page.keyboard.press('Enter');
        };
        await page.focus('#idle');
        await scan();
        await scan();
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart['1:piece'].qty), 2, 'consecutive identical scans each add one');
        assert.equal(await page.evaluate(() => renders), 2);
        assert.equal(await page.evaluate(() => JSON.parse(requests[1].quantities)['1:piece']), 1, 'queued lookup sees prior scan');
        assert.equal(await page.evaluate(() => cart['1:piece'].price * cart['1:piece'].qty), 24);
        await page.keyboard.press('Enter');
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => requests.length), 2, 'extra Enter cannot replay cleared buffer');
        await scan('123456', 100);
        assert.equal(await page.evaluate(() => requests.length), 2, 'ordinary typing is ignored');
        for (const selector of ['#search', '#payment', '#note']) {
            await page.focus(selector);
            await scan();
        }
        assert.equal(await page.evaluate(() => requests.length), 2, 'editable fields do not trigger scanning');
        assert.equal(await page.inputValue('#search'), '123456');
        await page.focus('#idle');
        await page.evaluate(() => document.querySelector('.checkout-modal').classList.add('open'));
        await scan();
        assert.equal(await page.evaluate(() => requests.length), 2, 'dialogs block scanning');
        await page.evaluate(() => document.querySelector('.checkout-modal').classList.remove('open'));
        await page.focus('#sku');
        await scan();
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart['1:piece'].qty), 3, 'focused scan is handled exactly once');
        assert.equal(await page.inputValue('#sku'), '');
        await page.focus('#idle');
        await scan();
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart['1:piece'].qty), 3, 'insufficient stock preserves cart');
        assert.match(await page.evaluate(() => messages.at(-1).message), /Insufficient stock/);
        await scan('999999');
        await page.evaluate(() => scanQueue);
        assert.match(await page.evaluate(() => messages.at(-1).message), /not found/);
        assert.equal(await page.evaluate(() => scans.filter(scan => scan.product).length), 3, 'failed scans never report success');
        await page.keyboard.type('123456', { delay: 5 });
        await page.keyboard.press('Control+Enter');
        assert.equal(await page.evaluate(() => requests.length), 5, 'checkout shortcut does not submit scan');
        await page.evaluate(() => { cartWorkspace.context.locked = true; });
        await scan();
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => requests.length), 5, 'locked register cannot scan');
        console.log('POS keyboard scanning: passed');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
