<?php

declare(strict_types=1);

// Contract tests for the One-paste Remote Access setup block (#458):
// - Pure PowerShell block builder for permanent and interim Quick modes
// - Quoting, embedding the application root and local port
// - Installer Quick mode, automatic startup, failure recovery, legacy task removal
// - Platform Settings generation actions, local-only CSRF POST, audit logging
// - Token masking before explicit generation

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

$root = dirname(__DIR__, 3);
$builderClass = 'App\\Services\\TunnelSetupBlockBuilder';
$builderFile = $root . '/src/backend/app/Services/TunnelSetupBlockBuilder.php';

$assert(file_exists($builderFile), 'TunnelSetupBlockBuilder.php exists');
require_once $builderFile;
$assert(class_exists($builderClass), "Class {$builderClass} exists");
$builder = new $builderClass();

echo "\n=== 1. Permanent named tunnel block ===\n";

$permanentToken = 'eyJhIjoiMTEyMjMzNDQ1NSIsInQiOiJwZXJtYW5lbnQifQ==';
$permanent = $builder->buildSetupBlock([
    'app_root' => 'C:\\Store PC\\retailmind',
    'local_port' => 8080,
    'token' => $permanentToken,
    'hostname' => 'supervisor.mystore.com',
    'mode' => 'permanent',
]);

$assert(str_contains($permanent, 'PERMANENT named Cloudflare Tunnel'), 'Permanent block is labelled as a permanent named tunnel');
$assert(str_contains($permanent, "C:\\Store PC\\retailmind"), 'Permanent block embeds the resolved application root');
$assert(str_contains($permanent, '$localPort = 8080'), 'Permanent block embeds the configured local port');
$assert(str_contains($permanent, "service_install.ps1"), 'Permanent block invokes the existing tunnel installer');
$assert(str_contains($permanent, '-Mode Permanent'), 'Permanent block selects permanent installer mode');
$assert(str_contains($permanent, '-Hostname \'supervisor.mystore.com\''), 'Permanent block passes the saved hostname');
$assert(str_contains($permanent, '-Token \'' . $permanentToken . '\''), 'Permanent block passes the saved token once');
$assert(substr_count($permanent, $permanentToken) === 1, 'Raw token appears exactly once in the generated block');
$assert(str_contains($permanent, 'RetailMindCloudflareTunnel'), 'Permanent block names the shared Windows Service');
$assert(str_contains($permanent, 'sc.exe query'), 'Permanent block prints the resulting service state');
$assert(str_contains($permanent, 'IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)'), 'Permanent block requires an elevated PowerShell');
$assert(str_contains($permanent, 'Is64BitOperatingSystem'), 'Permanent block requires 64-bit Windows');
$assert(str_contains($permanent, "ConnectAsync('1.1.1.1', 443)"), 'Permanent block checks internet access');
$assert(str_contains($permanent, "ConnectAsync('127.0.0.1', \$localPort)"), 'Permanent block checks the local RetailMind origin');
$assert(str_contains($permanent, 'scripts/tunnel/service_install.ps1'), 'Permanent block uses the existing installer path');
$assert(!str_contains($permanent, '--url'), 'Permanent block does not create a Quick Tunnel');

echo "\n=== 2. Interim Quick Tunnel block ===\n";

$quick = $builder->buildSetupBlock([
    'app_root' => 'C:\\retailmind',
    'local_port' => 80,
    'token' => '',
    'hostname' => 'ignored.example.com',
    'mode' => 'permanent',
]);

$assert(str_contains($quick, 'INTERIM Quick Tunnel'), 'Blank token forces the interim Quick Tunnel block');
$assert(str_contains($quick, 'URL changes'), 'Quick block warns that the URL changes on restart');
$assert(str_contains($quick, 'Cloudflare Access is not in front of this URL'), 'Quick block warns that Cloudflare Access is absent');
$assert(str_contains($quick, '-Mode Quick'), 'Quick block selects Quick installer mode');
$assert(!str_contains($quick, '-Token'), 'Quick block does not pass a token');
$assert(!str_contains($quick, '-Hostname'), 'Quick block does not pass a hostname');

$hostnameWithoutToken = $builder->buildSetupBlock([
    'app_root' => 'C:\\retailmind',
    'local_port' => 80,
    'hostname' => 'supervisor.mystore.com',
]);

$assert(str_contains($hostnameWithoutToken, 'INTERIM Quick Tunnel'), 'Hostname without a token stays in Quick mode');
$assert(!str_contains($hostnameWithoutToken, $permanentToken), 'Quick block never includes a token');
$assert($builder->mode($permanentToken) === 'permanent', 'Saving a token switches generation to permanent mode');
$assert($builder->mode(null) === 'quick', 'Clearing the token returns generation to Quick mode');
$assert($builder->mode($permanentToken, 'quick') === 'quick', 'An explicit Quick request cannot be upgraded by a saved token');

echo "\n=== 3. Quoting and validation ===\n";

$quoted = $builder->buildSetupBlock([
    'app_root' => "C:\\Store's PC\\retailmind",
    'local_port' => 8443,
    'token' => "token'with'quotes",
    'hostname' => 'shop.example.com',
    'mode' => 'permanent',
]);

$assert(str_contains($quoted, "'C:\\Store''s PC\\retailmind'"), 'Application root is safely single-quoted for PowerShell');
$assert(str_contains($quoted, "'token''with''quotes'"), 'Token is safely single-quoted for PowerShell');
$assert(str_contains($quoted, '$localPort = 8443'), 'Non-default ports are preserved');

