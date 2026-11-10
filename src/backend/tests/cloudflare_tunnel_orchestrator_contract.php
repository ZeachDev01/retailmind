<?php

declare(strict_types=1);

// cloudflare_tunnel_orchestrator_contract.php
// Contract and integration tests for Ticket #441:
// - Portable tunnel runner scripts
// - CloudflareTunnelService lifecycle, PID tracking, URL extraction, graceful stop
// - Administrator remote_access_api endpoint (auth, CSRF, start, stop, status)
// - Store Configuration UI Remote Access card and controls

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
};

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$root = dirname(__DIR__, 3);

echo "=== 1. Service Class Existence and Structure ===\n";

$serviceClass = 'App\\Services\\CloudflareTunnelService';
$serviceFile = $root . '/src/backend/app/Services/CloudflareTunnelService.php';

$assert(file_exists($serviceFile), "CloudflareTunnelService.php file exists at {$serviceFile}");
if (file_exists($serviceFile)) {
    require_once $serviceFile;
}
$assert(class_exists($serviceClass), "Class {$serviceClass} exists");

echo "\n=== 2. Path Resolution and Zero Machine Coupling ===\n";

$tempDir = sys_get_temp_dir() . '/rm_tunnel_test_' . uniqid();
@mkdir($tempDir . '/bin', 0777, true);
@mkdir($tempDir . '/tunnel', 0777, true);

if (class_exists($serviceClass)) {
    $service = new $serviceClass($tempDir, $tempDir . '/bin', $tempDir . '/tunnel');
    $assert($service->getRootDir() === $tempDir, "Custom root dir respected");
    $assert(str_starts_with($service->getBinPath(), $tempDir . '/bin'), "Bin path is within configured binDir");
    $assert($service->getPidPath() === $tempDir . '/tunnel/tunnel.pid', "PID path resolved correctly");
    $assert($service->getLogPath() === $tempDir . '/tunnel/tunnel.log', "Log path resolved correctly");
    $assert($service->getUrlPath() === $tempDir . '/tunnel/tunnel.url', "URL path resolved correctly");
}

echo "\n=== 3. URL Extraction Contract ===\n";

if (class_exists($serviceClass)) {
    $service = new $serviceClass($tempDir, $tempDir . '/bin', $tempDir . '/tunnel');

    $sampleLog1 = <<<'LOG'
2026-11-08T00:00:01Z INF Starting tunnel tunnelID=abc
2026-11-08T00:00:02Z INF +--------------------------------------------------------------------------------------------+
2026-11-08T00:00:02Z INF |  Your quick Tunnel has been created! Visit it at (it may take some time to be reachable):  |
2026-11-08T00:00:02Z INF |  https://amber-crystal-beacon.trycloudflare.com                                            |
2026-11-08T00:00:02Z INF +--------------------------------------------------------------------------------------------+
LOG;

    $extracted = $service->extractPublicUrl($sampleLog1);
    $assert($extracted === 'https://amber-crystal-beacon.trycloudflare.com', "Extracts trycloudflare.com URL from standard log banner");

    $sampleLog2 = '{"time":"2026-11-08T00:00:02Z","level":"info","message":"https://quiet-river-99.trycloudflare.com"}';
    $extracted2 = $service->extractPublicUrl($sampleLog2);
    $assert($extracted2 === 'https://quiet-river-99.trycloudflare.com', "Extracts trycloudflare.com URL from json-formatted log line");

    $sampleLogReused = <<<'LOG'
2026-11-08T00:00:02Z INF |  https://first-tunnel.trycloudflare.com |
2026-11-08T01:00:02Z INF Tunnel server stopped
2026-11-08T01:00:04Z INF |  https://second-tunnel.trycloudflare.com |
LOG;
    $extractedReused = $service->extractPublicUrl($sampleLogReused);
    $assert($extractedReused === 'https://second-tunnel.trycloudflare.com', "Returns the newest URL when the log holds several Quick Tunnel restarts");

    $sampleLogEmpty = "2026-11-08T00:00:01Z INF Connecting to edge network...\n";
    $extractedEmpty = $service->extractPublicUrl($sampleLogEmpty);
    $assert($extractedEmpty === null, "Returns null when no public URL is present in the log yet");
}

