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
        const remoteContext = await browser.newContext();
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

        // 1b. Approved route Store overview loads successfully for owner
        const overviewResponse = await remotePage.goto(origin + '/components/administrator/dashboard.php');
        assert.equal(overviewResponse.status(), 200, 'Owner Store overview must return 200');
        const overviewText = await remotePage.textContent('body');
        assert(overviewText.includes('Store status') || overviewText.includes('Gross Sales'), 'Store overview content renders');

        // 1c. Remote refresh button or period selector works
        const refreshResponse = await remotePage.goto(origin + '/components/administrator/dashboard.php?period=week&range=30');
        assert.equal(refreshResponse.status(), 200, 'Store overview refresh returns 200');

        // 1d. Remote sign-out works and redirects
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
