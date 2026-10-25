const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '../../..');
const php = process.env.PHP_BINARY || 'php';
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-remote-browser-'));
const routerPath = path.join(output, 'router.php');
const logPath = path.join(output, 'server.log');

const bootstrapPath = path.join(root, 'src/backend/bootstrap/app.php').replace(/\\/g, '/');
const frontendDir = path.join(root, 'src/frontend').replace(/\\/g, '/');
const backupDir = path.join(output, 'backup').replace(/\\/g, '/');
if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, {recursive: true});

fs.writeFileSync(routerPath, `<?php
$_SERVER["RETAILMIND_TEST_ENFORCE_REMOTE"] = "1";
$_ENV["DATABASE_ENV"] = "local";
$_ENV["BACKUP_STORAGE_PATH"] = '${backupDir}';
$_ENV["RETAILMIND_REMOTE_HOST"] = "monitor.retailmind.store";
$_ENV["RETAILMIND_TUNNEL_SECRET"] = "expected-tunnel-token-999";
$_ENV["RETAILMIND_REMOTE_OWNER_EMAIL"] = "owner@retailmind.store";

require '${bootstrapPath}';

App\\Core\\Session::start();

$pdo = App\\Core\\Database::connection();
$adminUser = $pdo->query("SELECT u.user_id, u.full_name, u.session_version, r.role_name FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.role_name='admin' AND u.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$fixtureUser = $_SERVER["HTTP_X_FIXTURE_USER"] ?? null;
if ($fixtureUser === "owner" && $adminUser) {
    $_SESSION["user_id"] = (int)$adminUser["user_id"];
    $_SESSION["full_name"] = (string)$adminUser["full_name"];
    $_SESSION["role"] = "admin";
    $_SESSION["roles"] = ["admin"];
    $_SESSION["session_version"] = (int)$adminUser["session_version"];
    $_SESSION["csrf_token"] = "fixture-csrf";
    $_SESSION["_authenticated_at"] = time();
    $_SESSION["_last_activity"] = time();
    $_SESSION["_restore_epoch"] = App\\Backup\\RecoveryStore::epoch();
}

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$file = realpath('${frontendDir}' . ($path === "/" ? "/index.php" : $path));
$root = realpath('${frontendDir}');

if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo "Not Found";
    exit;
}

if (!str_ends_with($file, ".php")) {
    return false;
}

require $file;
`);

