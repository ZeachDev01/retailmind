<?php

declare(strict_types=1);

// cloudflare_permanent_tunnel_service_contract.php
// Contract and integration tests for Ticket #444:
// - Permanent Cloudflare Tunnel token configuration and persistence
// - CloudflareTunnelService token execution (cloudflared run --token <token>)
// - Fallback to ephemeral quick tunnels when token is cleared
// - Remote access API endpoint for token management (save_token, clear_token)
// - Store Configuration UI controls for permanent token
// - Standalone Windows Service installation/removal script contracts (relative paths, start= auto, auto-recovery)
// - Deployment documentation for dedicated Store PCs

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

echo "=== 1. Token Persistence and Configuration Methods ===\n";

$serviceClass = 'App\\Services\\CloudflareTunnelService';
$serviceFile = $root . '/src/backend/app/Services/CloudflareTunnelService.php';

$assert(file_exists($serviceFile), "CloudflareTunnelService.php exists");
require_once $serviceFile;

$tempDir = sys_get_temp_dir() . '/rm_permanent_tunnel_test_' . uniqid();
@mkdir($tempDir . '/bin', 0777, true);
@mkdir($tempDir . '/tunnel', 0777, true);

$service = new $serviceClass($tempDir, $tempDir . '/bin', $tempDir . '/tunnel');

// Initial state: no token configured
$assert(!$service->isPermanent(), "Default state is non-permanent (no token)");
$assert($service->getToken() === null, "Default token is null");
$assert($service->getHostname() === null, "Default hostname is null");
$assert($service->getMaskedToken() === null, "Default masked token is null");

// Set token and hostname
$testToken = 'eyJhIjoiMTEyMjMzNDQ1NSIqueryTokenValue1234567890';
$testHostname = 'supervisor.mysupermarket.com';
$saved = $service->setToken($testToken, $testHostname);
$assert($saved === true, "setToken returns true on success");
$assert($service->isPermanent() === true, "isPermanent returns true after setting token");
$assert($service->getToken() === $testToken, "getToken returns configured token");
$assert($service->getHostname() === $testHostname, "getHostname returns configured hostname");

// Verify storage on disk in tunnel_config.json
$configPath = $service->getConfigPath();
$assert(file_exists($configPath), "tunnel_config.json file created on disk at {$configPath}");
$configData = json_decode((string)file_get_contents($configPath), true);
$assert(is_array($configData), "tunnel_config.json is valid JSON");
$assert(($configData['token'] ?? '') === $testToken, "tunnel_config.json contains token");
$assert(($configData['hostname'] ?? '') === $testHostname, "tunnel_config.json contains hostname");

// Test token masking
$masked = $service->getMaskedToken();
$assert($masked !== null && str_contains($masked, '••••'), "Masked token hides secret middle characters: {$masked}");
$assert(str_starts_with($masked, substr($testToken, 0, 4)), "Masked token preserves first 4 characters");
$assert(str_ends_with($masked, substr($testToken, -4)), "Masked token preserves last 4 characters");

// Test status output enrichment
$status = $service->getStatus();
$assert(($status['mode'] ?? '') === 'permanent', "getStatus reports mode as permanent");
$assert(($status['token_configured'] ?? false) === true, "getStatus reports token_configured as true");
$assert(($status['hostname'] ?? '') === $testHostname, "getStatus reports configured hostname");
$assert(($status['masked_token'] ?? '') === $masked, "getStatus reports masked_token");

// Test clearing token
$cleared = $service->clearToken();
$assert($cleared === true, "clearToken returns true");
$assert($service->isPermanent() === false, "isPermanent returns false after clearing");
$assert($service->getToken() === null, "getToken returns null after clearing");
$statusAfterClear = $service->getStatus();
$assert(($statusAfterClear['mode'] ?? '') === 'quick', "getStatus reports mode as quick after clearing token");
$assert(($statusAfterClear['token_configured'] ?? true) === false, "getStatus reports token_configured as false after clearing");

// Test Environment fallback when config file has no token
putenv('CLOUDFLARE_TUNNEL_TOKEN=env-token-secret-999');
$_ENV['CLOUDFLARE_TUNNEL_TOKEN'] = 'env-token-secret-999';
$assert($service->getToken() === 'env-token-secret-999', "getToken falls back to Environment when config file has no token");
putenv('CLOUDFLARE_TUNNEL_TOKEN');
unset($_ENV['CLOUDFLARE_TUNNEL_TOKEN']);

