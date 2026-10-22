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
    const functions = pos.slice(pos.indexOf('function setTextIfPresent('), pos.indexOf('function quickProductMedia('))
        + pos.slice(pos.indexOf('function updatePaymentFields('), pos.indexOf('async function apiHeldSale('));
    const bindings = ['checkoutButton', 'confirmCheckoutButton'].map(name => pos.match(new RegExp(name + "\\.addEventListener\\('click', [^)]+\\);"))[0]).join('\n')
        + '\n' + ['checkoutSummary', 'cashQuick'].map(name => pos.match(new RegExp(name + "\\.addEventListener\\('click',[\\s\\S]*?\\n\\}\\);"))?.[0] || '').join('\n')
        + '\n' + pos.match(/promotionSelect\.addEventListener\('change',[\s\S]*?\n\}\);/)[0]
        + '\n' + pos.match(/cashReceived\.addEventListener\('input', [^)]+\);/)[0];
    const editGuard = pos.slice(pos.indexOf('function cartChangesAllowed()'), pos.indexOf('function addToCart('));
    const source = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8')
        + fs.readFileSync('src/frontend/assets/js/checkout-attempt.js', 'utf8')
        + fs.readFileSync('src/frontend/assets/js/checkout-quote.js', 'utf8');
    const styles = ['style.css', 'cashier.css'].map(name => fs.readFileSync('src/frontend/assets/css/' + name, 'utf8')).join('\n');
    let quoteTotal = 80;
    let productName = 'Current Item';
    let committed = null;
    let submissions = 0;
    let stale = true;
    let unavailable = false;
    let eligible = true;
    let delayQuote = 0;
    let quotedCart = null;
    const server = http.createServer((request, response) => {
        if (request.url === '/lost-response') { request.socket.destroy(); return; }
        if (request.url === '/receipt') { response.end('<p>Saved Receipt #42</p>'); return; }
        if (request.url.includes('recover_checkout=')) {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({sale: committed, receipt_url: committed ? '/receipt' : null})); return;
        }
        if (request.method === 'POST') {
            if (request.url !== '/pos') { response.writeHead(404); response.end('Unknown quote route'); return; }
            let body = '';
            request.on('data', chunk => { body += chunk; });
            request.on('end', () => {
                if (body.includes('review_quote')) {
                    quotedCart = JSON.parse(body.match(/name="cart"\r\n\r\n([^\r]+)/)[1]);
                    if (unavailable) { response.end('<p>Sign in again.</p>'); return; }
                    response.setHeader('Content-Type', 'application/json');
                    const selected = body.match(/name="promotion_id"\r\n\r\n([^\r]*)/)?.[1] || '';
                    if (selected && !eligible) { response.writeHead(409); response.end(JSON.stringify({message: 'The selected promotion is no longer eligible. Review the updated total.'})); return; }
                    const total = selected === '1' ? quoteTotal : selected === '2' ? 95 : 100;
                    setTimeout(() => response.end(JSON.stringify({token: 'server-quote', quote: {total,
                        sale: {total: 100, items: [{product_name: productName, quantity: 4, unit_price: 25, subtotal: 100}]},
                        eligible_promotions: eligible ? [{promotion_id: 1, promotion_name: 'Best promotion', applied_amount: 100 - quoteTotal}, {promotion_id: 2, promotion_name: 'Smaller promotion', applied_amount: 5}] : [], discount: {promotion_name: selected ? (selected === '1' ? 'Best promotion' : 'Smaller promotion') : null, discount_amount: 100 - total}}})), delayQuote);
                } else {
                    submissions++;
                    if (stale) { response.end('<p>Prices changed. Review the final quote again.</p>'); }
                    else { committed = {sale_id: 42}; response.writeHead(302, {Location: '/lost-response'}); response.end(); }
                }
            }); return;
        }
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        const breakdown = pos.match(/<div class="payment-breakdown"[\s\S]*?(?=<form method="POST" id="checkout-form")/)[0];
        response.end(`<style>${styles}</style><body class="cashier-page">${breakdown}${form}${modal}<span id="cart-total"></span><p id="message"></p><input id="sku"><script>${source}</script><script>
            let cart = {1: {qty: 4, name: 'Cached Item', price: 25}};
            let checkoutConfirmed = false, checkoutSubmitting = false, resumedHeldSaleId = 1, promotionRefreshTimer;
            const posShiftOpen = true;
            const checkoutForm = document.getElementById('checkout-form');
            const cartInput = document.getElementById('cart-input');
            const paymentMethod = document.getElementById('payment-method');
            const cashReceived = document.getElementById('cash-received');
            const cashQuick = document.getElementById('cash-quick');
            const changeDue = document.getElementById('change-due');
            const referenceField = document.getElementById('reference-field');
            const discountSummary = document.getElementById('discount-summary');
            const promotionSelect = document.getElementById('promotion-id');
            const paymentReference = document.getElementById('payment-reference');

            const skuInput = document.getElementById('sku');
            const checkoutButton = document.getElementById('checkout-button');
            const confirmCheckoutButton = document.getElementById('confirm-checkout-button');
            const checkoutModal = document.getElementById('checkout-modal');
            const checkoutSummary = document.getElementById('checkout-summary');
            function getNetTotal() { return 100 - getDiscountAmount(); }
            function getCartTotal() { return 100; }
            function getCartItemCount() { return Object.values(cart).reduce((count, item) => count + item.qty, 0); }
            function getDiscountAmount() { return Number(promotionSelect.selectedOptions[0]?.dataset.amount || 0); }
            function money(value) { return Number(value).toFixed(2); }
            function escapeHtml(value) { return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;'); }
            function showCartMessage(message) { document.getElementById('message').textContent = message; }
            const checkoutQuote = new CheckoutQuote(checkoutForm);
            const checkoutAttempt = new CheckoutAttempt(checkoutForm, '1', {recoverUrl: '/pos', restore: value => cart = value, message: showCartMessage});
            window.loaded = checkoutAttempt.recover().then(() => checkoutButton.disabled = false);
            ${editGuard}
            {${functions}\n${bindings}}
        </script>`);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const pageErrors = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        await page.goto(origin + '/pos'); await page.evaluate(() => loaded);
        assert.equal(await page.getByRole('combobox', {name: 'Promotion', exact: true}).inputValue(), '', 'Promotion defaults to no promotion');
        assert.equal(await page.locator('[name=discount_type]').count(), 0, 'Custom discount controls removed');
        assert.equal(await page.locator('#cash-received').evaluate(input => input.readOnly), false, 'Cashier can type cash received before quote review');
        const mainPresets = page.locator('#cash-field').getByRole('group', {name: 'Quick cash amounts'});
        assert.deepEqual(await mainPresets.getByRole('button').allTextContents(), ['Exact', '₱20', '₱50', '₱100']);
        await page.fill('#cash-received', '125.50');
        await mainPresets.getByRole('button', {name: 'Exact', exact: true}).click();
        assert.equal(await page.locator('#cash-received').inputValue(), '100.00', 'Exact replaces entered cash with the current net total');
        assert.equal(await page.locator('#change-due').inputValue(), '0.00', 'Exact payment has no change');
        for (const amount of [20, 50, 100]) {
            const button = mainPresets.getByRole('button', {name: `₱${amount}`, exact: true});
            assert.equal(await button.count(), 1, `₱${amount} option appears below the main Cash received input`);
            await button.click();
            assert.equal(await page.locator('#cash-received').inputValue(), `${amount}.00`, 'Main preset sets an exact amount');
            assert(await button.evaluate(button => button.getBoundingClientRect().top >= document.getElementById('cash-received').getBoundingClientRect().bottom), 'Preset sits below cash input');
        }
        await page.fill('#cash-received', '125.50');
        assert.equal(await page.locator('#change-due').inputValue(), '25.50', 'Typing cash updates displayed change');
        await page.evaluate(() => { cartInput.value = JSON.stringify([{product_id: 1, qty: 4}]); return checkoutQuote.refreshPromotions(); });
        const promotions = page.getByRole('combobox', {name: 'Promotion', exact: true});
        assert.deepEqual(await promotions.locator('option').allTextContents(), ['No promotion', 'Best promotion · ₱20.00', 'Smaller promotion · ₱5.00']);
        await promotions.selectOption('2');
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱95.00/, 'Smaller selection is honored');
        await page.evaluate(() => closeCheckoutConfirm());
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        assert.equal(await promotions.inputValue(), '2', 'Eligible selection survives cart refresh');
        eligible = false;
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        assert.equal(await promotions.inputValue(), '', 'Invalid selection clears');
        assert.match(await page.locator('#promotion-status').textContent(), /Selection cleared.*review the updated total/i);
        assert.equal(await page.locator('#quote-token-input').inputValue(), '', 'Refresh cannot authorize payment');
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        assert.match(await page.locator('#promotion-status').textContent(), /No eligible promotions/);
        unavailable = true;
        await page.evaluate(() => checkoutQuote.refreshPromotions().catch(() => {}));
        assert.match(await page.locator('#promotion-status').textContent(), /Promotions unavailable/, 'Failure is distinguished from empty eligibility');
        unavailable = false; eligible = true; delayQuote = 150;
        const refreshing = page.evaluate(() => checkoutQuote.refreshPromotions());
        await page.waitForFunction(() => document.getElementById('promotion-status').textContent.includes('Loading'));
        await refreshing; delayQuote = 0;
        await promotions.selectOption('1');
        quoteTotal = 70;
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        assert.match(await promotions.locator('option:checked').textContent(), /30.00/, 'Retained promotion recalculates its amount');
        quoteTotal = 80;
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        eligible = false;
        await page.click('#confirm-checkout-button');
        await page.waitForFunction(() => document.getElementById('message').textContent.includes('no longer eligible'));
        assert.equal(await promotions.inputValue(), '', 'Deactivation at payment clears selection');
        assert.equal(submissions, 0, 'Invalid promotion never records payment');
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱100.00/, 'Cashier explicitly reviews total after invalidation');
        await page.evaluate(() => closeCheckoutConfirm());
        eligible = true;
        await page.evaluate(() => checkoutQuote.refreshPromotions());
        await promotions.selectOption('1');
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        quoteTotal = 70;
        await page.click('#confirm-checkout-button');
        await page.waitForFunction(() => document.getElementById('message').textContent.includes('Prices, stock or promotions changed'));
        assert.equal(submissions, 0, 'Changed amount requires a fresh review, never silent payment');
        quoteTotal = 80;
        await promotions.selectOption('');
        assert.equal(await page.evaluate(() => checkoutQuote.isCurrent()), false, 'Selection invalidates review');
        await promotions.focus(); await page.keyboard.press('ArrowDown');
        assert.equal(await promotions.inputValue(), '1', 'Promotion supports native keyboard selection');
        await page.evaluate(() => {
            window.cartWorkspace = {busy:false, context:{locked:false}};
            window.reviewCurrentCart = async () => {
                await new Promise(resolve => setTimeout(resolve, 50));
                window.editRefusedDuringReview = !cartChangesAllowed();
                if (cartChangesAllowed()) cart[1].qty = 99;
                cart[1].qty = 2; return true;
            };
        });
        await page.click('#checkout-button');
        await page.waitForFunction(() => document.getElementById('checkout-modal').classList.contains('open'));
        assert.equal(await page.locator('#review-cash').inputValue(), '125.50', 'Typed main cash amount carries into final quote review');
        assert.equal(await page.locator('#review-change').textContent(), '45.50', 'Change uses final reviewed total rather than cached total');
        assert(await page.locator('body').evaluate(body => body.classList.contains('no-scroll')), 'Checkout locks background scrolling');
        await page.evaluate(() => { checkoutSubmitting = true; closeCheckoutConfirm(); });
        assert(await page.locator('#checkout-modal').evaluate(modal => modal.classList.contains('open')), 'Submitting checkout keeps its page-owned close guard');
        await page.evaluate(() => { checkoutSubmitting = false; closeCheckoutConfirm(); });
        assert.equal(await page.locator('body').evaluate(body => body.classList.contains('no-scroll')), false, 'Closing review releases scroll lock');
        assert.equal(await page.evaluate(() => editRefusedDuringReview), true, 'Delayed review blocks intervening cart edits');
        assert.equal(quotedCart[0].qty, 2, 'Accepted stock review rebuilds the hidden quote payload');
        await page.evaluate(() => { window.cartWorkspace = null; cart[1].qty = 4; checkoutModal.classList.remove('open'); checkoutSummary.innerHTML = ''; });
        unavailable = true;
        await page.click('#checkout-button');
        await page.waitForFunction(() => document.getElementById('message').textContent.includes('final quote is unavailable'));
        assert.equal(await page.locator('#quote-token-input').inputValue(), '', 'Unavailable review grants no token');
        assert.equal(await page.locator('#review-cash').count(), 0, 'Unavailable review never opens tender collection');
        unavailable = false;
        await page.click('#checkout-button');
        await page.waitForSelector('#review-cash');
        const items = page.getByRole('table', {name: 'Items in this sale'});
        assert.equal(await items.count(), 1, 'Items are readable as named rows with quantity and amount columns');
        assert.deepEqual(await items.getByRole('columnheader').allTextContents(), ['Item', 'Qty', 'Amount']);
        assert.deepEqual(await items.getByRole('row').nth(1).getByRole('cell').allTextContents(), ['Current Item₱25.00 each', '4', '₱100.00']);
        assert.match(await page.locator('#checkout-summary').textContent(), /Best promotion/);
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱80.00/, 'Server discount replaces cached total');
        const total = page.getByRole('group', {name: 'Total due', exact: true});
        assert.equal(await total.count(), 1, 'Total due has its own clearly labeled summary');
        assert.equal(await total.locator('strong').textContent(), '₱80.00');
        const changeOutput = page.getByRole('status', {name: 'Change due', exact: true});
        assert.equal(await changeOutput.count(), 1, 'Change has a labeled live output');
        const reviewPresets = page.locator('#checkout-summary').getByRole('group', {name: 'Quick cash amounts'});
        assert.deepEqual(await reviewPresets.getByRole('button').allTextContents(), ['Exact', '₱20', '₱50', '₱100']);
        await reviewPresets.getByRole('button', {name: 'Exact', exact: true}).click();
        assert.equal(await page.locator('#review-cash').inputValue(), '80.00', 'Exact uses the discounted server quote instead of the cached total');
        assert.equal(await page.locator('#cash-received').inputValue(), '80.00', 'Reviewed Exact payment carries into checkout');
        assert.equal(await page.locator('#review-change').textContent(), '0.00', 'Reviewed Exact payment has no change');
        assert(await total.locator('strong').evaluate(amount => parseFloat(getComputedStyle(amount).fontSize) > parseFloat(getComputedStyle(document.querySelector('.review-items td')).fontSize)), 'Total amount is larger than item text');
        assert(await changeOutput.evaluate(amount => parseFloat(getComputedStyle(amount).fontSize) > parseFloat(getComputedStyle(document.querySelector('.review-items td')).fontSize)), 'Change amount is larger than item text');
        for (const width of [320, 375, 1440]) {
            await page.setViewportSize({width, height: 900});
            assert(await items.evaluate(table => table.scrollWidth <= table.clientWidth), `Items, quantities and amounts fit together at ${width}px`);
            if (process.env.CHECKOUT_SCREENSHOT_DIR && [320, 1440].includes(width)) {
                await page.screenshot({path: require('node:path').join(process.env.CHECKOUT_SCREENSHOT_DIR, `checkout-${width}.png`), fullPage: true});
            }
        }
        for (const [amount, change] of [[20, '0.00'], [50, '0.00'], [100, '20.00']]) {
            const preset = page.locator('#checkout-summary').getByRole('button', {name: `₱${amount}`, exact: true});
            assert.equal(await preset.count(), 1, `Cashier can choose exactly ₱${amount}`);
            await preset.click();
            assert.equal(await page.locator('#review-cash').inputValue(), `${amount}.00`, 'Preset replaces cash received instead of adding to total');
            assert.equal(await page.locator('#cash-received').inputValue(), `${amount}.00`, 'Selected cash is used for checkout');
            assert.equal(await page.locator('#review-change').textContent(), change, 'Change uses reviewed total');
        }
        await page.fill('#review-cash', '90');
        assert.equal(await page.locator('#review-change').textContent(), '10.00', 'Manual entry still works after choosing a preset');
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
        productName = 'Baby Diapers Size 4 20 Pack Extra Long Product Name';
        await page.goto(origin + '/pos'); await page.evaluate(() => loaded);
        assert.match(await page.locator('#message').textContent(), /Do not collect payment again/);
        await page.click('#checkout-button'); await page.waitForSelector('#review-cash');
        assert.match(await page.locator('#checkout-summary').textContent(), /Total due: ₱70.00/);
        await page.setViewportSize({width: 320, height: 740});
        assert(await page.getByRole('table', {name: 'Items in this sale'}).evaluate(table => table.scrollWidth <= table.clientWidth), 'Long item names wrap without pushing quantity or amount off screen');
        await page.setViewportSize({width: 1440, height: 900});
        stale = false;
        // The deliberately broken response commits Chromium's error page.
        // Wait for that navigation instead of racing it with an arbitrary delay.
        const lostResponseNavigation = page.waitForEvent('framenavigated', {
            predicate: frame => frame === page.mainFrame() && frame.url().startsWith('chrome-error://')
        });
        await page.click('#confirm-checkout-button');
        await lostResponseNavigation;
        await page.goto(origin + '/pos');
        await page.waitForURL(origin + '/receipt');
        assert.match(await page.textContent('body'), /Saved Receipt #42/);
        assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.1')).id), firstAttempt, 'Quote review and lost outcome keep the retry identity');
        assert.equal(submissions, 2, 'Recovery never submits or collects payment a third time');
        const controls = await browser.newPage();
        const actionBindings = pos.slice(pos.indexOf("clearCartButton.addEventListener('click'"), pos.indexOf("paymentMethod.addEventListener('change'"));
        const names = ['clearCart', 'checkoutNow', 'submitConfirmedCheckout', 'holdCurrentSale', 'confirmDiscardHeldSale', 'resumeHeldSale'];
        await controls.setContent('<button id="clear-cart-btn">Clear</button><button id="checkout-button">Review</button><button id="confirm-checkout-button">Confirm</button><button id="hold-sale-btn">Hold</button><button id="confirm-discard-sale">Discard</button><div id="hold-list"><button data-resume-held-sale="42"><span>Resume</span></button></div>');
        await controls.addScriptTag({content:'window.boundCalls=[]; {' +
            ['clearCartButton:clear-cart-btn', 'checkoutButton:checkout-button', 'confirmCheckoutButton:confirm-checkout-button', 'holdSaleButton:hold-sale-btn', 'holdList:hold-list'].map(pair => {
                const [name, id] = pair.split(':'); return `const ${name}=document.getElementById('${id}');`;
            }).join('') + names.map(name => `async function ${name}(id){boundCalls.push(['${name}',typeof id==='number'?id:null]);}`).join('') + actionBindings + '}'});
        for (const id of ['clear-cart-btn', 'checkout-button', 'confirm-checkout-button', 'hold-sale-btn', 'confirm-discard-sale']) await controls.locator('#' + id).click();
        await controls.getByText('Resume', {exact:true}).click();
        assert.deepEqual(await controls.evaluate(() => boundCalls), names.map(name => [name,name === 'resumeHeldSale' ? 42 : null]), 'All scoped asynchronous POS buttons bind, including dynamically rendered held-sale actions');
        await controls.close();
        const insecure = await browser.newPage();
        await insecure.route('http://retailmind.test/**', async route => {
            const response = await route.fetch({url: origin + new URL(route.request().url()).pathname});
            await route.fulfill({response});
        });
        await insecure.goto('http://retailmind.test/pos');
        await insecure.evaluate(() => loaded);
        assert.equal(await insecure.evaluate(() => Boolean(navigator.locks)), false, 'Plain HTTP custom domains cannot use Web Locks');
        await insecure.click('#checkout-button');
        await insecure.waitForSelector('#review-cash');
        await insecure.fill('#review-cash', '100');
        await insecure.click('#confirm-checkout-button');
        await insecure.waitForFunction(() => document.getElementById('message').textContent.includes('recovery'));
        assert.equal(submissions, 2, 'Unsupported context never submits payment');
        assert.equal(await insecure.evaluate(() => localStorage.getItem('retailmind.checkout.1')), null);
        assert.match(await insecure.locator('#message').textContent(), /HTTPS.*localhost/, 'Recovery refusal explains the browser requirement');
        assert.equal(await insecure.locator('#confirm-checkout-button').isEnabled(), true);
        await insecure.close();
        assert.deepEqual(pageErrors, [], 'Checkout emits no browser errors');
        console.log('Reviewed quote browser: passed (explicit dropdown/keyboard selection, retain/recalculate/clear, loading/empty/error, deactivation and repricing require review, cash/tender/responsive totals, lost outcome recovery)');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exit(1); });
