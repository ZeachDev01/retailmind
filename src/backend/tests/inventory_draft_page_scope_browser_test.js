'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});
    try {
        const page = await browser.newPage();
        const script = fs.readFileSync('src/frontend/assets/js/inventory-drafts.js', 'utf8');
        await page.route('http://localhost/**', route => route.fulfill({contentType:'text/html', body:'<main><form id="add-product-form"><input name="product_name"></form><form id="receiving_form"><input name="supplier"></form></main>'}));
        async function open(path) {
            await page.goto('http://localhost/' + path);
            await page.addScriptTag({content:script});
            await page.evaluate(async () => {
                RetailMindInventoryDrafts.start({user:'1', key:btoa(String.fromCharCode(...new Uint8Array(32)))});
                RetailMindInventoryDrafts.register({eligible:form => form.matches('#add-product-form, #receiving_form')});
                document.dispatchEvent(new Event('DOMContentLoaded'));
                // restore waits for initialization, including all recovery offers.
                await RetailMindInventoryDrafts.restore('initialization-probe');
            });
        }
        await open('stock_issues.php');
        await page.evaluate(() => {
            localStorage.setItem('retail_mind_add_product_state', '{"product_name":"Older product"}');
            localStorage.setItem('retail_mind_add_product_step', '2');
        });
        await open('stock_issues.php');
        assert.equal(await page.locator('[data-legacy-product-draft]').count(), 0,
            'Stock Issues must not offer disposal of an older Product draft');
        await open('products.php');
        await page.locator('[name="product_name"]').fill('Unfinished product');
        await page.evaluate(() => RetailMindInventoryDrafts.flush());
        await open('stock_receiving.php');
        await page.locator('[name="supplier"]').fill('Unfinished receiving');
        await page.evaluate(() => RetailMindInventoryDrafts.flush());
        await open('stock_issues.php');
        assert.equal(await page.locator('[data-recovery-id], [data-legacy-product-draft]').count(), 0,
            'Stock Issues must not offer Product or Receiving draft actions');
        await open('stock_receiving.php');
        assert.equal(await page.locator('[data-recovery-id="receiving_form"]').count(), 1, 'Receiving offers its own draft');
        assert.equal(await page.locator('[data-legacy-product-draft]').count(), 0, 'Receiving must not offer an older Product draft');
        await open('products.php');
        assert.equal(await page.locator('[data-recovery-id="add-product-form"]').count(), 1, 'Products offers its own draft');
        assert.equal(await page.locator('[data-legacy-product-draft]').count(), 1, 'Products still offers explicit disposal of its older draft');
        assert.equal(await page.evaluate(() => Object.keys(localStorage).filter(key => key.startsWith('retailmind:inventory:v1:')).length), 2, 'Page changes preserve both encrypted drafts');
        console.log('PASS: Product and Receiving recovery actions stay on their respective pages');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
