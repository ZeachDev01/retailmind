const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    if (process.argv[2] !== '--browser') {
        execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'cash_refund_concurrency_integration.php')], {
            env: { ...process.env, RUN_REFUND_DB_TESTS: '1', RUN_REFUND_COMPLETION_BROWSER: '1' },
            stdio: 'inherit', timeout: 180000
        });
        return;
    }
    const [, , , origin, saleIdArg, widthArg] = process.argv;
    const saleId = Number(saleIdArg);
    const width = Number(widthArg);
    const output = path.join(os.tmpdir(), 'retailmind-refund-completion');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ serviceWorkers: 'block' });
        page.setDefaultTimeout(15000);
        const posts = [];
        page.on('request', request => {
            if (request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/refunds.php')) posts.push(request.url());
        });
        await page.addInitScript(() => { window.printCalls = 0; window.print = () => window.printCalls++; });
        // Serve real static assets locally; every other same-origin request reaches the production handler.
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            const asset = url.pathname.match(/\/assets\/(.+)$/);
            if (url.origin === origin && asset) {
                return route.fulfill({ path: path.join(__dirname, '../../frontend/assets', decodeURIComponent(asset[1])) });
            }
            if (url.origin === origin && url.pathname.endsWith('/session_activity.php')) {
                return route.fulfill({ status: 204, body: '' });
            }
            if (url.origin === origin) return route.continue();
            return route.abort();
        });
        const receipt = page.getByRole('region', { name: 'Completed Refund Receipt', exact: true });
        const refundIds = [];
        const receipts = [];
        for (const [sale, quantity, amount] of [[saleId, 1, '25.00'], [saleId + 1, 1, '25.00'], [saleId + 1, 2, '50.00']]) {
            await page.goto(`${origin}/components/cashier/refunds.php?sale_id=${sale}`);
            const form = page.locator('.refund-sale .refund-form');
            await form.locator('input[name^="quantity"]').first().fill(String(quantity));
            await form.locator('select[name^="disposition"]').first().selectOption('damaged');
            await form.locator('#reason').selectOption('customer_return');
            await form.locator('#note').fill('INTERNAL-NOTE-269');
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            assert.ok(await page.evaluate(() => window.scrollY) > 0, 'refund form is scrolled before submit');
            await form.getByRole('button', { name: 'Record refund', exact: true }).click();
            const printOption = page.getByRole('checkbox', {name: 'Print receipt automatically'});
            assert.equal(await printOption.isChecked(), false, 'each refund confirmation starts unchecked');
            const automaticPrints = refundIds.length === 1 ? 1 : 0;
            if (refundIds.length === 0) {
                await printOption.check();
                await page.locator('.rm-modal-overlay[data-rm-confirm] .rm-cancel').click();
                await page.locator('[data-rm-confirm]').waitFor({state: 'detached'});
                await form.getByRole('button', { name: 'Record refund', exact: true }).click();
                assert.equal(await page.getByRole('checkbox', {name: 'Print receipt automatically'}).isChecked(), false,
                    'canceling confirmation discards print choice');
            }
            if (automaticPrints) await printOption.check();
            for (const viewport of [1440, 390]) {
                await page.setViewportSize({width: viewport, height: 900});
                if (refundIds.length === 0) await page.screenshot({path: path.join(output, `auto-print-refund-${viewport}.png`)});
                assert.equal(await printOption.isVisible(), true);
                assert.equal(await page.locator('[data-rm-confirm] .rm-modal').last().evaluate(el => el.scrollWidth > el.clientWidth + 1), false);
            }
            await page.locator('.rm-modal-overlay[data-rm-confirm] .rm-accept').click();
            await page.waitForURL('**/refunds.php?refund_id=*');
            await page.locator('#receiptModal[open]').waitFor();
            assert.equal(await page.locator('#receiptModal[open]').count(), 1,
                'successful refund opens the same receipt modal as POS checkout');
            assert.equal(await page.locator('#receiptModal').evaluate(el => el.matches(':modal')), true);
            assert.equal(await page.locator('#saleReceiptTitle').evaluate(el => document.activeElement === el), true,
                'focus enters the refund receipt modal');
            await page.waitForFunction(() => window.scrollY === 0);
            const receiptBox = await receipt.boundingBox();
            assert.ok(receiptBox && receiptBox.y >= 0 && receiptBox.y < await page.evaluate(() => innerHeight),
                'completed receipt is scrolled into view');
            const refundId = Number(new URL(page.url()).searchParams.get('refund_id'));
            assert.ok(refundId > 0 && !refundIds.includes(refundId), 'each completion opens a distinct saved receipt');
            refundIds.push(refundId);
            assert.equal(await receipt.isVisible(), true);
            const completion = receipt.getByRole('status');
            assert.equal(await completion.isVisible(), true, 'completed refund shows a success banner');
            assert.ok((await completion.innerText()).includes('Refund completed'));
            assert.ok((await completion.innerText()).includes(`Refund Receipt #${refundId} was saved successfully.`));
            assert.ok((await completion.innerText()).includes(`Amount refunded\n₱${amount}`));
            const preview = receipt.locator('.receipt-print-area');
            const text = await preview.innerText();
            receipts.push(text);
            assert.ok(text.includes(`Refund #${refundId}`) && text.includes(`Original Sale Receipt #${sale}`));
            assert.equal(await preview.locator('.sale-receipt-item').count(), 1);
            assert.ok(text.includes('Returned item') && text.includes(`Quantity: ${quantity}`));
            assert.ok(!text.includes('Kept item') && !text.includes('INTERNAL-NOTE-269') && !text.includes('Damaged'));
            assert.equal(await preview.locator('.sale-receipt-grand-total strong').innerText(), `₱${amount}`);
            for (const field of ['Date/Time:', 'Philippine time', 'Cashier:', 'Register:', 'Reason:', 'CASH']) {
                assert.ok(text.includes(field), `retains ${field}`);
            }
            assert.equal(await preview.getAttribute('data-paper-width-mm'), String(width));
            assert.ok(Math.abs(await preview.evaluate(el => el.getBoundingClientRect().width) - width * 96 / 25.4) < 1);
            await page.waitForFunction(expected => window.printCalls === expected, automaticPrints);
            assert.equal(await page.evaluate(() => window.printCalls), automaticPrints, 'only opted-in completion prints');
            if (automaticPrints) {
                assert.equal(await page.locator('.receipt-print-root .receipt-print-area').getAttribute('data-paper-width-mm'), String(width));
                await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            }
            if (refundIds.length === 1) {
                for (const viewport of [1440, 390]) {
                    await page.setViewportSize({ width: viewport, height: 900 });
                    await page.screenshot({ path: path.join(output, `${width}mm-${viewport}.png`) });
                    assert.equal(await page.locator('#receiptModal').evaluate(el => el.scrollWidth > el.clientWidth + 1), false,
                        'receipt modal fits desktop and mobile');
                }
                await page.setViewportSize({ width: 1280, height: 720 });
            }
            for (let attempt = 1; attempt <= 2; attempt++) {
                await receipt.getByRole('button', { name: 'Print Refund Receipt', exact: true }).click();
                assert.equal(await page.evaluate(() => window.printCalls), automaticPrints + attempt, 'clicking Print invokes the browser print action');
                assert.equal(await page.locator('.receipt-print-root .receipt-print-area')
                    .getAttribute('data-paper-width-mm'), String(width), 'printed copy uses the configured paper width');
                // Browser afterprint fires on dismissal, including cancellation.
                await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
                assert.equal(await receipt.isVisible(), true);
                assert.equal(await page.locator('.receipt-print-root').count(), 0);
            }
            await page.reload();
            assert.equal(await preview.innerText(), text);
            assert.equal(await page.evaluate(() => window.printCalls), 0, 'refresh never auto-prints');
            await page.locator('#receiptModal[open]').waitFor();
            if (refundIds.length === 1) await page.keyboard.press('Escape');
            else await page.getByRole('button', { name: 'Close Refund Receipt', exact: true }).click();
            await page.waitForFunction(() => !document.getElementById('receiptModal').open
                && !new URL(location.href).searchParams.has('refund_id'));
            assert.equal(await page.locator('#sale_id').evaluate(el => document.activeElement === el), true,
                'closing returns focus to refund lookup');
            await page.reload();
            assert.equal(await page.locator('#receiptModal[open]').count(), 0, 'refresh keeps a dismissed receipt closed');
        }
        // A completed refund must still reveal its receipt when a page script fails.
        const failedSaleId = Number(process.argv[6]);
        await page.goto(`${origin}/components/cashier/refunds.php?sale_id=${failedSaleId}`);
        await page.locator('.main-content').waitFor();
        const failedForm = page.locator('.refund-sale .refund-form');
        await failedForm.locator('input[name^="quantity"]').first().fill('1');
        await failedForm.locator('select[name^="disposition"]').first().selectOption('damaged');
        await failedForm.locator('#reason').selectOption('customer_return');
        await page.route('**/assets/js/sale-receipt.js*', route => route.abort());
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        assert.ok(await page.evaluate(() => window.scrollY) > 0, 'refund form is scrolled before submit');
        await failedForm.getByRole('button', { name: 'Record refund', exact: true }).click();
        await page.locator('.rm-modal-overlay[data-rm-confirm] .rm-accept').click();
        await page.waitForURL('**/refunds.php?refund_id=*');
        await page.locator('[data-navigation-status][role="alert"]').waitFor();
        assert.equal(await page.evaluate(() => window.scrollY), 0, 'committed receipt is revealed when a page script fails');
        const failedBox = await receipt.boundingBox();
        assert.ok(failedBox && failedBox.y >= 0 && failedBox.y < await page.evaluate(() => innerHeight),
            'completed receipt stays in view when a page script fails');
        await page.unroute('**/assets/js/sale-receipt.js*');
        // Each receipt names only its own refund and original sale; nothing cumulative.
        for (const [index, text] of receipts.entries()) {
            for (const [other, refundId] of refundIds.entries()) {
                assert.equal(new RegExp(`Refund #${refundId}\\b`).test(text), index === other,
                    `receipt ${refundIds[index]} mentions only its own refund`);
            }
        }
        for (const [index, refundId] of refundIds.entries()) {
            await page.goto(`${origin}/components/cashier/refunds.php`);
            await page.getByRole('link', { name: `Preview Refund Receipt #${refundId}`, exact: true }).click();
            await page.waitForURL(`**/refunds.php?refund_id=${refundId}`);
            assert.equal(await receipt.locator('.receipt-print-area').innerText(), receipts[index]);
            assert.equal(await page.evaluate(() => window.printCalls), 0, 'history never auto-prints');
        }
        assert.equal(posts.length, 4, 'printing, cancellation, refresh and history submit no extra refunds');
        // Submit an exhausted sale directly to exercise server validation beyond browser limits.
        const failed = await page.request.post(`${origin}/components/cashier/refunds.php`, {
            form: { csrf_token: 'fixture-csrf', sale_id: saleId, [`quantity[${saleId}]`]: 1,
                [`disposition[${saleId}]`]: 'damaged', reason: 'customer_return', auto_print_receipt: '1' }, maxRedirects: 0
        });
        assert.equal(failed.status(), 200, 'failed refund does not redirect to a completed receipt');
        await page.setContent(await failed.text());
        assert.equal(await receipt.count(), 0);
        assert.equal(await page.locator('[data-auto-print="1"]').count(), 0, 'failed refund never schedules printing');
        assert.ok((await page.locator('.message.error[role="alert"]').innerText()).includes('already been fully refunded'),
            'failed refund retains its existing validation error');
        console.log(`Refund completion, manual print retry, refresh and history (${width} mm): passed`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exit(1); });
