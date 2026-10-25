const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 768, height: 1024}});
        let jobs = 0, requests = 0, status = 'queued';
        await page.route('http://store.test/**', async route => {
            if (route.request().method() !== 'POST') return route.fulfill({body: '<html><body><div class="receipt-container"><button data-store-print data-print-endpoint="/print" data-csrf="csrf" data-receipt-type="sale" data-receipt-id="1">Print to Store Printer</button></div></body></html>'});
            requests++;
            const data = new URLSearchParams(route.request().postData());
            assert.equal(data.get('csrf_token'), 'csrf');
            if (data.get('action') !== 'status') jobs++;
            await route.fulfill({contentType: 'application/json', body: JSON.stringify({job_id: 'job1', status})});
        });
        await page.goto('http://store.test/');
        await page.addStyleTag({content: fs.readFileSync(path.resolve(__dirname, '../../frontend/assets/css/cashier.css'), 'utf8')});
        await page.evaluate(() => {
            const keyboardViewport = new EventTarget();
            keyboardViewport.height = 400; keyboardViewport.offsetTop = 100;
            Object.defineProperty(window, 'visualViewport', {value: keyboardViewport});
            document.body.insertAdjacentHTML('beforeend', '<div class="checkout-modal open"><div class="checkout-dialog"><input value="50"><button>Complete payment</button></div></div>');
        });
        await page.evaluate(() => { window.print = () => { throw new Error('Must not open browser printing'); }; });
        await page.addScriptTag({content: fs.readFileSync(path.resolve(__dirname, '../../frontend/assets/js/sale-receipt.js'), 'utf8')});
        const payment = await page.getByRole('button', {name: 'Complete payment'}).boundingBox();
        assert.ok(payment.y >= 100 && payment.y + payment.height <= 500, 'Payment action remains within a simulated keyboard visual viewport');
        await page.evaluate(() => document.querySelector('.checkout-modal').remove());
        await page.getByRole('button', {name: 'Print to Store Printer'}).click();
        await page.getByRole('status').filter({hasText: 'Queued'}).waitFor();
        assert.equal(jobs, 1);
        status = 'uncertain';
        await page.getByRole('status').filter({hasText: 'Check for an existing copy'}).waitFor();
        page.once('dialog', dialog => dialog.dismiss());
        await page.getByRole('button', {name: 'Reprint to Store Printer'}).click();
        assert.equal(jobs, 1, 'Canceled informed reprint never submits');
        status = 'spooled';
        page.once('dialog', dialog => dialog.accept());
        await page.getByRole('button', {name: 'Reprint to Store Printer'}).click();
        await page.getByRole('status').filter({hasText: 'Windows print queue'}).waitFor();
        assert.equal(jobs, 2);
        assert.ok(requests >= 3);
        await page.setViewportSize({width: 1024, height: 768});
        assert.equal(await page.getByRole('status').count(), 1, 'Rotation preserves receipt status');
        console.log('Store print browser test passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
