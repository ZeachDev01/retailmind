const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Stock issue review layout: skipped (RUN_DB_TESTS=1 required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-stock-review-'));
const env = {...process.env, BACKUP_STORAGE_PATH: recovery};
const php = process.env.PHP_BINARY || 'php';
const fixture = action => {
    const result = spawnSync(php, ['src/backend/tests/support/theme_fixture.php', action, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const sql = code => {
    const result = spawnSync(php, ['-r', `
        require 'src/backend/bootstrap/app.php';
        $config = require 'src/backend/config/database.php';
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $argv[1]), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        ${code}
    `, database], {env, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('open_fiscal_period');
        const seed = spawnSync(php, ['-r', `
            require 'src/backend/bootstrap/app.php';
            $config = require 'src/backend/config/database.php';
            $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $argv[1]), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("INSERT INTO product_batches (product_id,batch_number,quantity,remaining_quantity,loose_quantity) VALUES (1,'REVIEW-BATCH',10,10,10)");
            $pdo->exec("UPDATE inventory_adjustments SET adjustment_qty=-5,reported_qty=5,form='piece'");
            for ($i = 0; $i < 24; $i++) $pdo->exec("INSERT INTO inventory_adjustments (product_id,adjustment_qty,reported_qty,form,adjustment_type,reported_by,shift_id,reason) VALUES (1,-5,5,'piece','damaged',4,1,'Layout fixture report')");
        `, database], {env, encoding: 'utf8'});
        assert.equal(seed.status, 0, seed.stderr);
        server = spawn(php, ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}});
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.stderr.on('data', chunk => {
                const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:\d+/);
                if (match) resolve(match[0]);
            });
        });
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        page.setDefaultTimeout(10000);
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_inventory_manager');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const guidanceDismiss = page.getByRole('button', {name: 'Not now', exact: true});
        if (await guidanceDismiss.isVisible()) {
            await guidanceDismiss.click();
            await guidanceDismiss.waitFor({state: 'hidden'});
        }
        await page.goto(origin + '/components/inventory_management/stock_issues.php');
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const output = path.join(os.tmpdir(), 'retailmind-stock-review');
        fs.mkdirSync(output, {recursive:true});
        assert.equal(await page.getByRole('link', {name:/Awaiting cashier reply/}).count(), 0);
        assert.equal(await page.locator('.conversation-reply-form').count(), 0);
        const issueRows = page.locator('.issue-list-table tbody tr');
        const reportHeader = page.getByRole('link', {name: 'Report', exact: true});
        const reportIds = async () => (await issueRows.locator('td:first-child').allTextContents()).map(text => Number(text.slice(1)));
        const navigate = (target) => Promise.all([page.waitForNavigation(), target.click()]);
        await navigate(reportHeader);
        assert.equal(await page.locator('th[aria-sort="ascending"]').innerText(), 'Report');
        const ascendingIds = await reportIds();
        assert.deepEqual(ascendingIds, ascendingIds.toSorted((a, b) => a - b));
        await navigate(reportHeader);
        const descendingIds = await reportIds();
        assert.deepEqual(descendingIds, descendingIds.toSorted((a, b) => b - a));
        await navigate(page.getByRole('link', {name: 'Next'}));
        assert.equal(await page.locator('th[aria-sort="descending"]').innerText(), 'Report');
        assert.ok((await reportIds())[0] < descendingIds.at(-1), 'Sort applies before pagination');
        const pendingUrl = page.url();
        await issueRows.first().locator('td:first-child').click();
        assert.equal(page.url(), pendingUrl);
        await navigate(issueRows.first().getByRole('link', {name: /View details/}));
        await navigate(page.getByRole('link', {name: 'Back to list'}));
        assert.equal(new URL(page.url()).searchParams.get('page'), '2');
        assert.equal(await page.locator('th[aria-sort="descending"]').innerText(), 'Report');
        await navigate(page.getByRole('link', {name: 'Reported quantity', exact: true}));
        assert.equal(await page.locator('th[aria-sort="ascending"]').innerText(), 'Reported quantity');
        await page.screenshot({path: path.join(output, 'pending-sorted.png'), fullPage: true});
        await page.getByRole('link', {name:/Record Stock Issue/}).click();
        const choose = async () => {
            const barcode = sql("echo $pdo->query('SELECT barcode FROM products WHERE product_id=1')->fetchColumn();");
            await page.locator('#barcode-input').fill(barcode);
            await page.locator('#barcode-input').press('Enter');
            await page.locator('#selected-product').waitFor({state:'visible'});
            assert.equal(await page.locator('#product_id').inputValue(), '1');
        };
        await choose();
        await page.locator('#category').selectOption('damaged');
        await page.locator('#quantity').fill('99');
        await page.locator('#explanation').fill('Two cracked units found by Manager.');
        await page.waitForFunction(() => Object.keys(localStorage).some(key => key.includes('stock-issue-form')));
        await page.reload();
        await page.getByRole('button',{name:'Resume',exact:true}).click();
        await page.locator('#selected-product').waitFor({state:'visible'});
        assert.equal(await page.locator('#quantity').inputValue(),'99','Direct Stock Issue draft quantity survives reload');
        assert.equal(await page.locator('#explanation').inputValue(),'Two cracked units found by Manager.','Direct draft reason survives reload');
        await Promise.all([page.waitForNavigation(), page.getByRole('button',{name:'Record & deduct stock',exact:true}).click()]);
        assert.equal(await page.locator('.message.error').count(), 1, 'Insufficient stock is reported');
        assert.equal(await page.locator('#quantity').inputValue(), '99', 'Failed form retains quantity');
        await page.locator('#selected-product').waitFor({state:'visible'});
        await page.locator('#quantity').fill('2');
        for (const theme of ['light','dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390,768,1440]) {
                await page.setViewportSize({width,height:900});
                const bounds = await page.evaluate(() => ({
                    overflow:document.documentElement.scrollWidth>innerWidth+1,
                    fields:[...document.querySelectorAll('#stock-issue-form input:not([type="hidden"]), #stock-issue-form select, #stock-issue-form textarea, #stock-issue-form button')].filter(x=>x.getBoundingClientRect().width).map(x=>({x:x.getBoundingClientRect().x,right:x.getBoundingClientRect().right,width:x.getBoundingClientRect().width}))
                }));
                assert.equal(bounds.overflow,false,`No page overflow at ${width} ${theme}`);
                assert.ok(bounds.fields.every(x=>x.x>=0 && x.right<=width+1 && x.width>30), `Controls fit at ${width}`);
                await page.screenshot({path:path.join(output,`record-${theme}-${width}.png`),fullPage:true});
            }
        }
        await page.setViewportSize({width:1440,height:1000});
        const directToken = await page.locator('[name="operation_token"]').inputValue();
        await Promise.all([page.waitForNavigation(), page.getByRole('button',{name:'Record & deduct stock',exact:true}).click()]);
        assert.match(await page.locator('main').innerText(), /Confirmed quantity deducted/);
        const directId = new URL(page.url()).searchParams.get('detail');
        assert.ok(directId);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"), '8');
        assert.equal(sql(`echo $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE adjustment_id=${directId}")->fetchColumn();`), '1');
        assert.equal(sql(`echo $pdo->query("SELECT COUNT(*) FROM inventory_adjustments WHERE adjustment_id=${directId} AND reported_by=3 AND approved_by=3 AND shift_id IS NULL AND status='approved'")->fetchColumn();`), '1');
        const retry = await page.evaluate(async ({token}) => {
            const response = await fetch('stock_issues.php?issue_view=record');
            const doc = new DOMParser().parseFromString(await response.text(),'text/html');
            return (await fetch('stock_issues.php?issue_view=record',{method:'POST',body:new URLSearchParams({csrf_token:doc.querySelector('[name="csrf_token"]').value,action:'record',operation_token:token,product_id:'1',category:'damaged',quantity:'2',form:'piece',explanation:'Two cracked units found by Manager.'})})).status;
        }, {token:directToken});
        assert.equal(retry,200);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"), '8', 'HTTP retry never deducts twice');
        // A previous waiting report moves straight to Manager review; its old note stays readable.
        sql("$pdo->exec(\"UPDATE inventory_adjustments SET status='returned',review_notes='Legacy clarification',approved_by=3,approved_at='2026-10-01 09:00:00' WHERE adjustment_id=1\");");
        await page.goto(origin+'/components/inventory_management/stock_issues.php?detail=1');
        const review = page.locator('#report-1');
        assert.equal(await review.locator('[name="verified_quantity"]').count(), 0, 'Batch inputs are the only quantity controls');
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 768, 1440]) {
                await page.setViewportSize({width, height: 900});
                const bounds = await page.evaluate(() => ({
                    overflow: document.documentElement.scrollWidth > innerWidth + 1,
                    back: document.querySelector('.issue-detail-topbar a').getBoundingClientRect().right,
                    header: document.querySelector('.issue-detail-topbar').getBoundingClientRect().right,
                    titleBottom: document.querySelector('.issue-detail-topbar h1').getBoundingClientRect().bottom,
                    linkTop: document.querySelector('.issue-detail-topbar a').getBoundingClientRect().top,
                    details: document.querySelector('.issue-review-body').getBoundingClientRect().bottom,
                    form: document.querySelector('.review-form').getBoundingClientRect().top,
                    table: document.querySelector('.issue-batches .table-wrap').getBoundingClientRect().right,
                    batchInput: document.querySelector('.issue-batches input').getBoundingClientRect().right,
                }));
                assert.equal(bounds.overflow, false, `Review fits at ${width} ${theme}`);
                assert.ok(Math.abs(bounds.back - bounds.header) < 1, 'Back to list aligns to the right edge');
                assert.ok(bounds.linkTop < bounds.titleBottom, 'Back to list shares the title row');
                assert.ok(bounds.details <= bounds.form, 'Report details sit above the decision form');
                assert.ok(bounds.batchInput <= bounds.table, 'Batch deduction stays visible without horizontal scrolling');
                await page.screenshot({path: path.join(output, `review-${theme}-${width}.png`), fullPage: true, animations: 'disabled'});
            }
        }
        const batch = review.locator('input[name^="batches["]');
        await batch.fill('0');
        assert.equal(await review.locator('[value="approve"]').isDisabled(), true);
        await batch.fill('2');
        assert.equal(await review.locator('[value="approve"]').textContent(), 'Approve & deduct 2 pieces');
        assert.equal(await review.locator('[name="review_notes"]').evaluate(field => field.required), true);
        await review.locator('[name="review_notes"]').fill('Only two affected after recount.');
        assert.equal(await review.locator('.conversation-reply-form').count(),0);
        await review.getByRole('button',{name:/Message history/}).click();
        assert.match(await page.locator('dialog[open]').innerText(),/Legacy clarification/);
        await page.keyboard.press('Escape');
        await Promise.all([page.waitForNavigation(),review.locator('[value="approve"]').click()]);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"),'6');
        assert.equal(sql("echo $pdo->query('SELECT reported_qty FROM inventory_adjustments WHERE adjustment_id=1')->fetchColumn();"),'5');
        // Explicit rejection of no affected units never changes stock.
        await page.goto(origin+'/components/inventory_management/stock_issues.php?detail=2');
        await page.locator('#report-2 input[name^="batches["]').fill('0');
        await page.locator('#report-2 [name="review_notes"]').fill('No affected units found.');
        await Promise.all([page.waitForNavigation(),page.locator('#report-2 [value="reject"]').click()]);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"),'6');
        assert.equal(await page.getByText('Activity', {exact: true}).count(), 0);
        await navigate(page.getByRole('link', {name: 'Back to list'}));
        await navigate(page.getByRole('link', {name: /Recent decisions/}));
        await navigate(page.getByRole('link', {name: 'Decision date', exact: true}));
        assert.equal(await page.locator('th[aria-sort="ascending"]').innerText(), 'Decision date');
        const dates = await issueRows.locator('time').evaluateAll(times => times.map(time => time.dateTime));
        assert.deepEqual(dates, dates.toSorted());
        const decisionsUrl = page.url();
        await issueRows.first().locator('td:first-child').click();
        assert.equal(page.url(), decisionsUrl);
        await page.getByLabel('Entries per page').selectOption('10');
        await page.waitForURL(url => url.searchParams.get('length') === '10');
        assert.equal(await page.locator('th[aria-sort="ascending"]').innerText(), 'Decision date');

        const deductionHeader = page.getByRole('link', {name: 'Quantity deducted', exact: true});
        const deductions = () => issueRows.locator('.cell-qty').allTextContents();
        await navigate(deductionHeader);
        assert.deepEqual(await deductions(), ['0 pieces', '-2 pieces', '-2 pieces']);
        await navigate(deductionHeader);
        assert.deepEqual(await deductions(), ['-2 pieces', '-2 pieces', '0 pieces']);

        const statusHeader = page.getByRole('link', {name: 'Status', exact: true});
        const statuses = () => issueRows.locator('.adjustment-status').allTextContents();
        await navigate(statusHeader);
        assert.deepEqual(await statuses(), ['Approved', 'Approved', 'Rejected']);
        await navigate(statusHeader);
        assert.deepEqual(await statuses(), ['Rejected', 'Approved', 'Approved']);
        await page.screenshot({path: path.join(output, 'decisions-sorted.png'), fullPage: true});
        await page.setViewportSize({width: 390, height: 900});
        await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
        await statusHeader.focus();
        assert.ok(await statusHeader.evaluate(link => getComputedStyle(link).color === getComputedStyle(link.closest('th')).color));
        await page.screenshot({path: path.join(output, 'decisions-sorted-dark-390.png'), fullPage: true, animations: 'disabled'});
        await page.setViewportSize({width: 1440, height: 900});
        // Direct sealed packs use the same atomic batch conversion.
        sql("$pdo->exec(\"UPDATE products SET pack_size=6,pack_barcode='DIRECT-PACK',pack_price=30 WHERE product_id=1\"); $pdo->exec('UPDATE inventory SET quantity_on_hand=60 WHERE product_id=1'); $pdo->exec('UPDATE product_batches SET quantity=60,remaining_quantity=60,sealed_quantity=10,loose_quantity=0 WHERE product_id=1');");
        await page.goto(origin+'/components/inventory_management/stock_issues.php?issue_view=record');
        await page.locator('#barcode-input').fill('DIRECT-PACK');
        await page.locator('#barcode-input').press('Enter');
        await page.locator('#selected-product').waitFor({state:'visible'});
        assert.equal(await page.locator('#form').inputValue(),'pack');
        await page.locator('#category').selectOption('damaged');
        await page.locator('#quantity').fill('2');
        await page.locator('#explanation').fill('Two sealed packs damaged.');
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Record & deduct stock',exact:true}).click()]);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"),'48');
        assert.match(await page.locator('#reviews-section').innerText(),/2 packs.*12 pcs/);
        // Cashier sees preserved original and actual deduction, without chat controls or reply queues.
        const cashier = await browser.newPage();
        await cashier.goto(origin+'/?login=1');
        await cashier.locator('#landing-login-username').fill('theme_cashier');
        await cashier.locator('#landing-login-password').fill(data.password);
        await Promise.all([cashier.waitForNavigation(),cashier.getByRole('button',{name:'Log in',exact:true}).click()]);
        const cashierHistoryUrl = origin+'/components/cashier/stock_issues.php';
        await cashier.goto(cashierHistoryUrl);
        const historyRows = cashier.locator('#report-history tbody tr');
        const historyPages = cashier.getByRole('navigation', {name: 'Report history pages'});
        const historyFilters = cashier.getByRole('navigation', {name: 'Filter report status'});
        const firstPage = await historyRows.evaluateAll(rows => rows.map(row => row.id));
        assert.equal(firstPage.length, 15);
        const navigateCashier = target => Promise.all([cashier.waitForNavigation(), target.click()]);
        await navigateCashier(historyPages.getByRole('link', {name: 'Next'}));
        const secondPage = await historyRows.evaluateAll(rows => rows.map(row => row.id));
        assert.ok(secondPage.length > 0 && secondPage.every(id => !firstPage.includes(id)));
        await navigateCashier(historyRows.first().getByRole('link', {name: /View details/}));
        assert.equal(await cashier.locator('#report-history .stock-report-card').count(), 1);
        await navigateCashier(cashier.getByRole('link', {name: 'Back to list'}));
        assert.deepEqual(await historyRows.evaluateAll(rows => rows.map(row => row.id)), secondPage);
        await navigateCashier(historyPages.getByRole('link', {name: 'Previous'}));
        assert.deepEqual(await historyRows.evaluateAll(rows => rows.map(row => row.id)), firstPage);
        await Promise.all([
            cashier.waitForNavigation(),
            cashier.getByRole('combobox', {name: 'Entries per page'}).selectOption('10'),
        ]);
        assert.equal(await historyRows.count(), 10);
        await navigateCashier(historyFilters.getByRole('link', {name: /^Awaiting manager/}));
        await navigateCashier(historyPages.getByRole('link', {name: 'Next'}));
        assert.equal(new URL(cashier.url()).searchParams.get('status'), 'pending');
        await navigateCashier(historyFilters.getByRole('link', {name: /^Cancelled/}));
        assert.match(await cashier.locator('#report-filter-status').innerText(), /No reports in this status/);
        await navigateCashier(historyFilters.getByRole('link', {name: /^Approved/}));
        assert.deepEqual(await historyRows.evaluateAll(rows => rows.map(row => row.id)), ['report-1']);
        for (const theme of ['light', 'dark']) {
            await cashier.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 768, 1440]) {
                await cashier.setViewportSize({width, height:844});
                const tableRegion = cashier.locator('#report-history .history-table-panel');
                assert.ok(await tableRegion.evaluate(region => {
                    const bounds = region.getBoundingClientRect();
                    return bounds.left >= 0 && bounds.right <= innerWidth && document.documentElement.scrollWidth <= innerWidth + 1;
                }), `Cashier report table fits at ${width} ${theme}`);
                await cashier.locator('#report-history').screenshot({path:path.join(output,`cashier-history-${theme}-${width}.png`)});
            }
        }
        await cashier.goto(cashierHistoryUrl+'?detail=1');
        assert.equal(await cashier.locator('.conversation-reply-form').count(),0);
        assert.equal(await cashier.getByRole('button',{name:/Awaiting your reply/}).count(),0);
        assert.match(await cashier.locator('main').innerText(),/Reported: 5/);
        assert.equal(await cashier.locator('#stock-issue-form').count(),1,'Shared Cashier reporting form remains available');
        await cashier.setViewportSize({width:390,height:844});
        assert.equal(await cashier.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
        await cashier.screenshot({path:path.join(output,'cashier-390.png'),fullPage:true});
        const stockBeforeReport = sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();");
        await cashier.locator('#barcode-input').fill('DIRECT-PACK');
        await cashier.locator('#barcode-input').press('Enter');
        await cashier.locator('#selected-product').waitFor({state:'visible'});
        await cashier.locator('#category').selectOption('damaged');
        await cashier.locator('#quantity').fill('2');
        await cashier.locator('#explanation').fill('Two packs reported by Cashier.');
        await Promise.all([cashier.waitForNavigation(),cashier.getByRole('button',{name:'Submit Report',exact:true}).click()]);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"),stockBeforeReport,'Cashier report does not deduct stock');
        const cashierReportId = sql("echo $pdo->query('SELECT MAX(adjustment_id) FROM inventory_adjustments WHERE reported_by=4')->fetchColumn();");
        await page.goto(origin+'/components/inventory_management/stock_issues.php?detail='+cashierReportId);
        const cashierReview = page.locator('#report-'+cashierReportId);
        await cashierReview.locator('input[name^="batches["]').fill('1');
        assert.equal(await cashierReview.locator('[value="approve"]').textContent(), 'Approve & deduct 1 pack');
        await cashierReview.locator('[name="review_notes"]').fill('Only one sealed pack is damaged.');
        await Promise.all([page.waitForNavigation(),cashierReview.locator('[value="approve"]').click()]);
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"),'42','Manager approves edited quantity of one pack');
        sql("$pdo->exec(\"UPDATE product_batches SET expiration_date='2000-01-01' WHERE product_id=1\");");
        const issueSearch = await (await page.request.get(origin+'/components/barcodeScanner/apiScanner/products.php?scope=stock_issues&id=1')).json();
        const saleSearch = await (await page.request.get(origin+'/components/barcodeScanner/apiScanner/products.php?id=1')).json();
        assert.equal(issueSearch.product.quantity_on_hand,42,'Expired stock remains available for Stock Issue recording');
        assert.equal(saleSearch.products.length,0,'Expired stock stays excluded from ordinary sale lookup');
        sql("$pdo->exec('UPDATE product_batches SET expiration_date=NULL WHERE product_id=1');");
        const unauthorized = await cashier.request.post(origin+'/components/inventory_management/stock_issues.php',{form:{action:'record',product_id:1,category:'damaged',quantity:1}});
        assert.ok(unauthorized.status()===403 || unauthorized.url().includes('login') || !unauthorized.url().includes('inventory_management/stock_issues.php'),'Cashier cannot submit direct Manager actions');

        const cashierIssuesUrl = origin + '/components/cashier/stock_issues.php';
        const managerIssuesUrl = origin + '/components/inventory_management/stock_issues.php';
        const workspaceUrl = origin + '/components/auth/workspace.php';
        const shortcut = cashier.getByRole('button', {name: 'Open Inventory Stock Issues', exact: true});
        const cashierToken = await cashier.locator('#stock-issue-form [name="csrf_token"]').inputValue();
        assert.equal(await shortcut.count(), 0);
        const deniedSwitch = await cashier.request.post(workspaceUrl, {
            form: {csrf_token: cashierToken, workspace: 'inventory_manager', destination: 'record_stock_issue'},
        });
        assert.equal(deniedSwitch.status(), 403, 'Shortcut requires the assigned Inventory Manager role');

        const olderOwnReport = sql(`
            $pdo->exec("INSERT INTO inventory_adjustments
                (product_id, adjustment_qty, reported_qty, form, adjustment_type, reported_by, reason, reported_at)
                VALUES (1, -1, 1, 'piece', 'damaged', 4, 'Older Cashier report', '2026-01-01 00:00:00')");
            echo $pdo->lastInsertId();
            $pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (4, 3, 0)");
        `);
        await cashier.goto(cashierIssuesUrl + '?detail=' + olderOwnReport);
        assert.equal(await cashier.locator('#stock-issue-form').count(), 0);
        assert.equal(await cashier.getByText('Shift Required', {exact: true}).count(), 0);
        assert.equal(await cashier.getByRole('heading', {name: 'Report History', exact: true}).count(), 1);
        assert.equal(await cashier.locator('#report-' + olderOwnReport).count(), 1, 'An older Cashier report remains available by direct link');
        const deniedReport = await cashier.request.post(cashierIssuesUrl, {
            form: {csrf_token: cashierToken, product_id: 1, category: 'damaged', quantity: 1, explanation: 'Should use Inventory instead.'},
        });
        assert.equal(deniedReport.status(), 403, 'Dual-role users cannot submit a new Cashier report');

        for (const theme of ['light', 'dark']) {
            await cashier.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            for (const width of [390, 768, 1440]) {
                await cashier.setViewportSize({width, height: 844});
                assert.ok(await shortcut.evaluate(button => {
                    const {left, right, height} = button.getBoundingClientRect();
                    return left >= 0 && right <= innerWidth && height >= 32 && document.documentElement.scrollWidth <= innerWidth + 1;
                }), `Shortcut fits at ${width} ${theme}`);
                await cashier.screenshot({path: path.join(output, `cashier-shortcut-${theme}-${width}.png`)});
            }
        }
        await shortcut.focus();
        await Promise.all([cashier.waitForURL(managerIssuesUrl + '?issue_view=record'), cashier.keyboard.press('Enter')]);
        await cashier.getByRole('button', {name: 'Record & deduct stock', exact: true}).waitFor();
        await cashier.locator('#barcode-input').fill('DIRECT-PACK');
        await cashier.locator('#barcode-input').press('Enter');
        await cashier.locator('#selected-product').waitFor({state: 'visible'});
        await cashier.locator('#category').selectOption('damaged');
        await cashier.locator('#quantity').fill('1');
        await cashier.locator('#explanation').fill('One pack from the Inventory shortcut.');
        await Promise.all([cashier.waitForNavigation(), cashier.getByRole('button', {name: 'Record & deduct stock', exact: true}).click()]);
        const shortcutRecordId = new URL(cashier.url()).searchParams.get('detail');
        assert.match(await cashier.locator('main').innerText(), /Confirmed quantity deducted/);
        assert.match(await cashier.getByRole('link', {name: 'Back to list'}).getAttribute('href'), /issue_view=decisions/);

        await cashier.goto(managerIssuesUrl + '?detail=' + olderOwnReport);
        const ownReview = cashier.locator('#report-' + olderOwnReport);
        await ownReview.locator('input[name^="batches["]').fill('0');
        await ownReview.locator('[name="review_notes"]').fill('No affected units found in the older report.');
        await Promise.all([cashier.waitForNavigation(), ownReview.locator('[value="reject"]').click()]);
        assert.equal(sql(`echo $pdo->query("SELECT COUNT(*) FROM inventory_adjustments WHERE adjustment_id=${olderOwnReport} AND reported_by=4 AND approved_by=4 AND status='rejected'")->fetchColumn();`), '1');
        assert.equal(sql("echo $pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn();"), '36', 'The direct entry deducts one pack and rejection leaves stock alone');

        const switchedBack = await cashier.request.post(workspaceUrl, {form: {csrf_token: cashierToken, workspace: 'cashier'}});
        assert.match(switchedBack.url(), /components\/cashier\/pos\.php$/);
        await cashier.goto(cashierIssuesUrl + '?detail=' + shortcutRecordId);
        assert.equal(await cashier.locator('#report-' + shortcutRecordId).count(), 0, 'A direct Inventory record stays out of Cashier history even by direct link');
        assert.equal(await cashier.locator('#report-' + cashierReportId).count(), 1, 'Cashier history still includes Cashier submissions');

        assert.deepEqual(errors,[],'No JavaScript errors in Manager flows');
        console.log('Stock issue direct recording/review browser checks passed; captures: '+output);
    } finally {
        if (browser) await browser.close();
        if (server) server.kill();
        fixture('cleanup');
        fs.rmSync(recovery, {recursive: true, force: true});
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
