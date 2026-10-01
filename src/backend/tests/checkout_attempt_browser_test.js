const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
let chromium;
try { ({chromium} = require('playwright')); } catch {
    console.log('Checkout tabs browser: skipped (install Playwright and Chromium)');
    process.exit(0);
}
if (!fs.existsSync(chromium.executablePath())) {
    console.log('Checkout tabs browser: skipped (install Chromium)');
    process.exit(0);
}

(async () => {
    let committed = null;
    const source = fs.readFileSync('src/frontend/assets/js/checkout-attempt.js', 'utf8');
    const server = http.createServer((request, response) => {
        if (request.url.includes('recover_checkout=')) {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({sale: committed, receipt_url: committed ? '/receipt' : null}));
        } else if (request.url === '/receipt') {
            response.end('<p>Saved Receipt #42</p>');
        } else {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<form id="checkout"><input name="checkout_attempt"><input name="cash_received" value="50"><input name="csrf_token" value="private-token"><input name="discount_approver_password" value="private-password"></form><script>${source}</script><script>
                window.messages = [];
                window.attempt = new CheckoutAttempt(document.getElementById('checkout'), '1', {recoverUrl: '/pos', restore: cart => window.restored = cart, message: text => messages.push(text)});
                window.loaded = attempt.recover();
            </script>`);
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch({headless: true});
    try {
        const context = await browser.newContext();
        const first = await context.newPage();
        const stale = await context.newPage();
        await Promise.all([first.goto(origin + '/pos'), stale.goto(origin + '/pos')]);
        await Promise.all([first.evaluate(() => loaded), stale.evaluate(() => loaded)]);
        assert.equal(await first.evaluate(() => Boolean(navigator.locks)), true);
        const id = await first.evaluate(async () => {
            await attempt.save({1: {product_id: 1, qty: 2}});
            return attempt.pending.id;
        });
        const refusal = await stale.evaluate(async () => {
            try { await attempt.save({1: {product_id: 1, qty: 2}}); return false; } catch { return !attempt.ready; }
        });
        assert(refusal, 'Already-open tab refuses another identity after response loss');
        assert.equal(await stale.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.1')).id), id);
        const persisted = await first.evaluate(() => localStorage.getItem('retailmind.checkout.1'));
        assert(!persisted.includes('private-'), 'Authorization secrets are not persisted');
        committed = {sale_id: 42};
        await stale.reload();
        await stale.waitForURL(origin + '/receipt');
        assert.match(await stale.textContent('body'), /Saved Receipt #42/, 'Reload recovers saved outcome before payment');

        committed = null;
        await first.evaluate(() => localStorage.clear());
        await Promise.all([first.goto(origin + '/pos'), stale.goto(origin + '/pos')]);
        await Promise.all([first.evaluate(() => loaded), stale.evaluate(() => loaded)]);
        const saves = await Promise.all([first, stale].map(page => page.evaluate(async () => {
            try { await attempt.save({1: {product_id: 1, qty: 2}}); return 'saved'; } catch { return 'recover'; }
        })));
        assert.deepEqual(saves.sort(), ['recover', 'saved'], 'Actual Chromium tabs persist exactly one attempt under Web Locks');
        console.log('Checkout tabs browser: passed (lost response, stale tab refusal, reload receipt recovery, simultaneous-tab serialization)');
    } finally {
        await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => {console.error(error); process.exit(1);});
