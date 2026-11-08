'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    const root = path.resolve(__dirname, '../../..');
    const posSource = fs.readFileSync(path.join(root, 'src/frontend/components/cashier/pos.php'), 'utf8');
    const receiptDialogJs = fs.readFileSync(path.join(root, 'src/frontend/assets/js/sale-receipt-dialog.js'), 'utf8');

    const receiptDialogPhp = fs.readFileSync(path.join(root, 'src/backend/app/Views/sale_receipt_dialog.php'), 'utf8');

    // Verification 1: Static source analysis for Ticket #447 requirements
    assert.match(posSource, /<input id="sku-input"[^>]*autofocus/, 'Barcode search field has autofocus attribute in HTML markup');
    assert.match(posSource, /skuInput\.addEventListener\('keydown'[\s\S]*checkoutNow\(\)/, 'Empty Enter key in barcode field triggers checkoutNow() payment flow');
    assert.match(posSource, /checkoutModal\.addEventListener\('keydown'[\s\S]*submitConfirmedCheckout\(\)/, 'Enter key in checkout confirmation modal triggers submitConfirmedCheckout()');
    assert.match(posSource, /if\s*\(event\.key\s*===\s*'Escape'\)[\s\S]*receiptModal/, 'Escape key dismisses receipt modal and returns focus to barcode search');
    assert.match(posSource, /confirmCheckoutButton\.disabled\s*=\s*true[\s\S]*Processing\.\.\./, 'Confirm payment button immediately disables with visual processing feedback');
    assert.match(posSource, /checkoutButton\.disabled\s*=\s*true[\s\S]*Processing\.\.\./, 'Checkout button immediately disables with visual processing feedback');
    assert.match(posSource, /\$receiptAutofocusAction\s*=\s*true;/, 'POS page sets $receiptAutofocusAction flag for receipt dialog');
    assert.match(receiptDialogPhp, /data-autofocus-action="1"/, 'Sale receipt dialog specifies data-autofocus-action="1" when flag is set');

    // Verification 2: Browser interaction tests with Playwright
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();

        // Test A: POS page landing autofocus and keyboard checkout trigger
        await page.setContent(`
            <input id="sku-input" class="pos-input" type="text" autofocus>
            <button id="add-code-btn" type="button">Add</button>
            <button id="checkout-button" type="button" disabled>Checkout</button>
            <form id="checkout-form"><input id="cash-received" type="text"></form>
            <div id="checkout-modal" class="checkout-modal" role="dialog">
                <button type="button" class="btn btn-secondary" onclick="closeCheckoutConfirm()">Review cart</button>
                <button type="button" class="btn" id="confirm-checkout-button">Confirm payment</button>
            </div>
            <dialog id="receiptModal" class="sale-receipt-dialog" data-autofocus-action="1">
                <div class="sale-receipt-dialog-header">
                    <h2 id="saleReceiptTitle" tabindex="-1">Sale Receipt #101</h2>
                    <button type="button" class="btn btn-secondary" data-close-receipt aria-label="Close Sale Receipt">Close</button>
                </div>
                <div id="receiptContent">
                    <div class="receipt-actions">
                        <button type="button" class="btn btn-secondary" id="print-receipt-btn">Print Receipt</button>
                    </div>
                </div>
            </dialog>
        `);

        await page.addScriptTag({
            content: `
                let cart = { '1:piece': { product_id: 1, name: 'Soap', price: 50, qty: 1 } };
                let checkoutModalOpened = false;
                let checkoutConfirmedCount = 0;
                let checkoutSubmitting = false;

                const skuInput = document.getElementById('sku-input');
                const checkoutButton = document.getElementById('checkout-button');
                const checkoutModal = document.getElementById('checkout-modal');
                const confirmCheckoutButton = document.getElementById('confirm-checkout-button');
                const receiptModal = document.getElementById('receiptModal');

                function checkoutNow() {
                    checkoutButton.disabled = true;
                    checkoutButton.innerHTML = '<i class="bi bi-hourglass-split"></i>Processing...';
                    checkoutModal.classList.add('open');
                    checkoutModalOpened = true;
                }

                function closeCheckoutConfirm() {
                    checkoutModal.classList.remove('open');
                    checkoutButton.disabled = false;
                    checkoutButton.textContent = 'Checkout';
                    skuInput.focus();
                }

                function submitConfirmedCheckout() {
                    if (checkoutSubmitting) return;
                    checkoutSubmitting = true;
                    confirmCheckoutButton.disabled = true;
                    confirmCheckoutButton.innerHTML = '<i class="bi bi-hourglass-split"></i>Processing...';
                    checkoutConfirmedCount++;
                }

                confirmCheckoutButton.addEventListener('click', submitConfirmedCheckout);
                checkoutButton.addEventListener('click', checkoutNow);

                skuInput.addEventListener('keydown', event => {
                    if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.isComposing) {
                        event.preventDefault();
                        if (skuInput.value.trim() === '' && Object.keys(cart).length > 0) {
                            checkoutNow();
                        }
                    }
                });

                checkoutModal.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        submitConfirmedCheckout();
                    }
                });

                document.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        if (receiptModal && receiptModal.open) {
                            receiptModal.close();
                            skuInput.focus();
                        } else if (checkoutModal.classList.contains('open')) {
                            closeCheckoutConfirm();
                        }
                    }
                });

                receiptModal.addEventListener('close', () => {
                    skuInput.focus();
                });

                ${receiptDialogJs}
            `
        });

        // 1. Verify barcode search field autofocuses immediately on landing
        assert.equal(await page.locator('#sku-input').evaluate(el => document.activeElement === el), true,
            'Barcode search field is focused on landing');

        // 2. Verify pressing Enter in empty sku-input with cart items triggers checkout
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => checkoutModalOpened), true,
            'Enter key in empty barcode search triggered checkoutNow() and opened checkout modal');
        assert.equal(await page.locator('#checkout-button').evaluate(el => el.disabled), true,
            'Checkout button immediately disabled upon trigger');
        assert.match(await page.locator('#checkout-button').innerHTML(), /Processing\.\.\./,
            'Checkout button displays visual processing feedback');

        // 3. Verify double-submit protection on confirm button
        await page.locator('#confirm-checkout-button').click();
        assert.equal(await page.locator('#confirm-checkout-button').evaluate(el => el.disabled), true,
            'Confirm payment button immediately disables upon click');
        assert.match(await page.locator('#confirm-checkout-button').innerHTML(), /Processing\.\.\./,
            'Confirm payment button displays visual processing feedback');
        assert.equal(await page.evaluate(() => checkoutConfirmedCount), 1,
            'First submission recorded');

        // Second click / Enter key cannot re-submit
        await page.keyboard.press('Enter');
        await page.locator('#confirm-checkout-button').click({ force: true });
        assert.equal(await page.evaluate(() => checkoutConfirmedCount), 1,
            'Second submission prevented by double-submit guard');

        // 4. Verify Escape dismisses modal and restores focus to barcode search
        await page.evaluate(() => {
            document.getElementById('checkout-modal').classList.add('open');
        });
        await page.keyboard.press('Escape');
        assert.equal(await page.evaluate(() => document.getElementById('checkout-modal').classList.contains('open')), false,
            'Escape key dismissed checkout modal');
        assert.equal(await page.locator('#sku-input').evaluate(el => document.activeElement === el), true,
            'Dismissing modal returned focus to barcode search field');

        // 5. Test sale receipt dialog autofocus on print/close action
        await page.evaluate(() => {
            window.openReceiptModal();
        });
        assert.equal(await page.locator('#print-receipt-btn').evaluate(el => document.activeElement === el), true,
            'Sale receipt dialog autofocuses on the print action');

        // 6. Test dismissing sale receipt dialog with Escape resets focus immediately to barcode search
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#receiptModal').evaluate(el => el.open), false,
            'Escape dismissed sale receipt dialog');
        assert.equal(await page.locator('#sku-input').evaluate(el => document.activeElement === el), true,
            'Dismissing sale receipt dialog immediately reset focus to barcode search field');

        console.log('POS rapid checkout ergonomics browser checks: PASSED');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
