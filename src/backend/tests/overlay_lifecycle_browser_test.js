const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const css = fs.readFileSync('src/frontend/assets/css/style.css', 'utf8');
    const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');
    const html = `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style>
        <button id="trigger">Manage</button><div style="height:1500px"></div>
        <div class="rm-drawer-overlay" id="drawer" aria-hidden="true"><section class="rm-drawer"><button id="drawer-first">First</button><button id="drawer-last">Last</button></section></div>
        <div class="user-modal-overlay" id="user" aria-hidden="true"><section class="user-modal"><button class="user-modal-close">Close user</button></section></div>
        <div class="checkout-modal" id="checkout"><button>Payment review</button><button id="payment-confirm">Confirm payment</button></div>
        <dialog id="receipt"><button>Receipt</button></dialog>
        <form id="plain"><button type="submit">Save</button></form>
        <form id="confirmed" data-confirm="Save these changes?"><button type="submit">Confirm save</button></form>
        <script>${ui}</script>`;
    const browser = await chromium.launch({headless: true});
    const failures = [];
    try {
        for (const width of [320, 375, 425, 768, 1024, 1440]) {
            const page = await browser.newPage({viewport: {width, height: 844}});
            page.setDefaultTimeout(2000);
            await page.setContent(html);
            const check = async (name, callback) => {
                try { await callback(); }
                catch (error) { failures.push(`${width}px ${name}: ${error.message}`); }
            };
            await check('idempotent page initialization and canceled form submission', async () => {
                const state = await page.evaluate(async () => {
                    RetailMindUI.initPageContent();
                    RetailMindUI.initPageContent();
                    let accepted = 0;
                    const form = document.getElementById('plain');
                    form.addEventListener('submit', event => { if (!event.defaultPrevented) accepted++; event.preventDefault(); });
                    form.requestSubmit();
                    await new Promise(resolve => setTimeout(resolve, 20));
                    form.requestSubmit();
                    return {accepted, disabled: form.querySelector('button').disabled, pending: form.dataset.rmSubmitting};
                });
                assert.deepEqual(state, {accepted: 2, disabled: false, pending: undefined});
            });
            await check('confirmation cancellation resolves and leaves form usable', async () => {
                await page.evaluate(() => {
                    window.cancelled = undefined;
                    RetailMindUI.confirm({message: 'Cancel this action'}).then(value => window.cancelled = value);
                });
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => window.cancelled === false);
                await page.waitForFunction(() => !document.querySelector('.rm-modal-overlay'));
                await page.evaluate(() => document.getElementById('confirmed').requestSubmit());
                await page.evaluate(() => document.getElementById('confirmed').requestSubmit());
                await page.waitForFunction(() => !!document.querySelector('.rm-modal-overlay.open'));
                await page.locator('.rm-modal-overlay.open .rm-cancel').click();
                await page.waitForTimeout(30);
                assert.equal(await page.locator('#confirmed button').isEnabled(), true);
                await page.evaluate(() => document.getElementById('confirmed').requestSubmit());
                await page.waitForFunction(() => !!document.querySelector('.rm-modal-overlay.open'));
                assert.equal(await page.locator('.rm-modal-overlay.open').count(), 1, 'One confirmation per submission');
                await page.locator('.rm-modal-overlay.open .rm-cancel').click();
                await page.waitForFunction(() => !document.querySelector('.rm-modal-overlay'));
            });
            await check('confirmation acceptance submits once', async () => {
                await page.evaluate(() => {
                    window.confirmedSubmissions = 0;
                    document.getElementById('confirmed').addEventListener('submit', event => {
                        if (!event.defaultPrevented) window.confirmedSubmissions++;
                        event.preventDefault();
                    });
                    document.getElementById('confirmed').requestSubmit();
                });
                await page.locator('.rm-modal-overlay.open .rm-accept').click();
                await page.waitForFunction(() => window.confirmedSubmissions === 1);
                await page.waitForFunction(() => !document.querySelector('.rm-modal-overlay'));
                assert.equal(await page.locator('#confirmed button').isEnabled(), true);
            });
            await check('topmost Escape and focus containment', async () => {
                await page.evaluate(() => {
                    document.getElementById('trigger').focus({preventScroll: true});
                    RetailMindUI.openOverlay(document.getElementById('drawer'));
                    RetailMindUI.openOverlay(document.getElementById('user'));
                });
                await page.waitForTimeout(40);
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#user').evaluate(node => node.classList.contains('open')), false);
                assert.equal(await page.locator('#drawer').evaluate(node => node.classList.contains('open')), true);
                assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), true);
                await page.locator('#drawer-last').focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.evaluate(() => document.activeElement.id), 'drawer-first');
                await page.keyboard.press('Shift+Tab');
                assert.equal(await page.evaluate(() => document.activeElement.id), 'drawer-last');
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), false);
                assert.equal(await page.evaluate(() => document.activeElement.id), 'trigger');
            });
            await check('payment dismissal guards and native dialogs keep ownership', async () => {
                await page.evaluate(() => {
                    RetailMindUI.openOverlay(document.getElementById('checkout'));
                    document.getElementById('payment-confirm').focus();
                });
                await page.waitForTimeout(40);
                assert.equal(await page.evaluate(() => document.activeElement.id), 'payment-confirm', 'Caller-selected payment focus survives the shared timer');
                await page.evaluate(() => {
                    document.addEventListener('keydown', event => {
                        if (event.key === 'Escape' && document.getElementById('checkout').classList.contains('open')) RetailMindUI.closeOverlay(document.getElementById('checkout'));
                    });
                    RetailMindUI.confirm({message: 'Confirm payment action'});
                });
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#checkout').evaluate(node => node.classList.contains('open')), true, 'Escape dismisses only the top confirmation');
                await page.evaluate(() => {
                    RetailMindUI.closeOverlay(document.getElementById('checkout'));
                    RetailMindUI.openOverlay(document.getElementById('drawer'));
                    document.getElementById('receipt').showModal();
                });
                await page.waitForTimeout(40);
                assert.equal(await page.evaluate(() => document.activeElement.closest('dialog')?.id), 'receipt');
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.getElementById('receipt').open);
                assert.equal(await page.locator('#drawer').evaluate(node => node.classList.contains('open')), true, 'Native receipt Escape keeps the drawer open');
                await page.evaluate(() => {
                    window.Swal = {isVisible: () => true};
                    document.getElementById('trigger').focus({preventScroll: true});
                });
                await page.keyboard.press('Escape');
                assert.equal(await page.locator('#drawer').evaluate(node => node.classList.contains('open')), true, 'SweetAlert owns dismissal while visible');
                await page.evaluate(() => { delete window.Swal; RetailMindUI.closeOverlay(document.getElementById('drawer')); });
            });
            await check('rapid open-close does not focus a hidden panel or scroll the page', async () => {
                const state = await page.evaluate(async () => {
                    window.scrollTo({top: 200, behavior: 'instant'});
                    document.getElementById('trigger').focus({preventScroll: true});
                    const scroll = window.scrollY;
                    RetailMindUI.openOverlay(document.getElementById('drawer'));
                    RetailMindUI.closeOverlay(document.getElementById('drawer'));
                    await new Promise(resolve => setTimeout(resolve, 50));
                    return {focused: document.activeElement.id, scroll: window.scrollY, initialScroll: scroll};
                });
                assert.equal(state.focused, 'trigger');
                assert.equal(state.scroll, state.initialScroll);
            });
            await page.close();
        }
        assert.deepEqual(failures, [], failures.join('\n'));
        console.log('Overlay lifecycle: passed (320/375/425/768/1024/1440px, repeated initialization, confirm/cancel/retry, topmost dismissal, payment/native dialog ownership, focus trap/restore, scroll stability)');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
