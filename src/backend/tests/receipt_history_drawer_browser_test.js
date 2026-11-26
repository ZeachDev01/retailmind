const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const read = file => fs.readFileSync(path.join(root, file), 'utf8');
    const stripPhp = text => text.replace(/<\?=[\s\S]*?\?>/g, '0').replace(/<\?php[\s\S]*?\?>/g, '');
    const source = read('src/frontend/components/invoice/sales.php');
    const main = stripPhp(source.match(/<main[\s\S]*?<\/main>/)[0]);
    const dialog = stripPhp(read('src/backend/app/Views/sale_receipt_dialog.php'))
        .replace(/data-receipt-endpoint="[^"]*"/, 'data-receipt-endpoint="/details"')
        .replace(/data-initial-receipt="[^"]*"/, 'data-initial-receipt="0"');
    const styles = ['vendor/datatables/datatables.min.css', 'css/style.css', 'css/invoices.css', 'css/sale-receipt.css']
        .map(file => read('src/frontend/assets/' + file)).join('\n');
    const receipt = {sale_id: 42, sale_date: '2026-10-16 08:00:00', sale_date_display: 'Oct 16, 2026',
        cashier_name: 'Saved Cashier', item_count: 2, total_amount: 100, payment_method: 'cash',
        attribution_label: 'Shift #3158', refund_status: 'Partial', refunded_amount: 25, remaining_net_amount: 75};
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://receipt.test/**', route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/0') return route.fulfill({json: {
                draw: Number(url.searchParams.get('draw')), recordsTotal: 1, recordsFiltered: 1, data: [receipt]
            }});
            if (url.pathname === '/details') return route.fulfill({contentType: 'text/html', body: '<div class="receipt-container">Saved receipt #42: purchased items and payment details</div>'});
            return route.fulfill({contentType: 'text/html', body: '<style>' + styles + '</style><body class="receipts-page">' + main + dialog + '</body>'});
        });
        await page.goto('http://receipt.test/');
        await page.addScriptTag({path: path.join(root, 'src/frontend/assets/js/ui.js')});
        await page.addScriptTag({path: path.join(root, 'src/frontend/assets/vendor/datatables/datatables.min.js')});
        await page.addScriptTag({path: path.join(root, 'src/frontend/assets/js/sale-receipt-dialog.js')});
        await page.addScriptTag({content: stripPhp(source.match(/<script>([\s\S]*?)<\/script>/)[1])});
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        assert.equal(await page.locator('#receipt-table-footer select').inputValue(), '15');
        assert.equal(await page.locator('#receiptsTable thead th').count(), 7);
        for (const width of [1440, 1024, 768, 640, 390, 320]) {
            await page.setViewportSize({width, height: width === 640 ? 360 : 900});
            assert(await page.locator('.receipt-table-wrap').evaluate(el => el.scrollWidth <= el.clientWidth), 'Table fits at ' + width);
            const button = page.getByRole('button', {name: 'Full details', exact: true});
            await button.click();
            await page.getByText('Saved receipt #42: purchased items and payment details').waitFor();
            assert.equal(await page.locator('#receiptOtherDetails').isVisible(), false);
            await page.getByRole('tab', {name: 'Refund receipts', exact: true}).click();
            assert.equal(await page.locator('#receiptContent').isVisible(), false);
            const summary = page.locator('.receipt-drawer-summary');
            assert.match(await summary.innerText(), /Shift #3158/);
            assert.match(await summary.innerText(), /Partial/);
            assert.match(await summary.innerText(), /₱25\.00/);
            assert.match(await summary.innerText(), /₱75\.00/);
            const header = await page.locator('.sale-receipt-dialog-header').boundingBox();
            await page.locator('.receipt-drawer-body').evaluate(el => { el.scrollTop = el.scrollHeight; });
            assert.equal((await page.locator('.sale-receipt-dialog-header').boundingBox()).y, header.y, 'Header remains reachable at every height');
            await page.keyboard.press('ArrowLeft');
            assert.equal(await page.getByRole('tab', {name: 'Original receipt', exact: true}).getAttribute('aria-selected'), 'true');
            assert.equal(await page.locator('#receiptContent').isVisible(), true);
            const bounds = await page.locator('#receiptModal').boundingBox();
            const viewportWidth = await page.evaluate(() => document.documentElement.clientWidth);
            assert(Math.abs(bounds.x + bounds.width - viewportWidth) < 2, 'Drawer touches right edge');
            await page.keyboard.press('Escape');
            assert(await button.evaluate(el => el === document.activeElement), 'Closing restores focus');
        }
        assert.deepEqual(errors, []);
        console.log('Receipt drawer: hidden columns, full details, right alignment, no horizontal scroll, and keyboard focus passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
