const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/inventory_management/products.php'), 'utf8');
    const inlineCss = source.match(/<style>([\s\S]*?)<\/style>/)[1];
    const drawer = source.slice(source.indexOf('<div class="rm-drawer-overlay"'), source.indexOf('<div class="rm-modal-overlay"'));
    assert(drawer.includes('id="product-drawer"'), 'Fixture uses the production product drawer');
    const css = ['style', 'theme', 'inventory'].map(name => fs.readFileSync(path.join(frontend, `assets/css/${name}.css`), 'utf8')).join('\n');
    const rows = Array.from({length: 80}, (_, index) => `<tr><td><input type="checkbox" aria-label="Select product ${index}"></td><td class="product-cell">All Purpose Flour ${index}</td><td class="mobile-hide">SKU-DRY-${index}</td><td class="mobile-hide">Pantry</td><td class="mobile-hide">₱2.40</td><td class="stock-cell">42</td><td class="status-cell">Available</td><td class="action-cell"><button>Manage</button></td></tr>`).join('');
    const closedModals = ['add-product', 'add-category', 'stock-adjust', 'bulk-category', 'column'].map(id => `<div class="rm-modal-overlay" id="${id}-modal" aria-hidden="true"><section class="rm-modal"><button>Close ${id}</button></section></div>`).join('');
    const html = `<html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}\n${inlineCss}</style></head><body class="products-page"><div class="app-shell"><main class="main-content"><h1>Products &amp; Stock</h1><button id="open-product">Manage All Purpose Flour 1kg</button><div class="data-table-shell product-table-shell"><div class="data-table-scroll"><table class="data-table" id="products-table"><thead><tr>${['Select', 'Product', 'SKU / Barcode', 'Category', 'Selling Price', 'Current Stock', 'Status', 'Actions'].map(label => `<th>${label}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></div><footer class="table-pagination">Showing 1–10 of 80 <button>Next page</button></footer></div></main></div>${drawer}${closedModals}<div class="command-overlay" aria-hidden="true"><div class="command-dialog"><input aria-label="Search pages"></div></div></body></html>`;
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'product-drawer-layers-'));
    const browser = await chromium.launch({headless: true});
    try {
        for (const viewport of [{width:1536,height:1024}, {width:390,height:844}, {width:768,height:1024,touch:true}, {width:834,height:1112,touch:true}, {width:1024,height:768,touch:true}, {width:1280,height:800,touch:true}]) {
            for (const theme of ['light', 'dark']) {
                const label = `${viewport.width}-${theme}`;
                const page = await browser.newPage({viewport:{width:viewport.width,height:viewport.height}, hasTouch:!!viewport.touch, isMobile:!!viewport.touch, deviceScaleFactor:viewport.touch ? 2 : 1, reducedMotion:'no-preference',colorScheme:theme});
                await page.setContent(html);
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                await page.addScriptTag({path: path.join(frontend, 'assets/js/ui.js')});
                await page.evaluate(() => {
                    RetailMindUI.initPageContent();
                    document.getElementById('product-drawer-title').textContent = 'All Purpose Flour 1kg';
                    document.getElementById('product-drawer-subtitle').textContent = 'SKU-DRY-002 · Pantry';
                    document.getElementById('product-panel-info').innerHTML = `<div class="detail-grid">${Array.from({length: 30}, (_, index) => `<div class="detail-item"><span>Product detail ${index}</span><strong>All Purpose Flour 1kg</strong></div>`).join('')}</div>`;
                    document.getElementById('product-drawer-footer').hidden = true;
                    const overlay = document.getElementById('product-drawer');
                    window.drawerFades = 0;
                    overlay.addEventListener('transitionrun', event => {
                        if (event.target === overlay && event.propertyName === 'opacity') window.drawerFades++;
                    });
                    document.getElementById('open-product').addEventListener('click', () => RetailMindUI.openOverlay(overlay));
                    const table = document.querySelector('.data-table-scroll');
                    table.scrollTop = 350;
                    window.scrollTo({top: 300, behavior: 'instant'});
                });
                await page.screenshot({path: path.join(output, `${label}-closed.png`)});
                const openDrawer = () => viewport.touch ? page.locator('#open-product').tap() : page.locator('#open-product').click();
                const swipe = async (x, y, endY) => {
                    const session = await page.context().newCDPSession(page);
                    await session.send('Input.dispatchTouchEvent', {type:'touchStart',touchPoints:[{x,y}]});
                    for (let i=1; i<=6; i++) {
                        await session.send('Input.dispatchTouchEvent', {type:'touchMove',touchPoints:[{x,y:y+(endY-y)*i/6}]});
                        await page.waitForTimeout(20);
                    }
                    await session.send('Input.dispatchTouchEvent', {type:'touchEnd',touchPoints:[]});
                    await session.detach();
                };
                await openDrawer();
                const waitDrawer = () => page.waitForFunction(() => getComputedStyle(document.getElementById('product-drawer')).opacity === '1' && Math.abs(new DOMMatrix(getComputedStyle(document.querySelector('#product-drawer .rm-drawer')).transform).m41) < .1);
                await waitDrawer();
                assert.equal(await page.locator('#product-drawer').evaluate(el => getComputedStyle(el).backdropFilter), 'none', `${label}: backdrop does not sample the table through a GPU blur`);
                await page.screenshot({path: path.join(output, `${label}-initial-open.png`)});
                if (viewport.touch) await page.locator('#product-drawer [data-close-drawer]').tap();
                else await page.keyboard.press('Escape');
                await page.waitForFunction(() => getComputedStyle(document.getElementById('product-drawer')).opacity === '0');
                await page.evaluate(() => { window.drawerFades = 0; });
                assert.equal(await page.locator('.rm-modal-overlay, .rm-drawer-overlay, .command-overlay').evaluateAll(nodes => nodes.every(node => getComputedStyle(node).visibility === 'hidden')), true, `${label}: closed overlays leave the paint tree`);
                assert.equal(await page.locator('.rm-modal-overlay button').first().evaluate(button => { button.focus(); return document.activeElement === button; }), false, `${label}: closed modal cannot receive focus`);
                for (let cycle = 0; cycle < 3; cycle++) {
                    await openDrawer();
                    await waitDrawer();
                    const state = await page.evaluate(() => {
                        const overlay = document.getElementById('product-drawer');
                        const bounds = overlay.querySelector('.rm-drawer').getBoundingClientRect();
                        const points = [[bounds.right - 20, bounds.bottom - 30], [bounds.left + bounds.width / 2, bounds.bottom * .75], [bounds.left + 50, bounds.bottom * .9]];
                        return {
                            visible: getComputedStyle(overlay).visibility,
                            bodyOverflow: getComputedStyle(document.body).overflowY,
                            ownsLowerRight: points.every(([x, y]) => overlay.contains(document.elementFromPoint(x, y))),
                            ariaHidden: overlay.getAttribute('aria-hidden'),
                        };
                    });
                    assert.deepEqual(state, {visible: 'visible', bodyOverflow: 'hidden', ownsLowerRight: true, ariaHidden: 'false'}, `${label}: drawer stays above the scrolled table`);
                    if (cycle === 0) await page.screenshot({path: path.join(output, `${label}-open.png`)});
                    if (viewport.touch && cycle === 0) {
                        await page.setViewportSize({width:viewport.height,height:viewport.width});
                        await waitDrawer();
                        const rotated = await page.locator('#product-drawer .rm-drawer').boundingBox();
                        assert(rotated.x >= -1 && rotated.y >= -1 && rotated.x + rotated.width <= viewport.height + 1 && rotated.y + rotated.height <= viewport.width + 1, `${label}: open drawer fits after rotation`);
                        await page.screenshot({path:path.join(output, `${label}-rotated.png`)});
                        await page.setViewportSize({width:viewport.width,height:viewport.height});
                        await waitDrawer();
                    }
                    const pageScroll = await page.evaluate(() => window.scrollY);
                    if (viewport.touch) await swipe(3, viewport.height * .75, viewport.height * .4);
                    else { await page.mouse.move(3, viewport.height / 2); await page.mouse.wheel(0, 300); }
                    await page.waitForTimeout(80);
                    assert.equal(await page.evaluate(() => window.scrollY), pageScroll, `${label}: background cannot scroll`);
                    const body = page.locator('#product-drawer-body');
                    await body.evaluate(element => { element.scrollTop = 0; });
                    if (viewport.touch) {
                        const box = await body.boundingBox();
                        await swipe(box.x + box.width / 2, box.y + box.height * .8, box.y + box.height * .3);
                    } else { await body.hover(); await page.mouse.wheel(0, 350); }
                    await page.waitForFunction(() => document.getElementById('product-drawer-body').scrollTop > 0);
                    await page.keyboard.press('Escape');
                    await page.waitForFunction(() => getComputedStyle(document.getElementById('product-drawer')).visibility === 'hidden');
                    assert.equal(await page.locator('body').evaluate(element => element.classList.contains('no-scroll')), false, `${label}: closing unlocks background`);
                    assert.equal(await page.locator('#product-drawer [data-close-drawer]').evaluate(button => { document.getElementById('open-product').focus({preventScroll: true}); button.focus(); return document.activeElement === button; }), false, `${label}: closed drawer cannot receive focus`);
                }
                assert.equal(await page.evaluate(() => window.drawerFades), 6, `${label}: opening and closing keep their fade transitions`);
                await page.close();
            }
        }
        console.log(`Product drawer layers browser: passed (desktop/mobile/tablet, light/dark, touch swipes, rotation, scrolling, stacking, visibility, focus and fades; screenshots: ${output})`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
