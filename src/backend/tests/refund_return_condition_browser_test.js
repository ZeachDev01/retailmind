const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
(async () => {
    const fixture = path.join(os.tmpdir(), `retailmind-return-condition-${process.pid}.json`);
    const output = path.join(os.tmpdir(), 'retailmind-return-condition-browser');
    fs.mkdirSync(output, { recursive: true });
    let browser;
    try {
        execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'cash_refund_concurrency_integration.php')], {
            env: { ...process.env, RUN_REFUND_DB_TESTS: '1', RETURN_CONDITION_BROWSER_FIXTURE: fixture }, stdio: 'pipe', timeout: 120000
        });
        const data = JSON.parse(fs.readFileSync(fixture, 'utf8'));
        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.setContent(`<body class="cashier-refunds-page"><main style="max-width:520px;padding:16px">${data.before}</main>`);
            for (const name of ['style.css', 'refunds.css']) await page.addStyleTag({ path: path.join(__dirname, '../../frontend/assets/css', name) });
            await page.addScriptTag({ path: path.join(__dirname, '../../frontend/assets/js/refund-return-conditions.js') });
            await page.evaluate(() => window.initRefundConditions(document));
            await page.locator('summary').first().click();
            const form = page.locator('.refund-condition-form').first();
            await form.locator('select').selectOption('restockable');
            assert.equal(await form.locator('.refund-condition-preview').innerText(), 'Barnats — Damaged → Restockable. Return 1 piece to available stock.');
            await form.locator('input[name=correction_reason]').fill('Inspected fit for sale');
            assert.equal(await form.evaluate(el => el.checkValidity()), false, 'Physical confirmation is required');
            await form.locator('input[name=fit_for_sale]').check();
            assert.equal(await form.evaluate(el => el.checkValidity()), true);
            assert.equal(await form.locator('input[name=quantity]').count(), 0, 'Returned quantity cannot be edited');
            assert.equal(await form.locator('.refund-condition-preview').evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }), true, 'Preview stays readable on screen');
            await page.screenshot({ path: path.join(output, `preview-${width}.png`), fullPage: true });
            await page.setContent(`<body class="cashier-refunds-page"><main style="max-width:520px;padding:16px">${data.after}</main>`);
            assert.match(await page.innerText('main'), /Edited Cashier/);
            assert.match(await page.innerText('main'), /Inspected whole and fit for sale/);
            assert.match(await page.innerText('main'), /Effective condition: Restockable/);
            await page.screenshot({ path: path.join(output, `history-${width}.png`), fullPage: true });
        }
        console.log(`Return condition signed-in handler desktop/mobile checks passed (${output})`);
    } finally { if (browser) await browser.close(); if (fs.existsSync(fixture)) fs.unlinkSync(fixture); }
})().catch(error => { console.error(error); process.exit(1); });