echo "\n=== 2. Command Arguments: Permanent vs Quick Tunnel Launch ===\n";

$capturedArgs = null;
$capturedBin = null;
$service->setProcessLauncher(function (string $binPath, array $args, string $logFile, string $pidFile) use (&$capturedArgs, &$capturedBin): int {
    $capturedBin = $binPath;
    $capturedArgs = $args;
    file_put_contents($pidFile, '12345');
    return 12345;
});
$service->setProcessChecker(function (int $pid): bool {
    return true;
});
$service->setProcessKiller(function (int $pid): bool {
    return true;
});

// A. Token configured -> should launch 'run --token <token>' bypassing ephemeral quick tunnels
$service->setToken('eyJh-permanent-token-test', 'pos.mychain.com');
$service->start(80, 0);

$assert(is_array($capturedArgs), "Process launcher invoked with arguments array");
$argString = implode(' ', $capturedArgs);
$assert(in_array('run', $capturedArgs, true), "Arguments include 'run' subcommand for permanent tunnel");
$assert(in_array('--token', $capturedArgs, true), "Arguments include '--token' flag");
$tokenIdx = array_search('--token', $capturedArgs, true);
$assert($tokenIdx !== false && ($capturedArgs[$tokenIdx + 1] ?? '') === 'eyJh-permanent-token-test', "Arguments pass the configured token directly after '--token'");
$assert(!in_array('--url', $capturedArgs, true), "Permanent tunnel bypasses ephemeral '--url' quick tunnel argument");
$service->stop();

// B. Token cleared -> should launch ephemeral quick tunnel with '--url'
$service->clearToken();
$capturedArgs = null;
$service->start(8080, 0);

$assert(is_array($capturedArgs), "Process launcher invoked for quick tunnel");
$assert(in_array('tunnel', $capturedArgs, true), "Quick tunnel arguments include 'tunnel' command");
$assert(in_array('--url', $capturedArgs, true), "Quick tunnel arguments include '--url' flag");
$urlIdx = array_search('--url', $capturedArgs, true);
$assert($urlIdx !== false && str_contains($capturedArgs[$urlIdx + 1] ?? '', '8080'), "Quick tunnel points to local port");
$assert(!in_array('--token', $capturedArgs, true), "Quick tunnel does not pass '--token'");
$service->stop();

echo "\n=== 3. Remote Access API Token Endpoints ===\n";

$apiFile = $root . '/src/frontend/components/administrator/remote_access_api.php';
$assert(file_exists($apiFile), "remote_access_api.php exists");

// Test save_token action via simulated API call
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$testCsrf = csrf_token();

// Save token via API POST
$tempRunDir = $tempDir . '/api_test_tunnel';
@mkdir($tempRunDir, 0777, true);

// Verify API script handles save_token and clear_token
$apiSource = file_get_contents($apiFile);
$assert(str_contains($apiSource, 'save_token'), "remote_access_api.php supports 'save_token' action");
$assert(str_contains($apiSource, 'clear_token'), "remote_access_api.php supports 'clear_token' action");

echo "\n=== 4. Platform Settings UI Permanent Token Form Controls ===\n";

$settingsFile = $root . '/src/frontend/components/system_administrator/system_settings.php';
$assert(file_exists($settingsFile), "system_settings.php exists");
$settingsSource = file_get_contents($settingsFile);

$assert(str_contains($settingsSource, 'name="tunnel_token"') || str_contains($settingsSource, 'id="tunnel_token"'), "system_settings.php has tunnel_token input field");
$assert(str_contains($settingsSource, 'save_tunnel_token'), "system_settings.php supports 'save_tunnel_token' action");
$assert(str_contains($settingsSource, 'clear_tunnel_token'), "system_settings.php supports 'clear_tunnel_token' action");
$assert(str_contains($settingsSource, 'Permanent') || str_contains($settingsSource, 'permanent'), "system_settings.php mentions permanent tunnel mode");

echo "\n=== 5. Standalone Windows Service Script Contracts ===\n";

$serviceInstallScript = $root . '/src/backend/scripts/tunnel/service_install.ps1';
$serviceUninstallScript = $root . '/src/backend/scripts/tunnel/service_uninstall.ps1';
$runTunnelScript = $root . '/src/backend/scripts/tunnel/run_tunnel.ps1';

