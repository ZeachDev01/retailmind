const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

const css = ['global', 'forms', 'utilities', 'sidebar', 'dashboard', 'tables', 'modals', 'products', 'responsive', 'cashier', 'theme']
    .map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
const fields = Array.from({ length: 24 }, (_, i) => `<div class="form-group"><label>Field ${i + 1}</label><input value="Editable value ${i + 1}"></div>`).join('');
const rows = Array.from({ length: 180 }, (_, i) => `<tr><td>SKU-${i}</td><td>Product ${i}</td><td>Pantry</td><td>100 units</td><td>2026-12-01</td><td><button type="button">View details</button></td></tr>`).join('');
const table = `<table><thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Available quantity</th><th>Expiry</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table>`;
const header = (family, title) => `<header class="${family}-header"><div><h2>${title}</h2><p>Review details and keep the form actions accessible.</p></div><button type="button" class="rm-close">Close</button></header>`;
const actions = family => `<footer class="${family}-actions"><button type="button" class="btn btn-secondary">Cancel changes</button><button type="button" class="btn">Save changes</button></footer>`;
const html = `<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style>
<div class="app-shell"><aside class="sidebar">Navigation</aside><main class="main-content"><section class="dashboard-section"><h1>Responsive workspace</h1><div class="table-wrap">${table}</div></section><section class="data-table-shell"><div class="data-table-scroll">${table.replace('<table>', '<table class="data-table">')}</div><footer class="table-pagination"><span>Showing rows 1–25</span><div class="pagination-buttons"><button>1</button><button>2</button></div></footer></section><section class="data-table-shell product-table-shell"><div class="data-table-scroll"><table class="data-table"><tbody><tr hidden><td>Filtered product</td></tr></tbody></table></div></section></main></div>
<div class="rm-modal-overlay" id="form"><section class="rm-modal">${header('rm-modal', 'Long inventory form')}<form><div class="rm-modal-body">${fields}</div>${actions('rm-modal')}</form></section></div>
<div class="rm-drawer-overlay" id="drawer"><aside class="rm-drawer">${header('rm-drawer', 'Product details')}<div class="rm-drawer-body">${fields}</div><footer class="rm-drawer-footer"><button class="btn">Update product</button></footer></aside></div>
<div class="user-modal-overlay" id="user"><section class="user-modal">${header('user-modal', 'Add store staff')}<form class="user-form">${fields}${actions('modal')}</form></section></div>
<div class="user-drawer-overlay" id="staff"><aside class="user-drawer">${header('user-drawer', 'Manage staff')}<div class="user-drawer-body"><nav class="user-drawer-tabs"><button class="user-drawer-tab">Info</button><button class="user-drawer-tab">Access</button><button class="user-drawer-tab">History</button><button class="user-drawer-tab">Security</button></nav>${fields}</div></aside></div>
<div class="checkout-modal" id="checkout"><section class="checkout-dialog">${header('checkout-dialog', 'Review final quote before payment')}<div class="checkout-dialog-body"><div class="checkout-summary-card">${Array.from({ length: 40 }, (_, i) => `<p>Purchase item ${i + 1}: ₱100.00</p>`).join('')}</div></div>${actions('checkout-dialog')}</section></div>`;

