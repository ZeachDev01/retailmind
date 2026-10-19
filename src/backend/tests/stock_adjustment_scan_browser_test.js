'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
const modal = source.slice(source.indexOf('<div class="rm-modal-overlay" id="stock-adjust-modal"'), source.indexOf('<div class="rm-modal-overlay" id="bulk-category-modal"'));
const rendered = spawnSync('php', [], { encoding: 'utf8', input: `<?php
    function csrf_field() { return ''; }
    $activeProducts = [
        ['product_id'=>1, 'sku'=>'MILK-01', 'barcode'=>'00123456', 'product_name'=>'Milk 1L', 'quantity_on_hand'=>3],
        ['product_id'=>2, 'sku'=>'MILK-02', 'barcode'=>'001234567', 'product_name'=>'Milk 500mL', 'quantity_on_hand'=>0],
        ['product_id'=>3, 'sku'=>'SOAP-01', 'barcode'=>'99887766', 'product_name'=>'Soap & Care', 'quantity_on_hand'=>8]
    ]; if (true): ?>${modal}` });
assert.equal(rendered.status, 0, rendered.stderr);
const script = source.slice(source.indexOf('        const adjustmentModal ='), source.indexOf('        function openModal('));

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const css = ['style', 'theme', 'inventory'].map(name => fs.readFileSync(path.join(frontend, `assets/css/${name}.css`), 'utf8')).join('\n');
        await page.setContent(`<meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}${source.match(/<style>([\s\S]*?)<\/style>/)[1]}</style>${rendered.stdout}`);
        await page.addScriptTag({ path: path.join(frontend, 'assets/js/ui.js') });
        if (script) await page.addScriptTag({ content: script });
        await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('stock-adjust-modal')));
        const search = page.getByLabel('Scan barcode or search products');
        await search.waitFor({ timeout: 1500 });
        assert.equal(await page.locator('#stock-adjust-modal select').count(), 0, 'product search and selection use a single field');
        await search.pressSequentially('00123456', { delay: 5 });
        await search.press('Enter');
        assert.equal(await page.locator('[name=product_id]').inputValue(), '1', 'scan selects exact barcode despite another code sharing its prefix');
        assert.match(await page.locator('#adjust-product-status').textContent(), /Milk 1L/);
        assert.equal(await page.locator('[name="qty_change"]').inputValue(), '', 'scanning never changes stock quantity');
        await search.pressSequentially('99887766', { delay: 5 });
        assert.equal(await page.locator('[name=product_id]').inputValue(), '3', 'next scan replaces the selected code and searches without Enter');
        await page.locator('[name="qty_change"]').fill('5');
        await page.locator('[name="adjustment_reason"]').fill('Verified physical-count correction');
        await page.evaluate(() => {
            window.adjustmentSubmissions = 0;
            document.querySelector('#stock-adjust-modal form').addEventListener('submit', event => {
                event.preventDefault();
                window.adjustmentSubmissions++;
            });
        });
        await search.press('Enter');
        assert.equal(await page.evaluate(() => window.adjustmentSubmissions), 0, 'scanner Enter never submits a valid adjustment');
        await search.fill('  mIlK  ');
        assert.match(await page.locator('#adjust-product-status').textContent(), /2 matching products/, 'name search ignores case and surrounding spaces');
        assert.equal(await page.locator('[name=product_id]').inputValue(), '', 'ambiguous name requires choosing a product');
        const milkLabel = await page.locator('#adjust-product-options option[data-product-id=\"2\"]').getAttribute('value');
        await search.fill(milkLabel);
        assert.equal(await page.locator('[name=product_id]').inputValue(), '2', 'choosing a suggestion selects its product');
        await search.fill('not-a-product');
        assert.equal(await page.locator('[name=product_id]').inputValue(), '', 'unknown scan clears a previous selection');
        assert.match(await page.locator('#adjust-product-status').textContent(), /No matching product/);
        assert.equal(await search.evaluate(input => input.checkValidity()), false);
        await search.fill('milk-02');
        assert.equal(await page.locator('[name=product_id]').inputValue(), '2', 'SKU finds a zero-stock product for adjustment');
        await page.evaluate(() => {
            RetailMindUI.closeOverlay(document.getElementById('stock-adjust-modal'));
            RetailMindUI.openOverlay(document.getElementById('stock-adjust-modal'));
        });
        assert.equal(await search.inputValue(), milkLabel, 'reopening displays the selected product');
        assert.equal(await page.locator('#adjust-product-options option').count(), 3, 'all product suggestions stay available');
        assert.equal(await page.locator('[name=product_id]').inputValue(), '2', 'reopening preserves the selected product');
        await search.fill('milk');
        await page.evaluate(() => RetailMindUI.closeOverlay(document.getElementById('stock-adjust-modal')));
        await page.evaluate(() => document.body.insertAdjacentHTML('beforeend', '<div id="product-drawer" class="rm-drawer-overlay"><section role="dialog"><button id="drawer-adjust-stock">Adjust Stock</button></section></div>'));
        const drawerHandler = source.match(/if \(drawerAdjustButton\) drawerAdjustButton.addEventListener\('click', \(\) => \{([\s\S]*?)\n                \}\);/)[1];
        await page.addScriptTag({ content: `
            document.getElementById('drawer-adjust-stock').addEventListener('click', () => {
                const product = { product_id: 3 };
                function openModal(id) { RetailMindUI.openOverlay(document.getElementById(id)); }
                ${drawerHandler}
            });
            RetailMindUI.openOverlay(document.getElementById('product-drawer'));
        ` });
        await page.getByRole('button', { name: 'Adjust Stock', exact: true }).click();
        assert.equal(await page.locator('[name=product_id]').inputValue(), '3', 'drawer preselection works after searching for a different product');
        await page.waitForFunction(() => getComputedStyle(document.getElementById('product-drawer')).visibility === 'hidden');
        const output = fs.mkdtempSync(path.join(os.tmpdir(), 'retailmind-stock-adjustment-'));
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            const bounds = await search.boundingBox();
            assert(bounds.x >= 0 && bounds.x + bounds.width <= width, `${width}: search fits viewport`);
            await page.screenshot({ path: path.join(output, `${width}.png`) });
        }
        console.log(`Stock adjustment scan/search: passed; screenshots: ${output}`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