echo "\n=== 4. Lifecycle, PID Tracking, Status, and Stop Seam ===\n";

if (class_exists($serviceClass)) {
    $simulatedBinDir = $tempDir . '/sim_bin';
    $simulatedRunDir = $tempDir . '/sim_run';
    @mkdir($simulatedBinDir, 0777, true);
    @mkdir($simulatedRunDir, 0777, true);

    $simService = new $serviceClass($tempDir, $simulatedBinDir, $simulatedRunDir);

    // Initial state: offline
    $status0 = $simService->getStatus();
    $assert($status0['status'] === 'offline', "Initial status is offline");
    $assert($status0['online'] === false, "Initial online boolean is false");
    $assert($status0['url'] === null, "Initial URL is null");
    $assert($status0['pid'] === null, "Initial PID is null");

    // Configure a simulated process launcher for deterministic unit testing
    $simulatedPid = 987654;
    $simLogFile = $simService->getLogPath();
    $simService->setProcessLauncher(function (string $binPath, array $args, string $logFile, string $pidFile) use ($simulatedPid): int {
        file_put_contents($logFile, "Tunnel starting...\nhttps://simulated-preview-tunnel.trycloudflare.com\n");
        file_put_contents($pidFile, (string)$simulatedPid);
        return $simulatedPid;
    });

    // Custom process checker for the simulated PID
    $simIsRunning = true;
    $simService->setProcessChecker(function (int $pid) use (&$simIsRunning, $simulatedPid): bool {
        return $simIsRunning && $pid === $simulatedPid;
    });

    // Custom process killer for the simulated PID
    $simService->setProcessKiller(function (int $pid) use (&$simIsRunning, $simulatedPid): bool {
        if ($pid === $simulatedPid) {
            $simIsRunning = false;
            return true;
        }
        return false;
    });

    // Start tunnel
    $startResult = $simService->start(80, 1);
    $assert($startResult['status'] === 'online', "Start sets status to online");
    $assert($startResult['online'] === true, "Start sets online flag to true");
    $assert($startResult['pid'] === $simulatedPid, "Start tracks the correct process PID");
    $assert($startResult['url'] === 'https://simulated-preview-tunnel.trycloudflare.com', "Start captures dynamic URL");
    $assert(file_exists($simService->getPidPath()), "PID file written to storage");
    $assert((int)file_get_contents($simService->getPidPath()) === $simulatedPid, "PID file contains exact process PID");

    // Status check
    $statusCheck = $simService->getStatus();
    $assert($statusCheck['status'] === 'online', "getStatus returns online");
    $assert($statusCheck['url'] === 'https://simulated-preview-tunnel.trycloudflare.com', "getStatus returns active URL");

    // Stop tunnel
    $stopResult = $simService->stop();
    $assert($stopResult['status'] === 'offline', "Stop updates status to offline");
    $assert($stopResult['online'] === false, "Stop updates online flag to false");
    $assert(!file_exists($simService->getPidPath()), "Graceful stop removes PID file");
    $assert($simService->isRunning() === false, "isRunning returns false after stop");

    // Stale PID recovery
    file_put_contents($simService->getPidPath(), '999999'); // Non-existent process
    $staleStatus = $simService->getStatus();
    $assert($staleStatus['status'] === 'offline', "Stale PID reports offline");
    $assert(!file_exists($simService->getPidPath()), "Stale PID file automatically cleaned up");
}

echo "\n=== 5. Remote Access API Endpoint Contract ===\n";

