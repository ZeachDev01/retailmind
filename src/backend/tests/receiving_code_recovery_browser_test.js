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
const output = path.resolve('output/issue-481');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/' + (action.startsWith('receiving_code_') ? 'receiving_code_fixture.php' : action === 'create' ? 'receiving_ui_fixture.php' : 'theme_fixture.php'), action, database], {encoding: 'utf8', env});
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
        const lookup = async (code, receiving = true) => (await page.request.get(origin + '/components/barcodeScanner/apiScanner/product_code_lookup.php?code=' + encodeURIComponent(code) + (receiving ? '&context=receiving' : ''))).json();
        fixture('receiving_code_overlap');
        assert.equal((await lookup('THEME-ITEM')).matched_code_kind, 'case_barcode', 'receiving prefers the explicit case barcode over SKU');
        assert.equal((await lookup('THEME-ITEM', false)).matched_code_kind, 'sku', 'other lookup callers retain SKU precedence');
        fixture('receiving_code_pack_overlap');
        assert.equal((await lookup('THEME-ITEM')).matched_code_kind, 'pack_barcode');
        fixture('receiving_code_unit_overlap');
        assert.equal((await lookup('THEME-ITEM')).matched_code_kind, 'unit_barcode');
        fixture('receiving_code_legacy');
        assert.equal((await lookup('THEME-BARCODE')).outcome, 'ambiguous', 'legacy barcode type conflicts require explicit resolution');
        fixture('receiving_code_multiple');
        assert.equal((await lookup('UI-BARCODE-0')).outcome, 'ambiguous', 'multiple products never select arbitrarily');
        fixture('receiving_unavailable');
        const inactive = await lookup('THEME-ITEM');
        assert.equal(inactive.outcome, 'inactive');
        assert.equal(inactive.product, null);
        assert.deepEqual(inactive.candidates, [], 'inactive record data stays private');
        assert.equal((await lookup('THEME-ITEM', false)).outcome, 'unknown');
        fixture('receiving_code_reset');
        fixture('receiving_pack_config');
        const before = fixture('inventory_state');
        const scan = async code => {
            await page.locator('#product_search_scan').fill(code);
            await page.locator('#product_search_scan').press('Enter');
            await page.waitForFunction(() => !document.getElementById('scan_status').textContent.includes('Checking'));
        };
        for (const [code, mode] of [['THEME-ITEM','units'], ['THEME-BARCODE','units'], ['THEME-CASE','packages'], ['THEME-PACK','packs']]) {
            const match = await lookup(code);
            assert.equal(match.outcome, 'match');
            await page.goto(url);
            await scan(code);
            assert.equal(await page.locator('#quantity_mode').inputValue(), mode);
            assert.equal(await page.locator('#receiving_code_recovery').isVisible(), false, 'known packaging skips recovery');
            assert.equal(await page.evaluate(() => document.activeElement.id), mode === 'units' ? 'received_qty' : mode === 'packages' ? 'received_packages' : 'received_packs');
        }
        await page.goto(url);
        await page.locator('#product_search_scan').fill('UNKNOWN-CARTON-12');
        await page.locator('#product_search_scan').press('Enter');
        await page.locator('#receiving_code_recovery').waitFor({state:'visible'});
        assert.equal(await page.locator('[name=scan_packaging]:checked').count(), 0, 'unknown classification has no default');
        await page.getByLabel('Receiving case', {exact:true}).check();
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        assert.match(await page.locator('#scan_package_confirmation').innerText(), /24/);
        assert.equal(await page.locator('.receiving-line').count(), 0, 'unknown carton requires explicit size confirmation before adding');
        await page.locator('#scan_use_units').click();
        await page.locator('#received_qty').fill('12');
        assert.equal(await page.locator('#quantity_mode').inputValue(), 'units', '12-unit carton does not inherit saved 24-unit conversion');
        await page.locator('#supplier').fill('Delivery stays');
        await scan('UNKNOWN-CANCEL');
        await page.getByLabel('Sales Pack', {exact:true}).check();
        assert.match(await page.locator('#scan_packaging_hint').innerText(), /size, barcode and price/);
        await page.locator('#scan_back').click();
        assert.equal(await page.locator('#product_search_scan').inputValue(), 'UNKNOWN-CANCEL');
        await page.locator('#scan_cancel').click();
        assert.equal(await page.locator('#product_search_scan').inputValue(), 'UNKNOWN-CANCEL');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'product_search_scan');
        assert.equal(await page.locator('#received_qty').inputValue(), '12');
        assert.equal(await page.locator('#supplier').inputValue(), 'Delivery stays');
        const pattern = '**/product_code_lookup.php?*';
        await page.route(pattern, route => route.abort('failed'));
        await scan('LOOKUP-FAILURE');
        assert.equal(await page.locator('#scan_classification').isVisible(), false, 'network error never becomes an unknown code');
        assert.equal(await page.locator('#scan_retry').isVisible(), true);
        await page.unroute(pattern);
        await page.locator('#scan_retry').click();
        await page.locator('#scan_classification').waitFor({state:'visible'});
        await page.locator('#scan_cancel').click();
        assert.equal(await page.locator('#received_qty').inputValue(), '12');
        assert.equal(fixture('inventory_state'), before, 'lookup and manual recovery never change product identifiers, status or stock');
        await page.locator('#receiving_next').click();
        await page.locator('#receiving_review').waitFor({state:'visible'});
        assert.match(await page.locator('#receiving_review').innerText(), /12/);
        await page.locator('#receiving_back').click();
        await page.goto(url);
        await scan('UNKNOWN-CONFIRM');
        await page.getByLabel('Receiving case', {exact:true}).check();
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        fixture('receiving_conversion_changed');
        await page.locator('#scan_confirm_size').click();
        await page.waitForFunction(() => document.getElementById('scan_saved_size').textContent.includes('12 base units'));
        assert.equal(await page.locator('.receiving-line').count(), 0, 'changed size must be explicitly confirmed again');
        await page.locator('#scan_confirm_size').click();
        await page.locator('#received_packages').waitFor({state:'visible'});
        assert.equal(await page.locator('#quantity_mode').inputValue(), 'packages');
        await page.locator('#received_packages').fill('1');
        await scan('UNKNOWN-EXISTING-CONVERSION');
        await page.getByLabel('Receiving case', {exact:true}).check();
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        fixture('receiving_pack_config');
        await page.locator('#scan_confirm_size').click();
        await page.waitForFunction(() => document.getElementById('scan_saved_size').textContent.includes('24 base units'));
        await page.locator('#scan_confirm_size').click();
        await page.locator('#receiving_code_recovery').waitFor({state:'hidden'});
        await page.locator('#receiving_next').click();
        await page.waitForFunction(() => document.getElementById('entry_status').textContent.includes('Product conversion or saved cost changed'));
        assert.equal(await page.locator('#receiving_review').isVisible(), false, 'existing delivery still needs refreshed Review after unknown carton confirmation');
        assert.equal(await page.locator('#received_packages').inputValue(), '1');
        await scan('UNKNOWN-APPEARANCE');
        fs.mkdirSync(output, {recursive:true});
        for (const theme of ['light','dark']) {
            for (const width of [1440,390]) {
                await page.setViewportSize({width,height:1000});
                await page.emulateMedia({reducedMotion:'reduce'});
                await page.evaluate(t => {document.documentElement.dataset.theme=t;document.documentElement.style.colorScheme=t;}, theme);
                await page.locator('#scan_recovery_message').evaluate(el => el.blur());
                await page.locator('#scan_recovery_message').focus();
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
                const box = await page.locator('#scan_recovery_message').boundingBox();
                assert(box.y >= 60 && box.y + box.height <= 1000, 'recovery focus clears fixed chrome: ' + JSON.stringify({width,theme,box}));
                await page.screenshot({path:path.join(output, `recovery-${width}-${theme}.png`),fullPage:true});
            }
        }
        await page.setViewportSize({width:1440,height:1000});
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        assert.equal(await page.locator('[name=scan_packaging]:checked').count(), 0);
        assert.equal(await page.evaluate(() => document.activeElement.value), 'units', 'selection without classification focuses explicit choice');
        await page.locator('#scan_cancel').click();
        fixture('receiving_unavailable');
        await scan('THEME-ITEM');
        assert.equal(await page.locator('#scan_classification').isVisible(), false);
        assert.equal(await page.locator('#scan_manage_product').isVisible(), true);
        assert.equal(await page.locator('#received_packages').inputValue(), '1');
        await page.locator('#scan_cancel').click();
        fixture('receiving_code_reset');
        fixture('receiving_code_legacy');
        await scan('THEME-BARCODE');
        assert.equal(await page.locator('#scan_classification').isVisible(), false);
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        assert.equal(await page.locator('#scan_confirm_size').isVisible(), false);
        await page.locator('#scan_use_units').click();
        assert.equal(await page.locator('#received_packages').inputValue(), '1', 'legacy resolution preserves existing entered allocation');
        assert.equal(await page.evaluate(() => document.activeElement.id), 'received_qty_1', 'explicit base-unit recovery adds a separate base-unit batch');
        assert.equal(await page.locator('#quantity_mode_1').inputValue(), 'units');
        fixture('receiving_code_reset');
        await page.route(pattern, route => route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false,error:'Lookup unavailable'})}));
        await scan('SERVER-FAILURE');
        assert.equal(await page.locator('#scan_classification').isVisible(), false);
        await page.unroute(pattern);
        await page.locator('#scan_cancel').click();
        await page.locator('#received_qty_1').fill('5');
        await scan('UNKNOWN-RESUME');
        await page.getByLabel('Receiving case', {exact:true}).check();
        await page.evaluate(() => RetailMindInventoryDrafts.keep(document.getElementById('receiving_form')));
        await page.reload();
        await page.getByRole('button', {name:'Resume',exact:true}).click();
        await page.locator('#receiving_code_recovery').waitFor({state:'visible'});
        assert.equal(await page.locator('#product_search_scan').inputValue(), 'UNKNOWN-RESUME');
        assert.equal(await page.locator('#received_packages').inputValue(), '1');
        assert.equal(await page.locator('#received_qty_1').inputValue(), '5');
        await page.locator('#scan_find_existing').click();
        await page.locator('#product_search_scan').fill('THEME-ITEM');
        await page.getByRole('button', {name:'Add Theme Test Item',exact:true}).click();
        await page.locator('#scan_package_confirmation').waitFor({state:'visible'});
        // Different authenticated workspaces cannot use receiving lookup to disclose inactive records.
        const cashier = await browser.newPage();
        await cashier.goto(origin + '/?login=1');
        await cashier.locator('#landing-login-username').fill('theme_cashier');
        await cashier.locator('#landing-login-password').fill(data.password);
        await Promise.all([cashier.waitForURL(u => !u.search.includes('login=1')),cashier.getByRole('button',{name:'Log in',exact:true}).click()]);
        const denied = await cashier.request.get(origin + '/components/barcodeScanner/apiScanner/product_code_lookup.php?context=receiving&code=THEME-ITEM');
        assert.equal(denied.status(), 403);
        assert.equal((await denied.json()).product, undefined);
        assert.deepEqual(errors, []);
        console.log('Receiving code recovery: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
