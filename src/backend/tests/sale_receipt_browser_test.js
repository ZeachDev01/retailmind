const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
let chromium;
try { ({ chromium } = require('playwright')); } catch (error) {
    console.log('Thermal Sale Receipt browser checks: skipped (install npm dependencies and Chromium)');
    process.exit(0);
}
if (!fs.existsSync(chromium.executablePath())) {
    console.log('Thermal Sale Receipt browser checks: skipped (run npm run playwright:install)');
    process.exit(0);
}

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const output = process.env.RECEIPT_BROWSER_OUTPUT || path.join(os.tmpdir(), 'retailmind-receipt-103');
    fs.mkdirSync(output, { recursive: true });
    const css = ['global', 'invoices', 'responsive', 'sale-receipt'].map(name =>
        fs.readFileSync(path.join(root, `src/frontend/assets/css/${name}.css`), 'utf8')).join('\n');
    const script = fs.readFileSync(path.join(root, 'src/frontend/assets/js/sale-receipt.js'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        for (const variant of ['short', 'long']) {
            const html = execFileSync(process.env.PHP_BINARY || 'php',
                [path.join(__dirname, 'support/sale_receipt_fixture.php'), variant], { encoding: 'utf8' });
            const page = await browser.newPage();
            await page.setContent(`<html><head><meta charset="UTF-8"><style>${css}</style></head><body><div class="app-shell"><div class="main-content">Application framing${html}</div></div></body></html>`);
            await page.evaluate(() => { window.printCalls = 0; window.print = () => { window.printCalls++; }; });
            await page.addScriptTag({ content: script });
            assert.equal(await page.evaluate(() => window.printCalls), 0, 'loading completed receipt never prints');
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 900 });
                // Incumbent shell mobile margins are absent in this fixture.
                await page.addStyleTag({ content: '.main-content { margin: 0; padding: 16px; }' });
                await page.screenshot({ path: path.join(output, `${variant}-${width}.png`), fullPage: true });
                const metrics = await page.locator('.sale-receipt').evaluate(el => ({
                    width: el.getBoundingClientRect().width,
                    overflow: Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className)
                }));
                assert.ok(Math.abs(metrics.width - 80 * 96 / 25.4) < 1, 'preview measures 80mm');
                assert.deepEqual(metrics.overflow, [], 'receipt copy and numbers fit without horizontal clipping');
            }
            await page.locator('button').click();
            assert.equal(await page.evaluate(() => window.printCalls), 1, 'only deliberate action prints');
            assert.equal(await page.locator('.receipt-print-root .sale-receipt').count(), 1);
            await page.emulateMedia({ media: 'print' });
            assert.equal(await page.locator('.app-shell').isVisible(), false, 'application frame is excluded');
            assert.equal(await page.locator('.receipt-print-root button').count(), 0, 'controls are excluded');
            assert.equal(await page.locator('.receipt-print-root .sale-receipt').isVisible(), true);
            const printOverflow = await page.locator('.receipt-print-root .sale-receipt').evaluate(el =>
                Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className));
            assert.deepEqual(printOverflow, [], 'print references and amounts have no horizontal clipping');
            // Chromium print-to-PDF executes the browser pagination/layout engine.
            await page.pdf({ path: path.join(output, `${variant}.pdf`), width: '80mm', height: '297mm', printBackground: true, displayHeaderFooter: false });
            await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            assert.equal(await page.locator('.receipt-print-root').count(), 0, 'print cancellation cleans only print copy');
            await page.emulateMedia({ media: 'screen' });
            assert.equal(await page.locator('.sale-receipt').count(), 1, 'completed receipt survives cancellation');
            assert.ok((await page.locator('.sale-receipt').innerText()).includes('Receipt #103'));
            // Another receipt on the page must not be selected by a modal's action.
            await page.evaluate(() => {
                const modal = document.createElement('div');
                modal.className = 'modal active';
                modal.innerHTML = '<div class="modal-content">' + document.querySelector('.receipt-container').outerHTML + '</div>';
                document.body.appendChild(modal);
                modal.querySelector('.sale-receipt-code').textContent = 'MODAL-SELECTED';
                modal.querySelector('button').click();
            });
            assert.ok((await page.locator('.receipt-print-root').innerText()).includes('MODAL-SELECTED'), 'modal prints its selected receipt');
            assert.equal(await page.locator('.receipt-print-root .sale-receipt').count(), 1);
            await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
            await page.close();
        }
        console.log(`Thermal Sale Receipt browser checks: passed; screen captures and Chromium print PDFs: ${output}`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
