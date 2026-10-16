'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const { chromium } = require('playwright');

const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
function between(start, end) {
    const s = source.indexOf(start);
    const e = source.indexOf(end, s);
    if (s === -1 || e === -1) throw new Error(`Could not find slice between "${start}" and "${end}"`);
    return source.slice(s, e);
}

(async () => {
    let requests = [];
    const server = http.createServer((req, res) => {
        requests.push({ url: req.url, method: req.method });
        res.setHeader('Content-Type', 'text/html');
        res.end(`<!DOCTYPE html><html><head><meta charset="utf-8"></head>
        <body class="cashier-page cashier-pos-page">
            <div id="recent-scan"></div>
            <input id="sku-input" autocomplete="off">
            <input id="search">
            <input id="payment" type="number">
            <button id="add-code-btn">Add</button>
            <button id="idle">Idle</button>
            <div class="checkout-modal" aria-modal="true"></div>
            <div id="scanner-result"></div>
            <tbody id="cart-body"></tbody>
            <span id="cart-total">0.00</span>
            <span id="cart-item-count">0</span>
            <span id="cart-line-count">0</span>
            <span id="summary-subtotal">0.00</span>
            <span id="summary-discount">0.00</span>
            <span id="summary-change">0.00</span>
            <button id="checkout-button" disabled></button>
            <button id="hold-sale-btn" disabled></button>
            <button id="void-sale-btn" disabled></button>
            <button id="clear-cart-btn" disabled></button>
            <div id="cash-field"></div>
            <div id="change-field"></div>
            <div id="reference-field"></div>
            <div id="cash-quick"></div>
            <input id="cash-received">
            <input id="change-due">
            <input id="payment-reference">
            <select id="payment-method"><option value="cash">Cash</option></select>
            <input id="discount-value" value="0">
            <select id="discount-type"><option value="none">None</option></select>
            <form id="checkout-form"></form>
            <script>
                let cart = {}, renders = 0, messages = [], scans = [];
                const skuInput = document.getElementById('sku-input');
                const scannerResult = document.getElementById('scanner-result');
                const addCodeBtn = document.getElementById('add-code-btn');
                const recentScan = document.getElementById('recent-scan');
                const checkoutButton = document.getElementById('checkout-button');
                const holdSaleButton = document.getElementById('hold-sale-btn');
                const voidSaleButton = document.getElementById('void-sale-btn');
                const clearCartButton = document.getElementById('clear-cart-btn');
                const cashField = document.getElementById('cash-field');
                const changeField = document.getElementById('change-field');
                const referenceField = document.getElementById('reference-field');
                const cashQuick = document.getElementById('cash-quick');
                const cashReceived = document.getElementById('cash-received');
                const changeDue = document.getElementById('change-due');
                const paymentReference = document.getElementById('payment-reference');
                const paymentMethod = document.getElementById('payment-method');
                const discountValue = document.getElementById('discount-value');
                const discountType = document.getElementById('discount-type');
                const checkoutForm = document.getElementById('checkout-form');
                const discountSummary = null;
                const supervisorFields = { classList: { toggle() {} } };
                const cartWorkspace = { context: { locked: false }, busy: false, save() {} };
                const posShiftOpen = true, checkoutSubmitting = false, checkoutAttempt = { pending: false };
                const lowStockFallback = 0;
                const barcodeApiUrl = '/pos-api';
                const csrfToken = 'fixture';

                function showCartMessage(message, type) { messages.push({ message, type }); }
                function playScanTone() {}
                function pulseRecentScan() {}
                function getLowStockThreshold() { return 0; }
                function escapeHtml(value) { return String(value ?? ''); }
                function money(value) { return Number(value || 0).toFixed(2); }
                function setTextIfPresent(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }
                function updateQuickProductAvailability() {}
                function renderCart() { renders++; }

                ${between('function showRecentScan(', 'async function clearCart(')}
                ${between("skuInput.addEventListener('keydown'", "checkoutForm.addEventListener('submit'")}
            </script>
        </body></html>`);
    });

    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;

    const browser = await chromium.launch({
        headless: true,
        executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    });

    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        page.setOffline = (val) => context.setOffline(val);
        await page.goto(origin + '/pos');

        // 1. Bootstrapping: load active sellable items into client-side IndexedDB
        const testCatalog = [
            {
                product_id: 1,
                sku: 'MILK-SKU',
                barcode: '123456',
                case_barcode: 'CASE-123456',
                product_name: 'Fresh Milk 1L',
                unit_price: 12.00,
                quantity_on_hand: 3
            },
            {
                product_id: 2,
                sku: 'BREAD-SKU',
                barcode: '654321',
                case_barcode: 'CASE-654321',
                product_name: 'Whole Wheat Bread',
                unit_price: 8.50,
                quantity_on_hand: 5
            }
        ];

        await page.evaluate(async (items) => {
            await window.initPosCatalogDb(items);
        }, testCatalog);

        // Verify items were loaded into client-side IndexedDB with correct properties
        const storedItems = await page.evaluate(async () => {
            const db = window.posDb;
            return new Promise((resolve, reject) => {
                const tx = db.transaction('products', 'readonly');
                const store = tx.objectStore('products');
                const req = store.getAll();
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        });
        assert.equal(storedItems.length, 2, 'IndexedDB contains all preloaded items');
        assert.equal(storedItems[0].product_name, 'Fresh Milk 1L');
        assert.equal(storedItems[0].unit_price, 12.00);
        assert.equal(storedItems[0].quantity_on_hand, 3);
        assert.equal(storedItems[0].sku, 'MILK-SKU');
        assert.equal(storedItems[0].barcode, '123456');
        assert.equal(storedItems[0].case_barcode, 'CASE-123456');

        // 2. Emulate network offline (page.setOffline(true))
        await page.setOffline(true);

        // Reset server request counter to verify zero network requests during offline cart construction
        requests = [];

        // Benchmark lookup against IndexedDB directly (< 2ms)
        const lookupBenchmark = await page.evaluate(async () => {
            const t0 = performance.now();
            const product = await window.lookupProductInIndexedDB('123456');
            const duration = performance.now() - t0;
            return { product, duration };
        });
        assert.ok(lookupBenchmark.product !== null, 'IndexedDB found product');
        assert.equal(lookupBenchmark.product.product_name, 'Fresh Milk 1L');
        assert.ok(lookupBenchmark.duration < 2, `IndexedDB lookup took ${lookupBenchmark.duration.toFixed(3)}ms (must be < 2ms)`);

        const scan = async (code = '123456') => {
            await page.keyboard.type(code);
            const t0 = await page.evaluate(() => performance.now());
            await page.keyboard.press('Enter');
            await page.evaluate(() => scanQueue);
            return page.evaluate((start) => performance.now() - start, t0);
        };

        // 3. Barcode scan while offline in < 50ms with zero network requests
        await page.focus('#idle');
        const scanDuration = await scan('123456');

        assert.ok(scanDuration < 50, `Offline scan took ${scanDuration}ms (must be < 50ms)`);
        assert.equal(requests.length, 0, 'Zero network requests made during cart construction');
        assert.equal(await page.evaluate(() => cart[1].qty), 1, 'Scanned product added to cart with qty 1');
        assert.equal(await page.evaluate(() => cart[1].price), 12.00, 'Scanned product has correct price');
        assert.equal(await page.evaluate(() => window.getLocalStock(1)), 2, 'Local in-memory quantity on hand decremented to 2');

        // 4. SKU typing resolves against IndexedDB with zero network requests
        await page.focus('#sku-input');
        await page.keyboard.type('BREAD-SKU');
        const skuT0 = await page.evaluate(() => performance.now());
        await page.keyboard.press('Enter');
        await page.evaluate(() => scanQueue);
        const skuDuration = await page.evaluate((start) => performance.now() - start, skuT0);

        assert.ok(skuDuration < 50, `SKU typing took ${skuDuration}ms (must be < 50ms)`);
        assert.equal(requests.length, 0, 'Zero network requests on SKU typing');
        assert.equal(await page.evaluate(() => cart[2].qty), 1, 'Bread added to cart with qty 1');
        assert.equal(await page.evaluate(() => cart[2].price), 8.50, 'Bread has correct price');
        assert.equal(await page.evaluate(() => window.getLocalStock(2)), 4, 'Bread local stock decremented to 4');

        // 5. Case barcode scan resolves against IndexedDB
        await page.focus('#idle');
        await scan('CASE-123456');
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart[1].qty), 2, 'Case barcode scan added another Milk');
        assert.equal(await page.evaluate(() => window.getLocalStock(1)), 1, 'Milk local stock decremented to 1');

        // 6. 3rd Milk scan decrements stock to 0
        await scan('123456');
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart[1].qty), 3, '3rd Milk scan increments cart to 3');
        assert.equal(await page.evaluate(() => window.getLocalStock(1)), 0, 'Milk local stock decremented to 0');

        // 7. 4th Milk scan exceeds local stock -> immediately warns cashier, preserves cart
        await scan('123456');
        await page.evaluate(() => scanQueue);
        assert.equal(await page.evaluate(() => cart[1].qty), 3, 'Cart quantity preserved when exceeding stock');
        assert.match(await page.evaluate(() => messages.at(-1).message), /Insufficient stock/, 'Cashier immediately warned');
        assert.equal(requests.length, 0, 'Still zero network requests after stock warning');

        // 8. Decrementing / removing item restores in-memory stock
        await page.evaluate(() => updateCartQty(1, 2));
        assert.equal(await page.evaluate(() => window.getLocalStock(1)), 1, 'Reducing cart qty to 2 restores local stock to 1');

        await page.evaluate(() => removeFromCart(1));
        assert.equal(await page.evaluate(() => window.getLocalStock(1)), 3, 'Removing item from cart restores local stock to 3');

        console.log('POS offline scan browser test: passed');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
