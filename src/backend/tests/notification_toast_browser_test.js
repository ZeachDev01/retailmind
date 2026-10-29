'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const frontend = path.resolve(__dirname, '../../frontend');
        const receivingSource = fs.readFileSync(path.join(frontend, 'components/report/stock_receiving.php'), 'utf8');
        for (const type of ['success', 'error']) {
            const receivingPage = await browser.newPage();
            await receivingPage.setContent('<main></main>');
            await receivingPage.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
            const message = type === 'success' ? 'Receiving entry saved. Baby Flo: 1 accepted units.' : 'The receiving record could not be saved.';
            const variable = type === 'success' ? 'message' : 'error';
            const blocks = [...receivingSource.matchAll(/<\?php if \(\$(message|error)\): \?>([\s\S]*?)<\?php endif; \?>/g)]
                .filter(match => match[1] === variable)
                .map(match => match[2]
                    .replace(/<\?= htmlspecialchars\(\$\w+\) \?>/g, message)
                    .replace(/<\?= json_encode\([\s\S]*?\?>/g, JSON.stringify(message)));
            assert.ok(blocks.length, `Receiving ${type} feedback is covered`);
            await receivingPage.locator('main').evaluate((main, html) => { main.innerHTML = html; }, blocks.join('\n'));
            for (const script of await receivingPage.locator('main script').allTextContents()) {
                await receivingPage.addScriptTag({content: script});
            }
            await receivingPage.evaluate(() => {
                RetailMindUI.initPageContent();
                RetailMindUI.initPageContent();
            });
            assert.deepEqual(await receivingPage.locator(`.rm-toast.${type} .rm-toast-copy > span`).allTextContents(), [message], `Receiving ${type} feedback appears exactly once`);
            assert.equal(await receivingPage.getByText('Stock is updated.', {exact: false}).count(), 0, 'Receiving leaves no separate stock-update banner');
            await receivingPage.close();
        }
        const productSource = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
        const productNoticeScript = productSource.match(/<script>\s*([\s\S]*?)const PRODUCT_PHOTO_LIMIT_MESSAGE/)[1];
        for (const type of ['success', 'warning', 'error']) {
            const noticePage = await browser.newPage();
            const message = type === 'success' ? 'Product added successfully. Barcode: RM26102112345678' : 'Check the product details.';
            await noticePage.route('http://retailmind.test/**', route => route.fulfill({contentType: 'text/html', body: '<main></main>'}));
            await noticePage.goto(`http://retailmind.test/products.php?${type}=${encodeURIComponent(message)}`);
            await noticePage.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
            if (productNoticeScript.trim()) {
                await noticePage.addScriptTag({content: productNoticeScript.replace(/<\?=[\s\S]*?\?>/g, JSON.stringify({type, message}))});
            }
            await noticePage.evaluate(() => {
                RetailMindUI.initPageContent();
                RetailMindUI.initPageContent();
            });
            assert.deepEqual(await noticePage.locator(`.rm-toast.${type} .rm-toast-copy > span`).allTextContents(), [message], `Product ${type} notice appears exactly once`);
            await noticePage.close();
        }
        await page.route('http://retailmind.test/**', route => route.fulfill({
            contentType: 'text/html',
            body: '<input id="scan"><main></main>',
        }));
        await page.goto('http://retailmind.test/?success=Saved');
        await page.clock.install();
        await page.addStyleTag({path: path.join(frontend, 'assets/css/style.css')});
        await page.addStyleTag({content: '.bi-x-lg::before { content: "×"; }'});
        await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
        await page.evaluate(() => {
            window.dialogs = [];
            window.Swal = {fire(options) { dialogs.push(options); return Promise.resolve({}); }};
            document.querySelector('main').innerHTML = `
                <div class="message success">Product updated.</div>
                <div class="message error">Try saving again.</div>
                <div class="message warning">Check the delivery.</div>
                <div class="message warning" role="note">Open a shift first.</div>
                <div class="alert" data-rm-alert-processed="1">Persistent instruction.</div>
                <div id="rm-flash-messages" hidden data-info="Settings loaded."></div>`;
            document.querySelector('#scan').focus();
            RetailMindUI.initPageContent();
            RetailMindUI.initPageContent();
        });
        assert.equal(await page.locator('.rm-toast').count(), 5, 'URL, flash, and inline feedback appears once');
        assert.deepEqual(await page.evaluate(() => dialogs), [], 'Routine feedback never calls SweetAlert');
        assert.equal(await page.locator('#scan').evaluate(node => node === document.activeElement), true, 'Scanner focus stays');
        assert.equal(await page.locator('.message.success').isVisible(), false, 'Success banner becomes a toast');
        assert.equal(await page.locator('.message.error').isVisible(), true, 'Failure instructions stay inline');
        assert.equal(await page.locator('[role="note"]').isVisible(), true, 'Persistent blockers stay inline');
        assert.equal(await page.locator('.rm-toast.warning').count(), 1, 'Warnings retain their severity');
        await page.clock.fastForward(3000);
        assert.equal(await page.locator('.rm-toast').count(), 5, 'Routine toasts remain past three seconds');
        await page.clock.fastForward(1300);
        await page.clock.fastForward(300);
        assert.equal(await page.locator('.rm-toast').count(), 1, 'Routine toasts expire after 4.3 seconds');
        assert.equal(await page.locator('.rm-toast.warning').count(), 1, 'Warning lasts longer');
        await page.clock.fastForward(15400);
        await page.clock.fastForward(300);
        assert.equal(await page.locator('.rm-toast').count(), 0, 'Warnings expire after twenty seconds');
        await page.evaluate(() => {
            window.redirects = [];
            RetailMindUI.navigate = url => redirects.push(url);
            const flash = document.querySelector('#rm-flash-messages');
            flash.dataset.info = 'Password updated.';
            flash.dataset.redirect = '/login';
            RetailMindUI.initPageContent();
        });
        await page.clock.fastForward(4300);
        assert.deepEqual(await page.evaluate(() => redirects), [], 'Redirect allows time to read the toast');
        await page.clock.fastForward(500);
        assert.deepEqual(await page.evaluate(() => redirects), ['/login'], 'Redirect continues without an OK click');
        await page.clock.fastForward(300);
        await page.clock.resume();

        await page.evaluate(async () => {
            const dialog = document.createElement('dialog');
            dialog.id = 'parent-dialog';
            dialog.innerHTML = '<input id="modal-input">';
            document.body.append(dialog);
            dialog.showModal();
            await RetailMindUI.queueAlert('Retry this action.', 'error');
        });
        await page.waitForTimeout(300);
        assert.equal(await page.locator('#parent-dialog .rm-toast.error').count(), 1, 'Toast is in the native modal top layer');
        assert.equal(await page.locator('#modal-input').evaluate(node => node === document.activeElement), true, 'Modal focus stays');
        await page.locator('.rm-toast-close').click();
        await page.waitForTimeout(300);
        assert.equal(await page.locator('.rm-toast').count(), 0, 'Modal toast can be dismissed');
        await page.evaluate(async () => {
            document.querySelector('#parent-dialog').close();
            delete window.Swal;
            await RetailMindUI.queueAlert('SQLSTATE[HY000] connection failed', 'error');
        });
        assert.equal(await page.locator('body > .rm-toast-stack .rm-toast.error').count(), 1, 'Toast returns to body without SweetAlert');
        assert.ok(!(await page.locator('.rm-toast.error').textContent()).includes('SQLSTATE'), 'Technical detail stays hidden');
        await page.locator('.rm-toast-close').click();
        await page.locator('.rm-toast').waitFor({state: 'detached'});
        await page.evaluate(async () => {
            window.Swal = {fire(options) { dialogs.push(options); return Promise.resolve({}); }};
            await RetailMindUI.alert('Recover checkout first', 'warning');
        });
        assert.equal(await page.evaluate(() => dialogs.length), 1, 'Explicit safeguard still uses SweetAlert');
        console.log('Notification toasts: passed (routing, timing, inline instructions, focus, modal layer, sanitization, safeguards)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
