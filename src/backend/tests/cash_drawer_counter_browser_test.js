'use strict';
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
    const html = execFileSync(php, ['-r', `
        require 'src/backend/app/Services/CashDenominationCounter.php';
        function generate_csrf_token() { return 'fixture'; }
        $openShift = ['shift_id' => 20]; $unresolvedHeldSales = [];
        require 'src/backend/app/Views/cash_drawer_counter.php';
    `], { encoding: 'utf8' });
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent(`<body class="cashier-shifts-page"><main style="padding:16px;min-width:0"><section class="dashboard-section">${html}</section></main></body>`);
        for (const file of ['style.css', 'cashier-pages.css']) await page.addStyleTag({ path: `src/frontend/assets/css/${file}` });
        await page.addScriptTag({ path: 'src/frontend/assets/js/ui.js' });
        await page.addScriptTag({ path: 'src/frontend/assets/js/cash-drawer-counter.js' });
        assert.equal(await page.locator('.money-counter-row').count(), 13);
        assert.equal(await page.locator('#actual-cash').getAttribute('readonly'), '');
        assert.equal(await page.locator('[data-step="-1"]').first().isDisabled(), true);
        await page.fill('#count-bill_1000', '2');
        await page.fill('#count-bill_500', '3');
        await page.fill('#count-bill_100', '5');
        assert.equal(await page.inputValue('#actual-cash'), '4000.00');
        await page.fill('#count-coin_025', '3');
        await page.fill('#count-coin_005', '2');
        await page.fill('#count-coin_001', '7');
        assert.equal(await page.inputValue('#actual-cash'), '4000.92');
        await page.locator('#count-coin_20').locator('..').locator('[data-step="1"]').click();
        assert.equal(await page.inputValue('#actual-cash'), '4020.92');
        await page.locator('#count-coin_20').locator('..').locator('[data-step="-1"]').click();
        for (const invalid of ['-1', '1.5', '1e2', '1000001', '']) {
            await page.fill('#count-coin_1', invalid);
            assert.equal(await page.locator('#count-coin_1').getAttribute('aria-invalid'), 'true');
            assert.equal(await page.locator('#cash-drawer-counter').evaluate(form => form.checkValidity()), false);
        }
        await page.fill('#count-coin_1', '0');
        assert.equal(await page.inputValue('#actual-cash'), '4000.92');
        for (const width of [1440, 768, 375, 320]) {
            await page.setViewportSize({ width, height: 900 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `no horizontal overflow at ${width}px`);
        }
        const payload = await page.locator('#cash-drawer-counter').evaluate(form => Object.fromEntries(new FormData(form)));
        assert.equal(payload['denominations[bill_1000]'], '2');
        assert.equal(payload.actual_cash, '4000.92');
        assert.equal(payload.shift_id, '20');
        assert.ok(!html.includes('expected_cash'), 'expected balance is absent from count markup');
        await page.click('#reset-cash-count');
        await page.locator('.rm-cancel').click();
        assert.equal(await page.inputValue('#actual-cash'), '4000.92', 'cancel reset preserves count');
        await page.click('#reset-cash-count');
        await page.locator('.rm-accept').last().click();
        await page.waitForFunction(() => document.getElementById('actual-cash').value === '0.00');
        assert.equal(await page.locator('.rm-toast.success').count(), 1);

        // 2. Test Open Shift Denomination Counter
        const openHtml = execFileSync(php, ['-r', `
            require 'src/backend/app/Services/CashDenominationCounter.php';
            function generate_csrf_token() { return 'fixture'; }
            $counterMode = 'open';
            $availableRegisters = [['register_id' => 3, 'name' => 'Express Lane']];
            require 'src/backend/app/Views/cash_drawer_counter.php';
        `], { encoding: 'utf8' });
        const openPage = await browser.newPage();
        await openPage.setContent(`<body class="cashier-shifts-page"><main style="padding:16px;min-width:0"><section class="dashboard-section">${openHtml}</section></main></body>`);
        for (const file of ['style.css', 'cashier-pages.css']) await openPage.addStyleTag({ path: `src/frontend/assets/css/${file}` });
        await openPage.addScriptTag({ path: 'src/frontend/assets/js/ui.js' });
        await openPage.addScriptTag({ path: 'src/frontend/assets/js/cash-drawer-counter.js' });
        assert.equal(await openPage.locator('#open-register').count(), 1, 'register select in open mode');
        await openPage.fill('#count-bill_500', '1');
        await openPage.fill('#count-bill_200', '2');
        assert.equal(await openPage.inputValue('#actual-cash'), '900.00');
        const openPayload = await openPage.locator('#cash-drawer-counter').evaluate(form => Object.fromEntries(new FormData(form)));
        assert.equal(openPayload.action, 'open');
        assert.equal(openPayload.register_id, '3');
        assert.equal(openPayload.opening_float, '900.00');
        await openPage.close();

        // 3. Test Drawer Reconciliation Real-Time Variance & Live Discrepancy Alert
        const reconHtml = execFileSync(php, ['-r', `
            require 'src/backend/app/Services/CashDenominationCounter.php';
            function generate_csrf_token() { return 'fixture'; }
            $openShift = ['shift_id' => 20]; $unresolvedHeldSales = [];
            $expectedCash = 2500.00;
            require 'src/backend/app/Views/cash_drawer_counter.php';
        `], { encoding: 'utf8' });
        const reconPage = await browser.newPage();
        await reconPage.setContent(`<body class="cashier-shifts-page"><main style="padding:16px;min-width:0"><section class="dashboard-section">${reconHtml}</section></main></body>`);
        for (const file of ['style.css', 'cashier-pages.css']) await reconPage.addStyleTag({ path: `src/frontend/assets/css/${file}` });
        await reconPage.addScriptTag({ path: 'src/frontend/assets/js/ui.js' });
        await reconPage.addScriptTag({ path: 'src/frontend/assets/js/cash-drawer-counter.js' });
        assert.equal(await reconPage.locator('#count-expected').innerText(), '₱2,500.00');
        assert.equal(await reconPage.locator('#count-variance').innerText(), '-₱2,500.00 (Deficit)');
        assert.ok(await reconPage.locator('#count-variance').evaluate(el => el.classList.contains('is-shortage')));
        assert.ok(await reconPage.locator('#drawer-variance-alert').isVisible(), 'discrepancy warning alert visible on deficit');
        
        // Count up to exact balanced ₱2,500.00 (2x ₱1000, 1x ₱500)
        await reconPage.fill('#count-bill_1000', '2');
        await reconPage.fill('#count-bill_500', '1');
        assert.equal(await reconPage.inputValue('#actual-cash'), '2500.00');
        assert.equal(await reconPage.locator('#count-variance').innerText(), '₱0.00 (Balanced)');
        assert.ok(await reconPage.locator('#count-variance').evaluate(el => el.classList.contains('is-balanced')));
        assert.ok(!(await reconPage.locator('#drawer-variance-alert').isVisible()), 'discrepancy alert hidden when balanced');

        // Add ₱200 bill -> surplus ₱200.00
        await reconPage.fill('#count-bill_200', '1');
        assert.equal(await reconPage.inputValue('#actual-cash'), '2700.00');
        assert.equal(await reconPage.locator('#count-variance').innerText(), '+₱200.00 (Surplus)');
        assert.ok(await reconPage.locator('#count-variance').evaluate(el => el.classList.contains('is-overage')));
        assert.ok(await reconPage.locator('#drawer-variance-alert').isVisible(), 'discrepancy warning alert visible on surplus');
        await reconPage.close();

        console.log('Cash drawer browser: passed (calculations, validation, reset, payload, responsive layout, open mode, real-time reconciliation variance)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
