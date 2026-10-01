const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
let chromium;
try { ({chromium} = require('playwright')); } catch {
    console.log('Reviewed quote browser: skipped (install Playwright and Chromium)'); process.exit(0);
}
if (!fs.existsSync(chromium.executablePath())) {
    console.log('Reviewed quote browser: skipped (install Chromium)'); process.exit(0);
}

(async () => {
    const pos = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
    const form = pos.match(/<form method="POST" id="checkout-form"[\s\S]*?<\/form>/)[0]
        .replace(/<\?=[\s\S]*?\?>/g, '<input name="csrf_token" value="fixture">');
    const modal = pos.match(/<div id="checkout-modal"[\s\S]*?(?=<div id="void-modal")/)[0];
    const functions = pos.slice(pos.indexOf('function validateCheckout('), pos.indexOf('async function apiHeldSale('));
    const source = fs.readFileSync('src/frontend/assets/js/checkout-attempt.js', 'utf8')
        + fs.readFileSync('src/frontend/assets/js/checkout-quote.js', 'utf8');
    let quoteTotal = 80;
    let committed = null;
    let submissions = 0;
    let stale = true;
    let unavailable = false;
    const server = http.createServer((request, response) => {
        if (request.url === '/lost-response') { request.socket.destroy(); return; }
        if (request.url === '/receipt') { response.end('<p>Saved Receipt #42</p>'); return; }
        if (request.url.includes('recover_checkout=')) {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({sale: committed, receipt_url: committed ? '/receipt' : null})); return;
        }
        if (request.method === 'POST') {
            let body = '';
            request.on('data', chunk => { body += chunk; });
            request.on('end', () => {
                if (body.includes('review_quote')) {
                    if (unavailable) { response.end('<p>Sign in again.</p>'); return; }
                    response.setHeader('Content-Type', 'application/json');
                    response.end(JSON.stringify({token: 'server-quote', quote: {total: quoteTotal,
                        sale: {total: 100, items: [{product_name: 'Current Item', quantity: 4, unit_price: 25, subtotal: 100}]},
                        eligible_promotions: [{promotion_name: 'Best promotion'}], discount: {promotion_name: 'Best promotion', discount_amount: 100 - quoteTotal}}}));
                } else {
                    submissions++;
                    if (stale) { response.end('<p>Prices changed. Review the final quote again.</p>'); }
                    else { committed = {sale_id: 42}; response.writeHead(302, {Location: '/lost-response'}); response.end(); }
                }
            }); return;
        }
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`${form}${modal}<p id="message"></p><input id="sku"><script>${source}</script><script>
            let cart = {1: {qty: 4, name: 'Cached Item', price: 25}};
            let checkoutConfirmed = false, checkoutSubmitting = false;
            const posShiftOpen = true;
            const checkoutForm = document.getElementById('checkout-form');
            const cartInput = document.getElementById('cart-input');
            const paymentMethod = document.getElementById('payment-method');
            const cashReceived = document.getElementById('cash-received');
            const paymentReference = document.getElementById('payment-reference');
            const discountReason = document.getElementById('discount-reason');
            const skuInput = document.getElementById('sku');
            const checkoutButton = document.getElementById('checkout-button');
            const confirmCheckoutButton = document.getElementById('confirm-checkout-button');
            const checkoutModal = document.getElementById('checkout-modal');
            const checkoutSummary = document.getElementById('checkout-summary');
            function getNetTotal() { return 100; }
            function getDiscountAmount() { return 0; }
            function money(value) { return Number(value).toFixed(2); }
            function escapeHtml(value) { return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;'); }
            function showCartMessage(message) { document.getElementById('message').textContent = message; }
            const checkoutQuote = new CheckoutQuote(checkoutForm);
            const checkoutAttempt = new CheckoutAttempt(checkoutForm, '1', {recoverUrl: '/pos', restore: value => cart = value, message: showCartMessage});
            window.loaded = checkoutAttempt.recover().then(() => checkoutButton.disabled = false);
            ${functions}
        </script>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.goto(origin + '/pos'); await page.evaluate(() => loaded);
        assert(await page.locator('#cash-received').evaluate(input => input.readOnly), 'Cash collection awaits server review');
        unavailable = true;
        await page.click('#checkout-button');
        await page.waitForFunction(() => document.getElementById('message').textContent.includes('final quote is unavailable'));
        assert.equal(await page.locator('#quote-token-input').inputValue(), '', 'Unavailable review grants no token');
        assert.equal(await page.locator('#review-cash').count(), 0, 'Unavailable review never opens tender collection');
        unavailable = false;
        await page.click('#checkout-button');
        await page.waitForSelector('#review-cash');
        assert.match(await page.locator('#checkout-summary').textContent(), /Current Item.*4 × ₱25.00/);
        assert.match(await page.locator('#checkout-summary').textContent(), /Best promotion/);
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱80.00/, 'Server discount replaces cached total');
        await page.fill('#review-cash', '100');
        assert.equal(await page.locator('#review-change').textContent(), '20.00');
        await page.evaluate(() => cart[1].qty = 5);
        await page.click('#confirm-checkout-button');
        assert.match(await page.locator('#message').textContent(), /changed.*Review the final quote again/);
        assert.equal(submissions, 0, 'A cart changed after review is never submitted');
        await page.evaluate(() => cart[1].qty = 4);
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        await page.fill('#review-cash', '100');
        await page.click('#confirm-checkout-button');
        await page.waitForFunction(() => document.body.textContent.includes('Prices changed.'));
        const firstAttempt = await page.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.1')).id);
        quoteTotal = 70;
        await page.goto(origin + '/pos'); await page.evaluate(() => loaded);
        assert.match(await page.locator('#message').textContent(), /Do not collect payment again/);
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱70.00/);
        stale = false;
        await page.click('#confirm-checkout-button');
        await page.waitForTimeout(150);
        await page.goto(origin + '/pos');
        await page.waitForURL(origin + '/receipt');
        assert.match(await page.textContent('body'), /Saved Receipt #42/);
        assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.1')).id), firstAttempt, 'Quote review and lost outcome keep the retry identity');
        assert.equal(submissions, 2, 'Recovery never submits or collects payment a third time');
        console.log('Reviewed quote browser: passed (review before tender, server totals/lines/promotions/change, changed cart refusal, stale quote review, lost outcome receipt recovery)');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exit(1); });
