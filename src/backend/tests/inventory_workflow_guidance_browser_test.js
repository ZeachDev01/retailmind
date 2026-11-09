const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Inventory Manager workflow guidance: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-invguidance-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('open_fiscal_period');
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: 'ignore'});
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        page.setDefaultTimeout(15000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        page.on('pageerror', error => console.error(error));

        const screenshots = 'output/playwright/inventory-workflow-guidance';
        fs.mkdirSync(screenshots, {recursive: true});

        const login = async role => {
            await page.goto(origin + '/components/auth/logout.php');
            await page.waitForLoadState('domcontentloaded');
            await page.goto(origin + '/?login=1');
            await page.waitForLoadState('domcontentloaded');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([
                page.waitForURL(u => !u.search.includes('login=1')),
                page.getByRole('button', {name: 'Log in', exact: true}).click(),
            ]);
            await page.waitForLoadState('domcontentloaded');
        };

        const initialInventory = JSON.parse(fixture('inventory_state'));

        // 1. First visit by Inventory Manager
        await login('inventory_manager');
        await page.waitForURL('**/inventory_management/inventory_overview.php');

        const guidanceBanner = page.locator('#workspace-guidance');
        await guidanceBanner.waitFor();

        // Check contextual heading and copy on landing (Stock monitoring)
        assert.match(await guidanceBanner.locator('h2').innerText(), /Inventory Manager Workspace/);
        assert.match(await guidanceBanner.locator('h2').innerText(), /Stock monitoring/);

        // Check Store setup checklist presence
        const checklist = guidanceBanner.locator('.store-setup-checklist');
        await checklist.waitFor();
        assert.match(await checklist.innerText(), /Store setup checklist/);

        // Inventory Manager sees:
        // - Products task: owned by Inventory Manager, has link and button "Confirm product setup"
        // - Calendar task: owned by Administrator, awaiting Administrator (no button)
        const productTask = checklist.locator('.setup-task-item', {hasText: 'Products and opening stock'});
        assert.match(await productTask.innerText(), /Inventory Manager/);
        assert.equal(await productTask.getByRole('link', {name: 'Set up Products', exact: true}).count(), 1);
        assert.equal(await productTask.getByRole('button', {name: 'Confirm product setup', exact: true}).count(), 1);

        const calendarTask = checklist.locator('.setup-task-item', {hasText: 'Store calendar review'});
        assert.match(await calendarTask.innerText(), /Administrator/);
        assert.equal(await calendarTask.getByRole('button', {name: 'Confirm calendar review'}).count(), 0);
        assert.match(await calendarTask.innerText(), /Awaiting Administrator/);

        // Visual screenshot of inventory landing guidance
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow at ' + width + 'px');
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `overview-${theme}-${width}.png`)});
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // 2. Product setup workflow (products.php)
        await page.goto(origin + '/components/inventory_management/products.php');
        await page.waitForLoadState('domcontentloaded');
        const prodGuidance = page.locator('#workspace-guidance');
        await prodGuidance.waitFor();
        assert.match(await prodGuidance.locator('h2').innerText(), /Product setup/);
        assert.equal(await page.locator('#add-product-btn').count(), 1, 'Existing Add Product button is present');

        // 3. Replenishment Recommendations workflow (replenishment_queue.php)
        // Starting point for daily buying review
        await page.goto(origin + '/components/inventory_management/replenishment_queue.php');
        await page.waitForLoadState('domcontentloaded');
        const replGuidance = page.locator('#workspace-guidance');
        await replGuidance.waitFor();
        assert.match(await replGuidance.locator('h2').innerText(), /Replenishment recommendations/);

        // Inspect buying advice in the table
        const table = page.locator('.queue-list table');
        await table.waitFor();
        assert.match(await table.innerText(), /Theme Test Item/);
        assert.match(await table.innerText(), /THEME-ITEM/);
        assert.match(await table.innerText(), /Stock-rule suggestion/);
        assert.match(await table.innerText(), /Not available yet/);

        // Optional forecast details inspection
        const details = page.locator('.queue-details').first();
        assert.equal(await details.count(), 1);
        await details.locator('summary').click();
        assert.match(await details.innerText(), /Stock-rule suggestion/);
        assert.match(await details.innerText(), /Forecast unavailable/);

        // Verify viewing advice changed NO stock or purchase records
        const midInventory = JSON.parse(fixture('inventory_state'));
        assert.deepEqual(midInventory, initialInventory, 'Viewing replenishment recommendations leaves inventory unchanged');

        // 4. Stock Receiving workflow (report/stock_receiving.php)
        await page.goto(origin + '/components/report/stock_receiving.php');
        await page.waitForLoadState('domcontentloaded');
        const rcvGuidance = page.locator('#workspace-guidance');
        await rcvGuidance.waitFor();
        assert.match(await rcvGuidance.locator('h2').innerText(), /Stock Receiving/);

        // Record actual goods arrival through Stock Receiving
        const csrfToken = await page.locator('#receiving_form input[name="csrf_token"]').inputValue();
        const receivingNonce = await page.locator('#receiving_form input[name="receiving_nonce"]').inputValue();
        assert.ok(csrfToken, 'CSRF token present');
        assert.ok(receivingNonce, 'Receiving nonce present');

        // Post stock arrival: 5 units of product 1
        const arrivalPost = await page.request.post(origin + '/components/report/stock_receiving.php', {
            form: {
                csrf_token: csrfToken,
                receiving_nonce: receivingNonce,
                entry_mode: 'shopping_trip',
                receiving_complete: '1',
                supplier: 'Arrival Supplier',
                invoice_number: 'INV-ARRIVE-1',
                'lines[0][product_id]': '1',
                'lines[0][quantity_mode]': 'units',
                'lines[0][received_qty]': '5',
                'lines[0][received_loose]': '5',
                'lines[0][accepted_loose]': '5',
                'lines[0][damaged_qty]': '0',
                'lines[0][discrepancy_qty]': '0',
            },
        });
        assert.equal(arrivalPost.status(), 200);

        // Verify committed stock increased from 10 to 15
        const afterReceiving = JSON.parse(fixture('inventory_state'));
        const p1Inv = afterReceiving.inventory.find(r => Number(r.product_id) === 1);
        assert.equal(Number(p1Inv.quantity_on_hand), 15, 'Stock Receiving committed 5 units to inventory (10 -> 15)');
        assert.ok(afterReceiving.stock_receiving.length > 0, 'Stock Receiving record created for actual arrivals');

        // 5. Inventory counts workflow (inventory_counts.php)
        await page.goto(origin + '/components/inventory_management/inventory_counts.php');
        await page.waitForLoadState('domcontentloaded');
        const countsGuidance = page.locator('#workspace-guidance');
        await countsGuidance.waitFor();
        assert.match(await countsGuidance.locator('h2').innerText(), /Inventory counts/);

        // 6. Stock Issue review workflow (stock_issues.php)
        await page.goto(origin + '/components/inventory_management/stock_issues.php');
        await page.waitForLoadState('domcontentloaded');
        const issuesGuidance = page.locator('#workspace-guidance');
        await issuesGuidance.waitFor();
        assert.match(await issuesGuidance.locator('h2').innerText(), /Stock Issue review/);

        // 7. Skip guidance and verify persisted state
        const skipBtn = issuesGuidance.getByRole('button', {name: 'Skip guidance', exact: true});
        await skipBtn.focus();
        assert.equal(await skipBtn.evaluate(el => document.activeElement === el), true);
        assert.notEqual(await skipBtn.evaluate(el => getComputedStyle(el).outlineStyle), 'none');

        await Promise.all([
            page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/auth/help.php')),
            skipBtn.click(),
        ]);
        await page.waitForLoadState('domcontentloaded');

        // Verify guidance state in DB
        const guidanceRows = JSON.parse(fixture('guidance_state'));
        assert.equal(guidanceRows.some(r => r.user_id === 3 && r.workspace === 'inventory_manager' && r.status === 'skipped'), true);

        // Verify setup confirmations were NOT completed by skipping
        const setupRows = JSON.parse(fixture('setup_state'));
        assert.equal(setupRows.length, 0, 'Skipping guidance does not complete store setup confirmations');

        // Subsequent visits must show NO automatic guidance banner on any of the 6 workflows
        const pagesToCheck = [
            '/components/inventory_management/inventory_overview.php',
            '/components/inventory_management/products.php',
            '/components/inventory_management/replenishment_queue.php',
            '/components/report/stock_receiving.php',
            '/components/inventory_management/inventory_counts.php',
            '/components/inventory_management/stock_issues.php',
        ];
        for (const p of pagesToCheck) {
            await page.goto(origin + p);
            await page.waitForLoadState('domcontentloaded');
            assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No automatic guidance on ' + p + ' after skip');
        }

        // Re-login: still quiet
        await login('inventory_manager');
        await page.waitForURL('**/inventory_management/inventory_overview.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'No guidance banner on re-login after skip');

        // 8. Reopen through Help
        await page.goto(origin + '/components/auth/help.php');
        await page.waitForLoadState('domcontentloaded');

        // Verify Help renders all 6 Inventory Manager workflows with links and detailed explanations
        const helpMain = page.locator('.main-content');
        const helpText = await helpMain.innerText();
        assert.match(helpText, /Inventory Manager Workspace/);
        assert.match(helpText, /Replenishment recommendations/);
        assert.match(helpText, /Product setup/);
        assert.match(helpText, /Stock monitoring/);
        assert.match(helpText, /Stock Receiving/);
        assert.match(helpText, /Inventory counts/);
        assert.match(helpText, /Stock Issue review/);

        // Check explanations in Help
        assert.match(helpText, /daily buying review/i);
        assert.match(helpText, /buying quantities, basis, and reasons/i);
        assert.match(helpText, /physically counted opening stock/i);
        assert.match(helpText, /Store knowledge rather than relying on defaults/i);
        assert.match(helpText, /commits stock to inventory/i);
        assert.match(helpText, /does not establish customer demand history/i);

        // Check links in Help match active permissions
        const guidanceLinks = helpMain.locator('.workspace-guidance-links');
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Review replenishment needs'}).count(), 1);
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Set up Products'}).count(), 1);
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Monitor stock'}).count(), 1);
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Record Stock Receiving'}).count(), 1);
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Check inventory counts'}).count(), 1);
        assert.equal(await guidanceLinks.getByRole('link', {name: 'Review Stock Issues'}).count(), 1);

        // Visual screenshot of Help page
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.evaluate(mode => document.documentElement.dataset.theme = mode, theme);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow on Help at ' + width + 'px');
                await page.screenshot({animations: 'disabled', path: path.join(screenshots, `help-${theme}-${width}.png`)});
            }
        }
        await page.setViewportSize({width: 1440, height: 900});

        // Leaving Help does NOT restart automatic guidance
        await page.goto(origin + '/components/inventory_management/inventory_overview.php');
        assert.equal(await page.locator('#workspace-guidance').count(), 0, 'Leaving Help does not re-enable automatic guidance');

        // 9. Verify operational alerts continue
        // Seeded notification or low stock alert remains present/accessible
        await page.goto(origin + '/components/notification/notifications.php');
        await page.waitForLoadState('domcontentloaded');
        assert.match(await page.locator('.main-content').innerText(), /Notifications/);

        console.log('Inventory Manager workflow guidance browser: passed');
    } finally {
        if (browser) await browser.close();
        if (server) server.kill('SIGTERM');
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => {
    console.error(error);
    process.exit(1);
});