(async () => {
    const browser = await chromium.launch({ headless: true });
    const failures = [];
    const check = (condition, message) => { if (!condition) failures.push(message); };
    try {
        const page = await browser.newPage({ reducedMotion: 'reduce' });
        await page.setContent(html);
        await page.addScriptTag({ content: fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8') });
        await page.evaluate(() => RetailMindUI.initPageContent());
        const families = [
            ['form', '.rm-modal', '.rm-modal-body', '.rm-modal-actions'],
            ['drawer', '.rm-drawer', '.rm-drawer-body', '.rm-drawer-footer'],
            ['user', '.user-modal', '.user-form', '.modal-actions'],
            ['staff', '.user-drawer', '.user-drawer-body', null],
            ['checkout', '.checkout-dialog', '.checkout-dialog-body', '.checkout-dialog-actions'],
        ];
        for (const width of [320, 375, 425, 768, 1024, 1440]) {
            for (const height of [844, 420]) {
                await page.setViewportSize({ width, height });
                await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
                const label = `${width}x${height}`;
                check(!await page.locator('.product-table-shell tr').isVisible(), `Hidden product row becomes visible at ${label}`);
                const bounds = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, width: document.documentElement.scrollWidth }));
                check(bounds.width <= bounds.viewport + 1, `Page overflows at ${label}: ${JSON.stringify(bounds)}`);
                for (const selector of ['.table-wrap', '.data-table-scroll', '.auto-table-pagination']) {
                    const box = await page.locator(selector).first().boundingBox();
                    check(box.x >= -1 && box.x + box.width <= width + 1, `${selector} escapes viewport at ${label}`);
                }
                for (const selector of ['.table-wrap', '.data-table-scroll']) {
                    check(await page.locator(selector).first().evaluate(el => {
                        if (el.scrollWidth <= el.clientWidth) return true;
                        el.scrollLeft = el.scrollWidth;
                        const moved = el.scrollLeft > 0;
                        el.scrollLeft = 0;
                        return moved;
                    }), `${selector} cannot scroll horizontally at ${label}`);
                }
                await page.locator('.auto-table-pagination select').selectOption('10');
                await page.locator('.auto-table-pagination .pagination-buttons button').nth(2).click();
                check(await page.locator('.table-wrap tbody tr:visible').first().locator('td').first().textContent() === 'SKU-10', `Pagination fails at ${label}`);
                await page.locator('.auto-table-toolbar input').fill('SKU-179');
                check(await page.locator('.table-wrap tbody tr:visible').count() === 1, `Filtering fails at ${label}`);
                await page.locator('.auto-table-clear').click();
                for (const [id, panel, scroll, footer] of families) {
                    const overlay = page.locator(`#${id}`);
                    check(await overlay.evaluate(el => getComputedStyle(el).visibility) === 'hidden', `${id} closed overlay still renders at ${label}`);
                    await overlay.evaluate(el => RetailMindUI.openOverlay(el));
                    await page.waitForFunction(({ id, panel }) => {
                        const transform = getComputedStyle(document.querySelector(`#${id} ${panel}`)).transform;
                        return transform === 'none' || transform === 'matrix(1, 0, 0, 1, 0, 0)';
                    }, { id, panel });
                    const cover = await overlay.boundingBox();
                    check(cover.x === 0 && cover.y === 0 && Math.abs(cover.width - bounds.viewport) <= 1 && Math.abs(cover.height - height) <= 1, `${id} overlay does not cover viewport at ${label}: ${JSON.stringify({ cover, bounds })}`);
                    const box = await overlay.locator(panel).boundingBox();
                    check(box.x >= -1 && box.y >= -1 && box.x + box.width <= width + 1 && box.y + box.height <= height + 1, `${id} panel escapes viewport at ${label}: ${JSON.stringify(box)}`);
                    check(await overlay.locator(panel).evaluate(el => {
                        const rect = el.getBoundingClientRect();
                        return el.contains(document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2));
                    }), `${id} panel covered by another layer at ${label}`);
                    const scroller = overlay.locator(scroll);
                    const dimensions = await scroller.evaluate(el => ({ width: el.clientWidth, scrollWidth: el.scrollWidth, height: el.clientHeight, scrollHeight: el.scrollHeight, overflowY: getComputedStyle(el).overflowY }));
                    check(dimensions.scrollWidth <= dimensions.width + 1, `${id} body overflows horizontally at ${label}`);
                    check(dimensions.scrollHeight > dimensions.height && /auto|scroll/.test(dimensions.overflowY), `${id} body cannot scroll at ${label}`);
                    await scroller.evaluate(el => { el.scrollTop = el.scrollHeight; });
                    check(await scroller.evaluate(el => el.scrollTop) > 0, `${id} body fails scroll at ${label}`);
                    if (footer) {
                        const footerBox = await overlay.locator(footer).boundingBox();
                        check(footerBox.y >= 0 && footerBox.y + footerBox.height <= height + 1, `${id} actions inaccessible at ${label}`);
                    }
                    await overlay.evaluate(el => RetailMindUI.closeOverlay(el));
                    await page.waitForTimeout(20);
                    check(!await page.locator('body').evaluate(el => el.classList.contains('no-scroll')), `Scroll remains locked after ${id} at ${label}`);
                }
            }
        }
        assert.equal(failures.length, 0, failures.join('\n'));
        console.log('Responsive components browser: passed (320, 375, 425, 768, 1024, 1440px; normal/short viewports; tables, filtering, pagination, five overlay families, form actions, scroll restoration)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
