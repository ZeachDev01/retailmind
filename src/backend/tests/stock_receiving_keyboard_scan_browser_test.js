'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const source = fs.readFileSync('src/frontend/components/report/stock_receiving.php', 'utf8');
const start = source.indexOf('// Keyboard-wedge scanners send a rapid burst');
const script = source.slice(start, source.indexOf('function updatePackageConversion', start));

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent('<button id="idle">Receive</button><input id="search"><input id="quantity" type="number"><textarea id="notes"></textarea><dialog id="help"></dialog>');
        await page.addScriptTag({ content: `
            const canReceive = true; let scans = [];
            async function identifyReceivingProduct(code) { scans.push(code); await new Promise(resolve => setTimeout(resolve, 10)); }
            ${script}
        ` });
        const scan = async (code = '123456', delay = 5) => {
            await page.keyboard.type(code, { delay });
            await page.keyboard.press('Enter');
            await page.evaluate(() => keyboardReceivingQueue);
        };
        await page.focus('#idle');
        await scan();
        await scan();
        assert.deepEqual(await page.evaluate(() => scans), ['123456', '123456'], 'continuous scans are queued');
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => scans.length), 2, 'empty buffer cannot replay');
        await scan('123456', 100);
        assert.equal(await page.evaluate(() => scans.length), 2, 'normal typing is ignored');
        for (const selector of ['#search', '#quantity', '#notes']) {
            await page.focus(selector);
            await scan();
        }
        assert.equal(await page.evaluate(() => scans.length), 2, 'form fields retain normal typing');
        assert.equal(await page.inputValue('#search'), '123456');
        await page.focus('#idle');
        await page.evaluate(() => document.getElementById('help').showModal());
        await scan();
        assert.equal(await page.evaluate(() => scans.length), 2, 'dialogs block scanning');
        console.log('Stock receiving keyboard scanning: passed');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
