'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Inventory count workflow: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = path.resolve('output/playwright/inventory-counts');
const php = process.env.PHP_BINARY || 'php';
const env = {...process.env, BACKUP_STORAGE_PATH: fs.mkdtempSync(path.join(os.tmpdir(), 'rm-counts-'))};
const route = '/components/inventory_management/inventory_counts.php';
function fixture(action) {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {encoding:'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout ? JSON.parse(result.stdout) : null;
}
async function selectProduct(page, sku) {
    await page.locator('#product_search').fill(sku);
    await page.locator('#product_results [data-value]').filter({hasText:sku}).first().click();
    await page.waitForFunction(() => document.getElementById('stock_version').value !== '');
}
async function save(page) {
    page.once('dialog', dialog => dialog.accept());
    await Promise.all([
        page.waitForURL('**/*count_view=recent'),
        page.locator('#save_count').click(),
    ]);
    assert.match(await page.locator('.alert.tag-success').innerText(), /saved.*Stock and batches updated/);
}
async function runCli(args, file) {
    const child = spawn('testsprite', args, {shell:process.platform === 'win32', stdio:['ignore','pipe','pipe']});
    let stdout = '', stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; process.stdout.write(chunk); });
    const code = await new Promise(resolve => child.on('close', resolve));
    fs.writeFileSync(path.join(output, file), stdout + '\n' + stderr);
    assert.equal(code, 0, 'TestSprite failed; inspect ' + path.join(output, file));
    return JSON.parse(stdout);
}
(async () => {
    let server, browser;
    try {
        const data = fixture('create');
        fixture('open_fiscal_period');
        fixture('inventory_count_setup');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S','127.0.0.1:' + port,'-t','src/frontend','src/backend/tests/support/theme_router.php'],
            {env:{...env, RM_THEME_TEST_DATABASE:database}, stdio:['ignore','ignore','pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 100));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless:true});
        const page = await browser.newPage({viewport:{width:1366,height:900}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(12000);
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button',{name:'Log in',exact:true}).click()]);
        await page.goto(origin + route);
        const guidanceDismiss = page.getByRole('button',{name:'Not now',exact:true});
        if (await guidanceDismiss.isVisible().catch(() => false)) {
            await guidanceDismiss.click();
            await page.locator('.swal2-confirm').click();
            await page.goto(origin + route);
        }
        assert.equal(await page.locator('#panel-new-count').isVisible(), true);
        assert.equal(await page.locator('#panel-recent-counts').isVisible(), false);
        await page.locator('#tab-new-count').focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('#tab-recent-counts').getAttribute('aria-selected'), 'true');
        await page.getByRole('link',{name:'Count again',exact:true}).click();
        await page.waitForFunction(() => document.getElementById('stock_version').value !== '');
        assert.equal(await page.locator('#single_quantity_group').isVisible(), false);
        const batchInputs = page.locator('#batch_counts_tbody .batch-count-input');
        assert.equal(await batchInputs.count(), 2);
        assert.equal(await batchInputs.nth(0).inputValue(), '');
        assert.equal(await batchInputs.nth(1).inputValue(), '');
        await batchInputs.nth(0).fill('5');
        assert.equal(await page.locator('.count-form').evaluate(form => form.checkValidity()), false, 'Blank second batch must block save');
        await batchInputs.nth(1).fill('4');
        await page.locator('#add_extra_batch_btn').click();
        await page.getByLabel('Found batch counted quantity',{exact:true}).fill('2');
        await page.getByLabel('Found batch number or label').fill('COUNT-FOUND');
        await page.locator('#discrepancy_reason').fill('Fresh shelf recount, including found stock.');
        assert.equal(await page.locator('#physicalQty').innerText(), '11');
        assert.equal(await page.locator('#differenceQty').innerText(), '+1');
        await page.locator('#tab-recent-counts').click();
        await page.locator('#tab-new-count').click();
        assert.equal(await batchInputs.nth(0).inputValue(), '5', 'Switching tabs retains entered work');
        fs.mkdirSync(output,{recursive:true});
        const geometry = [];
        for (const width of [390,768,1366]) {
            await page.setViewportSize({width,height:900});
            for (const theme of ['light','dark']) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No page overflow at ' + width);
                for (const input of await batchInputs.all()) {
                    const bounds = await input.boundingBox();
                    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width + 1, 'Actual count is visible without horizontal scrolling at ' + width);
                }
                const tabs = await page.locator('.count-tabs').boundingBox();
                const entry = await page.locator('.count-entry-main').boundingBox();
                const review = await page.locator('.count-review').boundingBox();
                if (width >= 1200) assert.ok(entry.x + entry.width <= review.x, 'Desktop summary sits beside count inputs');
                else assert.ok(entry.y + entry.height <= review.y + 1, 'Narrow screens put summary after count inputs');
                geometry.push({width,theme,tabs});
                await page.screenshot({path:path.join(output,`new-${width}-${theme}.png`),fullPage:true});
            }
        }
        fs.writeFileSync(path.join(output,'geometry.json'),JSON.stringify(geometry,null,2));
        await save(page);
        let state = fixture('inventory_count_state');
        assert.equal(state.counts[0].status, 'rejected');
        assert.equal(state.counts[1].status, 'approved');
        assert.equal(Number(state.stock[0].quantity_on_hand), 11);
        assert.equal(state.batches.find(batch => batch.batch_number === 'COUNT-FOUND').expiration_date, null);
        assert.equal(await page.getByRole('button',{name:'Approve',exact:true}).count(), 0);

        await page.locator('#tab-new-count').click();
        await selectProduct(page,'THEME-ITEM');
        for (const input of await page.locator('#batch_counts_tbody .batch-count-input').all()) await input.fill('1');
        await page.locator('#discrepancy_reason').fill('Stale stock must be reviewed again.');
        fixture('inventory_count_changed');
        page.once('dialog', dialog => dialog.accept());
        await Promise.all([page.waitForNavigation(), page.locator('#save_count').click()]);
        assert.match(await page.locator('.alert-error').first().innerText(), /Stock has changed/);
        assert.equal(fixture('inventory_count_state').counts.length, 2, 'Stale save adds no pending count');

        await page.goto(origin + route);
        await selectProduct(page,'COUNT-PACK');
        assert.equal(await page.locator('#single_quantity_group').isVisible(), false);
        await page.getByLabel('Packs counted, batch COUNT-PACK-A',{exact:true}).fill('1');
        await page.getByLabel('Loose pieces counted, batch COUNT-PACK-A',{exact:true}).fill('3');
        await page.locator('#discrepancy_reason').fill('Verified sealed and loose shelf count.');
        assert.equal(await page.locator('#physicalQty').innerText(), '15');
        await save(page);
        state = fixture('inventory_count_state');
        assert.equal(Number(state.stock.find(stock => Number(stock.product_id) === 2).quantity_on_hand),15);
        const packBatch = state.batches.find(batch => batch.batch_number === 'COUNT-PACK-A');
        assert.equal(Number(packBatch.sealed_quantity),1);
        assert.equal(Number(packBatch.loose_quantity),3);

        await page.locator('#tab-new-count').click();
        await selectProduct(page,'COUNT-EMPTY');
        assert.equal(await page.locator('#single_quantity_group').isVisible(), true);
        await page.locator('#physical_quantity').fill('0');
        await page.locator('#discrepancy_reason').fill('Verified no physical stock.');
        await save(page);

        await page.locator('#tab-new-count').click();
        await selectProduct(page,'COUNT-UNBATCHED');
        assert.equal(await page.locator('#single_quantity_group').isVisible(), false);
        assert.equal(await page.locator('#top_level_pack_counts').isVisible(), true);
        await page.locator('#physical_sealed_quantity').fill('1');
        await page.locator('#physical_loose_quantity').fill('2');
        assert.equal(await page.locator('#physicalQty').innerText(),'14');
        page.once('dialog', dialog => dialog.accept());
        await page.locator('#add_extra_batch_btn').click();
        assert.equal(await page.locator('#top_level_pack_counts').isVisible(), false);
        await page.getByLabel('Found batch sealed packs',{exact:true}).fill('1');
        await page.getByLabel('Found batch loose pieces',{exact:true}).fill('2');
        assert.equal(await page.locator('#physicalQty').innerText(),'14');
        await page.locator('.remove-batch-btn').click();
        assert.equal(await page.locator('#top_level_pack_counts').isVisible(), true);
        assert.equal(await page.locator('#physical_sealed_quantity').inputValue(),'');
        assert.equal(await page.locator('#physicalQty').innerText(),'-');
        await page.locator('#physical_sealed_quantity').fill('1');
        await page.locator('#physical_loose_quantity').fill('2');
        await page.locator('#discrepancy_reason').fill('Verify previously unbatched sealed stock.');
        await save(page);
        state = fixture('inventory_count_state');
        const unbatched = state.batches.filter(batch => Number(batch.product_id) === 4);
        assert.equal(unbatched.length,1);
        assert.equal(Number(unbatched[0].sealed_quantity),1);
        assert.equal(Number(unbatched[0].loose_quantity),2);

        fixture('inventory_count_history');
        await page.goto(origin + route + '?count_view=recent&q=THEME-ITEM&sort=counted&direction=asc&length=10');
        assert.equal(await page.locator('#panel-recent-counts tbody tr').count(),10);
        assert.match(await page.locator('.history-page-number').innerText(), /of 67 entries/);
        assert.equal(await page.locator('#panel-recent-counts tbody tr').first().locator('td').nth(3).innerText(),'0');
        await Promise.all([page.waitForURL(url => url.searchParams.get('page') === '2'), page.getByRole('link',{name:'Next',exact:true}).click()]);
        assert.match(page.url(), /count_view=recent/);
        assert.match(page.url(), /sort=counted/);
        assert.match(await page.locator('.history-page-number').innerText(), /Showing 11 to 20/);
        await page.goto(origin + route + '?count_view=recent&q=THEME-ITEM&length=10&page=7');
        assert.equal(await page.locator('#panel-recent-counts tbody tr').count(),7, 'Records beyond the previous 50-row limit remain reachable');
        await page.locator('#count_history_search').fill('COUNT-PACK');
        await Promise.all([page.waitForURL(url => url.searchParams.get('q') === 'COUNT-PACK'),page.getByRole('button',{name:'Search',exact:true}).click()]);
        assert.match(await page.locator('.history-page-number').innerText(), /of 1 entries/);
        assert.equal(await page.locator('.count-history-table .table-ghost-row').count(),0, 'Single record has no filler rows');
        assert.equal(await page.locator('.count-history-table tbody tr').count(),1);
        assert.equal(await page.locator('.count-history-table th').count(),8,'Saved-only history has no empty Action column');
        assert.equal(await page.locator('.count-product-cell > strong').innerText(),'Count Pack Item');
        for (const width of [390,768,1366]) {
            await page.setViewportSize({width,height:900});
            for (const theme of ['light','dark']) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; },theme);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,'History stays within page width');
                const searchBox = await page.locator('#count_history_search').boundingBox();
                const searchButton = await page.getByRole('button',{name:'Search',exact:true}).boundingBox();
                assert.ok(searchBox.height >= 44 && searchBox.width >= 140,'History search has a usable touch target');
                assert.ok(searchButton.x + searchButton.width <= width + 1,'Search button fits screen');
                const table = await page.locator('.count-history-table').boundingBox();
                assert.ok(table.height < 230,'Single record table does not reserve empty rows');
                await page.screenshot({path:path.join(output,`history-one-${width}-${theme}.png`),fullPage:true});
            }
        }
        await page.getByRole('button',{name:/View .* batches for count/}).click();
        await page.locator('#batch_detail_modal').waitFor({state:'visible'});
        await page.waitForFunction(() => document.getElementById('batch_detail_body').textContent.includes('COUNT-PACK-A'));
        assert.match(await page.locator('#batch_detail_body').innerText(), /COUNT-PACK-A/);
        await page.getByRole('button',{name:'Close',exact:true}).click();
        await page.locator('#count_history_search').fill('NO-MATCH-COUNT');
        await Promise.all([page.waitForURL(url => url.searchParams.get('q') === 'NO-MATCH-COUNT'),page.getByRole('button',{name:'Search',exact:true}).click()]);
        assert.match(await page.locator('#panel-recent-counts tbody').innerText(), /No counts match/);
        assert.equal(errors.length,0,errors.join('\n'));

        await page.goto(origin + route + '?count_view=recent');
        await page.screenshot({path:path.join(output,'recent-desktop.png'),fullPage:true});
        console.log('Inventory counts: direct saves, batches, packs, recount, stale rejection, tabs, pagination and responsive geometry passed.');
        if (process.env.RUN_TESTSPRITE === '1') {
            const plan = {
                projectId:'abe16d0b-21f0-432e-8a86-72b1436c42fe', type:'frontend',
                name:'Inventory counts direct save and searchable paginated history',
                description:'Disposable synthetic Store only. Do not use any other origin or change account passwords. Default browser viewport; separate local Chromium checks cover responsive sizes.',
                planSteps:[
                    {type:'action',description:'Open /?login=1 and log in with username theme_inventory_manager and password ' + data.password + '. Then open ' + route + '.'},
                    {type:'assertion',description:'Verify New Count and Recent Counts tabs exist; New Count is selected and history is hidden.'},
                    {type:'action',description:'Search THEME-ITEM, choose Theme Test Item, and inspect the existing batches.'},
                    {type:'assertion',description:'Actual counts are entered separately per batch, all initially blank. There is no separate editable Counted quantity total. Add found batch is available.'},
                    {type:'action',description:'For every listed existing batch enter actual count 1, and set Count reason to TestSprite verified shelf count. Verify the automatically summed actual total and difference, then click Save Count and accept its one confirmation.'},
                    {type:'assertion',description:'Verify success says the count was saved and stock and batches updated, Recent Counts is selected, and no Approve button is offered.'},
                    {type:'action',description:'In Recent Counts search THEME-ITEM. Sort by Counted, choose 10 entries per page, and navigate to page 2.'},
                    {type:'assertion',description:'Verify only matching product records appear, the sort survives pagination, and the footer says Showing 11 to 20 with a total above 50.'},
                    {type:'action',description:'Search NO-MATCH-COUNT.'},
                    {type:'assertion',description:'Verify an empty search result is shown with zero matching entries.'},
                ],
            };
            const planPath = path.join(output,'testsprite-plan.json');
            fs.writeFileSync(planPath,JSON.stringify(plan,null,2));
            const created = process.env.TESTSPRITE_TEST_ID ? null : await runCli(['test','create','--plan-from',planPath,'--output','json'],'testsprite-created.json');
            const id = process.env.TESTSPRITE_TEST_ID || created.id || created.test?.id || created.testId;
            assert.ok(id,'TestSprite creation returned no test ID');
            await runCli(['test','run',id,'--local',String(port),'--local-host','127.0.0.1','--timeout','1200','--output','json'],'testsprite-run.json');
            console.log('TestSprite inventory count workflow passed.');
        }
        const admin = await browser.newPage();
        await admin.goto(origin + '/?login=1');
        await admin.locator('#landing-login-username').fill('theme_admin');
        await admin.locator('#landing-login-password').fill(data.password);
        await Promise.all([admin.waitForURL(url => !url.search.includes('login=1')),admin.getByRole('button',{name:'Log in',exact:true}).click()]);
        await admin.goto(origin + route);
        assert.equal(await admin.locator('#panel-recent-counts').isVisible(),true);
        assert.equal(await admin.locator('#tab-new-count').count(),0);
        assert.equal(await admin.locator('#save_count').count(),0);
    } catch (error) {
        const failedPage = browser?.contexts()[0]?.pages()[0];
        if (failedPage) {
            fs.mkdirSync(output,{recursive:true});
            fs.writeFileSync(path.join(output,'failure.html'),await failedPage.content());
            await failedPage.screenshot({path:path.join(output,'failure.png'),fullPage:true});
        }
        throw error;
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
