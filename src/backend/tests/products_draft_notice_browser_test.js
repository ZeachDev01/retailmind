'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        const draftsJs = fs.readFileSync('src/frontend/assets/js/inventory-drafts.js', 'utf8');

        await page.route('http://localhost/products.php', route => {
            route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!DOCTYPE html><html><body><main id="product-table"><h1>Products</h1></main></body></html>'
            });
        });

        await page.goto('http://localhost/products.php');
        await page.addScriptTag({ content: draftsJs });
        await page.evaluate(async () => {
            window.RetailMindInventoryDrafts.start({
                key: null,
                user: '1',
                confirmed: false
            });
            document.dispatchEvent(new Event('DOMContentLoaded'));
        });

        await page.waitForTimeout(100);
        const status = await page.locator('#inventory-draft-status').count();
        assert.equal(status, 0, 'products.php must not render draft recovery unavailable status');

        // Also test non-products page receives notice when key is null
        await page.route('http://localhost/stock_receiving.php', route => {
            route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!DOCTYPE html><html><body><main id="receiving"><h1>Stock Receiving</h1></main></body></html>'
            });
        });
        await page.goto('http://localhost/stock_receiving.php');
        await page.addScriptTag({ content: draftsJs });
        await page.evaluate(async () => {
            window.RetailMindInventoryDrafts.start({
                key: null,
                user: '1',
                confirmed: false
            });
            document.dispatchEvent(new Event('DOMContentLoaded'));
        });
        await page.waitForTimeout(100);
        const receivingStatus = await page.locator('#inventory-draft-status').innerText();
        assert(receivingStatus.includes('Draft recovery is unavailable'), 'stock_receiving.php shows notice');

        console.log('Products draft notice suppression: passed');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});

