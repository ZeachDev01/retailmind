'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8');
    const markup = source.match(/<main[\s\S]*?<\/main>/)[0].replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        for (const hasOpenShift of [false, true]) {
            await page.setContent(`<body class="cashier-page">${markup}</body>`);
            for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
                await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
            }
            await page.evaluate(hasOpenShift => {
                document.getElementById('gcash-workspace').dataset.api = 'http://localhost/gcash_api.php';
                window.RetailMindUI = {toast: message => { throw new Error(message); }};
                window.fetch = async () => ({ok: true, json: async () => ({
                    success: true, session: null, last_closed_session: null,
                    has_open_shift: hasOpenShift, transactions: [], needs_checking: [], has_next: false
                })});
            }, hasOpenShift);
            await page.addScriptTag({path: 'src/frontend/assets/js/gcash-reference.js'});
            await page.addScriptTag({path: 'src/frontend/assets/js/gcash.js'});
            await page.locator('#gcash-session-status').filter({hasText: 'Closed'}).waitFor();
            const button = page.getByRole('button', {name: 'Open GCash', exact: true});
            assert.equal(await button.isVisible(), true, 'Open GCash stays visible');
            assert.equal(await button.isDisabled(), !hasOpenShift, 'Open GCash requires an open Cashier Shift');
            if (!hasOpenShift) {
                assert.equal(await button.evaluate(node => getComputedStyle(node).opacity), '0.55', 'Disabled button uses existing muted style');
            }
            await button.evaluate(node => node.click());
            assert.equal(await page.locator('#gcash-open-dialog').isVisible(), hasOpenShift, 'Only an open Cashier Shift allows the opening dialog');
        }
        console.log('GCash open shift button: passed (muted, blocked without shift, enabled with shift)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
