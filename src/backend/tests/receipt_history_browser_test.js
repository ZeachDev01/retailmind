const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const output = path.join(os.tmpdir(), 'retailmind-receipt-history-audit');
    fs.mkdirSync(output, {recursive: true});
    const fixture = path.join(output, 'history.json');
    const result = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'receipt_history_integration.php')], {
        env: {...process.env, RUN_CHECKOUT_DB_TESTS: '1', RECEIPT_HISTORY_BROWSER_FIXTURE: fixture}, encoding: 'utf8'
    });
    assert.match(result, /Receipt History amounts, status filters and Manila boundaries: passed/);
    const http = JSON.parse(fs.readFileSync(fixture, 'utf8'));
    const browser = await chromium.launch({headless: true});
    try {
        let failure = false;
        let empty = false;
        let requests = [];
        const errors = [];
        const rows = [{sale_id: 6, sale_date: '2026-10-01 08:39:18', sale_date_display: 'Oct 1, 2026 · 8:39 AM',
            cashier_name: 'Saved Cashier ' + 'Long name '.repeat(12), item_count: 3, total_amount: 1234567.89,
            payment_method: 'cash', attribution_label: 'Shift #1', refund_status: 'None', refunded_amount: 0, remaining_net_amount: 1234567.89}];
        async function open(options = {}) {
            const page = await browser.newPage({viewport: {width: 1440, height: 900}, ...options});
            page.on('pageerror', error => errors.push(error.stack));
            await page.addInitScript(({blockedStorage}) => {
                if (blockedStorage) for (const method of ['getItem', 'setItem', 'removeItem']) Storage.prototype[method] = () => {throw new DOMException('Storage blocked', 'SecurityError');};
                window.alert = message => {window.lastAlert = message;};
            }, options);
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.searchParams.get('action') === 'data') {
                    requests.push(url);
                    return route.fulfill({status: failure ? 500 : 200, contentType: 'application/json', body: JSON.stringify({
                        draw: Number(url.searchParams.get('draw')), recordsTotal: empty ? 0 : 1, recordsFiltered: empty ? 0 : 1,
                        data: empty || failure ? [] : rows, ...(failure ? {error: 'Unable to load receipts.'} : {})
                    })});
                }
                const asset = url.pathname.match(/\/assets\/(.*)/);
                if (asset) {
                    if (options.missingLibrary && asset[1].endsWith('datatables.min.js')) return route.abort();
                    const file = path.resolve(root, 'src/frontend/assets', decodeURIComponent(asset[1]));
                    if (file.startsWith(path.join(root, 'src/frontend/assets') + path.sep) && fs.existsSync(file)) {
                        return route.fulfill({path: file});
                    }
                    return route.fulfill({status: 404, body: ''});
                }
                if (url.hostname === 'history.test' && url.pathname.endsWith('/sales.php')) return route.fulfill({contentType: 'text/html', body: options.admin ? http.admin : http.list});
                return route.fulfill({status: 204, body: ''});
            });
            await page.goto('http://history.test/retailmind/src/frontend/components/invoice/sales.php?tab=transactions');
            return page;
        }
        const page = await open({blockedStorage: true});
        await page.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        assert.match(await page.locator('#receiptsTable').innerText(), /₱1,234,567.89/);
        await page.locator('#receiptDateFrom').fill('2026-10-02');
        await page.locator('#receiptDateFrom').dispatchEvent('change');
        await page.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        const before = requests.length;
        await page.locator('#receiptDateTo').fill('2026-10-01');
        await page.locator('#receiptDateTo').dispatchEvent('change');
        assert.match(await page.locator('#receiptTableStatusMessage').innerText(), /From must be on or before To/);
        assert.equal(requests.length, before, 'Invalid range leaves previous results unchanged');
        assert.equal(await page.locator('#receiptDateTo').getAttribute('aria-invalid'), 'true');
        await page.getByRole('button', {name: 'Clear filters'}).click();
        await page.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        assert.equal(await page.locator('#receiptDateTo').getAttribute('aria-invalid'), 'false');
        failure = true;
        await page.locator('#receiptRefundStatus').selectOption('partial');
        await page.getByRole('button', {name: 'Retry', exact: true}).waitFor();
        assert.match(await page.locator('#receiptTableStatusMessage').innerText(), /Unable to load receipts/);
        assert.equal(await page.locator('#receiptEmptyState').isVisible(), false, 'Errors never claim history is empty');
        failure = false;
        await page.getByRole('button', {name: 'Retry', exact: true}).click();
        await page.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => {document.documentElement.dataset.theme = theme; document.documentElement.style.colorScheme = theme;}, theme);
            for (const width of [320, 768, 1024, 1440]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${theme}/${width}: no page overflow`);
                if (process.env.RECEIPT_AXE_PATH) {
                    await page.addScriptTag({path: process.env.RECEIPT_AXE_PATH});
                    const violations = await page.evaluate(async () => (await axe.run(document, {
                        runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']}
                    })).violations.map(issue => ({id: issue.id, nodes: issue.nodes.map(node => ({target: node.target, summary: node.failureSummary}))})));
                    assert.deepEqual(violations, [], `${theme}/${width}: WCAG AA automated audit`);
                }
                for (const selector of ['#receiptDateFrom', '#receiptDateTo', '#receiptRefundStatus', '#clearReceiptFilters', '.dt-search input', '.dt-length select', '.btn-view']) {
                    const box = await page.locator(selector).boundingBox();
                    assert(box && box.height >= 44 && box.width >= 44, `${theme}/${width}: ${selector} touch target`);
                }
                await page.locator('#receiptDateFrom').focus();
                assert.equal(await page.locator('#receiptDateFrom').evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
                for (let step = 0; step < 8 && !await page.locator('#receiptDateTo').evaluate(el => document.activeElement === el); step++) await page.keyboard.press('Tab');
                assert.equal(await page.locator('#receiptDateTo').evaluate(el => document.activeElement === el), true);
                await page.screenshot({path: path.join(output, `${theme}-${width}.png`), fullPage: true});
            }
        }
        await page.emulateMedia({reducedMotion: 'reduce'});
        empty = true;
        await page.getByRole('button', {name: 'Clear filters'}).click();
        await page.locator('#receiptEmptyState').waitFor();
        assert.equal(await page.locator('.receipt-table-wrap').isVisible(), false);
        assert.deepEqual(errors, [], 'Receipt filters work with storage blocked');
        await page.close();
        const missing = await open({missingLibrary: true});
        await missing.getByRole('button', {name: 'Reload page'}).waitFor();
        assert.match(await missing.locator('#receiptTableStatusMessage').innerText(), /could not start/);
        assert.equal(await missing.locator('.receipt-table-wrap').isVisible(), false);
        await missing.close();
        empty = false;
        const touch = await open({hasTouch: true, isMobile: true, viewport: {width: 390, height: 844}});
        await touch.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        assert.equal(await touch.locator('.receipt-table-wrap').evaluate(el => el.scrollWidth > el.clientWidth), false, 'Receipt details fit without sideways scrolling');
        assert.equal(await touch.locator('#receiptsTable tbody tr').first().evaluate(el => getComputedStyle(el).display), 'grid', 'Narrow screens show stacked receipt details');
        await touch.getByRole('button', {name: 'Full details', exact: true}).first().scrollIntoViewIfNeeded();
        await touch.close();
        const admin = await open({admin: true});
        await admin.getByText('1 permitted receipt found.', {exact: true}).waitFor();
        await admin.locator('#receiptCashier').selectOption('2');
        await admin.waitForFunction(() => document.querySelector('#receiptTableStatusMessage').textContent === '1 permitted receipt found.');
        assert.equal(requests.at(-1).searchParams.get('cashier_id'), '2');
        for (const width of [320, 768, 1024, 1440]) {
            await admin.setViewportSize({width, height: 900});
            for (const selector of ['#receiptDateFrom', '#receiptDateTo', '#receiptRefundStatus', '#receiptCashier', '#clearReceiptFilters']) {
                const box = await admin.locator(selector).boundingBox();
                assert(box.x >= 0 && box.x + box.width <= width, `Administrator/${width}: ${selector} stays reachable`);
            }
        }
        await admin.evaluate(() => document.documentElement.style.fontSize = '200%');
        for (const selector of ['#receiptDateFrom', '#receiptDateTo', '#receiptRefundStatus', '#receiptCashier', '#clearReceiptFilters']) {
            const box = await admin.locator(selector).boundingBox();
            assert(box.x >= 0 && box.x + box.width <= 1440, `200% text: ${selector} stays reachable`);
        }
        await admin.close();
        console.log(`Receipt History Chromium failures/storage/dates/themes/responsive/keyboard: passed (${output})`);
    } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
