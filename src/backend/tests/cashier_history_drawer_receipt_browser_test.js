const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const read = file => fs.readFileSync(path.join(root, file), 'utf8');
    const stripPhp = text => text.replace(/<\?=[\s\S]*?\?>/g, '0').replace(/<\?php[\s\S]*?\?>/g, '');

    const source = read('src/frontend/components/cashier/history.php');
    const mainMatch = source.match(/<main[\s\S]*?<\/main>/);
    assert(mainMatch, 'Found main content in history.php');
    let mainHtml = stripPhp(mainMatch[0]);

    const styles = ['vendor/bootstrap-icons/bootstrap-icons.min.css', 'css/style.css', 'css/cashier-pages.css', 'css/sale-receipt.css']
        .map(file => {
            const full = path.join(root, 'src/frontend/assets', file);
            return fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : '';
        }).join('\n');

    const sampleSale = {
        sale_id: 108,
        cashier_id: 1,
        sale_date: '2026-11-07 18:30:00',
        total_amount: '350.00',
        payment_method: 'cash',
        cash_received: '500.00',
        change_due: '150.00',
        status: 'Completed'
    };

    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', err => errors.push(err.message));

        await page.route('http://cashier-history.test/**', route => {
            const url = new URL(route.request().url());
            if (url.searchParams.get('action') === 'view' && url.searchParams.get('ajax') === '1') {
                const saleId = url.searchParams.get('sale_id');
                return route.fulfill({
                    contentType: 'text/html',
                    body: `<div class="receipt-container">
                        <div class="receipt-actions no-print">
                            <button type="button" class="btn btn-secondary" onclick="window.printReceiptSection && window.printReceiptSection(this)">Print Receipt</button>
                        </div>
                        <section class="sale-receipt">
                            <div class="receipt-print-area">
                                <header class="receipt-header"><h3>RETAILMIND POS</h3></header>
                                <div class="sale-receipt-line"><span>Sale #${saleId}</span><span>₱350.00</span></div>
                            </div>
                        </section>
                    </div>`
                });
            }

            const html = `<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>${styles}</style>
            </head>
            <body class="cashier-history-page">
                ${mainHtml}
                <button type="button" id="test-open-btn" class="btn btn-primary btn-sm history-open-drawer" data-record='${JSON.stringify(sampleSale)}'>Details</button>
                <script id="history-columns-json" type="application/json">${JSON.stringify({sale_id: 'Sale #', sale_date: 'Date', total_amount: 'Total', payment_method: 'Method'})}</script>
                <script id="history-money-json" type="application/json">${JSON.stringify(['total_amount', 'cash_received', 'change_due'])}</script>
            </body>
            </html>`;
            return route.fulfill({contentType: 'text/html', body: html});
        });

        await page.goto('http://cashier-history.test/');

        // Inject the client logic from history.php
        const scriptMatch = source.match(/<script>([\s\S]*?)<\/script>\s*<\?php \$selected_sale = null;/);
        assert(scriptMatch, 'Found drawer controller script in history.php');
        const scriptCode = scriptMatch[1]
            .replace(/<\?= json_encode\(\$categoryOptions\) \?>/g, '{}')
            .replace(/<\?= json_encode\(\$type\) \?>/g, JSON.stringify('sales'))
            .replace(/<\?= json_encode\(\$tabs\[\$type\] \?\? 'Transaction'\) \?>/g, JSON.stringify('Sales'))
            .replace(/<\?= json_encode\(\$idColumn\) \?>/g, JSON.stringify('sale_id'))
            .replace(/<\?= json_encode\(app_url\('components\/invoice\/sales\.php\?action=view&ajax=1'\)\) \?>/g, JSON.stringify('http://cashier-history.test/components/invoice/sales.php?action=view&ajax=1'));

        await page.addScriptTag({content: scriptCode});

        // 1. Trigger open drawer with sampleSale
        await page.locator('#test-open-btn').click();

        const drawer = page.locator('#history-details-drawer');
        assert(await drawer.evaluate(el => el.open), 'Drawer is open');

        // Check initial Details view
        const detailsView = page.locator('#history-drawer-view-details');
        const receiptView = page.locator('#history-drawer-view-receipt');
        const titleEl = page.locator('#history-drawer-title');
        const backBtn = page.locator('#history-drawer-back-btn');

        assert(await detailsView.isVisible(), 'Details view is visible');
        assert.equal(await receiptView.isVisible(), false, 'Receipt view is hidden');
        assert.equal(await backBtn.isVisible(), false, 'Back button is hidden initially');
        assert.match(await titleEl.innerText(), /Sales #108/, 'Title shows Sales #108');

        // 2. Click "View receipt" button inside the drawer
        const receiptBtn = page.locator('#history-drawer-receipt-btn');
        assert(await receiptBtn.isVisible(), 'View receipt button is visible');
        await receiptBtn.click();

        // Check switch to Receipt view inside drawer
        await page.waitForTimeout(300); // Wait for transition
        assert(await drawer.evaluate(el => el.open), 'Drawer REMAINS open, does not close');
        assert.equal(await detailsView.isVisible(), false, 'Details view is now hidden');
        assert(await receiptView.isVisible(), 'Receipt view is now visible inside drawer');
        assert(await backBtn.isVisible(), 'Header back button is now visible');
        assert.match(await titleEl.innerText(), /Sale Receipt #108/, 'Title updated to Sale Receipt #108');

        // Verify receipt content rendered inside drawer
        await page.locator('.sale-receipt').waitFor();
        const receiptText = await page.locator('.receipt-print-area').innerText();
        assert.match(receiptText, /RETAILMIND POS/);
        assert.match(receiptText, /Sale #108/);

        // 3. Click header Back button to return to details
        await backBtn.click();
        await page.waitForTimeout(300); // Wait for transition
        assert(await detailsView.isVisible(), 'Details view is visible again');
        assert.equal(await receiptView.isVisible(), false, 'Receipt view is hidden again');
        assert.equal(await backBtn.isVisible(), false, 'Back button hidden again');
        assert.match(await titleEl.innerText(), /Sales #108/, 'Title restored to Sales #108');

        // 4. Test footer Back button
        await receiptBtn.click();
        await page.waitForTimeout(300);
        assert(await receiptView.isVisible(), 'Receipt view visible again');
        const returnBtn = page.locator('#history-drawer-return-btn');
        await returnBtn.click();
        await page.waitForTimeout(300);
        assert(await detailsView.isVisible(), 'Returned to details via footer back button');

        // 5. Test close drawer
        const closeBtn = page.locator('#history-drawer-close');
        await closeBtn.click();
        assert.equal(await drawer.evaluate(el => el.open), false, 'Drawer closes when close button is clicked');

        assert.deepEqual(errors, [], 'No page errors');
        console.log('✓ Cashier history drawer: view receipt inside drawer, switch animation, back navigation, and receipt rendering all passed!');
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error(err);
    process.exit(1);
});
