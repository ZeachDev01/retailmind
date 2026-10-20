'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');
const source = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8');
const dialog = source.match(/<dialog id="gcash-open-dialog"[\s\S]*?<\/dialog>/)[0];

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setContent(`<body class="cashier-page">${dialog}</body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        for (const viewport of [{width: 1919, height: 1008}, {width: 768, height: 1024}, {width: 375, height: 667}, {width: 640, height: 360}]) {
            await page.setViewportSize(viewport);
            await page.locator('dialog').evaluate(node => node.showModal());
            const box = await page.locator('dialog').boundingBox();
            const availableWidth = await page.evaluate(() => document.documentElement.clientWidth);
            assert.ok(Math.abs(box.x + box.width / 2 - availableWidth / 2) <= 2, 'GCash dialog is horizontally centered');
            assert.ok(Math.abs(box.y + box.height / 2 - viewport.height / 2) <= 2, 'GCash dialog is vertically centered');
            assert.ok(box.x >= 15 && box.y >= 15 && box.x + box.width <= viewport.width - 15 && box.y + box.height <= viewport.height - 15, 'Dialog stays inside viewport gutters');
            assert.equal(await page.locator('#gcash-open-cash').evaluate(node => node === document.activeElement), true, 'Opening focuses first amount field');
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('dialog').isVisible(), false, 'Escape closes dialog');
        }
        console.log('GCash dialog layout: passed (desktop, tablet, mobile, landscape, focus, Escape)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
