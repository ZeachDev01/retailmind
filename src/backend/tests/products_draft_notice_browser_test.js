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

        await page.evaluate(async () => {
            const rawKey = new Uint8Array(32);
            const key = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, ['encrypt']);
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const draft = {id:'entry:', formId:'', values:[], context:{}};
            const ciphertext = await crypto.subtle.encrypt({name:'AES-GCM', iv}, key, new TextEncoder().encode(JSON.stringify(draft)));
            const encode = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));
            localStorage.setItem('retailmind:inventory:v1:1:/products.php:entry:', JSON.stringify({iv:encode(iv), ciphertext:encode(ciphertext)}));
        });
        await page.reload();
        await page.addScriptTag({ content: draftsJs });
        await page.evaluate(() => {
            document.querySelector('main').insertAdjacentHTML('beforeend', '<form method="post"><input name="note"></form><form id="add-product-form" method="post"><input name="product_name"></form>');
            RetailMindInventoryDrafts.start({key:btoa(String.fromCharCode(...new Uint8Array(32))), user:'1', confirmed:false});
            document.dispatchEvent(new Event('DOMContentLoaded'));
        });
        await page.waitForTimeout(100);
        assert.equal(await page.getByText('Unfinished inventory work (entry:).').count(), 0, 'Products does not offer an unnamed form draft');
        await page.locator('[name="product_name"]').fill('Named Product draft');
        await page.waitForFunction(() => Object.keys(localStorage).some(key => key.endsWith(':add-product-form')));
        await page.evaluate(() => localStorage.removeItem('retailmind:inventory:v1:1:/products.php:entry:'));
        await page.locator('[name="note"]').fill('Temporary note');
        await page.waitForTimeout(100);
        assert.equal(await page.evaluate(() => localStorage.getItem('retailmind:inventory:v1:1:/products.php:entry:')), null, 'Products does not create another unnamed form draft');

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