(async () => {
    let server, browser;
    try {
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;

        server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', frontendDir, routerPath], {
            cwd: root,
            stdio: ['ignore', 'ignore', 'pipe']
        });
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });

        for (let attempt = 0; attempt < 100; attempt++) {
            try {
                const res = await fetch(origin, {redirect: 'manual'});
                if (res.status > 0) break;
            } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error('Server start failed: ' + logs);
        }

        browser = await chromium.launch({headless: true});

        // 1. Remote context: has remote tunnel headers
        const remoteContext = await browser.newContext({
            viewport: {width: 1280, height: 800}
        });
        const remotePage = await remoteContext.newPage();
        await remotePage.setExtraHTTPHeaders({
            'X-RetailMind-Tunnel-Secret': 'expected-tunnel-token-999',
            'Cf-Access-Authenticated-User-Email': 'owner@retailmind.store',
            'X-Fixture-User': 'owner',
        });

        // 1a. Unapproved route denied at server boundary with 403
        const posResponse = await remotePage.goto(origin + '/components/cashier/pos.php');
        assert.equal(posResponse.status(), 403, 'Unapproved remote route must return 403');
        const posBody = await remotePage.textContent('body');
        assert(posBody.includes('unapproved route on remote ingress'), '403 body explains unapproved route');

        // Direct request to denied shift mutation endpoint
        const deniedShiftResponse = await remotePage.goto(origin + '/export_shifts.php');
        assert.equal(deniedShiftResponse.status(), 403, 'Direct export_shifts.php request returns 403');

        // 1b. Approved route Store overview loads successfully for owner
        const overviewResponse = await remotePage.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(overviewResponse.status(), 200, 'Owner Store overview must return 200');
        const overviewText = await remotePage.textContent('body');
        assert(overviewText.includes('Store status') || overviewText.includes('Gross Sales'), 'Store overview content renders');

        // 1c. Remote Cashier Shift report (components/administrator/shift_report.php)
        const shiftReportResponse = await remotePage.goto(origin + '/components/administrator/shift_report.php');
        assert.equal(shiftReportResponse.status(), 200, 'Remote shift report returns 200');
        const shiftReportText = await remotePage.textContent('body');
        assert(shiftReportText.includes('Cashier Shift Report'), 'Cashier Shift Report title is rendered');
        assert(shiftReportText.includes('Run Report'), 'Filter submit button is present');
        assert(!shiftReportText.includes('Export report CSV'), 'CSV export button must not be available remotely');

        // Filter form submission remains on remote origin
        await remotePage.fill('#from', '2026-10-01');
        await remotePage.fill('#to', '2026-10-25');
        await remotePage.locator('.report-filter-grid button[type="submit"]').click();
        await remotePage.waitForURL(url => url.searchParams.get('from') === '2026-10-01');
        assert(remotePage.url().includes('from=2026-10-01'), 'Filtered URL loaded on remote origin');
        assert.equal(new URL(remotePage.url()).origin, origin, 'Remains on remote origin after filtering');

        // 1d. Remote Cashier Shifts screen (components/cashier/shifts.php)
        const shiftsResponse = await remotePage.goto(origin + '/components/cashier/shifts.php');
        assert.equal(shiftsResponse.status(), 200, 'Remote cashier shifts screen returns 200');
        const shiftsText = await remotePage.textContent('body');
        assert(shiftsText.includes('Cashier Shifts'), 'Cashier shifts heading rendered');
        assert(shiftsText.includes('Recent shifts'), 'Recent shifts section rendered');
        assert(!shiftsText.includes('Return to POS'), 'Return to POS link must not be rendered remotely');
        assert(!shiftsText.includes('Record movement'), 'Record drawer movement must not be rendered remotely');

        // 1e. Remote Staff activity / Operational Audit (components/system_administrator/audit_logs.php)
        const auditResponse = await remotePage.goto(origin + '/components/system_administrator/audit_logs.php');
        assert.equal(auditResponse.status(), 200, 'Remote audit logs returns 200');
        const auditText = await remotePage.textContent('body');
        assert(auditText.includes('Operational Audit') || auditText.includes('Protected Audit Records'), 'Audit title rendered');
        assert.equal(await remotePage.locator('#auditLogExportForm').count(), 0, 'Audit Export CSV form must not be rendered remotely');
        assert.equal(await remotePage.locator('button:has-text("Export CSV")').count(), 0, 'Audit Export CSV button must not be rendered remotely');

        // Details toggle in audit log table
        const detailsCount = await remotePage.locator('details.audit-details').count();
        if (detailsCount > 0) {
            await remotePage.locator('details.audit-details').first().click();
        }

        // 1f. Responsive verification at phone viewport (375x667)
        await remotePage.setViewportSize({width: 375, height: 667});
        const mobileReportResponse = await remotePage.goto(origin + '/components/administrator/shift_report.php');
        assert.equal(mobileReportResponse.status(), 200, 'Mobile viewport shift report returns 200');
        const mobileReportText = await remotePage.textContent('body');
        assert(mobileReportText.includes('Cashier Shift Report'), 'Mobile shift report renders title');
        assert(!mobileReportText.includes('Export report CSV'), 'Mobile shift report omits CSV export');

        const mobileShiftsResponse = await remotePage.goto(origin + '/components/cashier/shifts.php');
        assert.equal(mobileShiftsResponse.status(), 200, 'Mobile viewport cashier shifts returns 200');
        const mobileShiftsText = await remotePage.textContent('body');
        assert(mobileShiftsText.includes('Cashier Shifts'), 'Mobile cashier shifts renders title');
        assert(!mobileShiftsText.includes('Return to POS'), 'Mobile cashier shifts omits Return to POS');

        // 1g. Remote sign-out works and redirects
        await remotePage.setExtraHTTPHeaders({
            'X-RetailMind-Tunnel-Secret': 'expected-tunnel-token-999',
            'Cf-Access-Authenticated-User-Email': 'owner@retailmind.store',
        });
        const logoutResponse = await remotePage.goto(origin + '/components/auth/logout.php');
        assert(logoutResponse.url().includes('login=1'), 'Logout redirects to login page');

        await remoteContext.close();

        // 2. Local context: direct local origin without remote headers
        const localContext = await browser.newContext();
        const localPage = await localContext.newPage();

        // Authorized local operation is not blocked by remote boundary
        const localResponse = await localPage.goto(origin + '/components/cashier/pos.php');
        const localBody = await localPage.textContent('body');
        assert(!localBody.includes('unapproved route on remote ingress'), 'Local request not blocked by remote boundary');

        await localContext.close();

        console.log('Owner remote monitoring browser tests: passed');
    } finally {
        if (browser) await browser.close();
        if (server) {
            server.kill();
        }
        try {
            fs.rmSync(output, {recursive: true, force: true});
        } catch {}
    }
})();
