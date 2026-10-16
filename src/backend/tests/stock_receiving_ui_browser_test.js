const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Receiving UI: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = path.resolve('output/playwright/stock-receiving');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/receiving_ui_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/report/stock_receiving.php';
        await page.goto(url);
        const navbar = await page.locator('.sidebar').innerHTML();
        assert.equal(await page.locator('.receiving-category-tile[data-category="all"]').getAttribute('aria-pressed'), 'true');
        assert.match(await page.locator('.receiving-category-tile[data-category="all"]').innerText(), /All/);
        assert.equal(await page.locator('.receiving-catalog').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length), 3, '3-column responsive card grid');
        const firstCard = page.locator('.receiving-card').first();
        assert.match(await firstCard.locator('.receiving-card-stock').innerText(), /in stock|Out of stock|Low stock/);
        assert.match(await firstCard.locator('.receiving-card-pack').innerText(), /1 case = 24 pieces|1 package = 1 piece/);
        assert.match(await firstCard.locator('.receiving-card-add').innerText(), /\+ Add to Delivery/);
        assert.equal(await firstCard.locator('.receiving-card-add').isDisabled(), false, 'depleted and in-stock items remain enabled for receiving replenishment');
        assert.equal(await page.locator('#summary_products').innerText(), '0');
        assert.match(await page.locator('.receiving-accepted').innerText(), /Products added/);
        assert.match(await page.locator('.receiving-accepted').innerText(), /In current delivery list/);
        await page.getByLabel('Search products by name or SKU').fill('Receiving test product 999');
        await page.getByRole('button', {name: 'Add Receiving test product 999', exact: true}).click();
        assert.match(await page.locator('#receiving_lines').innerText(), /UI-999/);
        await page.getByRole('button', {name: 'Add Receiving test product 999', exact: true}).waitFor({state: 'visible'});
        assert.equal(await page.getByRole('button', {name: 'Add Receiving test product 999', exact: true}).isDisabled(), true);
        assert.equal(await page.locator('.receiving-card-add:disabled').innerText(), 'Added');
        assert.equal(await page.locator('#summary_products').innerText(), '1', 'hero counter updates dynamically on add');
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('12');
        await page.locator('#damaged_qty').fill('2');
        await page.locator('#cost_price').fill('3.50');
        assert.equal(await page.locator('#summary_accepted').innerText(), '10');
        assert.equal(await page.locator('#summary_damaged').innerText(), '2');
        assert.equal(await page.locator('#summary_cost').innerText(), '₱35.00');
        await page.locator('#receiving_next').click();
        assert.match(await page.locator('#receiving_review').innerText(), /10 accepted units/);
        assert.equal(await page.locator('.sidebar').innerHTML(), navbar, 'receiving steps preserve navigation');
        await page.goto(url);
        const catalogOptions = await page.locator('#product_id option').count();
        await page.locator('#receiving_next').click();
        assert.equal(await page.locator('#product_id').getAttribute('aria-invalid'), 'true');
        assert.equal(await page.locator('#product_id_error').innerText(), 'Select a product for this line.');
        await page.locator('#product_id').selectOption('1');
        assert.equal(await page.locator('#product_id').getAttribute('aria-invalid'), null);
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('abc');
        await page.locator('#quantity_mode').selectOption('packages');
        await page.locator('#received_packages').fill('2');
        assert.equal(await page.locator('#received_qty').isDisabled(), true);
        await page.locator('#receiving_next').click();
        assert.equal(await page.locator('#receiving_review').isVisible(), true, 'inactive invalid unit quantity does not block package review');
        assert.match(await page.locator('#receiving_review').innerText(), /48 accepted units/);
        await page.locator('#receiving_back').click();
        await page.locator('#quantity_mode').selectOption('units');
        await page.locator('#receiving_next').click();
        assert.equal(await page.locator('#received_qty').getAttribute('aria-invalid'), 'true');
        assert.equal(await page.locator('#received_qty').getAttribute('aria-describedby'), 'received_qty_error');
        await page.locator('#received_qty').fill('2');
        assert.equal(await page.locator('#received_qty').getAttribute('aria-invalid'), null);
        page.once('dialog', dialog => dialog.dismiss());
        await page.locator('.remove-receiving-line').click();
        assert.equal(await page.locator('#receiving_lines .receiving-line').count(), 1, 'cancel removal preserves entered details');
        assert.equal(await page.locator('#received_qty').inputValue(), '2');
        page.once('dialog', dialog => dialog.accept());
        await page.locator('.remove-receiving-line').click();
        assert.equal(await page.locator('#receiving_lines .receiving-line').count(), 0);
        await page.locator('#receiving_back').click();
        await page.locator('#add_receiving_line').click();
        for (let index = 0; index < 19; index++) await page.locator('#add_receiving_line').click();
        const expandedOptions = await page.locator('#receiving_lines [data-field="product_id"] option').count();
        assert.ok(expandedOptions <= catalogOptions + 38, `only the active line expands the full catalog (${expandedOptions}, catalog ${catalogOptions})`);
        const metrics = await page.evaluate(() => {
            const start = performance.now();
            const search = document.querySelector('#product_search');
            search.value = 'Receiving test product 999';
            search.dispatchEvent(new Event('input', {bubbles: true}));
            return {options: document.querySelectorAll('#receiving_lines option').length, searchMs: performance.now() - start};
        });
        console.log('Receiving UI metrics:', JSON.stringify(metrics));
        assert.ok(metrics.searchMs < 200, '1,001-product search stays below the interaction latency budget');
        await page.locator('#add_receiving_line').click();
        assert.equal(await page.locator('#product_id_20 option').count(), 2, 'new lines respect the existing search');
        assert.match(await page.locator('#product_search_status').innerText(), /1 matching product/);
        await page.locator('#product_id_20').selectOption({label: 'Receiving test product 999 (UI-999)'});
        await page.locator('#product_search').fill('no-such-product');
        assert.match(await page.locator('#product_search_status').innerText(), /No matching products/);
        assert.match(await page.locator('#product_id_20 option:checked').innerText(), /UI-999/, 'search preserves selected product');
        await page.locator('#product_code_scan').fill('THEME-BARCODE');
        await page.locator('#product_code_scan').press('Enter');
        await page.waitForFunction(() => document.querySelector('#scan_status').textContent.includes('selected. Matched'));
        assert.equal(await page.locator('#product_id_21').inputValue(), '1', 'barcode lookup can select outside the search filter');
        assert.deepEqual(errors, []);
        fs.mkdirSync(output, {recursive: true});
        await page.goto(url);
        const measurements = [];
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => { document.documentElement.dataset.theme = theme; document.documentElement.style.colorScheme = theme; }, theme);
            for (const width of [1440, 768, 390, 320]) {
                await page.setViewportSize({width, height: 1000});
                for (let step = 1; step <= 3; step++) {
                    if (step === 1 && await page.locator('#receiving_back').isVisible()) {
                        while (await page.locator('#receiving_back').isVisible()) await page.locator('#receiving_back').click();
                    }
                    if (step === 2) {
                        await page.locator('#product_id').selectOption('1');
                        await page.locator('#receiving_next').click();
                    }
                    if (step === 3) {
                        await page.locator('#received_qty').fill('5');
                        await page.locator('#cost_price').fill('3.50');
                        await page.locator('#notes').fill('Long note: ' + 'Receiving details 漢字 العربية '.repeat(15));
                        await page.locator('#receiving_next').click();
                    }
                    const layout = await page.evaluate(() => ({
                        overflow: document.documentElement.scrollWidth > innerWidth,
                        controls: Array.from(document.querySelectorAll('.receiving-form button, .receiving-form input:not([type="hidden"]), .receiving-form select, .receiving-form textarea'))
                            .filter(element => element.getClientRects().length)
                            .map(element => ({id:element.id, height:element.getBoundingClientRect().height, font:getComputedStyle(element).fontSize})),
                        unlabeled: Array.from(document.querySelectorAll('.receiving-form input:not([type="hidden"]), .receiving-form select, .receiving-form textarea')).filter(element => !element.labels?.length).map(element => element.id)
                    }));
                    assert.equal(layout.overflow, false, `${theme} ${width}px step ${step}: no page overflow`);
                    assert.deepEqual(layout.unlabeled, []);
                    assert.ok(layout.controls.every(control => control.height >= 44), `${theme} ${width}px step ${step}: 44px controls`);
                    const contrast = await page.evaluate(() => {
                        const rgb = color => color.match(/[\d.]+/g).slice(0, 3).map(Number);
                        const luminance = color => rgb(color).map(value => value / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4)
                            .reduce((sum, value, index) => sum + value * [.2126, .7152, .0722][index], 0);
                        const ratio = (one, two) => (Math.max(luminance(one), luminance(two)) + .05) / (Math.min(luminance(one), luminance(two)) + .05);
                        const background = element => {
                            while (element) {
                                const color = getComputedStyle(element).backgroundColor;
                                if (color !== 'rgba(0, 0, 0, 0)' && color !== 'transparent') return color;
                                element = element.parentElement;
                            }
                            return 'rgb(255,255,255)';
                        };
                        const failures = [];
                        let minimumText = Infinity, minimumBorder = Infinity;
                        for (const element of document.querySelectorAll('.receiving-form :is(p, label, small, li, h2, h3, h4, button, input:not([type="hidden"]), select, textarea)')) {
                            if (!element.getClientRects().length) continue;
                            const style = getComputedStyle(element);
                            const textRatio = ratio(style.color, background(element));
                            const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && Number(style.fontWeight) >= 700);
                            minimumText = Math.min(minimumText, textRatio);
                            if (textRatio < (large ? 3 : 4.5)) failures.push({id:element.id, text:element.textContent.slice(0, 40), textRatio});
                            if (element.matches('input, textarea') && element.placeholder) {
                                const placeholderRatio = ratio(getComputedStyle(element, '::placeholder').color, background(element));
                                minimumText = Math.min(minimumText, placeholderRatio);
                                if (placeholderRatio < 4.5) failures.push({id:element.id, placeholderRatio});
                            }
                            if (element.matches('input, select, textarea')) {
                                const borderRatio = ratio(style.borderTopColor, background(element.parentElement));
                                minimumBorder = Math.min(minimumBorder, borderRatio);
                                if (borderRatio < 3) failures.push({id:element.id, borderRatio});
                            }
                        }
                        return {failures, minimumText, minimumBorder};
                    });
                    assert.deepEqual(contrast.failures, [], `${theme} ${width}px step ${step}: AA text, placeholder and control contrast`);
                    measurements.push({theme, width, step, ...layout, contrast});
                    if (width === 1440 || width === 390) {
                        await page.evaluate(() => window.scrollTo({top:0, behavior:'instant'}));
                        await page.screenshot({path: path.join(output, `${theme}-${width}-step${step}.png`), fullPage: true});
                    }
                }
            }
        }
        await page.setViewportSize({width: 640, height: 900});
        await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, '200% text zoom remains readable without page overflow');
        await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
        await page.goto(url);
        await page.locator('#product_code_scan').fill('THEME-BARCODE');
        await page.route('**/product_code_lookup.php?**', route => route.fulfill({status:500, contentType:'application/json', body:'{"success":false}'}));
        await page.locator('#product_code_scan').press('Enter');
        await page.waitForFunction(() => document.querySelector('#scan_status').textContent.includes('could not be checked'));
        await page.unroute('**/product_code_lookup.php?**');
        await page.locator('#product_id').selectOption('1');
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('invalid inactive units');
        await page.locator('#quantity_mode').selectOption('packages');
        await page.locator('#received_packages').fill('2');
        await page.locator('#damaged_qty').fill('1');
        await page.locator('#receiving_next').click();
        await page.locator('[name="receiving_nonce"]').evaluate(input => { input.value = 'expired-ui-test'; });
        await Promise.all([page.waitForNavigation(), page.locator('.btn-submit').click()]);
        assert.match(await page.locator('.message.error').innerText(), /already been saved or expired/);
        assert.equal(await page.locator('#received_packages').inputValue(), '2', 'server rejection retains entered package quantity');
        if (await page.locator('.swal2-confirm').isVisible({timeout: 1000}).catch(() => false)) {
            await page.locator('.swal2-confirm').click();
        }
        await page.locator('#receiving_next').click();
        const [savedRequest] = await Promise.all([
            page.waitForRequest(request => request.method() === 'POST' && request.url().includes('/report/stock_receiving.php')),
            page.waitForNavigation(), page.locator('.btn-submit').click()
        ]);
        assert.match(await page.locator('.message.success').innerText(), /47 accepted units/);
        const state = JSON.parse(fixture('inventory_state'));
        assert.equal(Number(state.inventory.find(row => Number(row.product_id) === 1).quantity_on_hand), 57, 'package submission updates persisted stock once');
        const replay = await page.context().request.post(url, {form:Object.fromEntries(new URLSearchParams(savedRequest.postData()))});
        assert.match(await replay.text(), /already been saved or expired/);
        const replayState = JSON.parse(fixture('inventory_state'));
        assert.equal(Number(replayState.inventory.find(row => Number(row.product_id) === 1).quantity_on_hand), 57, 'replayed receipt never updates stock twice');
        await page.goto(url);
        await page.locator('.receiving-skip-link').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'receiving_main', 'keyboard bypasses repeated navigation');
        await page.locator('#product_id').focus();
        await page.keyboard.press('t');
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'add_receiving_line', 'keyboard reaches the next selection action');
        const touchContext = await browser.newContext({storageState: await page.context().storageState(), viewport:{width:390, height:844}, isMobile:true, hasTouch:true});
        const touchPage = await touchContext.newPage();
        await touchPage.goto(url);
        await touchPage.locator('#add_receiving_line').tap();
        assert.equal(await touchPage.locator('#receiving_lines .receiving-line').count(), 2, 'synthesized touch adds a product line');
        await touchPage.locator('#product_id_1').selectOption('1');
        await touchPage.locator('#product_id').focus();
        await touchPage.locator('#product_id').selectOption('1');
        assert.match(await touchPage.locator('#entry_status').innerText(), /Duplicate product/, 'duplicate feedback survives change events');
        await touchPage.locator('#product_id').selectOption({label:'Receiving test product 999 (UI-999)'});
        await touchPage.locator('#receiving_next').tap();
        assert.equal(await touchPage.locator('.receiving-form').getAttribute('data-step'), '2');
        await touchPage.locator('#received_qty').tap();
        await touchPage.locator('#received_qty').fill('1');
        await touchPage.locator('#received_qty_1').tap();
        await touchPage.locator('#received_qty_1').fill('2');
        await touchPage.locator('#receiving_next').tap();
        assert.equal(await touchPage.locator('#receiving_review').isVisible(), true, 'synthesized touch completes review with multiple products');
        await touchContext.close();
        const readonlyPage = await browser.newPage();
        await readonlyPage.goto(origin + '/?login=1');
        await readonlyPage.locator('#landing-login-username').fill('theme_super_admin');
        await readonlyPage.locator('#landing-login-password').fill(data.password);
        await Promise.all([readonlyPage.waitForURL(url => !url.search.includes('login=1')), readonlyPage.getByRole('button', {name:'Log in', exact:true}).click()]);
        await readonlyPage.goto(url);
        assert.equal(await readonlyPage.locator('#product_id').isDisabled(), true, 'technical workspace cannot record routine stock');
        assert.match(await readonlyPage.locator('.receiving-form').innerText(), /authorized Inventory Manager/);
        await readonlyPage.close();
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'measurements.json'), JSON.stringify({metrics, measurements, errors}, null, 2));
        console.log('Receiving UI hardening, search, themes and responsive checks: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
