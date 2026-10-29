'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/assets/js/gcash.js', 'utf8');
    const render = source.slice(source.indexOf('    async function loadFollowUp()'), source.indexOf('    async function request('));
    const markup = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8').match(/<section class="pos-panel gcash-follow-up-panel"[\s\S]*?<\/section>/)[0];
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-follow-up-'));
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        await page.setContent(`<body class="cashier-page"><main id="gcash-workspace">${markup}</main></body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        await page.addScriptTag({content: `
            const el = id => document.getElementById('gcash-' + id);
            const request = async () => { if (window.fail) throw new Error(); return window.fixtures || {}; };
            const openResolveDialog = tx => { window.reviewed = tx.transaction_id; };
            ${render}
        `});
        const container = page.locator('#gcash-follow-up');
        assert.equal(await container.innerText(), 'Loading follow-up work…');
        for (const populated of [false, true]) {
            await page.evaluate(async populated => {
                window.fixtures = populated ? {
                    needs_checking: [{transaction_id: 21, transaction_type: 'Cash-In', remaining_cash: '100.00', remaining_wallet: '0.00'}],
                    unassigned_exchanges: [{transaction_id: 22, transaction_type: 'Cash-Out <example>', amount: '50000.00', cashier_id: 3}]
                } : {};
                await loadFollowUp();
            }, populated);
            if (!populated) assert.equal(await container.innerText(), 'No unfinished exchanges requiring follow-up.');
            else {
                assert.equal(await container.locator('p').count(), 2);
                assert.equal(await container.locator('example').count(), 0, 'Transaction text remains escaped');
                await container.getByRole('button', {name: 'Review / record return'}).click();
                assert.equal(await page.evaluate(() => window.reviewed), 21);
            }
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 900});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No page overflow');
                for (const button of await container.getByRole('button').all()) {
                    const bounds = await button.boundingBox();
                    assert.ok(bounds.height >= 44 && bounds.x >= 0 && bounds.x + bounds.width <= width, 'Action fits and remains touch accessible');
                }
                await page.screenshot({path: path.join(output, `follow-up-${populated ? 'populated' : 'empty'}-${width}.png`), fullPage: true});
            }
        }
        await page.evaluate(async () => { window.fail = true; await loadFollowUp(); });
        assert.equal(await container.innerText(), 'Follow-up unavailable. Reload GCash to try again.');
        console.log('GCash follow-up: passed (loading, empty, populated, error, escaping, review action, desktop/mobile layout)');
        console.log('Screenshots: ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
