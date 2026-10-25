<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . "/../bootstrap/app.php";

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
};

$router = tempnam(sys_get_temp_dir(), 'rm-remote-router-');
$log = tempnam(sys_get_temp_dir(), 'rm-remote-log-');
$backupDir = sys_get_temp_dir() . '/rm-backup-' . bin2hex(random_bytes(6));
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}
$httpServer = null;

try {
    $bootstrapPath = var_export(realpath(__DIR__ . '/../bootstrap/app.php'), true);
    $frontendDir = var_export(realpath(__DIR__ . '/../../frontend'), true);
    $backupDirExport = var_export($backupDir, true);

    file_put_contents($router, '<?php
$_SERVER["RETAILMIND_TEST_ENFORCE_REMOTE"] = "1";
$_ENV["DATABASE_ENV"] = "local";
$_ENV["BACKUP_STORAGE_PATH"] = ' . $backupDirExport . ';
$_ENV["RETAILMIND_REMOTE_HOST"] = "monitor.retailmind.store";
$_ENV["RETAILMIND_TUNNEL_SECRET"] = "expected-tunnel-token-999";
$_ENV["RETAILMIND_REMOTE_OWNER_EMAIL"] = "owner@retailmind.store";
if (!empty($_SERVER["HTTP_X_REQUIRE_ACCESS_TOKEN"])) {
    $_ENV["RETAILMIND_REQUIRE_ACCESS_TOKEN"] = "1";
}
if (!empty($_SERVER["HTTP_X_CF_ACCESS_AUD"])) {
    $_ENV["RETAILMIND_CF_ACCESS_AUD"] = (string)$_SERVER["HTTP_X_CF_ACCESS_AUD"];
}
if (!empty($_SERVER["HTTP_X_CLEAR_OWNER_EMAIL"])) { unset($_ENV["RETAILMIND_REMOTE_OWNER_EMAIL"]); }

require ' . $bootstrapPath . ';

App\Core\Session::start();

// Fixture user setup
$pdo = App\Core\Database::connection();
$adminUser = $pdo->query("SELECT u.user_id, u.full_name, u.session_version, r.role_name FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.role_name=\'admin\' AND u.status=\'active\' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashierUser = $pdo->query("SELECT u.user_id, u.full_name, u.session_version, r.role_name FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.role_name=\'cashier\' AND u.status=\'active\' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cashierUser) {
    $pdo->exec("UPDATE users SET status=\'active\', must_change_password=0 WHERE user_id=9");
    $cashierUser = $pdo->query("SELECT u.user_id, u.full_name, u.session_version, r.role_name FROM users u JOIN roles r ON u.role_id=r.role_id WHERE r.role_name=\'cashier\' AND u.status=\'active\' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} else {
    $pdo->exec("UPDATE users SET must_change_password=0 WHERE user_id=" . (int)$cashierUser["user_id"]);
}

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
    $_SESSION["_restore_epoch"] = App\Backup\RecoveryStore::epoch();
} elseif ($fixtureUser === "multi" && $adminUser) {
    $_SESSION["user_id"] = (int)$adminUser["user_id"];
    $_SESSION["full_name"] = (string)$adminUser["full_name"];
    $_SESSION["role"] = "admin";
    $_SESSION["roles"] = ["admin", "cashier", "super_admin"];
    $_SESSION["session_version"] = (int)$adminUser["session_version"];
    $_SESSION["csrf_token"] = "fixture-csrf";
    $_SESSION["_authenticated_at"] = time();
    $_SESSION["_last_activity"] = time();
    $_SESSION["_restore_epoch"] = App\Backup\RecoveryStore::epoch();
} elseif ($fixtureUser === "cashier" && $cashierUser) {
    $_SESSION["user_id"] = (int)$cashierUser["user_id"];
    $_SESSION["full_name"] = (string)$cashierUser["full_name"];
    $_SESSION["role"] = "cashier";
    $_SESSION["roles"] = ["cashier"];
    $_SESSION["session_version"] = (int)$cashierUser["session_version"];
    $_SESSION["csrf_token"] = "fixture-csrf";
    $_SESSION["_authenticated_at"] = time();
    $_SESSION["_last_activity"] = time();
    $_SESSION["_restore_epoch"] = App\Backup\RecoveryStore::epoch();
}

if (!empty($_SERVER["HTTP_X_FIXTURE_SESSION_EXPIRED"])) { $_SESSION["_last_activity"] = time() - 7200; $_SESSION["_authenticated_at"] = time() - 7200; }
$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$file = realpath(' . $frontendDir . ' . ($path === "/" ? "/index.php" : $path));
$root = realpath(' . $frontendDir . ');

if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    echo "Not Found";
    exit;
}

if (!str_ends_with($file, ".php")) {
    return false;
}

require $file;
');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $assert(is_resource($socket), 'Reserve HTTP fixture port: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $httpServer = proc_open(
        [PHP_BINARY, '-S', $address, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes
    );
    $assert(is_resource($httpServer), 'Start HTTP server process');
    fclose($pipes[0]);

    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (!$ready && file_exists($log)) {
        fwrite(STDERR, "Server log:\n" . file_get_contents($log) . "\n");
    }
    $assert($ready, 'HTTP fixture ready');

    $request = static function (string $uri, array $headers = [], string $method = 'GET', ?string $body = null): array {
        global $address;
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        if ($body !== null && !isset($headers['Content-Type'])) {
            $headerLines[] = "Content-Type: application/x-www-form-urlencoded";
            $headerLines[] = "Content-Length: " . strlen($body);
        }
        $headerStr = implode("\r\n", $headerLines) . "\r\n";

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerStr,
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 5,
            ],
        ]);

        $resBody = @file_get_contents('http://' . $address . $uri, false, $ctx);
        $headersList = $http_response_header ?? [];
        $statusLine = $headersList[0] ?? '';
        preg_match('#HTTP/\S+\s+(\d+)#', $statusLine, $m);
        $code = isset($m[1]) ? (int)$m[1] : 0;

        return [$code, (string)$resBody, $headersList];
    };

    $remoteHeaders = [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ];

    // 1. Direct attempts against unapproved routes return 403
    [$code, $body] = $request('/components/cashier/pos.php', $remoteHeaders);
    $assert($code === 403, 'Remote ingress to cashier pos.php must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'unapproved route on remote ingress'), 'Denial message explains unapproved route');

    [$code, $body] = $request('/components/system_administrator/backup_restore.php', $remoteHeaders);
    $assert($code === 403, 'Remote ingress to backup_restore.php must be denied with 403, got ' . $code);

    foreach (['/export_shifts.php', '/record_shift.php', '/cash_in.php', '/export_reports.php', '/sales_export.php', '/components/invoice/sales.php', '/manifest.webmanifest', '/sw.js'] as $deniedEndpoint) {
        [$code, $body] = $request($deniedEndpoint, $remoteHeaders);
        $assert($code === 403, "Direct request to {$deniedEndpoint} must be denied with 403, got {$code}");
    }

    // 2. Owner Access email and JWT assertion checks
    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'intruder@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Mismatched Access email must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'unapproved owner identity'), 'Denial message explains unapproved email');

    // Reject missing token when token is required
    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
        'X-Require-Access-Token' => '1',
    ]);
    $assert($code === 403, 'Missing Access token when required must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'missing or invalid Cloudflare Access token'), 'Denial message explains missing token');
    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'X-Fixture-User' => 'owner',
        'X-Require-Access-Token' => '1',
        'X-Clear-Owner-Email' => '1',
    ]);
    $assert($code === 403, 'Missing owner email when token required must fail closed with 403, got ' . $code);

    // Session idle timeout / expiry on remote origin
    [$code, $body, $headersList] = $request('/components/administrator/dashboard.php', array_merge($remoteHeaders, [
        'X-Fixture-Session-Expired' => '1',
    ]));
    $assert($code === 302, 'Expired session on remote monitoring must redirect (302) to login, got ' . $code);
    $location = '';
    foreach ($headersList as $h) {
        if (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); break; }
    }
    $assert(str_contains($location, 'login=1') || str_contains($location, '?login=1'), 'Expired remote session redirects to login');

    // Reject malformed or expired JWT assertion
    $b64u = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $expiredJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $b64u(json_encode(['email' => 'owner@retailmind.store', 'aud' => ['aud-test-123'], 'exp' => time() - 3600]))
        . '.sig';

    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'Cf-Access-Jwt-Assertion' => $expiredJwt,
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Expired JWT assertion must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'missing or invalid Cloudflare Access token'), 'Denial message explains expired token');

    // Reject mismatched token email
    $mismatchedJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $b64u(json_encode(['email' => 'intruder@retailmind.store', 'aud' => ['aud-test-123'], 'exp' => time() + 3600]))
        . '.sig';

    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'Cf-Access-Jwt-Assertion' => $mismatchedJwt,
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Mismatched token email must be denied with 403, got ' . $code);

    // Accept valid JWT assertion
    $validJwt = $b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . $b64u(json_encode(['email' => 'owner@retailmind.store', 'aud' => ['aud-test-123'], 'exp' => time() + 3600]))
        . '.sig';

    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'Cf-Access-Jwt-Assertion' => $validJwt,
        'X-Fixture-User' => 'owner',
        'X-Require-Access-Token' => '1',
        'X-Cf-Access-Aud' => 'aud-test-123',
    ]);
    $assert($code === 200, 'Valid Access token and matching owner email pass boundary with 200, got ' . $code);

    // 3. Approved monitoring screens load for authenticated owner
    [$code, $body, $remoteHeadersList] = $request('/components/administrator/dashboard.php', $remoteHeaders);
    $assert($code === 200, 'Authenticated owner can load Store overview remotely, got ' . $code);
    $assert(str_contains($body, 'Store status') || str_contains($body, 'Gross Sales'), 'Store overview content is present');

    [$code, $body] = $request('/components/administrator/shift_report.php', $remoteHeaders);
    $assert($code === 200, 'Authenticated owner can load shift report remotely, got ' . $code);
    $assert(str_contains($body, 'Cashier Shift Report'), 'Shift report title present');
    $assert(!str_contains($body, '<button class="btn" type="submit" >Export report CSV</button>'), 'Shift report CSV export submit must not be enabled remotely');

    // Shift report filtering via GET
    [$code, $body] = $request('/components/administrator/shift_report.php?from=2026-10-01&to=2026-10-25', $remoteHeaders);
    $assert($code === 200, 'Remote shift report filter GET request returns 200');

    // Shift report POST (e.g. export_csv) is denied with 403
    [$code, $body] = $request('/components/administrator/shift_report.php', $remoteHeaders, 'POST', 'action=export_csv&csrf_token=fixture-csrf');
    $assert($code === 403, 'Remote shift report POST must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'disabled on remote ingress') || str_contains($body, 'unavailable on remote ingress'), 'Denial message explains write/export disabled');

    // Cashier shifts screen
    [$code, $body] = $request('/components/cashier/shifts.php', $remoteHeaders);
    $assert($code === 200, 'Authenticated owner can load cashier shifts screen remotely, got ' . $code);
    $assert(!str_contains($body, 'Return to POS'), 'Return to POS link must not be present remotely');
    $assert(!str_contains($body, 'Record movement'), 'Drawer movement action must not be present remotely');

    [$code, $body] = $request('/components/cashier/shifts.php', $remoteHeaders, 'POST', 'action=open&register_id=1&opening_float=100&csrf_token=fixture-csrf');
    $assert($code === 403, 'Remote cashier shift POST must be denied with 403, got ' . $code);

    // Operational audit screen and DataTables AJAX GET
    [$code, $body] = $request('/components/system_administrator/audit_logs.php', $remoteHeaders);
    $assert($code === 200, 'Authenticated owner can load operational audit remotely, got ' . $code);
    $assert(!str_contains($body, 'Export CSV</button>'), 'Audit CSV export button must not be present remotely');

    [$code, $body] = $request('/components/system_administrator/audit_logs.php?table=1&draw=1&start=0&length=25', $remoteHeaders);
    $assert($code === 200, 'Remote audit logs DataTables AJAX request returns 200, got ' . $code);
    $json = json_decode($body, true);
    $assert(is_array($json) && isset($json['recordsTotal']), 'Audit logs DataTables returns valid JSON');

    [$code, $body] = $request('/components/system_administrator/audit_logs.php', $remoteHeaders, 'POST', 'action=export_csv&csrf_token=fixture-csrf');
    $assert($code === 403, 'Remote audit POST must be denied with 403, got ' . $code);

    // Report generation screen
    [$code, $body] = $request('/components/report/report_generation.php', $remoteHeaders);
    $assert($code === 200, 'Owner can open Report Generation remotely, got ' . $code);
    $assert(str_contains($body, 'Report Generation'), 'Report Generation header present');

    [$code, $body] = $request('/components/report/report_generation.php', $remoteHeaders, 'POST', 'action=export_csv&csrf_token=fixture-csrf&report=sales_period');
    $assert($code === 403, 'Remote POST action=export_csv to report_generation.php must return 403, got ' . $code);

    // 4. Persisted state baseline snapshot before mutation attempts
    $pdo = App\Core\Database::connection();
    $snapUsers = $pdo->query('SELECT user_id, username, password_hash, status FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    $snapSalesCount = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $snapRefundsCount = (int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn();
    $snapStockCount = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();

    // 5. Representative Store/account mutation endpoints are denied
    $deniedMutations = [
        ['/components/cashier/pos.php', 'POST', 'action=checkout&amount=100&csrf_token=fixture-csrf'],
        ['/components/cashier/refunds.php', 'POST', 'action=refund&sale_id=1&amount=50&csrf_token=fixture-csrf'],
        ['/components/cashier/refunds.php', 'GET', null],
        ['/components/user_manager/user_manager.php', 'POST', 'action=create&username=hacker&role=admin&csrf_token=fixture-csrf'],
        ['/components/user_manager/user_manager.php', 'GET', null],
        ['/components/auth/change_password.php', 'POST', 'current_password=old&new_password=new&csrf_token=fixture-csrf'],
        ['/components/auth/change_password.php', 'GET', null],
        ['/components/auth/theme.php', 'POST', 'mode=dark&csrf_token=fixture-csrf'],
        ['/components/system_administrator/recovery_account.php', 'POST', 'action=rotate&csrf_token=fixture-csrf'],
        ['/components/system_administrator/system_settings.php', 'POST', 'setting=val&csrf_token=fixture-csrf'],
        ['/components/administrator/registers.php', 'POST', 'name=Register2&csrf_token=fixture-csrf'],
        ['/components/administrator/reset_data.php', 'POST', 'confirm=yes&csrf_token=fixture-csrf'],
        ['/components/administrator/database_backup.php', 'POST', 'action=backup&csrf_token=fixture-csrf'],
        ['/components/administrator/database_backup.php', 'GET', null],
        ['/components/backup/backup_download.php', 'GET', null],
        ['/components/backup/recovery.php', 'GET', null],
        ['/components/system_administrator/database_updates.php', 'POST', 'action=migrate&csrf_token=fixture-csrf'],
        ['/components/inventory_management/stock_issues.php', 'POST', 'action=issue&csrf_token=fixture-csrf'],
        ['/components/inventory_management/stock_receiving.php', 'POST', 'action=receive&csrf_token=fixture-csrf'],
        ['/components/inventory_management/add_product.php', 'POST', 'product_name=Test&price=10&csrf_token=fixture-csrf'],
        ['/backend/scripts/restore_database.php', 'GET', null],
    ];

    foreach ($deniedMutations as [$path, $m, $b]) {
        [$code, $body] = $request($path, $remoteHeaders, $m, $b);
        $assert($code === 403, "Direct mutation attempt to {$m} {$path} must return 403, got {$code}");
    }

    // 6. Representative side-effecting GET actions and query parameters are denied
    $deniedGetActions = [
        '/components/administrator/dashboard.php?action=delete',
        '/components/administrator/dashboard.php?action=reset',
        '/components/administrator/shift_report.php?action=delete',
        '/components/administrator/shift_report.php?action=export_csv',
        '/components/invoice/sales.php?action=delete',
        '/components/invoice/sales.php?action=refund',
        '/components/report/report_generation.php?action=backup',
        '/components/report/report_generation.php?export=csv',
        '/components/report/report_generation.php?format=csv',
        '/components/report/report_generation.php?format=sql',
        '/components/report/report_generation.php?download=1',
        '/components/cashier/shifts.php?action=open',
        '/components/cashier/shifts.php?action=close',
    ];

    foreach ($deniedGetActions as $uri) {
        [$code, $body] = $request($uri, $remoteHeaders);
        $assert($code === 403, "Side-effecting GET action to {$uri} must return 403, got {$code}");
    }

    // 7. Verification: Persisted database state remains completely unchanged
    $afterUsers = $pdo->query('SELECT user_id, username, password_hash, status FROM users ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    $afterSalesCount = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $afterRefundsCount = (int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn();
    $afterStockCount = (int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();

    $assert($snapUsers === $afterUsers, 'Users table state must remain completely unchanged');
    $assert($snapSalesCount === $afterSalesCount, 'Sales count must remain completely unchanged');
    $assert($snapRefundsCount === $afterRefundsCount, 'Cash refunds count must remain completely unchanged');
    $assert($snapStockCount === $afterStockCount, 'Stock movements count must remain completely unchanged');

    // 8. Anti-spoofing: Forwarding/proxy/scheme headers cannot grant local privilege
    $spoofHeaders = [
        ['X-Forwarded-For' => '127.0.0.1'],
        ['X-Real-IP' => '127.0.0.1'],
        ['X-Forwarded-Host' => 'localhost'],
        ['X-Forwarded-Proto' => 'http'],
        ['X-Forwarded-Port' => '80'],
        ['Client-IP' => '127.0.0.1'],
    ];

    foreach ($spoofHeaders as $headerPair) {
        $headers = array_merge($remoteHeaders, $headerPair);
        [$code] = $request('/components/cashier/pos.php', $headers);
        $assert($code === 403, 'Spoofed header ' . key($headerPair) . ' must not turn remote ingress into local trust, got ' . $code);

        [$code] = $request('/components/administrator/dashboard.php?action=delete', $headers);
        $assert($code === 403, 'Spoofed header with side-effecting GET must still be denied 403');
    }

    // 9. Workspace switching does not bypass the boundary
    $multiHeaders = [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'multi',
    ];

    // Multi-role owner loads workspace selector remotely
    [$code, $body] = $request('/components/auth/workspace.php', $multiHeaders);
    $assert($code === 200, 'Owner can open workspace selector remotely, got ' . $code);
    $assert(str_contains($body, 'Change workspace'), 'Workspace selector heading rendered');

    // Multi-role owner switches workspace to cashier remotely
    [$code, $body, $wsHeaders] = $request('/components/auth/workspace.php', $multiHeaders, 'POST', 'workspace=cashier&csrf_token=fixture-csrf');
    $assert($code === 302, 'Workspace switch responds with redirect (302), got ' . $code);
    $wsLocation = '';
    foreach ($wsHeaders as $h) {
        if (stripos($h, 'Location:') === 0) { $wsLocation = trim(substr($h, 9)); break; }
    }
    $assert(str_contains($wsLocation, 'components/cashier/shifts.php'), 'Remote workspace switch redirects to shifts.php, got ' . $wsLocation);

    // After switching workspace, cashier role attempts cashier POS: denied with 403
    [$code, $body] = $request('/components/cashier/pos.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert($code === 403, 'Cashier workspace cannot access POS remotely, got ' . $code);

    // Cashier role attempts refunds remotely: denied with 403
    [$code, $body] = $request('/components/cashier/refunds.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert($code === 403, 'Cashier workspace cannot access refunds remotely, got ' . $code);

    // Cashier role accessing administrator shift report: denied by role policy
    [$code, $body] = $request('/components/administrator/shift_report.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert($code === 403, 'Cashier role cannot access administrator shift report, got ' . $code);

    // Cashier role accessing approved cashier shifts screen remotely: 200 OK
    [$code, $body] = $request('/components/cashier/shifts.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert($code === 200, 'Cashier role can view cashier shifts remotely, got ' . $code);

    // 10. Local authorized operations remain functional
    [$code, $body] = $request('/components/cashier/pos.php', [
        'Host' => 'localhost',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert(!str_contains($body, 'unapproved route on remote ingress'), 'Local request must not be blocked by remote boundary');

    [$code, $body, $headers] = $request('/components/report/report_generation.php', [
        'Host' => 'localhost',
        'X-Fixture-User' => 'owner',
    ], 'POST', 'action=export_csv&csrf_token=fixture-csrf&report=sales_period');
    $isCsv = false;
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type: text/csv') !== false) {
            $isCsv = true;
            break;
        }
    }
    $assert($code === 200 && $isCsv, 'Authorized local CSV export from report_generation.php succeeds text/csv');

    [$code, $body, $headers] = $request('/components/administrator/shift_report.php', [
        'Host' => 'localhost',
        'X-Fixture-User' => 'owner',
    ], 'POST', 'action=export_csv&csrf_token=fixture-csrf');
    $isCsv = false;
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type: text/csv') !== false) {
            $isCsv = true;
            break;
        }
    }
    $assert($code === 200 && $isCsv, 'Authorized local CSV export from shift_report.php succeeds text/csv');

    // 11. Session cookie secure flag under remote ingress
    $cookieHeaders = array_filter($remoteHeadersList, static fn($h) => stripos($h, 'Set-Cookie:') === 0);
    $hasSecureCookie = false;
    foreach ($cookieHeaders as $ch) {
        if (stripos($ch, 'Secure') !== false) {
            $hasSecureCookie = true;
            break;
        }
    }
    $assert($hasSecureCookie, 'Remote session cookie must contain Secure flag');

    // 12. Sign-out remotely: logout.php is approved and redirects to login
    [$code, $body, $headers] = $request('/components/auth/logout.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 302, 'Remote sign-out must redirect (302), got ' . $code);
    $location = '';
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
            break;
        }
    }
    $assert(str_contains($location, 'login=1'), 'Sign-out redirects to login URL');

    echo "Owner remote monitoring HTTP tests: passed\n";
} finally {
    try {
        $p = App\Core\Database::connection();
        $p->exec("UPDATE users SET status='disabled' WHERE user_id=9");
    } catch (Throwable) {}
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    if (file_exists($router)) {
        @unlink($router);
    }
    if (file_exists($log)) {
        @unlink($log);
    }
    if (is_dir($backupDir)) {
        @rmdir($backupDir);
    }
}
