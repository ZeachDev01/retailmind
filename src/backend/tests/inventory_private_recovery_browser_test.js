const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Inventory private recovery: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-inventory-private-'));
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-receiving-'))};
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/inventory_recovery_fixture.php', action, database], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
}
(async () => {
    let server, context;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = ''; server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        async function open(username = 'theme_inventory_manager') {
            context = await chromium.launchPersistentContext(userDataDir, {headless: true, viewport: {width: 820, height: 1180}});
            const page = context.pages()[0]; page.setDefaultTimeout(10000);
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill(username);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            await page.goto(origin + '/components/report/stock_receiving.php');
            return page;
        }
        let page = await open();
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('5');
        await page.locator('.receiving-extras summary').first().click();
        await page.locator('#batch_number').fill('PRIVATE-BATCH-316');
        await page.locator('#notes').fill('PRIVATE-NOTE-316');
        await page.locator('#supplier').fill('PRIVATE-STORE-316');
        await page.locator('#invoice_number').fill('PRIVATE-RECEIPT-316');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        const draftStorageKey = await page.evaluate(() => Object.keys(localStorage).find(key => key.startsWith('retailmind:inventory:v1:') && key.endsWith(':receiving_form')));
        assert(draftStorageKey, 'Edited receiving entry has a stored encrypted draft');
        const originalToken = await page.locator('[name="operation_token"]').inputValue();
        const storage = await page.evaluate(() => JSON.stringify(localStorage));
        assert(!storage.includes('PRIVATE-'), 'Device storage must not expose form values in plaintext');
        assert(!storage.includes('encryption_key'), 'Device storage must not persist an unlock key');
        await page.reload();
        await page.getByRole('button', {name: 'Resume', exact: true}).waitFor();
        assert.equal(await page.locator('#receiving_lines .receiving-line').count(), 0, 'Recovery never silently restores or posts');
        await page.getByRole('button', {name: 'Resume', exact: true}).click();
        assert.equal(await page.locator('#received_qty').inputValue(), '5');
        assert.equal(await page.locator('#notes').inputValue(), 'PRIVATE-NOTE-316');
        assert.equal(await page.locator('#supplier').inputValue(), 'PRIVATE-STORE-316');
        assert.equal(await page.locator('[name="operation_token"]').inputValue(), originalToken);
        await context.close(); context = null;
        page = await open('theme_inventory_other');
        assert.equal(await page.getByRole('button', {name: 'Resume', exact: true}).count(), 0, 'Another authenticated manager cannot discover or resume another user draft');
        assert(!(await page.locator('body').innerText()).includes('PRIVATE-'), 'Another manager cannot inspect draft values');
        await context.close(); context = null;
        page = await open();
        await page.getByRole('button', {name: 'Resume', exact: true}).click();
        assert.equal(await page.locator('#invoice_number').inputValue(), 'PRIVATE-RECEIPT-316', 'Same user unlocks draft after browser process restart');
        assert.equal(await page.locator('#batch_number').inputValue(), 'PRIVATE-BATCH-316');
        const before = JSON.parse(fixture('inventory_state'));
        await page.locator('#receiving_next').click();
        await context.setOffline(true);
        await Promise.all([
            page.waitForEvent('requestfailed', {predicate: request => request.method() === 'POST'}),
            page.locator('.btn-submit').click(),
        ]);
        const unsent = JSON.parse(fixture('inventory_state'));
        assert.equal(unsent.stock_receiving.length, before.stock_receiving.length, 'Connection failure before submission saves nothing');
        await context.setOffline(false);
        await page.reload(); await page.getByRole('button', {name:'Resume', exact:true}).click();
        if (await page.locator('#receiving_next').isVisible()) await page.locator('#receiving_next').click();
        let committedResolve; const committed = new Promise(resolve => { committedResolve = resolve; });
        await page.route('**/components/report/stock_receiving.php', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            const response = await route.fetch();
            assert(response.ok(), 'Server committed receiving before simulating response loss');
            await route.abort('failed'); committedResolve();
        });
        await page.locator('.btn-submit').click(); await Promise.race([committed, new Promise((_, reject) => setTimeout(() => reject(new Error('No receiving POST after submit')), 15000))]);
        await page.unroute('**/components/report/stock_receiving.php');
        await page.reload();
        await page.getByRole('button', {name: 'Resume', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[name="operation_token"]').value.length === 48);
        if (await page.locator('#receiving_next').isVisible()) await page.locator('#receiving_next').click();
        await page.locator('.btn-submit').click();
        await page.waitForFunction(() => document.querySelector('.message.success')?.textContent.includes('Receiving entry saved'));
        await page.waitForFunction(key => localStorage.getItem(key) === null, draftStorageKey);
        const after = JSON.parse(fixture('inventory_state'));
        assert.equal(after.stock_receiving.length, before.stock_receiving.length + 1, 'Response-loss retry saves exactly one receiving record');
        assert.equal(after.stock_movements.length, before.stock_movements.length + 1, 'Response-loss retry posts exactly one stock movement');
        // Explicit Discard and storage-unavailable feedback use the same browser workflow.
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('7');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await page.getByRole('button', {name: 'Discard', exact: true}).click();
        await page.reload(); assert.equal(await page.getByRole('button', {name: 'Resume', exact: true}).count(), 0);
        // Dynamic Count rows are rebuilt from current coverage before values resume.
        await page.goto(origin + '/components/inventory_management/inventory_counts.php');
        await page.locator('#product_id').selectOption('1', {force:true});
        await page.locator('#add_extra_batch_btn').waitFor({state: 'visible'});
        await page.locator('#add_extra_batch_btn').click();
        await page.locator('[name="batches[extra_1][physical_quantity]"]').fill('2');
        await page.locator('[name="batches[extra_1][batch_number]"]').fill('FOUND-DRAFT');
        await page.locator('[name="batches[extra_1][notes]"]').fill('Found on top shelf');
        await page.locator('[name="discrepancy_reason"]').fill('Shelf count draft');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await page.getByRole('button', {name:'Resume', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('[name="batches[extra_1][notes]"]')?.value === 'Found on top shelf');
        assert.equal(await page.locator('[name="batches[extra_1][batch_number]"]').inputValue(), 'FOUND-DRAFT');
        assert.equal(await page.locator('[name="discrepancy_reason"]').inputValue(), 'Shelf count draft');
        // Supplier Pullout restores the selected current batch before quantities and notes.
        await page.goto(origin + '/components/inventory_management/supplier_pullouts.php');
        await page.locator('#product_id').selectOption('1', {force:true});
        await page.waitForFunction(() => document.querySelector('#batch_id').options.length > 1);
        const batchId = await page.locator('#batch_id option').nth(1).getAttribute('value');
        await page.locator('#batch_id').selectOption(batchId, {force:true});
        await page.locator('[name="quantity"]').fill('1');
        await page.locator('[name="reason"]').fill('Damaged packaging');
        await page.locator('[name="notes"]').fill('Pullout draft notes');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await page.getByRole('button', {name:'Resume', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('[name="notes"]').value === 'Pullout draft notes');
        assert.equal(await page.locator('#batch_id').inputValue(), batchId);
        assert.equal(await page.locator('[name="quantity"]').inputValue(), '1');
        // Stock Issue review retains its report identity and entered explanation.
        await page.goto(origin + '/components/inventory_management/stock_issues.php');
        await page.locator('.review-form [name="review_notes"]').first().fill('Review on tablet draft');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await page.getByRole('button', {name:'Resume', exact:true}).click();
        assert.equal(await page.locator('.review-form [name="review_notes"]').first().inputValue(), 'Review on tablet draft');
        // Explicit Product creation retains entered fields and wizard context, including an image.
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
        await page.locator('#add-product-form [name="product_name"]').fill('Unfinished Product draft');
        await page.locator('#add-product-form [name="brand"]').fill('Draft brand');
        await page.locator('#product-image-input').setInputFiles({name:'draft.png', mimeType:'image/png', buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6w5kAAAAASUVORK5CYII=', 'base64')});
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await page.getByRole('button', {name:'Resume', exact:true}).click();
        assert.equal(await page.locator('#add-product-form [name="product_name"]').inputValue(), 'Unfinished Product draft');
        assert.equal(await page.locator('#add-product-form [name="brand"]').inputValue(), 'Draft brand');
        assert.equal(await page.locator('#product-image-input').evaluate(field => field.files[0]?.name), 'draft.png');
        await page.evaluate(() => RetailMindUI.closeOverlay(document.getElementById('add-product-modal')));
        // Explicit Product edit recovery reopens the correct Product, without creating one.
        await page.locator('.manage-product[data-product-id="1"]').click();
        await page.locator('#product-edit-btn').click();
        await page.locator('#edit-product-name').fill('Existing Product draft edit');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload();
        await page.locator('[data-recovery-id="product-edit-form"]').getByRole('button', {name:'Resume', exact:true}).click();
        assert.equal(await page.locator('#edit-product-name').inputValue(), 'Existing Product draft edit');
        assert.equal(await page.locator('#product-edit-form [name="product_id"]').inputValue(), '1');
        await page.goto(origin + '/components/report/stock_receiving.php');
        await page.addInitScript(() => { Storage.prototype.setItem = () => { throw new Error('Storage blocked'); }; });
        await page.reload();
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Draft recovery is unavailable'));
        assert.deepEqual(errors, []);
        console.log('Inventory private recovery and lost-response HTTP retry: passed (Chromium emulation; physical tablets pending)');
    } finally {
        if (context) await context.close();
        if (server) server.kill();
        fixture('cleanup');
        fs.rmSync(userDataDir, {recursive:true, force:true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
