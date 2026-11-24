'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/assets/js/gcash.js', 'utf8');
    const render = source.slice(source.indexOf('    async function loadHistory()'), source.indexOf("    el('prev').addEventListener"));
    const drawerScript = source.slice(source.indexOf('    function openDetailsDrawer(tx)'), source.indexOf("    el('review-cancel')"));
    const markup = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8').match(/<section class="pos-panel gcash-history"[\s\S]*?<\/section>/)[0];
    const fundsMarkup = [...fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8').matchAll(/<section class="pos-panel gcash-history"[\s\S]*?<\/section>/g)][1][0];
    const fundsScript = source.slice(source.indexOf('    let fundHistoryRequest'), source.indexOf('    tabs.forEach((t, idx)'));
    const drawerMarkup = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8').match(/<dialog id="gcash-details-drawer"[\s\S]*?<\/dialog>/)[0];
    const browser = await chromium.launch({headless: true});
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-history-'));
    try {
        const page = await browser.newPage();
        await page.setContent(`<body class="cashier-page"><main id="gcash-workspace">${markup}\n${fundsMarkup}\n${drawerMarkup}</main></body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) {
            await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        }
        await page.addScriptTag({content: `
            let historyRequest = 0, page = 1, rows = [], leaving = false;
            const el = id => document.getElementById('gcash-' + id);
            const cell = (row, value) => { const td = row.insertCell(); td.textContent = value; return td; };
            const formatPhp = (val, sign = false) => (sign && val > 0 ? '+' : '') + (val < 0 ? '-₱' : '₱') + Math.abs(val).toLocaleString('en-PH', {minimumFractionDigits: 2});
            const notify = message => { throw new Error(message); };
            const request = async () => {
                if (window.requestFailure) throw new Error('Records unavailable. Try again later.');
                return {transactions: window.historyFixtures, movements: window.fundFixtures || [], has_next: false};
            };
            const openCorrectionDialog = tx => {};
            ${render}
            ${fundsScript}
            ${drawerScript}
        `});
        await page.evaluate(async () => {
            window.historyFixtures = [{transaction_id: 24, time_formatted: 'Oct 24, 2026 · 8:17 PM', shift_id: 3243,
                cashier_name: 'Cashier <example>', reference_number: '2647392034309', transaction_type: 'Cash-Out',
                principal_amount: 184, gross_fee: 5, fee_method: 'cash', service_cash_delta: -179, wallet_delta: 184,
                verification_status: 'Completed', session_id: 1, has_receipt: true, notes: 'Checked <reference>'}];
            await loadHistory();
        });
        const row = page.locator('#gcash-history-body tr:not(.table-ghost-row)');
        assert.equal(await row.locator('td').count(), 7);
        assert.equal(await row.locator('td').nth(3).textContent(), '₱184.00');
        assert.equal(await row.locator('td').nth(4).textContent(), '₱5.00');
        assert.equal(await row.locator('small:visible').count(), 0, 'No stacked row subtext');
        assert.equal(await page.locator('#gcash-history-status').isVisible(), false);
        assert.equal(await row.getByRole('button', {name: 'Receipt', exact: true}).count(), 1);
        assert.equal(await row.getByRole('button', {name: 'Correct', exact: true}).count(), 1);
        assert.equal(await row.getByRole('button', {name: 'Details', exact: true}).count(), 1);

        // Click Details button -> Opens Right Drawer (no dropdown below row)
        await row.getByRole('button', {name: 'Details', exact: true}).click();

        const drawer = page.locator('#gcash-details-drawer');
        assert.equal(await drawer.evaluate(d => d.open), true, 'Details click opens the right drawer');
        assert.equal(await page.locator('#gcash-drawer-principal').textContent(), '₱184.00');
        assert.equal(await page.locator('#gcash-drawer-fee').textContent(), '₱5.00');
        assert.equal(await page.locator('#gcash-drawer-ref').textContent(), '2647392034309');
        assert.equal(await page.locator('#gcash-drawer-cashier').textContent(), 'Cashier <example>');
        assert.equal(await page.locator('#gcash-drawer-cash-delta').textContent(), '-₱179.00');
        assert.equal(await page.locator('#gcash-drawer-wallet-delta').textContent(), '+₱184.00');
        assert.equal(await page.locator('#gcash-details-drawer example, #gcash-details-drawer reference').count(), 0, 'Details escape user text');
        await page.screenshot({path: path.join(output, 'drawer-open-1440.png')});
        await page.locator('#gcash-drawer-close').click();
        await page.waitForTimeout(100);
        assert.equal(await drawer.evaluate(d => d.open), false, 'Drawer close button closes the drawer');
        for (const width of [1440, 1024, 390]) {
            await page.setViewportSize({width, height: 900});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Table scroll stays inside page');
            assert.equal(await page.locator('.gcash-history table').first().evaluate(table => getComputedStyle(table).display), 'table', 'One scroll container preserves table layout on mobile');
            assert.equal(await row.locator('td').nth(3).evaluate(cell => getComputedStyle(cell).textAlign), 'right', 'Principal aligns with its column heading');
            const date = await row.locator('td').first().boundingBox();
            const region = await page.locator('.gcash-table-wrap').first().boundingBox();
            assert.ok(date.x >= region.x && date.x + date.width <= region.x + region.width, 'Full date column is visible at the start of history');
            await page.screenshot({path: path.join(output, `history-${width}.png`), fullPage: true});
        }
        await page.evaluate(async () => { window.historyFixtures = []; await loadHistory(); });
        assert.equal(await page.locator('#gcash-history-status').innerText(), 'No transactions on this page.');
        await page.evaluate(() => loadFundMovements());
        for (const theme of ['light', 'dark']) {
            await page.locator('html').evaluate((root, theme) => root.dataset.theme = theme, theme);
            for (const width of [1440, 768, 390]) {
                await page.setViewportSize({width, height: 900});
                for (const kind of ['history', 'funds']) {
                    assert.equal(await page.locator(`#gcash-${kind}-empty`).isVisible(), true);
                    assert.equal(await page.locator(`#gcash-${kind}-empty ~ .gcash-table-wrap`).isVisible(), false);
                    const description = await page.locator(`#gcash-${kind}-empty p`).boundingBox();
                    assert.ok(description.x >= 0 && description.x + description.width <= width, 'Description fits viewport');
                }
                assert.equal(await page.locator('.gcash-pagination').isVisible(), false, 'Empty first page hides pagination');
                await page.screenshot({path: path.join(output, `empty-${theme}-${width}.png`), fullPage: true});
            }
        }
        await page.evaluate(async () => { page = 2; await loadHistory(); });
        assert.equal(await page.locator('#gcash-history-empty-title').textContent(), 'No transactions on this page');
        assert.equal(await page.locator('.gcash-pagination').isVisible(), true, 'Empty later page preserves Previous');
        await page.evaluate(async () => {
            window.fundFixtures = [{created_at: '2026-11-24', movement_type: 'replenishment', fund_type: 'cash', direction: 'in', amount: 100, session_id: 1, recorder_name: 'Test Cashier'}];
            await loadFundMovements();
        });
        assert.equal(await page.locator('#gcash-funds-empty').isVisible(), false);
        assert.equal(await page.locator('#gcash-funds-body tr').count(), 1);
        await page.evaluate(async () => {
            window.requestFailure = true;
            await loadFundMovements();
            try { await loadHistory(); } catch (_) {}
        });
        for (const kind of ['history', 'funds']) {
            assert.equal(await page.locator(`#gcash-${kind}-empty`).isVisible(), false, 'Failure never looks like an empty record set');
            assert.match(await page.locator(`#gcash-${kind}-status`).textContent(), /Records unavailable/);
        }
        console.log('GCash history: passed (columns, disclosure, escaping, actions, empty state, responsive layout)');
        console.log('Screenshots: ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
