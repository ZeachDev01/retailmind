<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

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

    // 1. Remote unapproved route is denied at the server boundary (403)
    [$code, $body] = $request('/components/cashier/pos.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Remote ingress to cashier pos.php must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'unapproved route on remote ingress'), 'Denial message explains unapproved route');

    // Remote unapproved backup route is denied
    [$code, $body] = $request('/components/system_administrator/backup_restore.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Remote ingress to backup_restore.php must be denied with 403, got ' . $code);

    // 2. Remote owner Access email check
    [$code, $body] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'intruder@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Mismatched Access email must be denied with 403, got ' . $code);
    $assert(str_contains($body, 'unapproved owner identity'), 'Denial message explains unapproved email');

    // 3. Local authorized operation is still available
    [$code, $body] = $request('/components/cashier/pos.php', [
        'Host' => 'localhost',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert(!str_contains($body, 'unapproved route on remote ingress'), 'Local request must not be blocked by remote ingress boundary');

    // 4. Remote approved route: Store overview loads for authenticated owner
    [$code, $body, $headers] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 200, 'Authenticated owner can load Store overview remotely, got ' . $code);
    $assert(str_contains($body, 'Store status') || str_contains($body, 'Gross Sales'), 'Store overview content is present');

    // Check session cookie has Secure flag on remote origin
    $cookieHeaders = array_filter($headers, static fn($h) => stripos($h, 'Set-Cookie:') === 0);
    $hasSecureCookie = false;
    foreach ($cookieHeaders as $ch) {
        if (stripos($ch, 'Secure') !== false) {
            $hasSecureCookie = true;
            break;
        }
    }
    $assert($hasSecureCookie, 'Remote session cookie must contain Secure flag');

    // 5. Existing Workspace permissions still apply remotely: cashier accessing Store overview gets 403 from role policy
    [$code, $body, $cashierHeaders] = $request('/components/administrator/dashboard.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'cashier',
    ]);
    $assert($code === 403, 'Cashier accessing administrator Store overview must be denied with 403 by role policy, got ' . $code);
    $assert(str_contains($body, 'Access denied: your role does not have permission'), 'Role policy denial message is present');

    // 6. Sign-out remotely: logout.php is approved and redirects to login
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

    // 7. Approved Store Reports render and filter remotely (#321)
    // 7a. Report Generation overview
    [$code, $body] = $request('/components/report/report_generation.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 200, 'Owner can open Report Generation remotely, got ' . $code);
    $assert(str_contains($body, 'Report Generation'), 'Report Generation header present');

    // 7b. Report Generation with filters (Gross profit linked from Store overview)
    [$code, $body] = $request('/components/report/report_generation.php?report=gross_profit&from=2026-01-01&to=2026-12-31', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 200, 'Owner can filter Gross Profit report remotely, got ' . $code);
    $assert(str_contains($body, 'Gross Profit') || str_contains($body, 'Gross Sales'), 'Gross Profit report content rendered');

    // 7c. Cashier shift report renders and filters remotely
    [$code, $body] = $request('/components/administrator/shift_report.php?from=2026-01-01&to=2026-12-31', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 200, 'Owner can open Cashier Shift report remotely, got ' . $code);
    $assert(str_contains($body, 'Cashier Shift Report'), 'Cashier Shift Report title present');

    // 8. Remote direct bulk CSV export and backup download requests are denied (403) (#321)
    [$code, $body] = $request('/components/report/report_generation.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ], 'POST', 'action=export_csv&csrf_token=fixture-csrf&report=sales_period');
    $assert($code === 403, 'Remote POST action=export_csv to report_generation.php must return 403, got ' . $code);
    $assert(str_contains($body, 'bulk export') || str_contains($body, 'unavailable on remote ingress'), 'Export denial message returned');

    [$code, $body] = $request('/components/administrator/shift_report.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ], 'POST', 'action=export_csv&csrf_token=fixture-csrf');
    $assert($code === 403, 'Remote POST action=export_csv to shift_report.php must return 403, got ' . $code);

    // 8b. Direct unapproved export / backup scripts are denied
    [$code, $body] = $request('/components/administrator/database_backup.php', [
        'Host' => 'monitor.retailmind.store',
        'X-RetailMind-Tunnel-Secret' => 'expected-tunnel-token-999',
        'Cf-Access-Authenticated-User-Email' => 'owner@retailmind.store',
        'X-Fixture-User' => 'owner',
    ]);
    $assert($code === 403, 'Remote GET to database_backup.php must return 403, got ' . $code);

    // 9. Local authorized CSV export remains intact (#321)
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
    $assert($code === 200 && $isCsv, 'Authorized local CSV export from report_generation.php must succeed with text/csv, got code ' . $code);

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
    $assert($code === 200 && $isCsv, 'Authorized local CSV export from shift_report.php must succeed with text/csv, got code ' . $code);

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
