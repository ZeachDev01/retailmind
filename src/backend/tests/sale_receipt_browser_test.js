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
    const output = process.env.RECEIPT_BROWSER_OUTPUT || path.join(os.tmpdir(), 'retailmind-receipt-104');
    fs.mkdirSync(output, { recursive: true });
    const css = ['global', 'invoices', 'responsive', 'sale-receipt'].map(name =>
        fs.readFileSync(path.join(root, `src/frontend/assets/css/${name}.css`), 'utf8')).join('\n');
    const themeCss = fs.readFileSync(path.join(root, 'src/frontend/assets/css/theme.css'), 'utf8');
    const script = fs.readFileSync(path.join(root, 'src/frontend/assets/js/sale-receipt.js'), 'utf8');
    const browser = await chromium.launch({ headless: true });
    try {
        const adminHtml = execFileSync(process.env.PHP_BINARY || 'php',
            [path.join(__dirname, 'support/register_paper_fixture.php')], { encoding: 'utf8' });
        const adminCss = ['style', 'global', 'forms', 'tables', 'admin', 'responsive'].map(name =>
            fs.readFileSync(path.join(root, `src/frontend/assets/css/${name}.css`), 'utf8')).join('\n');
        const adminPage = await browser.newPage();
        await adminPage.setContent(adminHtml);
        await adminPage.addStyleTag({ content: adminCss + '\n.main-content { margin: 0; padding: 16px; }' });
        assert.equal(await adminPage.locator('#register-paper-width').inputValue(), '80', 'creation defaults to 80 mm');
        assert.deepEqual(await adminPage.locator('tbody select').evaluateAll(els => els.map(el => el.value)), ['80', '58'], 'both saved widths appear alongside Register details');
        await adminPage.locator('#register-paper-width').selectOption('58');
        assert.equal(await adminPage.locator('#register-paper-width').inputValue(), '58');
        const edit = adminPage.locator('tbody form').filter({ has: adminPage.locator('select') }).first();
        await edit.locator('select').selectOption('58');
        assert.deepEqual(await edit.evaluate(form => Object.fromEntries(new FormData(form))),
            { csrf_token: 'synthetic', action: 'set_paper_width', register_id: '1', paper_width_mm: '58' }, 'editing posts width, identity, action and CSRF token');
        for (const width of [1440, 390]) {
            await adminPage.setViewportSize({ width, height: 900 });
            await adminPage.screenshot({ path: path.join(output, `registers-${width}.png`), fullPage: true });
        }
        await adminPage.close();
        // Exercise both retained legacy search entry points with the shipped helper.
        const legacySource = fs.readFileSync(path.join(root, 'src/backend/legacy/routes/invoice/receipt.php'), 'utf8');
        const searchHelper = legacySource.match(/function performSearch\(\) \{[\s\S]*?\n\}/)?.[0];
        const enterHandler = legacySource.match(/document.getElementById\('search-input'\).addEventListener\('keypress',[\s\S]*?\n\}\);/)?.[0];
        assert.ok(searchHelper && enterHandler, 'legacy search helper and Enter handler remain available');
        const searchPage = await browser.newPage();
        await searchPage.route('https://example.test/**', route => route.fulfill({
            contentType: 'text/html', body: '<input id="search-input"><select id="search-field"><option value="cashier">Cashier</option></select><button onclick="performSearch()">Search</button>'
        }));
        for (const action of ['button', 'Enter']) {
            await searchPage.goto('https://example.test/receipt.php?keep=original');
            await searchPage.addScriptTag({ content: searchHelper + '\n' + enterHandler });
            await searchPage.locator('#search-input').fill('Alice & Co');
            const navigation = searchPage.waitForURL(url => url.searchParams.get('search') === 'Alice & Co');
            if (action === 'button') await searchPage.locator('button').click();
            else await searchPage.locator('#search-input').press('Enter');
            await navigation;
            const destination = new URL(searchPage.url());
            assert.equal(destination.searchParams.get('field'), 'cashier');
            assert.equal(destination.searchParams.get('keep'), 'original');
        }
        await searchPage.close();
        for (const paperWidthMm of [80, 58]) {
            for (const variant of ['short', 'long']) {
                const html = execFileSync(process.env.PHP_BINARY || 'php',
                    [path.join(__dirname, 'support/sale_receipt_fixture.php'), variant, String(paperWidthMm)], { encoding: 'utf8' });
                const page = await browser.newPage();
                await page.setContent(`<html data-theme="dark"><head><meta charset="UTF-8"><style>${css}</style><style>${themeCss}</style></head><body><div class="app-shell"><div class="main-content">Application framing${html}</div></div></body></html>`);
                await page.evaluate(() => { window.printCalls = 0; window.print = () => { window.printCalls++; }; });
                await page.addScriptTag({ content: script });
                assert.equal(await page.evaluate(() => window.printCalls), 0, 'loading completed receipt never prints');
                for (const width of [1440, 390]) {
                    await page.setViewportSize({ width, height: 900 });
                    // Incumbent shell mobile margins are absent in this fixture.
                    await page.addStyleTag({ content: '.main-content { margin: 0; padding: 16px; }' });
                    await page.screenshot({ path: path.join(output, `${paperWidthMm}mm-${variant}-${width}.png`), fullPage: true });
                    const metrics = await page.locator('.sale-receipt').evaluate(el => ({
                        width: el.getBoundingClientRect().width,
                        overflow: Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className)
                    }));
                    assert.ok(Math.abs(metrics.width - paperWidthMm * 96 / 25.4) < 1, 'preview measures selected paper width');
                    assert.deepEqual(metrics.overflow, [], 'receipt copy and numbers fit without horizontal clipping');
                }
                await page.locator('button').click();
                assert.equal(await page.evaluate(() => window.printCalls), 1, 'only deliberate action prints');
                assert.equal(await page.locator('.receipt-print-root .sale-receipt').count(), 1);
                await page.emulateMedia({ media: 'print' });
                assert.equal(await page.locator('.receipt-print-root .receipt-print-area').evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)', 'Thermal paper remains light');
                assert.equal(await page.locator('.app-shell').isVisible(), false, 'application frame is excluded');
                assert.equal(await page.locator('.receipt-print-root button').count(), 0, 'controls are excluded');
                assert.equal(await page.locator('.receipt-print-root .sale-receipt').isVisible(), true);
                assert.ok(Math.abs(await page.locator('.receipt-print-root .sale-receipt').evaluate(el => el.getBoundingClientRect().width) - paperWidthMm * 96 / 25.4) < 1, 'print copy retains selected paper width');
                const printOverflow = await page.locator('.receipt-print-root .sale-receipt').evaluate(el =>
                    Array.from(el.querySelectorAll('*')).filter(child => child.scrollWidth > child.clientWidth + 1).map(child => child.className));
                assert.deepEqual(printOverflow, [], 'print references and amounts have no horizontal clipping');
                // Chromium print-to-PDF executes the browser pagination/layout engine.
                await page.pdf({ path: path.join(output, `${paperWidthMm}mm-${variant}.pdf`), width: `${paperWidthMm}mm`, height: '297mm', printBackground: true, displayHeaderFooter: false });
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
        }
        console.log(`Thermal Sale Receipt browser checks: passed; screen captures and Chromium print PDFs: ${output}`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
