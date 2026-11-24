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
        const drawer = fs.readFileSync(path.join(__dirname, '../../frontend/components/cashier/refunds.php'), 'utf8').match(/<dialog id="refund-details-drawer"[\s\S]*?<\/dialog>/)[0];
        const render = async (fragment, name) => {
            const html = `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">${['theme.css', 'style.css', 'cashier-pages.css', 'refunds.css'].map(file => `<style>${fs.readFileSync(path.join(__dirname, '../../frontend/assets/css', file), 'utf8')}</style>`).join('')}</head><body class="cashier-refunds-page">${drawer}<script>${fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/refund-return-conditions.js'), 'utf8')}</script><script>
                document.getElementById('refund-drawer-conditions').innerHTML = ${JSON.stringify(fragment + '<p><a href="after.html">Saved history</a> · <a href="packAfter.html">Pack history</a></p>').replace(/</g, '\\u003c')};
                document.getElementById('refund-drawer-title').textContent = 'Refund #3';
                document.getElementById('refund-drawer-fields').innerHTML = '<dt>Sale ID</dt><dd>#1</dd><dt>Shift ID</dt><dd>#1</dd><dt>Recorded at</dt><dd>Nov 24, 2026 · 1:33 PM</dd><dt>Payment method</dt><dd>CASH</dd><dt>Refund amount</dt><dd>₱15.00</dd><dt>Reason</dt><dd>Customer return</dd><dt>Note</dt><dd>None</dd>';
                document.getElementById('refund-drawer-receipt-btn').hidden = false;
                document.getElementById('refund-details-drawer').showModal();
                window.initRefundConditions(document);
            </script></body></html>`;
            fs.writeFileSync(path.join(output, `${name}.html`), html);
            await page.setContent(html);
        };
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await render(data.before, 'before');
            assert.equal(await page.locator('dialog').evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }), true, 'Drawer fits the viewport including its scrollbar gutter');
            assert.equal(await page.locator('.refund-condition-line').first().evaluate(el => {
                const heading = el.querySelector('h4');
                const title = heading.getBoundingClientRect();
                const button = el.querySelector('summary').getBoundingClientRect();
                return button.left >= title.right - parseFloat(getComputedStyle(heading).paddingRight) && button.top < title.bottom && button.height >= 44;
            }), true, 'Correction button sits beside the item with a touch-sized target');
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
            assert.equal(await form.locator('input[name=fit_for_sale]').evaluate(el => el.getBoundingClientRect().height), 18, 'Confirmation remains a compact checkbox');
            await form.locator('button[type=submit]').scrollIntoViewIfNeeded();
            assert.equal(await page.locator('.history-drawer-footer').first().evaluate(el => { const r = el.getBoundingClientRect(); return r.bottom <= innerHeight && r.top >= 0; }), true, 'Footer stays visible while the body scrolls');
            assert.equal(await page.locator('.history-drawer-header').evaluate(el => el.getBoundingClientRect().top), 0, 'Header stays pinned');
            await page.screenshot({ path: path.join(output, `preview-${width}.png`), fullPage: true });
            await render(data.after, 'after');
            assert.match(await page.innerText('.refund-condition-lines'), /Edited Cashier/);
            assert.match(await page.innerText('.refund-condition-lines'), /Inspected whole and fit for sale/);
            assert.match(await page.innerText('.refund-condition-lines'), /Effective condition:\s+Restockable/);
            assert.equal(await page.locator('.refund-condition-line .refund-condition-history').count(), 0, 'History is separate from editable items');
            assert.equal(await page.locator('.refund-condition-history').evaluate(el => el.getBoundingClientRect().top >= el.previousElementSibling.getBoundingClientRect().bottom), true, 'All history follows returned items');
            await page.screenshot({ path: path.join(output, `history-${width}.png`), fullPage: true });
        }
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await render(data.packBefore, 'packBefore');
            await page.locator('summary').first().click();
            const form = page.locator('.refund-condition-form').first();
            await form.locator('select').selectOption('restockable');
            assert.equal(await form.locator('.refund-condition-preview').innerText(), 'Barnats Sales Pack — Damaged → Restockable. Return 3 packs to available stock.');
            assert.match(await page.innerText('.refund-condition-lines'), /3 packs.*6 pieces per pack/);
            assert.match(await form.locator('.refund-condition-confirm').innerText(), /sealed, complete and fit for sale/);
            await form.locator('input[name=correction_reason]').fill('Sealed complete packs inspected');
            assert.equal(await form.evaluate(el => el.checkValidity()), false);
            await form.locator('input[name=fit_for_sale]').check();
            assert.equal(await form.evaluate(el => el.checkValidity()), true);
            assert.equal(await form.locator('input[name=quantity]').count(), 0);
            assert.equal(await form.locator('.refund-condition-preview').evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }), true);
            await page.screenshot({ path: path.join(output, `pack-preview-${width}.png`), fullPage: true });
            await render(data.packAfter, 'packAfter');
            assert.match(await page.innerText('.refund-condition-lines'), /Sealed complete packs inspected/);
            assert.match(await page.innerText('.refund-condition-lines'), /Effective condition:\s+Restockable/);
            await page.screenshot({ path: path.join(output, `pack-history-${width}.png`), fullPage: true });
        }
        // Exercise a long list without altering the persisted refund fixture.
        await page.setViewportSize({ width: 390, height: 900 });
        await render(data.after, 'many');
        const originalItems = await page.locator('.refund-condition-line').count();
        await page.locator('.refund-condition-lines').evaluate(section => {
            const item = section.querySelector('article');
            const history = section.querySelector('.refund-condition-history');
            for (let i = 0; i < 5; i++) section.insertBefore(item.cloneNode(true), history);
            const events = history.querySelector('ul');
            for (let i = 0; i < 12; i++) events.append(events.firstElementChild.cloneNode(true));
        });
        assert.equal(await page.locator('.refund-condition-line').count(), originalItems + 5);
        await page.locator('.refund-condition-events li').last().scrollIntoViewIfNeeded();
        assert.equal(await page.locator('.refund-condition-events li').last().isVisible(), true, 'Last correction remains reachable in a long history');
        await page.screenshot({ path: path.join(output, 'many-history-390.png'), fullPage: true });
        console.log(`Return condition signed-in handler desktop/mobile checks passed (${output})`);
    } finally { if (browser) await browser.close(); if (fs.existsSync(fixture)) fs.unlinkSync(fixture); }
})().catch(error => { console.error(error); process.exit(1); });
