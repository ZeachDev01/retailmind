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
const output = path.resolve('output/issue-479');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/' + (action === 'create' ? 'receiving_ui_fixture.php' : 'theme_fixture.php'), action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('open_fiscal_period');
        fixture('receiving_pack_config');
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
        // Each navigation starts a separate scenario; draft persistence has its own test.
        await page.addInitScript(() => {
            Object.keys(sessionStorage).filter(key => key.startsWith('receiving-draft:')).forEach(key => sessionStorage.removeItem(key));
        });
        page.setDefaultTimeout(6000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const url = origin + '/components/report/stock_receiving.php';
        const state = () => JSON.parse(fixture('inventory_state'));
        let before = state();
        const scan = async code => {
            await page.locator('#product_search_scan').fill(code);
            await Promise.all([page.waitForResponse(r => r.url().includes('product_code_lookup.php')), page.locator('#product_search_scan').press('Enter')]);
            await page.waitForFunction(() => !document.getElementById('scan_status').textContent.includes('Checking'));
        };
        const review = async () => {
            await page.locator('#receiving_next').click();
            await page.locator('#receiving_review').waitFor({state: 'visible'});
        };
        await page.goto(url);
        await scan('THEME-CASE');
        await page.locator('#received_packages').fill('1');
        await page.getByRole('button', {name:'Add another batch', exact:true}).click();
        assert.equal(await page.locator('.receiving-product-group').count(), 1, 'one product group contains independent batches');
        assert.equal(await page.locator('.receiving-line').count(), 2);
        assert.equal(await page.locator('#batch_number_1').inputValue(), '', 'new batches never copy provenance');
        assert.equal(await page.locator('#expiration_date_1').inputValue(), '');
        await scan('THEME-CASE');
        assert.equal(await page.locator('#quantity_mode_1').inputValue(), 'packs', 'rescan cannot change even an empty added batch mode');
        await page.locator('#quantity_mode_1').selectOption('units');
        await page.locator('#received_qty_1').fill('12');
        await scan('THEME-PACK');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_qty_1', 'rescan focuses the edited allocation');
        assert.equal(await page.locator('#received_qty_1').inputValue(), '12');
        assert.equal(await page.locator('#summary_products').innerText(), '1');
        assert.equal(await page.locator('#summary_accepted').innerText(), '36');
        await page.locator('#quantity_mode').selectOption('units');
        await page.locator('#received_qty').fill('12');
        await page.locator('.receiving-line').first().locator('summary').click();
        await page.locator('#cost_price').fill('6');
        fixture('receiving_conversion_changed');
        await page.locator('#receiving_next').click();
        await page.waitForFunction(() => document.getElementById('entry_status').textContent.includes('changed'));
        assert.equal(await page.locator('#receiving_review').isVisible(), false, 'a later inherited-cost batch still requires refreshed Review');
        await review();
        await page.locator('#receiving_back').click();
        fixture('receiving_pack_config');
        await review();
        await page.locator('#receiving_back').click();
        await page.locator('#quantity_mode').selectOption('packages');
        for (const [index, price, lot, expiry] of [[0, '6', 'MAY', '2027-05-31'], [1, '7', 'AUG', '2027-08-31']]) {
            const row = page.locator('.receiving-line').nth(index);
            if (!(await row.locator('details').evaluate(details => details.open))) await row.locator('summary').click();
            await row.locator('[data-field="cost_price"]').fill(price);
            await row.locator('[data-field="batch_number"]').fill(lot);
            await row.locator('[data-field="expiration_date"]').fill(expiry);
        }
        for (const close of await page.getByRole('button', {name:'Dismiss notification',exact:true}).all()) await close.click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width,height:1000});
                await page.emulateMedia({reducedMotion:'reduce'});
                await page.evaluate(t => {document.documentElement.dataset.theme=t; document.documentElement.style.colorScheme=t;}, theme);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'multi-batch editor fits ' + width + ' ' + theme);
                await page.getByRole('button', {name:'Add another batch',exact:true}).focus();
                fs.mkdirSync('output/issue-480', {recursive:true});
                await page.getByRole('button', {name:'Add another batch',exact:true}).evaluate(button => button.blur());
                await page.screenshot({path:`output/issue-480/batches-${width}-${theme}.png`,fullPage:true});
            }
        }
        await page.setViewportSize({width:1440,height:1000});
        await page.locator('#product_search_scan').fill('');
        await review();
        assert.equal(await page.locator('#current_product_cost_0').inputValue(), '', 'differing known costs require an explicit choice');
        await page.locator('.btn-submit').click();
        await page.waitForFunction(() => document.activeElement.id === 'current_product_cost_0');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'current_product_cost_0');
        assert.equal(state().stock_receiving.length, before.stock_receiving.length, 'missing choice saves nothing');
        await page.locator('#current_product_cost_0').selectOption('7.00');
        await page.locator('#receiving_back').click();
        await review();
        assert.equal(await page.locator('#current_product_cost_0').inputValue(), '7.00', 'Review retains a valid explicit choice');
        await page.locator('#receiving_back').click();
        await page.locator('#cost_price_1').fill('8');
        await review();
        assert.equal(await page.locator('#current_product_cost_0').inputValue(), '', 'editing the selected cost invalidates the choice');
        await page.locator('#receiving_back').click();
        await page.locator('#cost_price_1').fill('7');
        await page.getByRole('button', {name:'Add another batch',exact:true}).click();
        await page.locator('#receiving_next').click();
        assert.equal(await page.locator('#receiving_review').isVisible(), false, 'an added empty batch blocks Review');
        await page.locator('.receiving-line').nth(2).getByRole('button', {name:/Remove/}).click();
        assert.equal(await page.locator('.receiving-line').count(), 2, 'removing one batch preserves siblings');
        await page.getByRole('button', {name:'Add another batch',exact:true}).click();
        await page.locator('#quantity_mode_2').selectOption('units');
        await page.locator('#received_qty_2').fill('1');
        await page.locator('.receiving-line').nth(2).locator('summary').click();
        await page.locator('#cost_price_2').fill('9');
        await review();
        await page.locator('#current_product_cost_0').selectOption('9.00');
        await page.locator('#receiving_back').click();
        page.once('dialog', dialog => dialog.dismiss());
        await page.locator('.receiving-line').nth(2).getByRole('button', {name:/Remove/}).click();
        assert.equal(await page.locator('.receiving-line').count(), 3, 'cancelling removal preserves entered batch values');
        page.once('dialog', dialog => dialog.accept());
        await page.locator('.receiving-line').nth(2).getByRole('button', {name:/Remove/}).click();
        await review();
        assert.equal(await page.locator('#current_product_cost_0').inputValue(), '', 'removing the selected cost requires a new choice');
        await page.locator('#current_product_cost_0').selectOption('7.00');
        await page.evaluate(() => RetailMindInventoryDrafts.keep(document.getElementById('receiving_form')));
        await page.reload();
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        await page.locator('.receiving-line').nth(1).waitFor({state:'visible'});
        assert.equal(await page.locator('.receiving-product-group').count(), 1);
        assert.equal(await page.locator('.receiving-line').count(), 2, 'encrypted recovery preserves all allocations');
        assert.equal(await page.locator('#batch_number').inputValue(), 'MAY');
        assert.equal(await page.locator('#batch_number_1').inputValue(), 'AUG');
        assert.equal(await page.locator('#quantity_mode').inputValue(), 'packages');
        assert.equal(await page.locator('#received_packages').inputValue(), '1');
        assert.equal(await page.locator('#quantity_mode_1').inputValue(), 'units');
        assert.equal(await page.locator('#received_qty_1').inputValue(), '12');
        await review();
        assert.equal(await page.locator('#current_product_cost_0').inputValue(), '7.00', 'encrypted recovery keeps the valid current-cost choice');
        await page.route('**/stock_receiving.php', route => route.request().method() === 'POST' ? route.abort('failed') : route.continue());
        await page.locator('.btn-submit').click();
        await page.waitForTimeout(400);
        await page.unroute('**/stock_receiving.php');
        await page.goto(url);
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        await page.locator('.receiving-line').nth(1).waitFor({state:'visible'});
        assert.equal(await page.locator('.receiving-line').count(), 2, 'uncertain response keeps both batches');
        await review();
        const [multiRequest, multiResponse] = await Promise.all([
            page.waitForRequest(r => r.method() === 'POST' && r.url() === url, {timeout:20000}),
            page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url, {timeout:20000}),
            page.locator('.btn-submit').click(),
        ]);
        assert.match(await multiResponse.text(), /Receiving entry saved/);
        assert.equal(state().stock_receiving.length, before.stock_receiving.length + 2, 'one save persists two batches');
        const multiReplay = await page.context().request.post(url, {form:Object.fromEntries(new URLSearchParams(multiRequest.postData()))});
        assert.match(await multiReplay.text(), /Receiving entry saved/);
        assert.equal(state().stock_receiving.length, before.stock_receiving.length + 2, 'multi-batch retries do not duplicate stock');
        before = state();
        await page.goto(url);
        await scan('THEME-CASE');
        assert.equal(await page.locator('.receiving-line').count(), 1, JSON.stringify({errors, scan:await page.locator('#scan_status').innerText()}));
        assert.equal(await page.locator('#quantity_mode').inputValue(), 'packages');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_packages');
        await page.locator('#received_packages').fill('2');
        await scan('THEME-PACK');
        assert.equal(await page.locator('#quantity_mode').inputValue(), 'packages', 'rescan keeps edited mode');
        assert.equal(await page.locator('#received_packages').inputValue(), '2');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_packages', 'rescan focuses visible quantity');
        assert.equal(await page.locator('.receiving-line').count(), 1);
        await page.locator('#quantity_mode').selectOption('packs');
        await page.locator('#received_packs').fill('3');
        await page.locator('#quantity_mode').selectOption('packages');
        assert.equal(await page.locator('#received_packages').inputValue(), '2', 'switching modes keeps original counts');
        await page.locator('#quantity_mode').selectOption('packs');
        assert.equal(await page.locator('#received_packs').inputValue(), '3');
        await page.locator('#quantity_mode').selectOption('packages');
        await page.locator('#batch_number').fill('INLINE-LOT');
        await page.locator('#supplier').fill('Test shop');
        await page.locator('#invoice_number').fill('INLINE-479');
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 768, 600, 390, 320]) {
                await page.setViewportSize({width, height: 1000});
                await page.emulateMedia({reducedMotion: 'reduce'});
                await page.evaluate(t => { document.documentElement.dataset.theme = t; document.documentElement.style.colorScheme = t; }, theme);
                assert.equal(await page.locator('#batch_number').isVisible(), true);
                assert.equal(await page.locator('#expiration_date').isVisible(), true);
                const geometry = await page.evaluate(() => {
                    const rect = id => { const r = document.getElementById(id).getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width}; };
                    return {catalog:rect('receiving_selection'), editor:document.querySelector('.delivery-editor').getBoundingClientRect().toJSON(), lot:rect('batch_number'), expiry:rect('expiration_date'), overflow:document.documentElement.scrollWidth > innerWidth};
                });
                assert.equal(geometry.overflow, false, theme + ' ' + width + ' fits');
                if (width === 1440) {
                    assert(geometry.editor.x > geometry.catalog.x, 'split catalog');
                    assert.equal(geometry.lot.y, geometry.expiry.y, 'lot and expiry side by side');
                } else if (width <= 600) assert(geometry.expiry.y > geometry.lot.y, 'lot and expiry stacked');
                await page.locator('#received_packages').focus();
                await page.locator('#received_packages').evaluate(el => el.scrollIntoView({block:'center',behavior:'instant'}));
                const box = await page.locator('#received_packages').boundingBox();
                const chromeBottom = await page.evaluate(() => Math.max(0, ...Array.from(document.querySelectorAll('.global-topbar, .admin-mobile-topbar')).filter(el => el.getClientRects().length).map(el => el.getBoundingClientRect().bottom)));
                assert(box.y >= chromeBottom && box.y + box.height <= 1000, 'quantity clears fixed chrome');
                fs.mkdirSync(output, {recursive:true});
                await page.screenshot({path:path.join(output, `editor-${width}-${theme}.png`), fullPage:true});
            }
        }
        assert.equal(state().stock_receiving.length, before.stock_receiving.length, 'scanning and editing never saves stock');
        await review();
        assert.match(await page.locator('#receiving_review').innerText(), /48 units/);
        assert.match(await page.locator('#receiving_review').innerText(), /1 case = 24 base units/);
        assert.match(await page.locator('#receiving_review').innerText(), /Expiry: Not recorded/);
        await page.getByRole('button', {name:'Back to editing',exact:true}).click();
        assert.equal(await page.locator('#received_packages').inputValue(), '2');
        await page.locator('#received_packages').fill('');
        await page.locator('#receiving_next').click();
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_packages');
        await page.locator('#entry_status a').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_packages');
        await page.locator('#received_packages').fill('2');
        fixture('receiving_conversion_changed');
        await page.locator('#receiving_next').click();
        await page.waitForFunction(() => document.getElementById('entry_status').textContent.includes('changed'));
        assert.equal(await page.locator('#received_packages').inputValue(), '2');
        await review();
        assert.match(await page.locator('#receiving_review').innerText(), /24 units/);
        assert.match(await page.locator('#receiving_review').innerText(), /Cost: ₱5.00/);
        fixture('receiving_pack_config');
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:20000}), page.locator('.btn-submit').click()]);
        assert.match(await page.locator('.message.error').innerText(), /conversion changed/);
        assert.equal(await page.locator('#received_packages').inputValue(), '2', 'stale commit keeps quantities');
        await page.locator('#quantity_mode').selectOption('packs');
        assert.equal(await page.locator('#received_packs').inputValue(), '3', 'rejected commit retains inactive mode counts');
        await page.locator('#quantity_mode').selectOption('packages');
        assert.equal(state().stock_receiving.length, before.stock_receiving.length, 'stale commit is atomic');
        fixture('receiving_conversion_changed');
        await page.locator('#receiving_next').click();
        await page.waitForFunction(() => document.getElementById('entry_status').textContent.includes('changed'));
        await review();
        await page.locator('#receiving_back').click();
        await page.waitForFunction(() => Object.keys(localStorage).some(k => k.startsWith('retailmind:inventory:v1:')));
        await page.waitForTimeout(500);
        await page.reload();
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        assert.equal(await page.locator('#received_packages').inputValue(), '2', 'encrypted recovery keeps quantities');
        assert.equal(await page.locator('#batch_number').inputValue(), 'INLINE-LOT');
        assert.equal(await page.locator('#supplier').inputValue(), 'Test shop');
        await review();
        await page.route('**/stock_receiving.php', route => route.request().method() === 'POST' ? route.abort('failed') : route.continue());
        await page.locator('.btn-submit').click();
        await page.waitForTimeout(500);
        await page.unroute('**/stock_receiving.php');
        await page.goto(url);
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        assert.equal(await page.locator('#received_packages').inputValue(), '2', 'uncertain save retains draft');
        await review();
        const [request, savedResponse] = await Promise.all([
            page.waitForRequest(r => r.method() === 'POST' && r.url() === url),
            page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url),
            page.locator('.btn-submit').click()
        ]);
        assert.match(await savedResponse.text(), /Receiving entry saved[^<]*24 accepted units/);
        await page.waitForFunction(() => !Object.keys(localStorage).some(k => k.startsWith('retailmind:inventory:v1:') && k.endsWith('receiving_form')));
        const replay = await page.context().request.post(url, {form:Object.fromEntries(new URLSearchParams(request.postData()))});
        assert.match(await replay.text(), /Receiving entry saved/);
        assert.equal(state().stock_receiving.length, before.stock_receiving.length + 1, 'retry token prevents duplicate receipt');
        // Legacy stage snapshots cross the encrypted storage boundary, just like older clients.
        await page.goto(url + '?product_id=1');
        await page.locator('#quantity_mode').selectOption('units');
        await page.locator('#received_qty').fill('7');
        const documentSource = await (await page.context().request.get(url)).text();
        const draftKey = JSON.parse(documentSource.match(/key:\s*("[^"]+")/)[1]);
        for (const legacyStep of [1, 2, 3]) {
            await page.evaluate(async ({draftKey, legacyStep}) => {
                await RetailMindInventoryDrafts.keep(document.getElementById('receiving_form'));
                const storageKey = Object.keys(localStorage).find(k => k.startsWith('retailmind:inventory:v1:') && k.endsWith('receiving_form'));
                const decode = value => Uint8Array.from(atob(value), c => c.charCodeAt(0));
                const key = await crypto.subtle.importKey('raw', decode(draftKey), {name:'AES-GCM'}, false, ['encrypt','decrypt']);
                const stored = JSON.parse(localStorage.getItem(storageKey));
                const draft = JSON.parse(new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:decode(stored.iv)},key,decode(stored.ciphertext))));
                draft.context.step = legacyStep;
                if (legacyStep === 3) draft.context.lines[0].damaged_qty = '1';
                const iv = crypto.getRandomValues(new Uint8Array(12));
                const ciphertext = new Uint8Array(await crypto.subtle.encrypt({name:'AES-GCM',iv},key,new TextEncoder().encode(JSON.stringify(draft))));
                const encode = bytes => btoa(Array.from(bytes, b => String.fromCharCode(b)).join(''));
                localStorage.setItem(storageKey,JSON.stringify({iv:encode(iv),ciphertext:encode(ciphertext)}));
            }, {draftKey, legacyStep});
            await page.reload();
            await page.getByRole('button', {name:'Resume',exact:true}).click();
            assert.equal(await page.locator('.receiving-form').getAttribute('data-step'), '1', 'old stages migrate into editor');
            assert.equal(await page.locator('#received_qty').inputValue(), legacyStep === 3 ? '' : '7', 'excluded quantities require correction');
        }
        await page.locator('#received_qty').fill('4');
        await page.evaluate(() => RetailMindInventoryDrafts.keep(document.getElementById('receiving_form')));
        fixture('receiving_unavailable');
        await page.reload();
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        await page.waitForFunction(() => document.getElementById('inventory-draft-status')?.textContent.includes('Your draft is still kept'));
        assert(await page.evaluate(() => Object.keys(localStorage).some(k => k.startsWith('retailmind:inventory:v1:'))), 'unavailable product retains saved draft');
        assert.deepEqual(errors, []);
        console.log('Inline receiving editor, scans, recovery, stale Review and retry: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
