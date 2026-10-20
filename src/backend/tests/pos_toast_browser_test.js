'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');
const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.setContent('<body class="cashier-pos-page"><input id="scan"><div id="products">Products</div></body>');
        for (const file of ['style.css', 'cashier.css']) {
            await page.addStyleTag({ path: `src/frontend/assets/css/${file}` });
        }
        await page.addScriptTag({ path: 'src/frontend/assets/js/ui.js' });
        const registerToast = source.match(/<script>(RetailMindUI\.toast\(<\?= json_encode\([^\r\n]*?\?>, 'info', 'Register ready', \d+\);)<\/script>/)[1];
        await page.clock.install();
        await page.clock.pauseAt(new Date());
        await page.addScriptTag({ content: registerToast.replace(/<\?=[\s\S]*?\?>/, JSON.stringify('Register: 2nd Register · Shift #3243')) });
        await page.clock.runFor(2999);
        assert.equal(await page.locator('.rm-toast.show').count(), 1, 'Register ready remains visible before three seconds');
        await page.clock.runFor(1);
        assert.equal(await page.locator('.rm-toast.show').count(), 0, 'Register ready dismisses at three seconds');
        await page.clock.runFor(250);
        assert.equal(await page.locator('.rm-toast').count(), 0, 'Register ready is removed after its exit transition');
        await page.clock.resume();
        await page.addScriptTag({ content: source.slice(source.indexOf('function showCartMessage('), source.indexOf('function playScanTone(')) });
        const originalTop = await page.locator('#products').evaluate(node => node.getBoundingClientRect().top);
        await page.focus('#scan');
        await page.evaluate(() => {
            for (const type of ['success', 'error', 'warning', 'info']) showCartMessage(type + ' message', type);
        });
        await page.waitForTimeout(300);
        assert.equal(await page.locator('.rm-toast').count(), 4);
        assert.equal(await page.locator('#products').evaluate(node => node.getBoundingClientRect().top), originalTop, 'toasts do not move products');
        assert.equal(await page.locator('#scan').evaluate(node => node === document.activeElement), true, 'toasts do not steal scanner focus');
        for (const width of [1440, 768, 375]) {
            await page.setViewportSize({ width, height: 900 });
            const stack = await page.locator('.rm-toast-stack').boundingBox();
            assert.ok(stack.x >= 0 && stack.x + stack.width <= width, 'stack stays within screen');
            assert.ok(Math.abs(stack.y + stack.height - 884) < 2, 'stack is at bottom');
            const boxes = await page.locator('.rm-toast').all();
            for (let i = 1; i < boxes.length; i++) {
                const previous = await boxes[i - 1].boundingBox(), current = await boxes[i].boundingBox();
                assert.ok(current.y > previous.y + previous.height, 'toasts stack with spacing');
            }
        }
        await page.locator('.rm-toast-close').first().click();
        await page.waitForTimeout(300);
        assert.equal(await page.locator('.rm-toast').count(), 3, 'dismiss removes one toast');
        await page.waitForFunction(() => document.querySelectorAll('.rm-toast').length === 0, { timeout: 4500 });
        assert.ok(!source.includes('id="cart-message"'), 'inline search notification is removed');
        console.log('POS toasts: passed (desktop, tablet, mobile, stacking, dismissal, expiry, focus)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
