const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        await page.setContent(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'support/drawer_movement_fixture.php')], { encoding: 'utf8' }));
        const root = path.resolve(__dirname, '../../..');
        await page.addStyleTag({ content: fs.readFileSync(path.join(root, 'src/frontend/assets/css/cashier-pages.css'), 'utf8') });
        assert.match(await page.locator('body').innerText(), /Expected cash is a ledger estimate; check physical funds/);
        assert.match(await page.locator('body').innerText(), /never record a second drawer movement for a refund/);
        assert.match(await page.getByRole('link', { name: 'Cash Refunds', exact: true }).getAttribute('href'), /cashier\/refunds.php$/);
        await page.locator('#movement-type').selectOption('cash_out');
        assert.equal(await page.locator('#movement-reason').inputValue(), 'petty_cash');
        assert.equal(await page.locator('#movement-approver').evaluate(el => el.required), true);
        await page.locator('#movement-reason').selectOption('supplier_payment');
        await page.locator('#movement-amount').fill('10.25');
        await page.locator('#movement-approver').fill('owner');
        await page.locator('#movement-approval-password').fill('fixture-password');
        const fields = await page.locator('#movement-type').evaluate(el => Object.fromEntries(new FormData(el.form)));
        assert.deepEqual(fields, { csrf_token:'fixture-csrf', action:'movement', movement_type:'cash_out', amount:'10.25', reason:'supplier_payment', note:'', approver_username:'owner', approver_password:'fixture-password' });
        const otherIndex = await page.locator('#movement-reason').evaluate(el => [...el.options].findIndex(option => option.value === 'other' && !option.disabled));
        await page.locator('#movement-reason').selectOption({ index: otherIndex });
        assert.equal(await page.locator('#movement-note').evaluate(el => el.required), true);
        assert.equal(await page.locator('#movement-approver').evaluate(el => el.required), false);
        await page.locator('#movement-type').selectOption('safe_drop');
        assert.equal(await page.locator('#movement-reason').inputValue(), 'excess_cash');
        assert.equal(await page.locator('#movement-note').evaluate(el => el.required), false);
        console.log('Drawer movement browser: passed (guidance, reasons, authorization fields and bound form)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