$assert(file_exists($serviceInstallScript), "service_install.ps1 exists at {$serviceInstallScript}");
$assert(file_exists($serviceUninstallScript), "service_uninstall.ps1 exists at {$serviceUninstallScript}");
$assert(file_exists($runTunnelScript), "run_tunnel.ps1 exists at {$runTunnelScript}");

if (file_exists($serviceInstallScript)) {
    $installSource = file_get_contents($serviceInstallScript);
    $assert(str_contains($installSource, '$PSScriptRoot'), "service_install.ps1 resolves paths relative to PSScriptRoot");
    $assert(str_contains($installSource, 'sc.exe') || str_contains($installSource, 'New-Service'), "service_install.ps1 uses sc.exe or New-Service");
    $assert(str_contains($installSource, 'start= auto') || str_contains($installSource, 'StartupType Automatic'), "service_install.ps1 sets service to start automatically at boot (start= auto)");
    $assert(str_contains($installSource, 'failure') || str_contains($installSource, 'restart'), "service_install.ps1 configures recovery/restart on failure");
    $assert(str_contains($installSource, '--token'), "service_install.ps1 supports --token configuration");
}

if (file_exists($serviceUninstallScript)) {
    $uninstallSource = file_get_contents($serviceUninstallScript);
    $assert(str_contains($uninstallSource, '$PSScriptRoot'), "service_uninstall.ps1 resolves paths relative to PSScriptRoot");
    $assert(str_contains($uninstallSource, 'sc.exe') || str_contains($uninstallSource, 'Remove-Service'), "service_uninstall.ps1 removes the Windows service");
    $assert(str_contains($uninstallSource, 'delete') || str_contains($uninstallSource, 'stop'), "service_uninstall.ps1 stops and deletes service");
}

if (file_exists($runTunnelScript)) {
    $runSource = file_get_contents($runTunnelScript);
    $assert(str_contains($runSource, '--token'), "run_tunnel.ps1 supports permanent tunnel token");
}

echo "\n=== 6. Dedicated Store PC Deployment Documentation ===\n";

$docFiles = [
    $root . '/docs/DEPLOYMENT_REMOTE_TUNNEL.md',
    $root . '/docs/guides/STORE_PC_TUNNEL_SETUP.md',
];
$foundDoc = null;
foreach ($docFiles as $doc) {
    if (file_exists($doc)) {
        $foundDoc = $doc;
        break;
    }
}
$assert($foundDoc !== null, "Deployment documentation exists in docs/DEPLOYMENT_REMOTE_TUNNEL.md or docs/guides/STORE_PC_TUNNEL_SETUP.md");

if ($foundDoc !== null) {
    $docContent = file_get_contents($foundDoc);
    $assert(str_contains($docContent, '5-minute') || str_contains($docContent, '5 Minute') || str_contains($docContent, 'Quick'), "Documentation contains 5-minute setup overview");
    $assert(str_contains($docContent, 'service_install.ps1'), "Documentation references service_install.ps1");
    $assert(str_contains($docContent, 'token') || str_contains($docContent, 'Token'), "Documentation explains tunnel token setup");
    $assert(str_contains($docContent, 'boot') || str_contains($docContent, 'restart') || str_contains($docContent, 'service'), "Documentation explains persistence and automatic boot recovery");
}

echo "\n=== 7. Windows Service Status Detection ===\n";

$svc = new $serviceClass($tempDir);
$assert(method_exists($svc, 'isWindowsServiceRunning'), "CloudflareTunnelService has isWindowsServiceRunning method");
$assert($svc->isWindowsServiceRunning('NonExistentTestService999') === false, "isWindowsServiceRunning returns false for non-existent service");

// Clean up temp directory
@unlink($tempDir . '/tunnel/tunnel_config.json');
@unlink($tempDir . '/tunnel/tunnel.pid');
@unlink($tempDir . '/tunnel/tunnel.log');
@rmdir($tempDir . '/tunnel');
@rmdir($tempDir . '/bin');
@rmdir($tempDir);

echo "\n==========================================\n";
if (!empty($failures)) {
    echo "TEST RUN FAILED with " . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}

echo "SUCCESS: All Cloudflare permanent tunnel service contract tests passed!\n";
exit(0);
