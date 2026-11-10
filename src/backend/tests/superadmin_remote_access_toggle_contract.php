<?php

declare(strict_types=1);

// Contract tests for the Super Administrator Remote Access toggle:
// - Platform Settings exposes the same on/off switch as the Administrator card
// - The switch calls the existing remote_access_api.php start/stop/status actions
// - Live status copy and the section badge follow the tunnel state

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
};

$root = dirname(__DIR__, 3);
$settingsFile = $root . '/src/frontend/components/system_administrator/system_settings.php';
$apiFile = $root . '/src/frontend/components/administrator/remote_access_api.php';
$scriptFile = $root . '/src/frontend/assets/js/remote-access-toggle.js';

echo "\n=== 1. Super Administrator Remote Access toggle ===\n";

$assert(file_exists($settingsFile), 'system_settings.php exists');
$assert(file_exists($apiFile), 'remote_access_api.php exists');
$assert(file_exists($scriptFile), 'remote-access-toggle.js exists');
$settings = file_exists($settingsFile) ? (string)file_get_contents($settingsFile) : '';
$api = file_exists($apiFile) ? (string)file_get_contents($apiFile) : '';
$script = file_exists($scriptFile) ? (string)file_get_contents($scriptFile) : '';

$assert(str_contains($settings, 'id="remote-access-toggle"'), 'Platform Settings has the Remote Access toggle switch');
$assert(str_contains($settings, 'role="switch"'), 'Remote Access toggle exposes the switch role');
$assert(str_contains($settings, 'Cloudflare Tunnel'), 'Toggle is labelled Cloudflare Tunnel');
$assert(str_contains($settings, 'Active &amp; encrypted'), 'Toggle shows the Active & encrypted state copy');
$assert(str_contains($settings, 'remote-toggle-status-text'), 'Toggle has live state text');
$assert(str_contains($settings, 'remote-access-feedback'), 'Toggle has an aria-live feedback region');

echo "\n=== 2. Toggle drives the existing tunnel API ===\n";

$assert(str_contains($settings, 'remote-access-toggle-config'), 'Platform Settings supplies the toggle configuration');
$assert(str_contains($settings, 'remote-access-toggle.js'), 'Platform Settings loads the toggle controller');
$assert(str_contains($script, 'X-CSRF-TOKEN'), 'Toggle sends the CSRF token');
$assert(str_contains($script, "request(shouldStart ? 'start' : 'stop')"), 'Toggle sends the start and stop actions');
$assert(str_contains($script, 'fetch(apiUrl, options)'), 'Toggle calls the configured tunnel API');
$assert(str_contains($api, "require_role(['admin', 'super_admin']);"), 'Tunnel mutations allow the Super Administrator role');

echo "\n=== 3. Live state follows the tunnel ===\n";

$assert(str_contains($settings, 'id="tunnel-public-url"'), 'Public URL value is addressable for live updates');
$assert(str_contains($settings, 'id="remote-mode-pill"'), 'Section badge is addressable for live updates');
$assert(str_contains($settings, 'store-settings.css'), 'Remote Access card reuses the existing tunnel card styles');
$assert(str_contains($script, 'if (!isCurrent(generation))'), 'Stale responses are discarded after a later toggle');
$assert(str_contains($script, "config.state === 'starting'"), 'A reload during startup resumes status polling');

echo "\n==========================================\n";
if (empty($failures)) {
    echo "SUCCESS: Super Administrator Remote Access toggle contract passed!\n";
    exit(0);
}

echo 'FAILURES DETECTED: ' . count($failures) . "\n";
foreach ($failures as $failure) {
    echo " - {$failure}\n";
}
exit(1);
