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
    const errors = [];
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
            page.on('pageerror', error => errors.push(error.message));
            page.setDefaultNavigationTimeout(20000);
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill(username);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1'), {waitUntil:'domcontentloaded'}), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
            await page.goto(origin + '/components/report/stock_receiving.php');
            return page;
        }
        let page = await open();
        async function resumeDraft(id) {
            const panel = id ? page.locator('[data-recovery-id="' + id + '"]') : page.locator('[data-recovery-id]').first();
            const draftId = await panel.getAttribute('data-recovery-id');
            await panel.getByRole('button', {name:'Resume', exact:true}).click();
            await page.locator('[data-recovery-id="' + draftId + '"]').waitFor({state:'detached'});
        }

        // Migrate only this user's older receiving data; never delete another user's work.
        await page.evaluate(() => {
            sessionStorage.setItem('receiving-draft:3', JSON.stringify({supplier:'Older private store', invoice_number:'OLDER-316', lines:[{product_id:'1', quantity_mode:'units', received_qty:'4', notes:'Older draft note'}]}));
            sessionStorage.setItem('receiving-draft:999', JSON.stringify({supplier:'Other operator old draft', lines:[]}));
            localStorage.setItem('retail_mind_add_product_state', JSON.stringify({product_name:'Unowned private Product name'}));
            localStorage.setItem('retail_mind_add_product_step', '2');
        });
        await page.reload();
        await page.getByRole('button', {name:'Discard older Product draft', exact:true}).waitFor();
        assert(!(await page.locator('body').innerText()).includes('Unowned private Product name'), 'Unowned legacy Product values are never rendered');
        assert(await page.evaluate(() => !!localStorage.getItem('retail_mind_add_product_state')), 'Unowned legacy data is retained until explicit Discard');
        assert(await page.evaluate(() => !!sessionStorage.getItem('receiving-draft:999')), 'Other operators legacy drafts are preserved');
        await page.getByRole('button', {name:'Discard older Product draft', exact:true}).click();
        await resumeDraft();
        assert.equal(await page.locator('#supplier').inputValue(), 'Older private store');
        assert.equal(await page.locator('#notes').inputValue(), 'Older draft note');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('receiving-draft:3')), null, 'Own legacy plaintext is removed only after encrypted migration succeeds');
        await page.evaluate(() => RetailMindInventoryDrafts.discard('receiving_form'));
        await page.reload();
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
        await resumeDraft();
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
        await resumeDraft();
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
        await page.reload(); await resumeDraft();
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
        await page.route('**/submission_status.php?**', route => route.fulfill({status:302, headers:{location:origin + '/?login=1'}}));
        await page.getByRole('button', {name:'Resume', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Sign in again'));
        assert(!(await page.locator('#inventory-draft-status').innerText()).includes('Unexpected token'), 'Expired-session response never exposes JSON parsing details');
        assert(await page.evaluate(key => !!localStorage.getItem(key), draftStorageKey), 'Session redirect retains draft');
        await page.unroute('**/submission_status.php?**');
        await resumeDraft();
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('already saved'));
        await page.waitForFunction(key => localStorage.getItem(key) === null, draftStorageKey);
        const after = JSON.parse(fixture('inventory_state'));
        assert.equal(after.stock_receiving.length, before.stock_receiving.length + 1, 'Response-loss retry saves exactly one receiving record');
        assert.equal(after.stock_movements.length, before.stock_movements.length + 1, 'Response-loss retry posts exactly one stock movement');
        // Explicit Discard and storage-unavailable feedback use the same browser workflow.
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('7');
        // Navigate immediately while capture/encryption may still be queued; the shared navigator must flush it.
        await page.locator('.sidebar a[href$="products.php"]').first().evaluate(link => link.click());
        await page.waitForURL(url => url.pathname.endsWith('/products.php'), {waitUntil:'domcontentloaded'});
        await page.goto(origin + '/components/report/stock_receiving.php');
        await resumeDraft();
        assert.equal(await page.locator('#received_qty').inputValue(), '7', 'Immediate AJAX navigation preserves the last entered quantity');
        await page.reload(); await page.getByRole('button', {name: 'Discard', exact: true}).click();
        await page.reload(); assert.equal(await page.getByRole('button', {name: 'Resume', exact: true}).count(), 0);
        async function loseCommittedResponse(pathname, submit) {
            const pattern = '**/components/' + (pathname === 'stock_receiving.php' ? 'report/' : 'inventory_management/') + pathname;
            let resolveCommit; const done = new Promise(resolve => { resolveCommit = resolve; });
            await page.route(pattern, async route => {
                if (route.request().method() !== 'POST') return route.continue();
                const response = await route.fetch();
                assert(response.ok());
                await route.abort('failed'); resolveCommit();
            });
            await submit();
            await Promise.race([done, new Promise((_, reject) => setTimeout(() => reject(new Error('No inventory decision POST')), 15000))]);
            await page.unroute(pattern);
            await page.reload();
        }
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
        await page.reload(); await resumeDraft();
        await page.waitForFunction(() => document.querySelector('[name="batches[extra_1][notes]"]')?.value === 'Found on top shelf');
        assert.equal(await page.locator('[name="batches[extra_1][batch_number]"]').inputValue(), 'FOUND-DRAFT');
        assert.equal(await page.locator('[name="discrepancy_reason"]').inputValue(), 'Shelf count draft');
        await page.locator('#batch_counts_tbody input[type="number"]').first().fill('5');
        await page.getByRole('button', {name:'Record Count', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('.alert.tag-success')?.textContent.includes('recorded'));
        const countDecisionForm = page.locator('form.inline-form').filter({has:page.locator('[name="action"][value="approve"]')}).first();
        const countDraftId = await countDecisionForm.locator('[name="inventory_draft_id"]').inputValue();
        await loseCommittedResponse('inventory_counts.php', () => countDecisionForm.getByRole('button', {name:'Approve', exact:true}).click());
        assert.equal(await page.locator('form.inline-form').filter({has:page.locator('[name="inventory_draft_id"][value="' + countDraftId + '"]')}).count(), 0, 'Confirmed count approval removes its pending form');
        await resumeDraft(countDraftId);
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('already saved'));
        assert.equal(await page.locator('[data-recovery-id="' + countDraftId + '"]').count(), 0, 'Lost approval response reconciles without restoring a vanished form');
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
        await page.reload(); await resumeDraft();
        await page.waitForFunction(() => document.querySelector('[name="notes"]').value === 'Pullout draft notes');
        assert.equal(await page.locator('#batch_id').inputValue(), batchId);
        assert.equal(await page.locator('[name="quantity"]').inputValue(), '1');
        // Stock Issue review retains its report identity and entered explanation.
        await page.goto(origin + '/components/inventory_management/stock_issues.php');
        await page.locator('.review-form [name="review_notes"]').first().fill('Review on tablet draft');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload(); await resumeDraft();
        assert.equal(await page.locator('.review-form [name="review_notes"]').first().inputValue(), 'Review on tablet draft');
        const issueDraftId = await page.locator('.review-form [name="inventory_draft_id"]').first().inputValue();
        const issueBefore = JSON.parse(fixture('inventory_state'));
        await loseCommittedResponse('stock_issues.php', () => page.locator('.review-form button[name="action"][value="approve"]').first().click());
        assert.equal(await page.locator('.review-form').count(), 0, 'Confirmed Stock Issue approval removes its pending form');
        await resumeDraft(issueDraftId);
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('already saved'));
        const issueAfter = JSON.parse(fixture('inventory_state'));
        assert.equal(issueAfter.stock_movements.length, issueBefore.stock_movements.length + 1, 'Lost Stock Issue approval response posts one stock movement');
        // Explicit Product creation retains entered fields and wizard context, including an image.
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.evaluate(() => RetailMindUI.openOverlay(document.getElementById('add-product-modal')));
        await page.locator('#add-product-form [name="product_name"]').fill('Unfinished Product draft');
        await page.locator('#add-product-form [name="brand"]').fill('Draft brand');
        await page.locator('#product-image-input').setInputFiles({name:'draft.png', mimeType:'image/png', buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6w5kAAAAASUVORK5CYII=', 'base64')});
        await page.waitForFunction(() => Object.keys(localStorage).some(key => key.endsWith(':add-product-form')));
        await page.evaluate(() => RetailMindInventoryDrafts.flush());
        await page.reload(); await resumeDraft();
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
        await resumeDraft('product-edit-form');
        assert.equal(await page.locator('#edit-product-name').inputValue(), 'Existing Product draft edit');
        assert.equal(await page.locator('#product-edit-form [name="product_id"]').inputValue(), '1');
        await page.evaluate(() => RetailMindUI.closeOverlay(document.getElementById('product-drawer')));
        await resumeDraft('add-product-form');
        const categoryValue = await page.locator('#product-category option').nth(1).getAttribute('value');
        await page.locator('#product-category').selectOption(categoryValue, {force:true});
        await page.locator('#wizard-next').click();
        await page.locator('#wizard-next').click();
        await page.locator('#cost-price').fill('1');
        await page.locator('#selling-price').fill('2');
        await page.locator('#wizard-next').click();
        await page.locator('#wizard-next').click();
        assert(await page.locator('[name="print_after_create"]').isChecked(), 'Default Product flow opens barcode labels');
        const productDraftKey = await page.evaluate(() => Object.keys(localStorage).find(key => key.endsWith(':add-product-form')));
        await Promise.all([page.waitForURL(url => url.pathname.endsWith('/print_barcodes.php'), {waitUntil:'domcontentloaded'}), page.locator('#wizard-save').click()]);
        await page.goto(origin + '/components/inventory_management/products.php', {waitUntil:'domcontentloaded'});
        await page.waitForFunction(key => localStorage.getItem(key) === null, productDraftKey);
        assert.equal(await page.locator('[data-recovery-id="add-product-form"]').count(), 0, 'Confirmed Product draft stays cleared after default barcode-label return');
        await page.goto(origin + '/components/report/stock_receiving.php');
        // A corrected, server-confirmed retry clears against the latest submitted snapshot, not the first failed one.
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('2');
        await page.locator('#receiving_next').click();
        fixture('disable_product');
        await page.locator('.btn-submit').click();
        await page.waitForFunction(() => !!document.querySelector('.message.error'));
        fixture('enable_product');
        await page.reload(); await resumeDraft();
        if (await page.getByRole('button', {name:'Edit line 1'}).isVisible()) await page.getByRole('button', {name:'Edit line 1'}).click();
        await page.locator('#received_qty').fill('3');
        await page.locator('#receiving_next').click();
        const correctedKey = await page.evaluate(() => Object.keys(localStorage).find(key => key.endsWith(':receiving_form')));
        await page.locator('.btn-submit').click();
        await page.waitForFunction(() => document.querySelector('.message.success')?.textContent.includes('Receiving entry saved'));
        await page.waitForFunction(key => localStorage.getItem(key) === null, correctedKey);
        await page.locator('.receiving-card-add').first().click();
        await page.locator('#receiving_next').click();
        await page.locator('#received_qty').fill('2');
        await page.locator('.receiving-extras summary').first().click();
        await page.locator('#notes').fill('Originally submitted notes');
        await page.locator('#receiving_next').click();
        const editedBefore = JSON.parse(fixture('inventory_state'));
        let editedCommitResolve; const editedCommit = new Promise(resolve => { editedCommitResolve = resolve; });
        await page.route('**/components/report/stock_receiving.php', async route => {
            if (route.request().method() !== 'POST') return route.continue();
            const response = await route.fetch(); assert(response.ok()); await route.abort('failed'); editedCommitResolve();
        });
        await page.locator('.btn-submit').click();
        await Promise.race([editedCommit, new Promise((_, reject) => setTimeout(() => reject(new Error('No edited receiving POST')), 15000))]);
        await page.unroute('**/components/report/stock_receiving.php');
        await page.getByRole('button', {name:'Edit line 1'}).click();
        await page.locator('#notes').fill('Newer unsent notes after commit');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload();
        await page.route('**/submission_status.php?**', route => route.abort('failed'));
        await resumeDraft();
        await page.unroute('**/submission_status.php?**');
        if (await page.locator('#receiving_next').isVisible()) await page.locator('#receiving_next').click();
        await page.locator('.btn-submit').click();
        await page.waitForFunction(() => document.querySelector('.message.error')?.textContent.includes('already saved with different values'));
        // Further edits on a validation-response page must keep the original submission baseline without requiring Resume.
        await page.locator('#notes').fill('Newer unsent notes after rejected retry');
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.startsWith('Unsaved values kept'));
        await page.reload();
        await page.getByRole('button', {name:'Resume', exact:true}).click();
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Newer unsaved values are still kept'));
        assert(await page.locator('[data-recovery-id="receiving_form"]').isVisible(), 'Newer values retain Resume and explicit Discard');
        assert((await page.locator('[data-recovery-id="receiving_form"] details').innerText()).includes('Newer unsent notes after rejected retry'), 'Newer values remain readable to their owner');
        const editedAfter = JSON.parse(fixture('inventory_state'));
        assert.equal(editedAfter.stock_receiving.length, editedBefore.stock_receiving.length + 1, 'Reconciling newer unsent values cannot submit them');
        assert.equal(editedAfter.stock_movements.length, editedBefore.stock_movements.length + 1, 'Reconciling newer unsent values cannot repeat stock');
        await page.getByRole('button', {name:'Discard', exact:true}).click();
        await page.addInitScript(() => { Storage.prototype.setItem = () => { throw new Error('Storage blocked'); }; });
        await page.reload();
        await page.waitForFunction(() => document.querySelector('#inventory-draft-status')?.textContent.includes('Draft recovery is unavailable'));
        assert.deepEqual(errors, []);
        console.log('Inventory private recovery and lost-response HTTP retry: passed (Chromium emulation; physical tablets pending)');
    } finally {
        if (context) await context.close();
        if (server) server.kill();
        try {
            const state = JSON.parse(fixture('inventory_state'));
            for (const product of state.products.filter(product => product.product_name === 'Unfinished Product draft' && /^storage\/images\/[a-f0-9]{32}\.png$/.test(product.product_image || ''))) {
                const image = path.resolve('src/backend', product.product_image);
                assert.equal(path.dirname(image), path.resolve('src/backend/storage/images'));
                fs.rmSync(image, {force:true});
            }
        } finally { fixture('cleanup'); }
        fs.rmSync(userDataDir, {recursive:true, force:true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
