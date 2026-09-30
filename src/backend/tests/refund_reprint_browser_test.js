const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const output = process.env.RECEIPT_BROWSER_OUTPUT || path.join(os.tmpdir(), 'retailmind-refund-reprint-106');
    fs.mkdirSync(output, { recursive: true });
    const fixturePath = path.join(output, 'saved-refund-fixture.json');
    execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'cash_refund_contract.php')], {
        env: { ...process.env, REFUND_REPRINT_FIXTURE: fixturePath }, encoding: 'utf8'
    });
    const fixtures = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
    const cssDirectory = path.join(root, 'src/frontend/assets/css');
    const css = ['style.css', 'cashier-pages.css', 'sale-receipt.css'].map(file =>
        fs.readFileSync(path.join(cssDirectory, file), 'utf8').replace(/@import url\("([^"]+)"\);/g,
            (_, imported) => fs.readFileSync(path.join(cssDirectory, imported), 'utf8'))).join('\n');
    const printScript = fs.readFileSync(path.join(root, 'src/frontend/assets/js/sale-receipt.js'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        for (const width of [80, 58]) {
            const fixture = fixtures[width];
            const page = await browser.newPage();
            const writes = [];
            page.on('request', request => { if (request.method() !== 'GET') writes.push(request.url()); });
            await page.addInitScript(() => { window.printCalls = 0; window.print = () => window.printCalls++; });
            await page.route('http://receipt.test/**', route => {
                const url = new URL(route.request().url());
                const receiptId = Number(url.searchParams.get('refund_id'));
                const receipt = receiptId === fixture.id
                    ? `<section class="dashboard-section receipt-container"><h2>Refund Receipt</h2><button class="btn no-print" type="button" onclick="printReceiptSection(this)">Print Refund Receipt</button>${fixture.receipt}</section>` : '';
                return route.fulfill({ contentType: 'text/html', body: `<html><head><meta charset="UTF-8"><style>${css}</style></head><body><main>${receipt}${fixture.history}</main><script>${printScript}</script></body></html>` });
            });
            await page.goto('http://receipt.test/components/cashier/refunds.php');
            const link = page.getByRole('link', { name: `Preview Refund Receipt #${fixture.id}`, exact: true });
            await link.focus();
            await page.keyboard.press('Enter');
            await page.waitForURL(`**/refunds.php?refund_id=${fixture.id}`);
            assert.equal(await page.evaluate(() => window.printCalls), 0, 'reopening never auto-prints');
            const text = await page.locator('.receipt-print-area').innerText();
            assert.ok(text.includes(`Refund #${fixture.id}`));
            assert.ok(!text.includes('Edited'), 'reprint uses recorded names and footer');
            assert.equal(await page.locator('.sale-receipt-item').count(), 2, 'partial refund contains both returned items');
            for (const viewport of [1440, 390]) {
                await page.setViewportSize({ width: viewport, height: 900 });
                await page.screenshot({ path: path.join(output, `${width}mm-reprint-${viewport}.png`), fullPage: true });
                const metrics = await page.locator('.receipt-print-area').evaluate(el => ({
                    width: el.getBoundingClientRect().width,
                    overflow: Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className)
                }));
                assert.ok(Math.abs(metrics.width - width * 96 / 25.4) < 1);
                assert.deepEqual(metrics.overflow, []);
            }
            await page.getByRole('button', { name: 'Print Refund Receipt' }).click();
            assert.equal(await page.evaluate(() => window.printCalls), 1);
            await page.emulateMedia({ media: 'print' });
            assert.equal(await page.locator('main').isVisible(), false);
            assert.equal(await page.locator('.receipt-print-root button').count(), 0);
            assert.equal(await page.locator('.receipt-print-root').innerText(), text);
            await page.pdf({ path: path.join(output, `${width}mm-partial-refund.pdf`), width: `${width}mm`, height: '297mm', printBackground: true });
            await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            await page.emulateMedia({ media: 'screen' });
            await page.reload();
            assert.equal(await page.locator('.receipt-print-area').innerText(), text);
            assert.equal(await page.evaluate(() => window.printCalls), 0);
            assert.deepEqual(writes, [], 'reopening, printing, and refresh never submit another refund');
            await page.close();
        }
    } finally { await browser.close(); }
    console.log(`Refund Receipt reprint browser checks: passed (${output})`);
})().catch(error => { console.error(error); process.exit(1); });
