'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});
    try {
        const page = await browser.newPage();
        const script = fs.readFileSync('src/frontend/assets/js/inventory-drafts.js', 'utf8');
        await page.route('http://localhost/stock_receiving.php', route => route.fulfill({
            contentType:'text/html', body:'<main><form id="receiving_form"><input name="note"></form></main>'
        }));
        async function open(user, keyByte) {
            await page.goto('http://localhost/stock_receiving.php');
            await page.addScriptTag({content:script});
            await page.evaluate(({user, keyByte}) => {
                RetailMindInventoryDrafts.start({user, key:btoa(String.fromCharCode(...new Uint8Array(32).fill(keyByte)))});
                RetailMindInventoryDrafts.register({eligible:form => form.id === 'receiving_form'});
                document.dispatchEvent(new Event('DOMContentLoaded'));
            }, {user, keyByte});
            // Saving a snapshot waits for initialization and all preceding recovery offers.
            await page.locator('[name="note"]').fill('initialization probe');
            await page.waitForFunction(() => Object.keys(localStorage).some(key => key.endsWith(':receiving_form')));
            await page.evaluate(() => RetailMindInventoryDrafts.flush());
        }
        await open('1', 1);
        await page.evaluate(() => RetailMindInventoryDrafts.discard('receiving_form'));
        await page.evaluate(async () => {
            const encode = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));
            const key = await crypto.subtle.importKey('raw', new Uint8Array(32).fill(2), 'AES-GCM', false, ['encrypt']);
            const iv = new Uint8Array(12);
            const draft = {id:'foreign', formId:'receiving_form', values:[], context:{}};
            const ciphertext = await crypto.subtle.encrypt({name:'AES-GCM', iv}, key, new TextEncoder().encode(JSON.stringify(draft)));
            const stored = JSON.stringify({iv:encode(iv), ciphertext:encode(ciphertext)});
            localStorage.setItem('retailmind:inventory:v1:1:/stock_receiving.php:foreign', stored);
            localStorage.setItem('retailmind:inventory:v1:2:/stock_receiving.php:foreign', stored);
        });
        await open('1', 1);
        assert.equal(await page.locator('#inventory-draft-status[role="alert"]').count(), 0,
            'Unrelated encrypted draft must not show the original-account unlock warning');
        assert.equal(await page.locator('[data-recovery-id="foreign"]').count(), 0,
            'Unrelated draft must not be offered');
        assert.equal(await page.evaluate(() => Object.keys(localStorage).filter(key => key.endsWith(':foreign')).length), 2,
            'Unrelated drafts remain stored for their owners');
        await open('1', 2);
        assert.equal(await page.locator('[data-recovery-id="foreign"]').count(), 1,
            'Original key can still recover its legacy draft');
        console.log('PASS: unrelated drafts stay silent and remain recoverable by their original key');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
