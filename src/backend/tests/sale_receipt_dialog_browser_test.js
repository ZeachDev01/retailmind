const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const output = path.join(os.tmpdir(), 'retailmind-receipt-dialog-115');
    fs.mkdirSync(output, {recursive: true});
    const cssDir = path.join(root, 'src/frontend/assets/css');
    const css = ['style', 'invoices', 'sale-receipt'].map(name => fs.readFileSync(path.join(cssDir, `${name}.css`), 'utf8').replace(/@import url\("([^"]+)"\);/g, (_, imported) => fs.readFileSync(path.join(cssDir, imported), 'utf8'))).join('\n');
    const script = fs.readFileSync(path.join(root, 'src/frontend/assets/js/ui.js'), 'utf8')
        + '\nRetailMindUI.isDebug=()=>debugReceipt; RetailMindUI.toast=(message,type)=>receiptAlerts.push({message,type});\n'
        + ['sale-receipt', 'sale-receipt-dialog'].map(name => fs.readFileSync(path.join(root, `src/frontend/assets/js/${name}.js`), 'utf8')).join('\n');
    const browser = await chromium.launch({headless: true});
    try {
        const fixturePath = path.join(output, 'http-history.json');
        execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'receipt_history_integration.php')], {
            env: {...process.env, RUN_CHECKOUT_DB_TESTS: '1', RECEIPT_HISTORY_BROWSER_FIXTURE: fixturePath}, encoding: 'utf8'
        });
        const http = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
        const cleanupPage = await browser.newPage();
        await cleanupPage.route('http://cleanup.test/**', route => route.fulfill({body: '<html><body></body></html>', contentType: 'text/html'}));
        await cleanupPage.goto('http://cleanup.test/');
        for (const [variant, pending, shouldClear] of [['completed', http.attempt, true], ['completed', 'different-attempt', false], ['repeat', null, false]]) {
            await cleanupPage.evaluate(pending => {
                sessionStorage.setItem('retailmind.cart-workspace', 'NEW UNFINISHED CART');
                sessionStorage.setItem('pos_cart', 'NEW CART');
                localStorage.removeItem('retailmind.checkout.1');
                if (pending) localStorage.setItem('retailmind.checkout.1', JSON.stringify({id: pending}));
            }, pending);
            const cleanup = [...http[variant].matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)]
                .map(match => match[1]).find(source => source.includes('retailmind.checkout.') && source.includes('sessionStorage.removeItem'));
            assert(cleanup, 'Production checkout cleanup script is present');
            await cleanupPage.addScriptTag({content: cleanup});
            assert.equal(await cleanupPage.evaluate(() => sessionStorage.getItem('retailmind.cart-workspace') === null), shouldClear,
                'only the committed checkout clears its cart; old completion links preserve new work');
            if (pending && !shouldClear) assert.equal(await cleanupPage.evaluate(() => JSON.parse(localStorage.getItem('retailmind.checkout.1')).id), pending);
        }
        await cleanupPage.close();
        const newSaleSource = fs.readFileSync(path.join(root, 'src/frontend/components/cashier/pos.php'), 'utf8')
            .match(/checkoutAttempt\.recover\(\)\.then\(async \(\) => \{[\s\S]*?\n\}\);/)[0];
        const freshPage = await browser.newPage();
        await freshPage.route('http://fresh.test/**', route => route.fulfill({body: '<html></html>', contentType: 'text/html'}));
        await freshPage.goto('http://fresh.test/pos.php?new_sale=1');
        await freshPage.evaluate(() => {
            window.decisions = 0; window.reloads = 0; window.cart = {1: {qty: 2}};
            window.checkoutAttempt = {ready: true, pending: null, recover: async () => {}};
            window.cartWorkspace = {resolveWork: async () => { decisions++; return true; }, clear: () => {}};
            window.resetPaymentState = () => {}; window.renderCart = () => {};
            window.loadHeldSales = async () => { reloads++; }; window.showCartMessage = () => {};
        });
        await freshPage.evaluate(source => eval(source), newSaleSource);
        assert.equal(await freshPage.evaluate(() => Object.keys(cart).length), 0, 'New Sale starts fresh after unfinished-work decision');
        assert.equal(await freshPage.evaluate(() => reloads), 1, 'newly held/discarded work refreshes displayed Held Sales');
        assert.ok(!new URL(freshPage.url()).searchParams.has('new_sale'));
        await freshPage.evaluate(() => { cart = {1: {qty: 3}}; });
        await freshPage.evaluate(source => eval(source), newSaleSource);
        assert.equal(await freshPage.evaluate(() => decisions), 1, 'refresh does not replay New Sale intent on new work');
        assert.equal(await freshPage.evaluate(() => cart[1].qty), 3);
        await freshPage.close();
        for (const width of [58, 80]) {
            const dialog = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'support/sale_receipt_dialog_fixture.php'), 'long', String(width)], {encoding: 'utf8'});
            const page = await browser.newPage({timezoneId: 'America/New_York'});
            await page.addInitScript(() => {
                window.debugReceipt = false; window.receiptAlerts = [];
                window.RetailMindUI = {isDebug: () => debugReceipt, toast: (message, type) => receiptAlerts.push({message, type})};
            });
            let status = 200;
            const writes = [];
            page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
            await page.route('http://receipt.test/**', route => {
                const url = new URL(route.request().url());
                if (url.searchParams.has('ajax')) return route.fulfill({status, contentType: 'text/html', body: dialog.match(/<div id="receiptContent">([\s\S]*)<\/div>\s*<\/dialog>/)[1].replace(/<div class="checkout-complete-banner[\s\S]*?(?=<div class="receipt-actions)/, '')});
                return route.fulfill({contentType: 'text/html', body: `<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"><style>${css}</style></head><body><main><h2 id="receipt-table-title" tabindex="-1">Receipt History</h2><button id="view" onclick="viewReceipt(103,this)">View saved receipt</button></main>${dialog}<script>window.printCalls=0;window.print=()=>window.printCalls++;</script><script>${script}</script></body></html>`});
            });
            await page.goto('http://receipt.test/sales.php?sale_id=103&checkout=complete');
            assert.equal(await page.locator('dialog[open]').count(), 1, 'checkout opens only one dialog');
            assert(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), 'Native receipt locks background scrolling');
            assert.equal(await page.locator('.receipt-print-area').count(), 1, 'no second inline preview');
            assert.equal(await page.locator('#saleReceiptTitle').evaluate(el => document.activeElement === el), true, 'focus enters labelled dialog');
            assert.ok((await page.locator('dialog').innerText()).includes('Payment completed'));
            assert.equal(await page.evaluate(() => window.printCalls), 0);
            for (const viewport of [320, 375, 425, 768, 1024, 1440]) {
                await page.setViewportSize({width: viewport, height: 720});
                for (let i = 0; i < 8; i++) {
                    await page.keyboard.press('Tab');
                    assert.equal(await page.evaluate(() => document.getElementById('receiptModal').contains(document.activeElement)), true, 'keyboard focus stays inside dialog');
                }
                assert.equal(await page.locator('dialog').evaluate(el => el.scrollWidth <= el.clientWidth + 1), true, 'long dialog does not clip horizontally');
                await page.locator('.sale-receipt-footer').scrollIntoViewIfNeeded();
                assert.equal(await page.locator('.sale-receipt-footer').isVisible(), true, 'long receipt bottom is reachable');
                await page.getByRole('button', {name: 'Print Receipt', exact: true}).scrollIntoViewIfNeeded();
                await page.screenshot({path: path.join(output, `${width}mm-${viewport}.png`)});
            }
            await page.getByRole('button', {name: 'Print Receipt', exact: true}).click();
            assert.equal(await page.evaluate(() => window.printCalls), 1);
            await page.emulateMedia({media: 'print'});
            assert.equal(await page.locator('dialog').isVisible(), false, 'dialog controls do not print');
            assert.equal(await page.locator('.receipt-print-root').isVisible(), true);
            assert.ok(Math.abs(await page.locator('.receipt-print-root .sale-receipt').evaluate(el => el.getBoundingClientRect().width) - width * 96 / 25.4) < 1);
            await page.pdf({path: path.join(output, `${width}mm-dialog.pdf`), width: `${width}mm`, height: '297mm', printBackground: true});
            await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            await page.emulateMedia({media: 'screen'});
            assert.equal(await page.locator('dialog[open]').count(), 1, 'canceled printing leaves saved document open');
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('dialog[open]').count(), 0);
            await page.waitForFunction(() => !document.body.classList.contains('no-scroll'));
            assert.equal(await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), false, 'Closing native receipt releases background scroll lock');
            await page.waitForFunction(() => document.activeElement === document.getElementById('receipt-table-title'));
            assert.ok(!new URL(page.url()).searchParams.has('checkout'));
            await page.locator('#view').focus(); await page.keyboard.press('Enter');
            await page.locator('dialog .sale-receipt').waitFor();
            assert.ok(!(await page.locator('dialog').innerText()).includes('Payment completed'), 'historical viewing never announces new payment');
            await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
            await page.waitForFunction(() => document.activeElement === document.getElementById('view'));
            status = 403;
            await page.locator('#view').click();
            await page.getByRole('alert').waitFor();
            assert.equal(await page.locator('dialog .receipt-print-area').count(), 0, 'denied read exposes no paper or stale success');
            assert.equal(await page.evaluate(() => receiptAlerts.at(-1).type), 'error', 'read failure uses aligned error Operator Alert');
            assert.equal(await page.evaluate(() => receiptAlerts.at(-1).message.includes('403')), false, 'shop mode hides technical response status');
            await page.getByRole('button', {name: 'Close Sale Receipt'}).click();
            await page.evaluate(() => { debugReceipt = true; });
            await page.locator('#view').click(); await page.getByRole('alert').waitFor();
            assert.equal(await page.evaluate(() => receiptAlerts.at(-1).message.includes('\nError: Receipt unavailable: 403')), true, 'APP_DEBUG view retains gated diagnostic detail');
            assert.deepEqual(writes, [], 'receipt navigation and canceled print perform no financial writes');
            await page.close();
        }
        console.log(`Sale Receipt dialog Chromium keyboard/mobile/long paper/manual print: passed (${output})`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
