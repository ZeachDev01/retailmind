const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') { console.log('GCash reports: skipped (RUN_DB_TESTS=1 required)'); process.exit(0); }
const php = process.env.PHP_BINARY || 'php';
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-gcash-report-'));
const output = path.resolve('output/playwright/gcash-reports');
const env = {...process.env, DB_NAME: database, BACKUP_STORAGE_PATH: path.join(temporary, 'recovery')};
const run = (args, input) => {
    const result = spawnSync(php, args, {input, encoding: 'utf8', env});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
const fixture = action => run(['src/backend/tests/support/theme_fixture.php', action, database]);
const reportPath = '/components/report/report_generation.php';
const parseCsv = bytes => {
    assert.deepEqual([...bytes.subarray(0, 3)], [239, 187, 191]);
    return JSON.parse(run(['-r', '$rows=[]; while (($row=fgetcsv(STDIN,null,",", "\\\"", ""))!==false) $rows[]=$row; echo json_encode($rows);'], bytes.subarray(3)));
};

(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        fixture('open_fiscal_period');
        run([], String.raw`<?php
            require 'src/backend/bootstrap/app.php';
            require 'src/backend/includes/functions.php';
            $pdo = App\Core\Database::connection();
            $pdo->exec("INSERT INTO user_roles (user_id,role_id) VALUES (1,4),(3,1)");
            $pdo->exec("UPDATE users SET display_name='=GCASH_CSV_TEST' WHERE user_id=4");
            $service = new App\Services\GCashTransactionService($pdo, require 'src/backend/config/gcash.php');
            $service->openSession(4,10000,10000);
            $service->recordCashIn(4,['principal'=>1000,'reference'=>'0000000000101','confirmed_cash'=>true,'confirmed_wallet'=>true]);
            $service->recordCashOut(4,['principal'=>500,'reference'=>'0000000000102','fee_method'=>'Fee via GCash','confirmed_cash'=>true,'confirmed_wallet'=>true]);
            $insert = $pdo->prepare("INSERT INTO gcash_transactions (cashier_id,reference_number,transaction_type,amount,verification_status,created_at) VALUES (4,?,'Payment',1,'Pending',NOW())");
            for ($i=0;$i<105;$i++) $insert->execute(['888888888'.str_pad((string)$i,4,'0',STR_PAD_LEFT)]);
            $pdo->exec("INSERT INTO gcash_transactions (cashier_id,reference_number,transaction_type,amount,verification_status,created_at) VALUES (3,'OLD-REFERENCE','Cash-Out',500,'Verified','2020-01-02 12:00:00')");
        `);
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        // Inject storage/retrieval failures only in the disposable HTTP test process.
        const router = path.join(temporary, 'router.php');
        const frontend = path.resolve('src/frontend').replaceAll('\\', '/');
        fs.writeFileSync(router, `<?php
            if (!isset($_GET['failure'])) return false;
            require '${path.resolve('src/backend/includes/auth.php').replaceAll('\\', '/')}';
            if ($_GET['failure'] === 'retrieval') {
                class InterruptedHistory extends PDOStatement {
                    private static bool $retrieved = false;
                    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array {
                        $rows=parent::fetchAll($mode,...$args);
                        if (isset($rows[0]['reference_number'])) {
                            if (self::$retrieved) throw new RuntimeException('History interrupted');
                            self::$retrieved=true;
                        }
                        return $rows;
                    }
                }
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[InterruptedHistory::class]);
            } else {
                class FailedStage {
                    public $context;
                    private int $writes=0;
                    public function stream_open($path,$mode,$options,&$openedPath) { return $path==='php://temp'; }
                    public function stream_write($data) { return ++$this->writes>3 ? false : strlen($data); }
                }
                stream_wrapper_unregister('php');
                stream_wrapper_register('php',FailedStage::class);
            }
            require '${frontend}' . '${reportPath}';
        `);
        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', router], {env, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch (_) {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch({headless: true});
        const context = await browser.newContext({viewport: {width: 1440, height: 960}});
        await context.route('https://**/*', route => route.fulfill({body: ''}));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const login = async (page, role) => {
            await page.goto(origin + '/?login=1');
            await page.locator('#landing-login-username').fill('theme_' + role);
            await page.locator('#landing-login-password').fill(data.password);
            await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        };
        await login(page, 'admin');
        const skip = page.getByRole('button', {name: 'Skip guidance', exact: true});
        if (await skip.isVisible()) {
            await skip.click();
            await skip.waitFor({state: 'hidden'});
        }
        await page.goto(origin + '/components/administrator/gcash_overview.php');
        assert.equal(await page.locator('#gcash-record, #gcash-record-dialog, #gcash-export').count(), 0);
        assert.equal(await page.getByRole('heading', {name: 'Activity report', exact: true}).isVisible(), true);
        assert.equal(await page.getByRole('tab').count(), 5);
        fs.mkdirSync(output, {recursive: true});
        await page.screenshot({path: path.join(output, 'overview-1440.png')});
        await page.goto(origin + reportPath);
        await page.getByLabel('Report', {exact: true}).selectOption('gcash_transactions');
        await page.waitForURL(url => url.searchParams.get('report') === 'gcash_transactions');
        const today = new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
        await page.getByLabel('Reporting period', {exact: true}).selectOption('today');
        await Promise.all([page.waitForURL(url => url.searchParams.get('period') === 'today'), page.getByRole('button', {name: 'Generate report', exact: true}).click()]);
        assert.equal(await page.locator('#from').inputValue(), today);
        const csrf = await page.locator('.report-filter-actions input[name="csrf_token"]').inputValue();
        const exportCsv = (selection = {}, headers = {}) => context.request.post(origin + reportPath + (selection.failure ? '?failure=' + selection.failure : ''), {
            headers, form: {report: 'gcash_transactions', action: 'export_csv', period: 'today', csrf_token: csrf, ...selection},
        });
        const full = await exportCsv({search: '888888888', page: 99});
        assert.equal(full.status(), 200);
        assert.equal(full.headers()['cache-control'], 'no-store');
        const [head, ...rows] = parseCsv(await full.body());
        assert.equal(head.length, 13);
        assert.equal(rows.length, 105, 'Exports all matching pages');
        assert.deepEqual(rows.map(row => row[1]), Array.from({length: 105}, (_, i) => '888888888' + String(104 - i).padStart(4, '0')));
        assert.equal(rows[0][3], 'Not recorded');
        assert.equal(rows[0][7], "'=GCASH_CSV_TEST");
        assert.equal(rows[0][11], 'Legacy reference only');
        for (const [selection, expected] of [[{type: 'Cash-In'}, 1], [{type: 'Cash-Out'}, 1], [{cashier_id: 3}, 0], [{search: 'absent'}, 0], [{period: 'month'}, 107], [{type: 'Cash-In', cashier_id: 4, search: '0000000000101'}, 1], [{period: 'custom', from: '2020-01-02', to: '2020-01-02', cashier_id: 3}, 1]]) {
            const response = await exportCsv(selection);
            assert.equal(response.status(), 200);
            assert.equal(parseCsv(await response.body()).length - 1, expected, JSON.stringify(selection));
        }
        for (const headers of [{'X-Forwarded-Host': 'store.trycloudflare.com'}, {'X-Forwarded-Host': 'store.trycloudflare.com', 'X-Forwarded-For': '203.0.113.8'}]) {
            const response = await exportCsv({search: '888888888'}, headers);
            assert.equal(response.status(), 200, 'Approved tunnel preserves Administrator export');
            assert.deepEqual(await response.body(), await full.body());
        }
        for (const selection of [{cashier_id: 'invalid'}, {type: 'invalid'}, {period: 'custom', from: '2026-02-30', to: today}, {csrf_token: ''}]) {
            const response = await exportCsv(selection);
            assert.equal(response.status(), selection.csrf_token === '' ? 403 : 400);
            assert.equal(response.headers()['content-disposition'], undefined);
        }
        for (const failure of ['retrieval', 'staging']) {
            const response = await exportCsv({failure, search: '888888888'});
            assert.equal(response.status(), 503);
            assert.equal(response.headers()['content-disposition'], undefined);
            assert.equal(await response.text(), 'Unable to export the complete report. Try again.');
        }
        await page.getByLabel('Search transactions', {exact: true}).fill('888888888');
        await Promise.all([page.waitForURL(url => url.searchParams.get('search') === '888888888'), page.getByRole('button', {name: 'Generate report', exact: true}).click()]);
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('button', {name: 'Export CSV', exact: true}).click();
        const download = await downloadPromise;
        assert.deepEqual(fs.readFileSync(await download.path()), await full.body(), 'UI download preserves displayed selection');
        fs.mkdirSync(output, {recursive: true});
        for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
            for (const width of [1440, 768, 390]) {
                await page.setViewportSize({width, height: 960});
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${theme}/${width}: page fits`);
                assert.equal(await page.getByLabel('Product', {exact: true}).count(), 0);
                await page.screenshot({path: path.join(output, `${theme}-${width}.png`)});
            }
        }
        assert.deepEqual(errors, []);
        for (const role of ['inventory_manager', 'cashier', 'super_admin']) {
            const restricted = await browser.newContext();
            await restricted.route('https://**/*', route => route.fulfill({body: ''}));
            const userPage = await restricted.newPage();
            await login(userPage, role);
            const denied = await restricted.request.get(origin + reportPath + '?report=gcash_transactions');
            assert.equal(denied.status(), 403, role + ' cannot read GCash financial report');
            const forged = await restricted.request.post(origin + reportPath, {form: {report: 'gcash_transactions', action: 'export_csv'}});
            assert.equal(forged.status(), 403);
            assert.equal(forged.headers()['content-disposition'], undefined);
            await restricted.close();
        }
        console.log('GCash reports: complete CSV, filters, UI download, tunnel, authorization, CSRF and failure staging passed.');
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) { const exited = new Promise(resolve => server.once('exit', resolve)); server.kill(); await exited; }
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
