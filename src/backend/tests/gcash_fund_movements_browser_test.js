'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const source = fs.readFileSync('src/frontend/components/cashier/gcash.php', 'utf8');
    const markup = source.match(/<main[\s\S]*?<\/main>/)[0].replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<body class="cashier-page">${markup}</body>`);
        for (const file of ['style.css', 'cashier.css', 'gcash.css']) await page.addStyleTag({path: `src/frontend/assets/css/${file}`});
        await page.evaluate(() => {
            document.getElementById('gcash-workspace').dataset.api = 'http://localhost/gcash_api.php';
            window.RetailMindUI = {toast() {}};
            window.movements = [{id: 4, created_at: '2026-11-11 19:05:16', movement_type: 'replenishment', fund_type: 'cash', direction: 'in', amount: 1000, session_id: 2, recorder_name: 'Cashier', authorizer_name: 'Admin', evidence: '', reason: 'From: Store safe <test>'}];
            window.requests = [];
            window.failMovements = false;
            window.fetch = async (url, options) => {
                const action = new URL(url).searchParams.get('action');
                if (options.body) {
                    const body = JSON.parse(options.body);
                    window.requests.push(body);
                    window.movements.unshift({...window.movements[0], id: 5, amount: Number(body.amount)});
                }
                return {ok: true, json: async () => ({
                    success: !(action === 'fund_movements' && window.failMovements), message: 'Movements unavailable',
                    movements: window.movements, movement_id: 5,
                    session: {is_controlling_cashier: true, opening_service_cash: 1000, opening_wallet_balance: 5000, expected_service_cash: 1000, expected_wallet_balance: 5000, opened_at: '2026-11-11 18:00:00'},
                    has_open_shift: true, transactions: [], needs_checking: [], has_next: false
                })};
            };
        });
        for (const script of ['gcash-reference.js', 'gcash.js']) await page.addScriptTag({path: `src/frontend/assets/js/${script}`});
        const fundsTab = page.getByRole('tab', {name: 'Service Fund Movements', exact: true});
        assert.equal(await fundsTab.count(), 1, 'Cashier has a dedicated fund movements tab');
        await page.getByRole('tab', {name: 'History', exact: true}).focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await fundsTab.getAttribute('aria-selected'), 'true');
        await page.locator('#gcash-funds-body tr').waitFor();
        assert.match(await page.locator('#gcash-funds-body').textContent(), /Store safe <test>/, 'History retains and escapes source details');
        assert.equal(await page.locator('#gcash-funds-body test').count(), 0);
        await page.keyboard.press('Home');
        assert.equal(await page.locator('#tab-new-transaction').getAttribute('aria-selected'), 'true');
        await page.locator('#gcash-funding-btn').click();
        assert.equal(await page.getByLabel('Reason (optional)', {exact: true}).getAttribute('required'), null);
        assert.equal(await page.getByLabel('Cash source', {exact: true}).getAttribute('required'), '');
        await page.locator('#gcash-funding-fund-type').selectOption('wallet');
        assert.equal(await page.locator('#gcash-funding-evidence').getAttribute('required'), '');
        await page.locator('#gcash-funding-fund-type').selectOption('cash');
        await page.locator('#gcash-funding-type').selectOption('product_drawer_transfer');
        assert.equal(await page.locator('#gcash-funding-dialog').getByLabel('Reason', {exact: true}).getAttribute('required'), '');
        await page.locator('#gcash-funding-type').selectOption('replenishment');
        await page.getByLabel('Cash source', {exact: true}).fill('Store safe');
        await page.locator('#gcash-funding-amount').fill('100');
        await page.locator('#gcash-funding-approver').fill('admin');
        await page.locator('#gcash-funding-password').fill('test-password');
        await page.locator('#gcash-funding-receipt').check();
        await page.locator('#gcash-funding-submit').click();
        await page.locator('#gcash-funding-dialog').waitFor({state: 'hidden'});
        await fundsTab.click();
        await page.waitForFunction(() => document.querySelectorAll('#gcash-funds-body tr').length === 2);
        assert.equal(await page.evaluate(() => window.requests[0].source_destination), 'Store safe');
        await page.evaluate(() => { window.movements = []; });
        await page.getByRole('tab', {name: 'History', exact: true}).click();
        await fundsTab.click();
        await page.getByText('No service fund movements recorded yet.', {exact: true}).waitFor();
        await page.evaluate(() => { window.failMovements = true; });
        await page.getByRole('tab', {name: 'History', exact: true}).click();
        await fundsTab.click();
        await page.getByText('Movements unavailable', {exact: true}).waitFor();
        assert.equal(await page.locator('#gcash-funds-body tr').count(), 0, 'Load failure does not show stale rows');
        assert.deepEqual(errors, []);
        console.log('GCash fund movements browser: passed (history, keyboard, form rules, save refresh, empty and error states)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