$invalidPortThrown = false;
try {
    $builder->buildSetupBlock(['app_root' => 'C:\\retailmind', 'local_port' => 0]);
} catch (InvalidArgumentException) {
    $invalidPortThrown = true;
}
$assert($invalidPortThrown, 'Invalid local port is rejected');

$missingRootThrown = false;
try {
    $builder->buildSetupBlock(['app_root' => '', 'local_port' => 80]);
} catch (InvalidArgumentException) {
    $missingRootThrown = true;
}
$assert($missingRootThrown, 'Missing application root is rejected');

echo "\n=== 4. Removal block ===\n";

$removal = $builder->buildRemovalBlock(['app_root' => 'C:\\Store PC\\retailmind']);
$assert(str_contains($removal, 'service_uninstall.ps1'), 'Removal block invokes the existing removal script');
$assert(str_contains($removal, 'RetailMindCloudflareTunnel'), 'Removal block names the shared Windows Service');
$assert(str_contains($removal, 'not deleted'), 'Removal block states application data is preserved');
$assert(!str_contains($removal, $permanentToken), 'Removal block never contains a token');
$assert(str_contains($removal, 'IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)'), 'Removal block requires an elevated PowerShell');

echo "\n=== 5. Installer and removal script contracts ===\n";

$installerPath = $root . '/src/backend/scripts/tunnel/service_install.ps1';
$uninstallerPath = $root . '/src/backend/scripts/tunnel/service_uninstall.ps1';
$assert(file_exists($installerPath), 'service_install.ps1 exists');
$assert(file_exists($uninstallerPath), 'service_uninstall.ps1 exists');

$installer = file_get_contents($installerPath);
$assert(str_contains($installer, '[ValidateSet("Permanent", "Quick", "Auto")]'), 'Installer exposes Permanent, Quick, and Auto modes');
$assert(str_contains($installer, '[string]$Mode = "Permanent"'), 'Installer defaults to Permanent so tokenless manual runs still fail safely');
$assert(str_contains($installer, '[string]$Hostname'), 'Installer accepts a hostname');
$assert(str_contains($installer, '[int]$Port = 80'), 'Installer accepts a local port');
$assert(str_contains($installer, 'tunnel --url http://127.0.0.1:$Port'), 'Installer creates a Quick Tunnel in Quick mode');
$assert(str_contains($installer, 'run --token $activeToken'), 'Installer creates a permanent named tunnel with a token');
$assert(str_contains($installer, 'start= auto'), 'Installer sets automatic boot startup');
$assert(str_contains($installer, 'sc.exe failure'), 'Installer configures crash restart actions');
$assert(str_contains($installer, 'RetailMindQuickTunnel'), 'Installer removes the legacy Quick Tunnel task');
$assert(str_contains($installer, '--logfile'), 'Installer writes tunnel logs used by the status card');

$uninstaller = file_get_contents($uninstallerPath);
$assert(str_contains($uninstaller, 'LegacyTaskName'), 'Removal script accepts the legacy task name');
$assert(str_contains($uninstaller, 'schtasks.exe /Delete'), 'Removal script deletes the legacy scheduled task');
$assert(str_contains($uninstaller, 'The application, database, and backups are not deleted'), 'Removal script documents preserved application data');

echo "\n=== 6. Platform Settings card contract ===\n";

$settingsPath = $root . '/src/frontend/components/system_administrator/system_settings.php';
$assert(file_exists($settingsPath), 'system_settings.php exists');
$settings = file_get_contents($settingsPath);

$assert(str_contains($settings, 'generate_tunnel_setup_block'), 'Card exposes the setup block generation action');
$assert(str_contains($settings, 'generate_tunnel_removal_block'), 'Card exposes the removal block generation action');
$assert(str_contains($settings, 'require_local_request()'), 'Generation is local-request only');
$assert(str_contains($settings, 'csrf_verify()'), 'Card POST actions verify CSRF');
$assert(str_contains($settings, 'require_capability(\\App\\Authorization\\RoleCapabilityPolicy::PLATFORM_GOVERNANCE)'), 'Card is guarded by platform governance');
$assert(str_contains($settings, 'TunnelSetupBlockBuilder'), 'Card uses the pure block builder');
$assert(str_contains($settings, 'log_activity'), 'Card writes an audit record for generation');
$assert(str_contains($settings, "'mode' => \$tunnelMode"), 'Audit record names the selected mode');
$assert(!preg_match("/log_activity\([^;]*'token'/s", $settings), 'Audit record excludes the raw token');
$assert(str_contains($settings, "masked_token"), 'Card displays only the masked token by default');
$assert(str_contains($settings, 'Cloudflare dashboard checklist'), 'Card shows the Cloudflare dashboard checklist');
$assert(str_contains($settings, 'Interim Quick Tunnel'), 'Card labels Quick Tunnel mode as interim');
$assert(str_contains($settings, 'Cloudflare Access is not in front of it'), 'Card states the Quick Tunnel lacks Cloudflare Access');
$assert(str_contains($settings, 'http://127.0.0.1:<?= (int)$localAppPort ?>'), 'Checklist points at the configured local origin');
$assert(str_contains($settings, 'id="tunnel-setup-block"') || str_contains($settings, "'tunnel-setup-block'"), 'Generated setup block has a copyable element');
$assert(str_contains($settings, 'navigator.clipboard'), 'Generated block supports clipboard copy');
$assert(str_contains($settings, 'execCommand'), 'Generated block has a clipboard fallback');

echo "\n==========================================\n";
if (!empty($failures)) {
    echo 'TEST RUN FAILED with ' . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo " - {$failure}\n";
    }
    exit(1);
}

echo "SUCCESS: All Remote Access setup block contract tests passed!\n";
exit(0);
