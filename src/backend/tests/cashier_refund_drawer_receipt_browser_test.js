const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const read = file => fs.readFileSync(path.join(root, file), 'utf8');
    const stripPhp = text => text.replace(/<\?=[\s\S]*?\?>/g, '0').replace(/<\?php[\s\S]*?\?>/g, '');

    const source = read('src/frontend/components/cashier/refunds.php');
    const mainMatch = source.match(/<main[\s\S]*?<\/main>/);
    assert(mainMatch, 'Found main content in refunds.php');
    let mainHtml = stripPhp(mainMatch[0]);

    const styles = ['vendor/bootstrap-icons/bootstrap-icons.min.css', 'css/style.css', 'css/cashier-pages.css', 'css/refunds.css', 'css/sale-receipt.css']
        .map(file => {
            const full = path.join(root, 'src/frontend/assets', file);
            return fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : '';
        }).join('\n');

    const sampleRefund = {
        refund_id: 15,
        sale_id: 88,
        shift_id: 3,
        created_at: '2026-11-07 19:00:00',
        payment_method: 'cash',
        refund_amount: '120.00',
        reason: 'Customer return',
        note: 'Damaged packaging'
    };

    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));

        await page.route('http://cashier-refund.test/**', route => {
            const url = new URL(route.request().url());
            if (url.searchParams.get('action') === 'view' && url.searchParams.get('ajax') === '1') {
                const saleId = url.searchParams.get('sale_id');
                return route.fulfill({
                    contentType: 'text/html',
                    body: `<div class="receipt-container"><section class="sale-receipt"><h3>Sale #${saleId} Original Receipt</h3></section></div>`
                });
            }

            const html = `<!DOCTYPE html>
            <html>
            <head><meta charset="UTF-8"><style>${styles}</style></head>
            <body class="cashier-refunds-page">
                ${mainHtml}
                <button type="button" id="test-refund-btn" class="btn btn-secondary btn-sm refund-open-drawer" data-refund='${JSON.stringify(sampleRefund)}'>Details</button>
            </body>
            </html>`;
            return route.fulfill({contentType: 'text/html', body: html});
        });

        await page.goto('http://cashier-refund.test/');

        const scriptMatch = source.match(/\(\(\) => \{\s*const drawer = document\.getElementById\('refund-details-drawer'\);[\s\S]*?\}\)\(\);/);
        assert(scriptMatch, 'Found refund drawer script');
        const scriptCode = scriptMatch[0]
            .replace(/<\?= json_encode\(app_url\('components\/invoice\/sales\.php\?action=view&ajax=1'\)\) \?>/g, JSON.stringify('http://cashier-refund.test/components/invoice/sales.php?action=view&ajax=1'));

        await page.addScriptTag({content: scriptCode});

        // 1. Open drawer
        await page.locator('#test-refund-btn').click();
        const drawer = page.locator('#refund-details-drawer');
        assert(await drawer.evaluate(el => el.open), 'Refund drawer is open');

        const detailsView = page.locator('#refund-drawer-view-details');
        const receiptView = page.locator('#refund-drawer-view-receipt');
        const backBtn = page.locator('#refund-drawer-back-btn');
        const titleEl = page.locator('#refund-drawer-title');

        assert(await detailsView.isVisible(), 'Details view is visible');
        assert.equal(await receiptView.isVisible(), false, 'Receipt view is hidden');

        // 2. Click "View receipt" in refund drawer
        const receiptBtn = page.locator('#refund-drawer-receipt-btn');
        assert(await receiptBtn.isVisible(), 'View receipt button is visible');
        await receiptBtn.click();

        await page.waitForTimeout(300);
        assert(await drawer.evaluate(el => el.open), 'Drawer remains open during receipt view');
        assert.equal(await detailsView.isVisible(), false, 'Details hidden');
        assert(await receiptView.isVisible(), 'Receipt view visible inside refund drawer');
        assert(await backBtn.isVisible(), 'Back button visible');
        assert.match(await titleEl.innerText(), /Sale Receipt #88/);

        // 3. Click back
        await backBtn.click();
        await page.waitForTimeout(300);
        assert(await detailsView.isVisible(), 'Details view visible again');
        assert.equal(await receiptView.isVisible(), false, 'Receipt view hidden');
        assert.match(await titleEl.innerText(), /Refund #15/);

        assert.deepEqual(errors, []);
        console.log('✓ Cashier refund drawer: view receipt switch and back animation passed!');
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error(err);
    process.exit(1);
});
