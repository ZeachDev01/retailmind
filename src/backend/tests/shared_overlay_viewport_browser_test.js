const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');

const frontend = path.resolve(__dirname, '../../frontend');
const calendar = fs.readFileSync(path.join(frontend, 'components/administrator/store_calendar.php'), 'utf8');
const calendarOverlay = calendar.slice(calendar.indexOf('<div class="rm-modal-overlay" id="calendar-editor"'), calendar.indexOf('<!-- Modal 2:'));
const products = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
const drawer = products.slice(products.indexOf('<div class="rm-drawer-overlay" id="product-drawer"'), products.indexOf('<div class="rm-modal-overlay" id="add-product-modal"'));
const html = execFileSync(process.env.PHP_BINARY || 'php', [], {encoding: 'utf8', input: `<?php
function csrf_field() { return ''; }
function current_role() { return 'inventory_manager'; }
$today = '2026-11-17';
?>${calendarOverlay}${drawer}`});

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const [width, height] of [[640, 360], [800, 480], [960, 600], [1024, 768], [1280, 800], [1366, 768]]) {
            const page = await browser.newPage({viewport: {width, height}, hasTouch: true});
            await page.setContent('<!doctype html><button id="trigger">Open editor</button>' + html);
            await page.addStyleTag({path: path.join(frontend, 'assets/css/style.css')});
            await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
            await page.locator('#trigger').focus();
            await page.evaluate(() => RetailMindUI.openOverlay(document.querySelector('#calendar-editor')));
            await page.waitForTimeout(220);
            const close = await page.locator('#calendar-editor .rm-close').boundingBox();
            assert.ok(close.width >= 44 && close.height >= 44, 'Shared close control has a 44px touch target');
            await page.locator('#calendar_notes').fill('Retain date exception note');
            await page.setViewportSize({width, height: 240});
            assert.equal(await page.locator('#calendar_notes').inputValue(), 'Retain date exception note');
            assert.equal(await page.locator('#calendar_notes').evaluate(node => node === document.activeElement), true);
            await page.locator('#save-status-btn').scrollIntoViewIfNeeded();
            const save = await page.locator('#save-status-btn').boundingBox();
            assert.ok(save.y >= 0 && save.y + save.height <= 240, 'Save remains reachable after viewport reduction');
            const cancel = page.locator('#calendar-editor .rm-modal-actions [data-close-modal]');
            const cancelBox = await cancel.boundingBox();
            const modalBox = await page.locator('#calendar-editor .rm-modal').boundingBox();
            assert.ok(cancelBox.y >= modalBox.y && cancelBox.y + cancelBox.height <= modalBox.y + modalBox.height, 'Cancel is not clipped by modal bounds');
            await page.locator('#calendar_date').fill('');
            await page.locator('#save-status-btn').click();
            assert.equal(await page.locator('#calendar_date').evaluate(node => node === document.activeElement && node.validity.valueMissing), true, 'Native validation focuses the required date');
            const fieldBox = await page.locator('#calendar_date').boundingBox();
            const bodyBox = await page.locator('#calendar-editor .rm-modal-body').boundingBox();
            assert.ok(fieldBox.y >= bodyBox.y && fieldBox.y + fieldBox.height <= bodyBox.y + bodyBox.height + 1, 'Invalid field is visible within the scrolling body');
            if (width === 640) {
                fs.mkdirSync('output/playwright/shared-overlays', {recursive: true});
                await page.screenshot({path: 'output/playwright/shared-overlays/calendar-640x240.png'});
            }
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('#trigger').evaluate(node => node === document.activeElement), true, 'Close returns focus');
            await page.evaluate(() => RetailMindUI.openOverlay(document.querySelector('#calendar-editor')));
            await page.waitForTimeout(220);
            assert.equal(await page.locator('#calendar_notes').inputValue(), 'Retain date exception note', 'Reopen keeps entered values');
            await page.keyboard.press('Escape');
            await page.setViewportSize({width, height});
            await page.evaluate(() => {
                document.querySelector('#product-panel-info').textContent = 'Connection interrupted. Retry after reconnecting. ' + 'A'.repeat(300);
                RetailMindUI.openOverlay(document.querySelector('#product-drawer'));
            });
            await page.waitForTimeout(220);
            assert.ok(await page.locator('.rm-drawer-body').evaluate(node => node.scrollWidth <= node.clientWidth + 1), 'Long error text wraps inside drawer');
            if (width === 640) await page.screenshot({path: 'output/playwright/shared-overlays/drawer-640x360.png'});
            await page.keyboard.press('Escape');
            await page.evaluate(() => { window.confirmResult = undefined; RetailMindUI.confirm({message: 'Long confirmation. '.repeat(100)}).then(value => { window.confirmResult = value; }); });
            await page.waitForTimeout(220);
            await page.locator('.rm-accept').click();
            assert.equal(await page.evaluate(() => window.confirmResult), true, 'Confirmation action remains reachable');
            assert.equal(await page.locator('#trigger').evaluate(node => node === document.activeElement), true, 'Confirmation returns focus');
            console.log(`${width}x${height}: calendar resize/reopen/focus, product drawer long error, confirmation passed`);
            await page.close();
        }
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
