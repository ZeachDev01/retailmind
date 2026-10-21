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
        const holdDialog = source.match(/<dialog id="gcash-needs-checking-dialog"[\s\S]*?<\/dialog>/)[0];
        await page.setContent(`<body class="cashier-page">${holdDialog}</body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        for (const viewport of [{width: 1440, height: 900}, {width: 375, height: 667}, {width: 640, height: 360}]) {
            await page.setViewportSize(viewport);
            await page.locator('dialog').evaluate(node => node.showModal());
            const box = await page.locator('dialog').boundingBox();
            assert.ok(box.x >= 15 && box.y >= 15 && box.x + box.width <= viewport.width - 15 && box.y + box.height <= viewport.height - 15, 'Hold dialog stays inside viewport gutters');
            assert.equal(await page.locator('dialog').evaluate(node => node.scrollWidth <= node.clientWidth), true, 'Hold dialog has no horizontal overflow');
            assert.equal(await page.locator('#gcash-nc-reference').evaluate(node => node.checkValidity()), true, 'Unknown reference can stay blank');
            await page.locator('#gcash-nc-notes').fill('');
            assert.equal(await page.locator('#gcash-nc-notes').evaluate(node => node.checkValidity()), false, 'Reason is required');
            await page.locator('#gcash-nc-notes').fill('Cash received, transfer unconfirmed');
            assert.equal(await page.locator('form').evaluate(node => node.checkValidity()), true, 'Hold can record no confirmed movements');
            await page.locator('#gcash-nc-submit').scrollIntoViewIfNeeded();
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('dialog').isVisible(), false, 'Escape closes hold dialog');
        }
        console.log('GCash hold dialog: passed (responsive layout, required reason, optional reference, unconfirmed movements, Escape)');
        const correctionDialog = source.match(/<dialog id="gcash-correction-dialog"[\s\S]*?<\/dialog>/)[0];
        await page.setContent(`<body class="cashier-page">${correctionDialog}</body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        await page.locator('#gcash-correction-summary').evaluate(node => node.textContent = '#8 · Ref: 1234534553456 · Cash-Out · PHP 500.00');
        for (const viewport of [{width: 1440, height: 900}, {width: 375, height: 667}, {width: 640, height: 360}]) {
            await page.setViewportSize(viewport);
            await page.locator('dialog').evaluate(node => node.showModal());
            const box = await page.locator('dialog').boundingBox();
            assert.ok(box.x >= 15 && box.y >= 15 && box.x + box.width <= viewport.width - 15 && box.y + box.height <= viewport.height - 15, 'Correction dialog stays inside viewport gutters');
            assert.equal(await page.locator('dialog').evaluate(node => node.scrollWidth <= node.clientWidth), true, 'Correction dialog has no horizontal overflow');
            await page.locator('#gcash-correction-cash-delta').fill('-10.00');
            await page.locator('#gcash-correction-wallet-delta').fill('10.00');
            assert.equal(await page.locator('#gcash-correction-cash-delta').evaluate(node => node.checkValidity()), true, 'Negative adjustment allowed');
            assert.equal(await page.locator('#gcash-correction-wallet-delta').evaluate(node => node.checkValidity()), true, 'Positive adjustment allowed');
            assert.equal(await page.locator('form').evaluate(node => node.checkValidity()), false, 'Reason and administrator credentials required');
            await page.locator('#gcash-correction-submit').scrollIntoViewIfNeeded();
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('dialog').isVisible(), false, 'Escape closes correction dialog');
        }
        console.log('GCash correction dialog: passed (responsive layout, signed amounts, required fields, Escape)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