$apiFile = $root . '/src/frontend/components/administrator/remote_access_api.php';
$assert(file_exists($apiFile), "remote_access_api.php exists at {$apiFile}");

if (file_exists($apiFile)) {
    // Subprocess execution wrapper to test isolated API requests
    $runApiRequest = function (string $codePayload): array {
        $wrapperFile = sys_get_temp_dir() . '/api_req_' . uniqid() . '.php';
        file_put_contents($wrapperFile, "<?php\n" . $codePayload);
        $output = [];
        $returnCode = 0;
        exec('php ' . escapeshellarg($wrapperFile), $output, $returnCode);
        @unlink($wrapperFile);
        $raw = implode("\n", $output);
        $json = json_decode($raw, true);
        return [
            'exitCode' => $returnCode,
            'raw' => $raw,
            'json' => is_array($json) ? $json : null,
        ];
    };

    $rootSafe = var_export($root, true);

    // Query active admin user credentials from database if available
    $adminId = 1;
    $sessionVer = 0;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->query("SELECT u.user_id, u.session_version FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'active' AND r.role_name IN ('admin', 'super_admin') AND u.must_change_password = 0 LIMIT 1");
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $adminId = (int)$row['user_id'];
                $sessionVer = (int)$row['session_version'];
            }
        }
    } catch (\Throwable $t) {
        // Fallback to defaults
    }

    // 5.1 GET status as Administrator
    $payloadGetAdmin = <<<PHP
require {$rootSafe} . '/src/backend/includes/auth.php';
\$_SESSION['user_id'] = {$adminId};
\$_SESSION['role'] = 'admin';
\$_SESSION['roles'] = ['admin'];
\$_SESSION['session_version'] = {$sessionVer};
\$_SERVER['REQUEST_METHOD'] = 'GET';
require {$rootSafe} . '/src/frontend/components/administrator/remote_access_api.php';
PHP;
    $resGetAdmin = $runApiRequest($payloadGetAdmin);
    $assert($resGetAdmin['json'] !== null && ($resGetAdmin['json']['success'] ?? false) === true, "GET as admin returns success JSON");
    $assert(isset($resGetAdmin['json']['status']), "GET response includes status");
    $assert(isset($resGetAdmin['json']['online']), "GET response includes online boolean");

    // 5.2 POST without CSRF token returns 403 Security check failed
    $payloadPostNoCsrf = <<<PHP
require {$rootSafe} . '/src/backend/includes/auth.php';
\$_SESSION['user_id'] = {$adminId};
\$_SESSION['role'] = 'admin';
\$_SESSION['roles'] = ['admin'];
\$_SESSION['session_version'] = {$sessionVer};
\$_SESSION['csrf_token'] = 'valid-csrf-token';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_POST = ['action' => 'status'];
require {$rootSafe} . '/src/frontend/components/administrator/remote_access_api.php';
PHP;
    $resPostNoCsrf = $runApiRequest($payloadPostNoCsrf);
    $assert($resPostNoCsrf['json'] !== null && ($resPostNoCsrf['json']['success'] ?? true) === false, "POST without valid CSRF is rejected");
    $assert(str_contains($resPostNoCsrf['json']['error'] ?? '', 'Security check failed'), "POST without CSRF reports Security check failed");

    // 5.3 POST with valid CSRF token and action 'status'
    $payloadPostStatus = <<<PHP
require {$rootSafe} . '/src/backend/includes/auth.php';
\$_SESSION['user_id'] = {$adminId};
\$_SESSION['role'] = 'admin';
\$_SESSION['roles'] = ['admin'];
\$_SESSION['session_version'] = {$sessionVer};
\$_SESSION['csrf_token'] = 'test-csrf-12345';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf-12345';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_POST = ['action' => 'status'];
require {$rootSafe} . '/src/frontend/components/administrator/remote_access_api.php';
PHP;
    $resPostStatus = $runApiRequest($payloadPostStatus);
    $assert($resPostStatus['json'] !== null && ($resPostStatus['json']['success'] ?? false) === true, "POST with valid CSRF and action=status succeeds");
    $assert(isset($resPostStatus['json']['status']), "POST status response includes status field");

    // 5.4 POST with unsupported action returns error
    $payloadPostBad = <<<PHP
