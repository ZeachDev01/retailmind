'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');
if (process.env.RUN_DB_TESTS !== '1') { console.log('GCash browser: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(output, 'recovery')};
const run = (file, args) => {
    const result = spawnSync(php, [file, ...args], {encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const fixture = action => run('src/backend/tests/support/cashier_ui_fixture.php', [action, database]);
const csvRows = bytes => {
    assert.deepEqual([...bytes.subarray(0, 3)], [239, 187, 191], 'CSV retains UTF-8 BOM');
    const parsed = spawnSync(php, ['-r', '$rows = []; while (($row = fgetcsv(STDIN, null, ",", "\\\"", "")) !== false) $rows[] = $row; echo json_encode($rows, JSON_THROW_ON_ERROR);'], {input: bytes.subarray(3), encoding: 'utf8'});
    assert.equal(parsed.status, 0, parsed.stderr);
    return JSON.parse(parsed.stdout);
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));

        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch (_) {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 900}});
        await context.route('https://**/*', route => route.fulfill({status: 200, body: '', contentType: route.request().resourceType() === 'stylesheet' ? 'text/css' : 'text/javascript'}));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.setDefaultTimeout(10000);
        const login = async (target, role) => {
            await target.goto(origin + '/?login=1');
            await target.locator('#landing-login-username').fill('theme_' + role);
            await target.locator('#landing-login-password').fill(data.password);
            await Promise.all([target.waitForURL(url => !url.search.includes('login=1')), target.getByRole('button', {name: 'Log in', exact: true}).click()]).catch(async error => { throw new Error(error.message + '\n' + await target.locator('body').innerText() + '\n' + logs.slice(-3000)); });
        };
        await login(page, 'admin');
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(await page.locator('#admin-expected-cash').textContent(), 'Not recorded');
        assert.equal(await page.locator('#admin-expected-wallet').textContent(), 'Not recorded');
        const missingEvidence = (await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json()).recorded_balances;
        assert.equal(missingEvidence.service_cash, null);
        assert.equal(missingEvidence.wallet_balance, null);
        const today = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
        assert.equal(await page.locator('#filter-start-date').inputValue(), today, 'Initial report defaults to Philippine Today');
        assert.equal(run('src/backend/tests/gcash_transaction_integration.php', [database]).trim(), 'GCash MySQL integration: passed');
        await page.reload();
        await page.getByRole('tab', {name: 'Transactions'}).click();
        const savedReceiptRow = page.locator('#gcash-admin-history-body tr', {hasText: '00000000000000000000000000123456'});
        const receiptAction = savedReceiptRow.getByRole('button', {name: 'Receipt', exact: true});
        const beforeReprint = await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json();
        await page.evaluate(() => { window.receiptPrints = 0; window.print = () => { window.receiptPrints++; }; });
        await receiptAction.click();
        await page.locator('#receiptModal .gcash-service-receipt').waitFor();
        assert.equal(await page.locator('#receiptModal').evaluate(dialog => getComputedStyle(dialog).padding), '0px', 'Receipt retains shared POS modal shell without Overview dialog padding');
        assert.equal(await page.locator('#txDetailModal').evaluate(dialog => dialog.open), false, 'Receipt action does not also open transaction breakdown');
        assert.equal(await page.locator('#saleReceiptTitle').evaluate(title => title === document.activeElement), true);
        assert.match(await page.locator('#receiptModal').innerText(), /00000000000000000000000000123456/);
        assert.equal(await page.evaluate(() => window.receiptPrints), 0, 'History reopen never automatically prints');
        await page.getByRole('button', {name: 'Print Receipt', exact: true}).click();
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        assert.equal(await page.evaluate(() => window.receiptPrints), 1);
        await page.keyboard.press('Escape');
        assert.equal(await receiptAction.evaluate(button => button === document.activeElement), true, 'Closing returns to originating Overview receipt action');
        assert.deepEqual(await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json(), beforeReprint, 'Receipt retrieval and printing leave Store-wide financial state unchanged');
        await page.getByRole('tab', {name: 'Overview', exact: true}).click();
        assert.equal(await page.locator('#filter-start-date').isVisible(), true, 'Shared reporting controls are available beside headline activity');
        await page.getByLabel('Reporting period', {exact: true}).selectOption('month_to_date');
        await page.locator('#btn-apply-filters').click();
        await page.waitForURL(url => url.searchParams.get('period') === 'month_to_date');
        assert.equal(await page.locator('#filter-start-date').inputValue(), today.slice(0, 7) + '-01');
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'overview', 'Shared scope preserves active section');
        const apiDefault = await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json();
        assert.equal(apiDefault.filters.start_date, today, 'Overview API defaults to Today');
        assert.equal(apiDefault.filters.end_date, today);
        const apiMonth = await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php?period=month_to_date')).json();
        assert.equal(apiMonth.filters.start_date, today.slice(0, 7) + '-01');
        assert.equal((await context.request.get(origin + '/components/administrator/gcash_overview_api.php?cashier_id=abc')).status(), 400);
        assert.equal((await context.request.get(origin + '/components/administrator/gcash_overview_api.php?start_date=2026-02-30&end_date=2026-03-01')).status(), 400);
        await page.getByLabel('Reporting period', {exact: true}).selectOption('custom');
        await page.locator('#filter-start-date').fill('2026-01-01');
        await page.locator('#filter-end-date').fill('2026-01-02');
        await page.locator('#btn-apply-filters').click();
        await page.waitForURL(url => url.searchParams.get('period') === 'custom');
        assert.equal(await page.locator('#admin-metric-total-tx').textContent(), '0');
        assert.equal(await page.locator('#filter-start-date').inputValue(), '2026-01-01');
        assert.equal(await page.locator('#filter-end-date').inputValue(), '2026-01-02');
        await page.getByLabel('Reporting period', {exact: true}).selectOption('today');
        await page.locator('#btn-apply-filters').click();
        await page.waitForURL(url => url.searchParams.get('period') === 'today');
        const completedCount = await page.locator('#admin-metric-total-tx').textContent();
        await page.route('**/gcash_overview.php?**', route => route.abort('failed'));
        await page.locator('#btn-apply-filters').click();
        await page.locator('#gcash-filter-status').filter({hasText: 'unavailable for this selection'}).waitFor();
        assert.equal(await page.locator('#admin-metric-total-tx').textContent(), 'Unavailable', 'A failed shared selection cannot display zero or stale totals');
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 0, 'Failed selections cannot retain rows from the previous selection');
        assert.equal(await page.locator('#gcash-tab-transactions .gcash-badge').textContent(), 'Unavailable');
        assert.equal(await page.locator('#admin-nc-count').textContent(), 'Unavailable', 'Failed review refresh cannot retain a numeric pending count');
        assert.equal((await page.locator('#gcash-session-status').textContent()).trim(), 'Unavailable');
        await page.unroute('**/gcash_overview.php?**');
        await page.locator('#btn-apply-filters').click();
        await page.waitForFunction(expected => document.getElementById('admin-metric-total-tx').textContent === expected, completedCount);
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        const evidence = (await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php')).json()).recorded_balances;
        assert.equal(evidence.state, 'last_counted');
        assert.equal(evidence.session_status, 'closed');
        assert.equal(evidence.cashier_id, 4);
        assert.ok(evidence.checked_at);
        assert.equal(await page.locator('#admin-cash-state').textContent(), 'Last counted');
        assert.equal(await page.locator('#admin-wallet-state').textContent(), 'Last counted');
        assert.ok((await page.locator('#admin-cash-checked').textContent()).includes(evidence.cashier_name));
        assert.ok((await page.locator('#admin-cash-checked').textContent()).includes(evidence.checked_at_formatted));
        assert.equal(await page.locator('#admin-cash-checked').isVisible(), false, 'Fund metadata stays collapsed by default');
        await page.getByText('Balance check & cashier', {exact: true}).click();
        assert.equal(await page.locator('#admin-cash-checked').isVisible(), true, 'Fund metadata remains accessible');
        await page.getByText('Balance check & cashier', {exact: true}).click();
        assert.equal(await page.locator('.page-subtitle, #tab-overview .gcash-card-sub, #tab-overview .pos-panel-title p').count(), 0, 'Overview omits explanatory subtext');
        const recordedCash = await page.locator('#admin-expected-cash').textContent();
        assert.equal(await page.getByRole('tab').count(), 5, 'All five task tabs remain available');
        const balances = page.getByRole('tab', {name: 'Overview'});
        assert.equal(await balances.getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('#tab-transactions').isVisible(), false);
        await balances.focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('#tab-transactions').isVisible(), true);
        await page.keyboard.press('End');
        await page.waitForURL(url => url.searchParams.get('tab') === 'money');
        assert.equal(await page.locator('#tab-money').isVisible(), true);
        await page.keyboard.press('ArrowLeft');
        assert.equal(await page.locator('#tab-unresolved').isVisible(), true);
        await page.keyboard.press('Home');
        assert.equal(await page.locator('#tab-overview').isVisible(), true);
        const initialReviewCount = Number(await page.locator('#admin-nc-count').textContent());
        const outstanding = page.getByRole('button', {name: /Review unresolved exchanges/});
        assert.ok((await outstanding.textContent()).includes(String(initialReviewCount)));
        await outstanding.click();
        assert.equal(await page.locator('#tab-unresolved').isVisible(), true);
        assert.ok((await page.locator('#tab-unresolved').textContent()).includes('All Cashiers'));
        const legacyUnresolved = page.locator('#gcash-admin-nc-body tr').filter({hasText: '7990000000999'});
        assert.equal((await legacyUnresolved.locator('td').nth(5).textContent()).trim(), 'Not recorded', 'Unresolved legacy reference has no recorded fee');
        assert.ok((await legacyUnresolved.locator('td').nth(6).textContent()).includes('Cash: Not recorded'), 'Unresolved legacy reference has no recorded cash movement');
        assert.ok((await legacyUnresolved.locator('td').nth(6).textContent()).includes('Wallet: Not recorded'), 'Unresolved legacy reference has no recorded wallet movement');
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=unresolved&start_date=2020-01-01&end_date=2020-01-02&cashier_id=1&search=no-match&type=Cash-Out');
        assert.equal(Number(await page.locator('#admin-nc-count').textContent()), initialReviewCount, 'Historic reporting and every list filter preserve Store-wide review count');
        assert.ok((await page.locator('#gcash-admin-nc-table').textContent()).includes('Other cashier held item'), 'Historical other-Cashier unresolved record remains visible');
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        await page.getByRole('tab', {name: 'Transactions'}).click();
        const search = page.getByRole('searchbox', {name: 'Search transactions'});
        await search.fill('no-such-gcash-reference');
        await page.getByText('No transactions match these filters.').waitFor();
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 0);
        assert.equal(await page.locator('#admin-metric-total-tx').textContent(), completedCount, 'Local search preserves shared completed activity in the browser');
        assert.equal(await page.locator('#admin-expected-cash').textContent(), recordedCash, 'Search does not change recorded funds');
        await search.fill('');
        await page.locator('#gcash-admin-history-body tr[data-transaction]').first().waitFor();
        // A delayed filter response preserves a tab chosen while it was loading.
        let releaseFilter;
        const filterGate = new Promise(resolve => { releaseFilter = resolve; });
        await page.route('**/gcash_overview.php?**', async route => {
            const response = await route.fetch();
            await filterGate;
            await route.fulfill({response});
        });
        const requestStarted = page.waitForRequest(request => request.url().includes('search=no-such-gcash-reference'));
        await search.fill('no-such-gcash-reference');
        await requestStarted;
        await page.getByRole('tab', {name: 'Overview'}).click();
        releaseFilter();
        await page.locator('#gcash-filter-status').filter({hasText: '0 matching transactions'}).waitFor({state: 'attached'});
        assert.equal(new URL(page.url()).searchParams.get('tab'), 'overview');
        await page.unroute('**/gcash_overview.php?**');
        await page.getByRole('tab', {name: 'Transactions'}).click();
        await search.fill('');
        await page.locator('#gcash-admin-history-body tr[data-transaction]').first().waitFor();
        // A superseded report must not replace the newer selection's rows or totals.
        let releaseObsolete;
        const obsoleteGate = new Promise(resolve => { releaseObsolete = resolve; });
        let obsoleteFinished;
        const obsoleteDone = new Promise(resolve => { obsoleteFinished = resolve; });
        await page.route('**/gcash_overview.php?**', async route => {
            if (new URL(route.request().url()).searchParams.get('search') !== 'no-such-gcash-reference') return route.continue();
            const response = await route.fetch();
            await obsoleteGate;
            try { await route.fulfill({response}); }
            catch (error) { if (!route.request().failure()) throw error; }
            finally { obsoleteFinished(); }
        });
        const obsoleteStarted = page.waitForRequest(request => request.url().includes('search=no-such-gcash-reference'));
        await search.fill('no-such-gcash-reference');
        await obsoleteStarted;
        await search.fill('');
        await page.locator('#gcash-filter-status').filter({hasText: /[1-9][0-9]* matching transactions/}).waitFor();
        releaseObsolete();
        await obsoleteDone;
        await page.unroute('**/gcash_overview.php?**');
        assert.ok(await page.locator('#gcash-admin-history-body tr[data-transaction]').count() > 0);
        assert.equal(await page.locator('#admin-metric-total-tx').textContent(), completedCount);
        await page.getByRole('button', {name: /^Cash-In \(/}).click();
        await page.waitForFunction(() => [...document.querySelectorAll('#gcash-admin-history-body tr[data-transaction]')].every(row => JSON.parse(row.dataset.transaction).transaction_type === 'Cash-In'));
        assert.ok(await page.locator('#gcash-admin-history-body tr[data-transaction]').count() > 0);
        await page.getByRole('button', {name: 'Details', exact: true}).first().click();
        const detail = page.getByRole('dialog', {name: 'Transaction Breakdown'});
        await detail.waitFor();
        assert.ok((await detail.textContent()).includes('GCash Service Cash'));
        await page.keyboard.press('Escape');
        assert.equal(await detail.isVisible(), false);
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', {name: 'Export Report'}).click();
        const download = await downloadPromise;
        const csv = fs.readFileSync(await download.path(), 'utf8');
        assert.ok(csv.includes('Cash-In'));
        assert.equal(csv.includes('Cash-Out'), false, 'Export respects type filter');
        await page.getByRole('button', {name: /^All \(/}).click();
        await search.fill('0000000000301');
        await page.getByRole('status').filter({hasText: '1 matching transactions'}).waitFor();
        await page.getByRole('button', {name: 'Details', exact: true}).click();
        assert.ok((await detail.textContent()).includes('Customer refunded 10 pesos fee overcharge'), 'Details preserve linked adjustment reason');
        assert.ok((await detail.textContent()).includes('−₱10.00'), 'Details preserve signed linked adjustment');
        await page.keyboard.press('Escape');
        await page.locator('#filter-cashier-id').selectOption('4');
        await search.fill('888888888');
        await page.getByRole('status').filter({hasText: '105 matching transactions'}).waitFor();
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').first().locator('td').nth(4).textContent(), 'Not recorded', 'Legacy history does not invent a zero fee');
        assert.equal(await page.locator('#gcash-admin-history-body .gcash-receipt').count(), 0, 'Unpreserved history never exposes a receipt action after filtering');
        const allDownloadPromise = page.waitForEvent('download');
        await page.getByRole('link', {name: 'Export Report'}).click();
        const allDownload = await allDownloadPromise;
        const allCsv = fs.readFileSync(await allDownload.path(), 'utf8');
        assert.deepEqual(csvRows(Buffer.from(allCsv)).slice(1).map(row => row[1]), Array.from({length: 105}, (_, index) => '888888888' + String(104 - index).padStart(4, '0')), 'CSV exports the complete ordered selection without duplicates');
        assert.ok(allCsv.includes('Legacy reference only'), 'CSV identifies reference-only history');
        assert.ok(allCsv.includes('Not recorded'), 'CSV does not invent legacy fee or movement evidence');
        assert.ok(allCsv.includes("'=GCASH_CSV_TEST"), 'CSV neutralizes formula-leading Cashier names');
        const exportRows = async (selection, requestContext = context) => {
            const response = await requestContext.request.get(origin + '/components/administrator/gcash_overview.php?' + new URLSearchParams({...selection, export: 'csv', page: 99}));
            assert.equal(response.status(), 200);
            assert.equal(response.headers()['content-type'], 'text/csv; charset=utf-8');
            assert.equal(response.headers()['content-disposition'], 'attachment; filename="gcash-transactions.csv"');
            assert.equal(response.headers()['cache-control'], 'no-store');
            const [heading, ...rows] = csvRows(await response.body());
            assert.deepEqual(heading, ['Date / Time', 'Reference No.', 'Type', 'Principal', 'Fee', 'Fee Location', 'Status', 'Cashier', 'Shift', 'Service Cash Movement', 'Wallet Movement', 'Record Basis', 'Legacy Reference Amount']);
            assert.ok(rows.every(row => row.length === heading.length));
            return rows;
        };
        for (const [searchTerm, count] of [['absent-traversal-reference', 0], ['8888888880000', 1], ['88888888800', 100], ['888888888', 105]]) {
            const rows = await exportRows({search: searchTerm});
            assert.deepEqual(rows.map(row => row[1]), Array.from({length: count}, (_, index) => '888888888' + String(count - 1 - index).padStart(4, '0')), 'Empty, one-record and larger exports preserve every expected ordered reference');
            if (count === 1) assert.deepEqual(rows[0].slice(1), ['8888888880000', 'Payment', 'Not recorded', 'Not recorded', 'Not recorded', 'Pending', "'=GCASH_CSV_TEST", 'Not recorded', 'Not recorded', 'Not recorded', 'Legacy reference only', '1.00']);
        }
        const shiftSearch = '#' + beforeReprint.transactions.find(row => row.shift_id !== null).shift_id;
        for (const selection of [{}, {period: 'month_to_date'}, {start_date: today, end_date: today}, {start_date: '2020-01-01', end_date: today}, {cashier_id: 4}, {type: 'Cash-Out'}, {search: '00000000000000000000000000123456'}, {search: '=GCASH_CSV_TEST'}, {search: '%'}, {search: '_'}, {search: '!'}, {search: shiftSearch}, {cashier_id: 4, type: 'Cash-In', start_date: today, end_date: today}]) {
            const listed = [];
            let listPage = 1, report;
            do {
                report = await (await context.request.get(origin + '/components/administrator/gcash_overview_api.php?' + new URLSearchParams({...selection, page: listPage++}))).json();
                listed.push(...report.transactions);
            } while (report.has_next);
            const rows = await exportRows(selection);
            assert.deepEqual(rows.map(row => row[1]), listed.map(row => row.reference_number ?? ''), 'Export and paginated list agree for ' + JSON.stringify(selection));
            assert.deepEqual(rows.map(row => row[6]), listed.map(row => row.verification_status), 'Export preserves historical statuses');
        }
        const rejectedExport = await context.request.get(origin + '/components/administrator/gcash_overview.php?export=csv&cashier_id=invalid');
        assert.equal(rejectedExport.status(), 400);
        assert.equal(rejectedExport.headers()['content-disposition'], undefined, 'Rejected exports cannot become a partial attachment');
        // Exercise real HTTP staging failure without a production-only test hook.
        const failureListener = net.createServer();
        await new Promise(resolve => failureListener.listen(0, '127.0.0.1', resolve));
        const failurePort = failureListener.address().port;
        await new Promise(resolve => failureListener.close(resolve));
        const failureOrigin = 'http://127.0.0.1:' + failurePort;
        const failureRouter = path.join(output, 'export-failure-router.php');
        const realRouter = path.resolve('src/backend/tests/support/theme_router.php').replaceAll('\\', '/');
        fs.writeFileSync(failureRouter, `<?php
            require '${path.resolve('src/backend/bootstrap/app.php').replaceAll('\\', '/')}';
            $_ENV['DB_NAME'] = $_SERVER['DB_NAME'] = getenv('RM_THEME_TEST_DATABASE');
            require '${path.resolve('src/backend/includes/auth.php').replaceAll('\\', '/')}';
            class UnavailableOverviewStatement extends PDOStatement {
                private static bool $returnedHistory = false;
                public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed {
                    $row = parent::fetch($mode, $orientation, $offset);
                    if (is_array($row) && array_key_exists('opening_service_cash', $row)) throw new RuntimeException('Overview funds unavailable');
                    return $row;
                }
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
                    $rows = parent::fetchAll($mode, ...$args);
                    if (($_GET['failure'] ?? '') === 'retrieval' && isset($rows[0]['reference_number'], $rows[0]['transaction_id'])) {
                        if (self::$returnedHistory) throw new RuntimeException('History retrieval interrupted');
                        self::$returnedHistory = true;
                        file_put_contents('${path.join(output, 'retrieved.json').replaceAll('\\', '/')}', json_encode(array_column($rows, 'reference_number')));
                    }
                    return $rows;
                }
            }
            if (in_array($_GET['failure'] ?? '', ['overview', 'retrieval'], true)) {
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [UnavailableOverviewStatement::class]);
                require '${realRouter}';
                exit;
            }
            class FailedExportStream {
                public $context;
                private int $writes = 0;
                public function stream_open($path, $mode, $options, &$openedPath) { return $path === 'php://temp'; }
                public function stream_write($data) { return ++$this->writes > 3 ? false : strlen($data); }
            }
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', FailedExportStream::class);
            require '${realRouter}';`);
        const failureServer = spawn(php, ['-S', '127.0.0.1:' + failurePort, '-t', 'src/frontend', failureRouter], {env: {...env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let failureLogs = '';
        failureServer.stderr.on('data', chunk => { failureLogs += chunk; });
        try {
            for (let attempt = 0; attempt < 100; attempt++) {
                try { if ((await fetch(failureOrigin + '/assets/js/gcash-admin-overview.js')).ok) break; } catch (_) {}
                await new Promise(resolve => setTimeout(resolve, 50));
                if (attempt === 99) throw new Error(failureLogs);
            }
            const cookie = (await context.cookies()).map(item => `${item.name}=${item.value}`).join('; ');
            const independentExport = await fetch(failureOrigin + '/components/administrator/gcash_overview.php?export=csv&failure=overview&search=888888888', {headers: {Cookie: cookie}});
            assert.equal(independentExport.status, 200, 'Matching rows export when overview-only retrieval is unavailable');
            assert.equal(Buffer.from(await independentExport.arrayBuffer()).toString('utf8'), allCsv);
            const unavailableOverview = await fetch(failureOrigin + '/components/administrator/gcash_overview_api.php?failure=overview', {headers: {Cookie: cookie}});
            assert.equal(unavailableOverview.status, 500, 'Failure fixture actually prevents full Overview retrieval');
            const interruptedExport = await fetch(failureOrigin + '/components/administrator/gcash_overview.php?export=csv&failure=retrieval&search=888888888', {headers: {Cookie: cookie}});
            assert.equal(interruptedExport.status, 503);
            assert.equal(interruptedExport.headers.get('content-disposition'), null, 'Retrieval failure after initial progress cannot deliver a partial attachment');
            assert.equal(await interruptedExport.text(), 'Unable to export the complete report. Try again.');
            const retrieved = JSON.parse(fs.readFileSync(path.join(output, 'retrieved.json'), 'utf8'));
            assert.ok(retrieved.length > 0 && retrieved.length < 105, 'Retrieval failed after an initial portion of the matching history');
            const failedExport = await fetch(failureOrigin + '/components/administrator/gcash_overview.php?export=csv', {headers: {Cookie: cookie}}).catch(error => { throw new Error(String(error.cause || error) + failureLogs.slice(-3000)); });
            assert.equal(failedExport.status, 503, 'Staging failure returns a controlled failure');
            assert.equal(failedExport.headers.get('content-disposition'), null, 'Staging failure cannot produce a CSV download');
            assert.equal(await failedExport.text(), 'Unable to export the complete report. Try again.');
        } finally {
            const exited = new Promise(resolve => failureServer.once('exit', resolve));
            failureServer.kill();
            await exited;
        }
        const firstPageIds = await page.locator('tr[data-transaction]').evaluateAll(rows => rows.map(row => JSON.parse(row.dataset.transaction).transaction_id));
        await page.getByRole('link', {name: 'Next', exact: true}).click();
        await page.waitForURL(url => url.search.includes('page=2'));
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 50);
        const secondPageIds = await page.locator('tr[data-transaction]').evaluateAll(rows => rows.map(row => JSON.parse(row.dataset.transaction).transaction_id));
        assert.equal(firstPageIds.some(id => secondPageIds.includes(id)), false, 'Stable pagination has no repeated records');
        assert.equal(new URL(page.url()).searchParams.get('search'), '888888888');
        assert.equal(new URL(page.url()).searchParams.get('cashier_id'), '4', 'Pagination preserves shared Cashier scope');
        assert.equal(new URL(page.url()).searchParams.get('start_date'), today, 'Pagination preserves shared reporting period');
        await page.getByRole('link', {name: 'Next', exact: true}).click();
        await page.waitForURL(url => url.search.includes('page=3'));
        assert.equal(await page.locator('#gcash-admin-history-body tr[data-transaction]').count(), 5);
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=transactions&cashier_id=invalid');
        assert.equal(await page.locator('#gcash-tab-transactions .gcash-badge').textContent(), 'Unavailable', 'Rejected initial scope cannot claim no matching history');
        assert.equal((await page.locator('#gcash-session-status').textContent()).trim(), 'Unavailable');
        assert.ok((await page.locator('#gcash-admin-history-body').textContent()).includes('unavailable'));
        await page.getByRole('link', {name: 'Reset', exact: true}).click();
        await page.waitForURL(url => !url.search.includes('search='));
        await page.goto(origin + '/components/administrator/gcash_overview.php?tab=transactions');
        const historyRegion = page.getByRole('region', {name: 'Store-wide transaction history', exact: true});
        assert.equal(await historyRegion.evaluate(region => region.clientHeight <= 600 && region.scrollHeight > region.clientHeight), true, 'Long history scrolls within a bounded, keyboard-accessible region');
        assert.equal(await historyRegion.getAttribute('tabindex'), '0');
        const adminApi = origin + '/components/administrator/gcash_overview_api.php';
        const token = await page.locator('#gcash-admin-workspace').getAttribute('data-csrf');
        assert.equal((await context.request.post(adminApi, {data: {action: 'resolve_exchange', transaction_id: 1}})).status(), 403, 'Resolution requires CSRF protection');
        assert.equal((await context.request.post(adminApi, {headers: {'X-CSRF-Token': token}, data: {action: 'resolve_exchange', transaction_id: 1, confirmed_wallet: false, confirmed_cash: true}})).status(), 400, 'Resolution requires both confirmations');
        await search.fill('no-such-gcash-reference');
        await page.getByRole('status').filter({hasText: '0 matching transactions'}).waitFor();
        await page.getByRole('tab', {name: 'Closing checks'}).click();
        const discrepancyBefore = Number((await page.locator('#gcash-tab-reconciliation .gcash-badge').textContent()).match(/\d+/)[0]);
        const waive = page.locator('.admin-btn-waive').first();
        await waive.click();
        const waiver = page.getByRole('dialog', {name: 'Waive Closing Discrepancy'});
        await waiver.waitFor();
        await waiver.getByLabel('Reason for waiver (5–500 characters)').fill('Confirmed count reviewed by Administrator');
        await waiver.getByRole('button', {name: 'Confirm Waiver'}).click();
        await waiver.waitFor({state: 'hidden'});
        await page.waitForFunction(previous => document.querySelector('#gcash-tab-reconciliation .gcash-badge').textContent === `Pending (${previous - 1})`, discrepancyBefore);
        assert.ok((await page.locator('#gcash-admin-diff-body').textContent()).includes('Waived'));
        assert.equal(await page.locator('#gcash-search').inputValue(), 'no-such-gcash-reference', 'Waiver refresh preserves local filter');
        await page.getByRole('tab', {name: 'Unresolved exchanges'}).click();
        const verify = page.getByRole('button', {name: 'Verify & Clear', exact: true}).first();
        await verify.click();
        const resolve = page.getByRole('dialog', {name: 'Verify & Clear Exchange'});
        await resolve.waitFor();
        assert.ok((await resolve.textContent()).includes('only the remaining movements'));
        assert.equal(await resolve.getByLabel('Print receipt automatically', {exact: true}).isChecked(), false);
        await resolve.getByLabel('Print receipt automatically', {exact: true}).check();
        await page.evaluate(() => { window.receiptPrints = 0; window.print = () => { window.receiptPrints++; throw new Error('Printer unavailable'); }; });
        await resolve.getByLabel('GCash reference number').fill('9999999999988');
        await resolve.getByLabel('Wallet transfer confirmed in the GCash app').check();
        await resolve.getByLabel('Physical service cash exchange confirmed').check();
        const pendingBefore = Number(await page.locator('#admin-nc-count').textContent());
        await page.route('**/gcash_overview_api.php', route => route.request().method() === 'POST' ? route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({error: 'Resolution unavailable'})}) : route.continue());
        await resolve.getByRole('button', {name: 'Verify & Clear', exact: true}).click();
        await resolve.getByRole('alert').filter({hasText: 'Resolution unavailable'}).waitFor();
        assert.equal(await page.evaluate(() => window.receiptPrints), 0, 'Failed resolution never prints');
        await page.unroute('**/gcash_overview_api.php');
        await resolve.getByRole('button', {name: 'Verify & Clear', exact: true}).click();
        await resolve.waitFor({state: 'hidden'});
        await page.waitForFunction(previous => Number(document.getElementById('admin-nc-count').textContent) < previous, pendingBefore);
        assert.equal(Number(await page.locator('#admin-nc-count').textContent()), pendingBefore - 1);
        await page.locator('#receiptModal .gcash-service-receipt').waitFor();
        await page.waitForFunction(() => window.receiptPrints === 1);
        const resolvedPaper = await page.locator('#receiptModal .gcash-service-receipt').innerText();
        assert.match(resolvedPaper, /9999999999988/);
        const afterResolution = await (await context.request.get(adminApi)).json();
        await page.evaluate(() => { window.print = () => { window.receiptPrints++; }; });
        await page.getByRole('button', {name: 'Print Receipt', exact: true}).click();
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        assert.equal(await page.evaluate(() => window.receiptPrints), 2, 'Later resolution permits manual print retry');
        assert.deepEqual(await (await context.request.get(adminApi)).json(), afterResolution, 'Retrying print never repeats resolution or money posting');
        await page.getByRole('button', {name: 'Close GCash Service Receipt'}).click();
        await page.waitForFunction(() => document.getElementById('gcash-tab-unresolved') === document.activeElement);
        assert.equal(await page.locator('#gcash-search').inputValue(), 'no-such-gcash-reference', 'Verification refresh preserves local filter');
        await page.getByRole('tab', {name: 'Transactions'}).click();
        await page.getByLabel('Reporting period', {exact: true}).selectOption('custom');
        await page.locator('#filter-start-date').fill('2020-01-01');
        await search.fill('9999999999988');
        await page.getByRole('status').filter({hasText: '1 matching transaction'}).waitFor();
        await page.locator('#gcash-admin-history-body').getByRole('button', {name: 'Receipt', exact: true}).click();
        await page.locator('#receiptModal .gcash-service-receipt').waitFor();
        assert.equal(await page.locator('#receiptModal .gcash-service-receipt').innerText(), resolvedPaper, 'Administrator history reopens saved later receipt');
        assert.equal(await page.evaluate(() => window.receiptPrints), 2, 'Administrator later history reopen never automatically prints');
        await page.getByRole('button', {name: 'Close GCash Service Receipt'}).click();
        await search.fill('no-such-gcash-reference');
        await page.getByRole('status').filter({hasText: '0 matching transactions'}).waitFor();
        await page.getByRole('tab', {name: 'Unresolved exchanges'}).click();
        const remainingVerify = page.getByRole('button', {name: 'Verify & Clear', exact: true}).first();
        await remainingVerify.click();
        await resolve.waitFor();
        assert.equal(await resolve.getByLabel('Print receipt automatically', {exact: true}).isChecked(), false, 'Each Administrator review resets printing');
        await page.keyboard.press('Escape');
        await page.route('**/gcash_overview.php?**', route => route.fulfill({status: 503, body: 'Unavailable'}));
        await page.getByRole('tab', {name: 'Transactions'}).click();
        await search.fill('failed-load');
        await page.locator('#gcash-filter-status').filter({hasText: 'Unable to load transactions'}).waitFor();
        assert.equal(await page.locator('#gcash-tab-unresolved .gcash-badge').textContent(), 'Unavailable');
        assert.ok((await page.locator('#gcash-admin-nc-table').textContent()).includes('Review data unavailable'));
        await page.unroute('**/gcash_overview.php?**');
        await search.fill('');
        await page.getByRole('status').filter({hasText: /matching transactions/}).waitFor();
        await page.getByRole('tab', {name: 'Overview'}).click();
        await page.emulateMedia({reducedMotion: 'reduce'});
        const capture = async name => {
            await page.evaluate(async () => {
                document.querySelector('[role="tab"][aria-selected="true"]').scrollIntoView({block: 'nearest', inline: 'center', behavior: 'instant'});
                document.querySelectorAll('.gcash-table-wrap').forEach(element => element.scrollTo({left: 0, top: 0, behavior: 'instant'}));
                window.scrollTo({top: 0, left: 0, behavior: 'instant'});
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            });
            assert.equal(await page.locator('[role="tab"][aria-selected="true"]').evaluate(element => {
                const tab = element.getBoundingClientRect();
                const nav = element.parentElement.getBoundingClientRect();
                return tab.left >= nav.left && tab.right <= nav.right;
            }), true, name + ' captures the complete selected tab');
            // Full-document Chromium captures can shift a horizontally scrolled tab strip; retain viewport evidence too.
            await page.screenshot({path: '.impeccable/review/' + name + '-viewport.png'});
            await page.screenshot({path: '.impeccable/review/' + name + '.png', fullPage: true});
        };
        fs.mkdirSync('.impeccable/review', {recursive: true});
        for (const [width, name] of [[1440, 'desktop'], [390, 'mobile']]) {
            await page.setViewportSize({width, height: 1080});
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, name + ' has no page overflow');
            assert.equal(await page.locator('.gcash-topbar-actions').evaluate(element => getComputedStyle(element).flexDirection), 'row', name + ' header actions remain in a compact row');
            await capture(name);
            for (const [tabName, suffix] of [['Transactions', 'transactions'], ['Closing checks', 'reconciliation'], ['Unresolved exchanges', 'unresolved']]) {
                await page.getByRole('tab', {name: tabName}).click();
                const panel = page.locator('#tab-' + suffix);
                assert.equal(await panel.locator('small, .pos-panel-title p').count(), 0, name + ' ' + suffix + ' has no secondary row captions');
                if (suffix !== 'transactions') {
                    assert.equal(await panel.getByText('All dates · All Cashiers', {exact: true}).isVisible(), true, name + ' review scope remains visible');
                    const details = panel.locator('.gcash-row-details').first();
                    assert.equal(await details.getAttribute('open'), null, 'Secondary record details start collapsed');
                    await details.locator('summary').click();
                    assert.equal(await details.evaluate(element => element.open), true, 'Record details remain accessible');
                    assert.ok((await details.innerText()).trim().length > (await details.locator('summary').innerText()).trim().length, 'Expanded details retain record content');
                    await details.locator('summary').click();
                    await panel.locator('.gcash-table-wrap').evaluate(element => element.scrollLeft = 0);
                }
                assert.equal(await page.getByRole('tab', {name: tabName}).evaluate(element => {
                    const tab = element.getBoundingClientRect();
                    const nav = element.parentElement.getBoundingClientRect();
                    return tab.left >= nav.left && tab.right <= nav.right;
                }), true, name + ' selected task tab stays visible');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, name + ' ' + suffix + ' has no page overflow');
                await capture(name + '-' + suffix);
            }
            await page.getByRole('tab', {name: 'Overview'}).click();
        }
        assert.deepEqual(errors, []);
        const privilegedContext = await browser.newContext();
        await privilegedContext.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const privilegedPage = await privilegedContext.newPage();
        await login(privilegedPage, 'super_admin');
        assert.equal((await exportRows({search: '8888888880000'}, privilegedContext)).length, 1, 'Active Super Administrator can export');
        await privilegedPage.goto(origin + '/components/auth/workspace.php');
        await Promise.all([privilegedPage.waitForURL('**/cashier/pos.php'), privilegedPage.locator('form', {has: privilegedPage.locator('input[value="cashier"]')}).getByRole('button', {name: 'Open', exact: true}).click()]);
        const wrongWorkspace = await privilegedContext.request.get(origin + '/components/administrator/gcash_overview.php?export=csv', {maxRedirects: 0});
        assert.equal(wrongWorkspace.status(), 403, 'Assigned Administrator privileges cannot bypass the active Cashier Workspace');
        assert.equal(wrongWorkspace.headers()['content-disposition'], undefined);
        await privilegedContext.close();
        const cashierContext = await browser.newContext();
        await cashierContext.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        const cashierPage = await cashierContext.newPage();
        await login(cashierPage, 'cashier');
        const unauthorizedExport = await cashierContext.request.get(origin + '/components/administrator/gcash_overview.php?export=csv', {maxRedirects: 0});
        assert.equal(unauthorizedExport.status(), 403, 'Cashier cannot export Administrator history');
        assert.equal(unauthorizedExport.headers()['content-disposition'], undefined);
        await cashierContext.close();
        console.log('GCash overview browser: passed');
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
