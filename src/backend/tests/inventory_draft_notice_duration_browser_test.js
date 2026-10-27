'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        const draftsJs = fs.readFileSync('src/frontend/assets/js/inventory-drafts.js', 'utf8');

        await page.route('http://localhost/stock_receiving.php', route => {
            route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!DOCTYPE html><html><body><main id="receiving"><form id="receiving_form" method="post"><input name="item_name" value="test"></form></main></body></html>'
            });
        });

        await page.goto('http://localhost/stock_receiving.php');
        await page.addScriptTag({ content: draftsJs });

        // Initialize drafts with a valid key
        const rawKey = new Uint8Array(32);
        const encodedKey = btoa(String.fromCharCode(...rawKey));
        await page.evaluate(key => {
            window.RetailMindInventoryDrafts.start({
                key,
                user: '1',
                confirmed: false
            });
            document.dispatchEvent(new Event('DOMContentLoaded'));
        }, encodedKey);

        await page.waitForTimeout(100);

        // 1. Trigger non-error status: "Unsaved values kept on this device..." via input
        await page.locator('[name="item_name"]').fill('updated');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Unsaved values kept on this device'));
        
        assert.equal(await page.locator('#inventory-draft-status').count(), 1, 'Status notice must be present initially');

        // Wait ~4.2 seconds to verify auto-removal
        await page.waitForTimeout(4200);
        assert.equal(await page.locator('#inventory-draft-status').count(), 0, 'Status notice must be removed after ~4 seconds');

        // 2. Trigger "Draft restored. Review items and quantities before saving." and verify auto-removal
        await page.evaluate(async () => {
            const rawKey = new Uint8Array(32);
            const key = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, ['encrypt']);
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const draft = {
                id: 'receiving_form',
                formId: 'receiving_form',
                values: [{ name: 'item_name', value: 'restored-val' }],
                context: {}
            };
            const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(JSON.stringify(draft)));
            const encode = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));
            localStorage.setItem('retailmind:inventory:v1:1:/stock_receiving.php:receiving_form', JSON.stringify({ iv: encode(iv), ciphertext: encode(ciphertext) }));
        });

        await page.reload();
        await page.addScriptTag({ content: draftsJs });
        await page.evaluate(key => {
            window.RetailMindInventoryDrafts.start({
                key,
                user: '1',
                confirmed: false
            });
            document.dispatchEvent(new Event('DOMContentLoaded'));
        }, encodedKey);

        await page.waitForFunction(() => document.querySelector('.inventory-recovery-alert'));
        await page.locator('.inventory-recovery-alert button:has-text("Resume")').click();

        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Draft restored. Review items and quantities before saving.'));
        assert.equal(await page.locator('#inventory-draft-status').count(), 1, 'Draft restored notice must be visible');

        await page.waitForTimeout(4200);
        assert.equal(await page.locator('#inventory-draft-status').count(), 0, 'Draft restored notice must auto-remove after ~4 seconds');

        // 3. Verify error alerts do NOT auto-remove (sticky until dismissed)
        await page.reload();
        await page.addScriptTag({ content: draftsJs });
        await page.evaluate(() => {
            window.RetailMindInventoryDrafts.start({
                key: null,
                user: '1',
                confirmed: false
            });
            document.dispatchEvent(new Event('DOMContentLoaded'));
        });

        await page.waitForFunction(() => document.querySelector('#inventory-draft-status[role="alert"]'));
        await page.waitForTimeout(4200);
        assert.equal(await page.locator('#inventory-draft-status[role="alert"]').count(), 1, 'Error alert notice must remain visible and not auto-remove');

        console.log('Inventory draft notice duration tests: passed');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
