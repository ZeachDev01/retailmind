'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(5000);
        const frontend = path.resolve(__dirname, '../../frontend');
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
