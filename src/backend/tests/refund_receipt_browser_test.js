const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const output = process.env.RECEIPT_BROWSER_OUTPUT || path.join(os.tmpdir(), 'retailmind-refund-receipt-105');
    fs.mkdirSync(output, { recursive: true });
    const css = fs.readFileSync(path.join(root, 'src/frontend/assets/css/sale-receipt.css'), 'utf8');
    const script = fs.readFileSync(path.join(root, 'src/frontend/assets/js/sale-receipt.js'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        const legacy = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'support/refund_receipt_fixture.php'), 'legacy-noncash', '80'], { encoding: 'utf8' });
        assert.ok(legacy.includes('External settlement evidence is unavailable on this receipt.'));
        assert.ok(!legacy.includes('Refund completed externally'), 'Legacy noncash receipt must not invent settlement evidence');
        for (const width of [80, 58]) {
            for (const variant of ['short', 'long']) {
                const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'support/refund_receipt_fixture.php'), variant, String(width)], { encoding: 'utf8' });
                const page = await browser.newPage();
                const writes = [];
                page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
                await page.setContent(`<html><head><meta charset="UTF-8"><style>${css}</style></head><body><main>${html}</main></body></html>`);
                await page.evaluate(() => { window.printCalls = 0; window.print = () => window.printCalls++; });
                await page.addScriptTag({ content: script });
                assert.equal(await page.evaluate(() => window.printCalls), 0, 'successful refund never auto-prints');
                const text = await page.locator('.receipt-print-area').innerText();
                for (const content of ['Refund #105', 'Original Sale Receipt #103', 'Other', 'Total refunded', 'Original payment method']) assert.ok(text.includes(content));
                for (const secret of ['PRIVATE-REFUND-NOTE', 'damaged', 'restockable']) assert.ok(!text.includes(secret));
                for (const viewport of [1440, 390]) {
                    await page.setViewportSize({ width: viewport, height: 900 });
                    await page.screenshot({ path: path.join(output, `${width}mm-${variant}-${viewport}.png`), fullPage: true });
                    const metrics = await page.locator('.receipt-print-area').evaluate(el => ({ width: el.getBoundingClientRect().width,
                        overflow: Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className) }));
                    assert.ok(Math.abs(metrics.width - width * 96 / 25.4) < 1);
                    assert.deepEqual(metrics.overflow, [], 'all preview values fit');
                }
                await page.locator('button').click();
                assert.equal(await page.evaluate(() => window.printCalls), 1);
                assert.equal(await page.locator('.receipt-print-root .receipt-print-area').innerHTML(), await page.locator('main .receipt-print-area').innerHTML());
                await page.emulateMedia({ media: 'print' });
                assert.equal(await page.locator('main').isVisible(), false);
                assert.equal(await page.locator('.receipt-print-root button').count(), 0);
                const overflow = await page.locator('.receipt-print-root .receipt-print-area').evaluate(el =>
                    Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className));
                assert.deepEqual(overflow, [], 'print values fit');
                await page.pdf({ path: path.join(output, `${width}mm-${variant}.pdf`), width: `${width}mm`, height: '297mm', printBackground: true, displayHeaderFooter: false });
                await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
                await page.emulateMedia({ media: 'screen' });
                assert.equal(await page.locator('.receipt-print-root').count(), 0);
                assert.equal(await page.locator('.receipt-print-area').innerText(), text, 'canceled print retains the completed receipt');
                assert.deepEqual(writes, [], 'printing cannot POST a duplicate refund');
                await page.close();
            }
        }
    } finally { await browser.close(); }
    console.log(`Thermal Refund Receipt browser checks: passed (${output})`);
})().catch(error => { console.error(error); process.exit(1); });