require {$rootSafe} . '/src/backend/includes/auth.php';
\$_SESSION['user_id'] = {$adminId};
\$_SESSION['role'] = 'admin';
\$_SESSION['roles'] = ['admin'];
\$_SESSION['session_version'] = {$sessionVer};
\$_SESSION['csrf_token'] = 'test-csrf-12345';
\$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf-12345';
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_POST = ['action' => 'invalid_unknown_action'];
require {$rootSafe} . '/src/frontend/components/administrator/remote_access_api.php';
PHP;
    $resPostBad = $runApiRequest($payloadPostBad);
    $assert($resPostBad['json'] !== null && ($resPostBad['json']['success'] ?? true) === false, "POST with unsupported action is rejected");
    $assert(str_contains($resPostBad['json']['error'] ?? '', 'Unsupported action'), "Unsupported action error message returned");
}

echo "\n=== 6. Standalone Portable Runner Scripts ===\n";

$ps1Script = $root . '/src/backend/scripts/tunnel/run_tunnel.ps1';
$batScript = $root . '/src/backend/scripts/tunnel/run_tunnel.bat';

$assert(file_exists($ps1Script), "run_tunnel.ps1 exists");
$assert(file_exists($batScript), "run_tunnel.bat exists");

if (file_exists($ps1Script)) {
    $ps1Content = (string)file_get_contents($ps1Script);
    $assert(str_contains($ps1Content, 'PSScriptRoot'), "run_tunnel.ps1 uses relative PSScriptRoot path resolution");
    $assert(!str_contains($ps1Content, 'c:\\xampp') && !str_contains($ps1Content, 'c:\\laragon'), "run_tunnel.ps1 has zero hardcoded machine coupling");
    $assert(str_contains($ps1Content, 'cloudflared'), "run_tunnel.ps1 manages cloudflared");
    $assert(str_contains($ps1Content, 'trycloudflare'), "run_tunnel.ps1 supports trycloudflare output");
    $assert(str_contains($ps1Content, 'Test-WebServer'), "run_tunnel.ps1 checks local web server availability");
}

echo "\n=== 7. Store Configuration UI Remote Access Card ===\n";

$storeSettingsFile = $root . '/src/frontend/components/administrator/store_settings.php';
$assert(file_exists($storeSettingsFile), "store_settings.php exists");

if (file_exists($storeSettingsFile)) {
    $uiContent = (string)file_get_contents($storeSettingsFile);
    $assert(str_contains($uiContent, 'Remote Access'), "store_settings.php includes 'Remote Access' section");
    $assert(str_contains($uiContent, 'remote-access-toggle') || str_contains($uiContent, 'remote_access_toggle'), "store_settings.php has remote access toggle switch");
    $assert(str_contains($uiContent, 'remote-status-badge') || str_contains($uiContent, 'remote_status_badge'), "store_settings.php has live status badge");
    $assert(str_contains($uiContent, 'remote-public-url') || str_contains($uiContent, 'remote_public_url'), "store_settings.php has public URL container/input");
    $assert(str_contains($uiContent, 'copy-remote-url') || str_contains($uiContent, 'copy_remote_url'), "store_settings.php has copy button");
    $assert(str_contains($uiContent, 'remote_access_api.php'), "store_settings.php references remote_access_api.php");
}

echo "\n==========================================\n";
if (empty($failures)) {
    echo "SUCCESS: All Cloudflare Tunnel orchestrator contract tests passed!\n";
    exit(0);
} else {
    echo "FAILURES DETECTED: " . count($failures) . "\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
