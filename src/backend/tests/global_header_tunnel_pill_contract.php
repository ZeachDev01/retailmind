<?php

declare(strict_types=1);

// global_header_tunnel_pill_contract.php
// Contract and integration tests for Ticket #443:
// - Global top navigation header remote access indicator across all workspace views and roles (Admin, Super Admin, Inventory Manager, Cashier)
// - Visual distinction between active (online - illuminated green badge/pill) and inactive (offline - muted/gray/subtle pill)
// - Interactive dropdown or popover with active remote URL and 1-click copy button with clipboard copy action and feedback
// - Status synchronization across navigation via remote_access_api.php

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

echo "=== 1. API Role-Based Authorization Seam ===\n";

$apiFile = $root . '/src/frontend/components/administrator/remote_access_api.php';
$assert(file_exists($apiFile), "remote_access_api.php exists at {$apiFile}");

$runApiRequest = function (int $userId, int $sessionVer, string $role, string $method = 'GET', array $postData = [], ?string $csrfToken = null) use ($root, $apiFile): array {
    $wrapperFile = sys_get_temp_dir() . '/api_req_role_' . uniqid() . '.php';
    $rootSafe = var_export($root, true);
    $apiSafe = var_export($apiFile, true);
    $userIdSafe = var_export($userId, true);
    $sessionVerSafe = var_export($sessionVer, true);
    $roleSafe = var_export($role, true);
    $methodSafe = var_export($method, true);
    $postSafe = var_export($postData, true);
    $csrfSafe = var_export($csrfToken, true);

    $code = <<<PHP
<?php
define('APP_ROOT', {$rootSafe});
\$_SERVER['DOCUMENT_ROOT'] = {$rootSafe} . '/src/frontend';
\$_SERVER['REQUEST_METHOD'] = {$methodSafe};
\$_SERVER['REQUEST_URI'] = '/retailmind/components/administrator/remote_access_api.php';
\$_SERVER['HTTP_HOST'] = '127.0.0.1';

require_once {$rootSafe} . '/src/backend/bootstrap/app.php';
require_once {$rootSafe} . '/src/backend/includes/auth.php';
require_once {$rootSafe} . '/src/backend/includes/csrf.php';

\$_SESSION = [
    'user_id' => {$userIdSafe},
    'username' => 'test_' . {$roleSafe},
    'display_name' => 'Test User',
    'role' => {$roleSafe},
    'roles' => [{$roleSafe}],
    'session_version' => {$sessionVerSafe},
];

if ({$csrfSafe} !== null) {
    \$_SERVER['HTTP_X_CSRF_TOKEN'] = {$csrfSafe};
    \$_SESSION['csrf_token'] = {$csrfSafe};
}

if ({$methodSafe} === 'POST') {
    \$_POST = {$postSafe};
}

require {$apiSafe};
PHP;

    file_put_contents($wrapperFile, $code);
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

$roleConfigs = [
    'super_admin' => ['user_id' => 1, 'session_version' => 5],
    'admin' => ['user_id' => 1, 'session_version' => 5],
    'inventory_manager' => ['user_id' => 1, 'session_version' => 5],
    'cashier' => ['user_id' => 76, 'session_version' => 1],
];

foreach ($roleConfigs as $testRole => $cfg) {
    $res = $runApiRequest($cfg['user_id'], $cfg['session_version'], $testRole, 'GET');
    $assert(
        $res['json'] !== null && ($res['json']['success'] ?? false) === true,
        "GET remote_access_api allows role '{$testRole}' to monitor status"
    );
    if ($res['json'] !== null) {
        $assert(isset($res['json']['status']), "Role '{$testRole}' receives 'status' in GET payload");
        $assert(isset($res['json']['online']), "Role '{$testRole}' receives 'online' boolean in GET payload");
    }
}

// Ensure non-admin roles CANNOT perform mutating POST actions (e.g. start/stop)
$dummyToken = 'valid-token';

foreach (['inventory_manager', 'cashier'] as $nonAdminRole) {
    $cfg = $roleConfigs[$nonAdminRole];
    $postRes = $runApiRequest($cfg['user_id'], $cfg['session_version'], $nonAdminRole, 'POST', ['action' => 'start'], $dummyToken);
    $assert(
        $postRes['json'] === null || ($postRes['json']['success'] ?? false) === false,
        "POST action 'start' is blocked for non-admin role '{$nonAdminRole}'"
    );
}

echo "\n=== 2. Navbar Absence Contract Across Roles in sidebar.php ===\n";

$sidebarFile = $root . '/src/frontend/components/sidebar.php';
$assert(file_exists($sidebarFile), "sidebar.php exists at {$sidebarFile}");

$tunnelDir = $root . '/src/backend/storage/tunnel';
$testPidFile = $tunnelDir . '/tunnel.pid';
$testUrlFile = $tunnelDir . '/tunnel.url';
$testLogFile = $tunnelDir . '/tunnel.log';
$testLogBak = $tunnelDir . '/tunnel.log.bak';

$setTestTunnelState = function (bool $online, ?string $url = null) use ($tunnelDir, $testPidFile, $testUrlFile, $testLogFile, $testLogBak): void {
    if (!is_dir($tunnelDir)) {
        @mkdir($tunnelDir, 0755, true);
    }
    if ($online) {
        if (file_exists($testLogFile) && !file_exists($testLogBak)) {
            @rename($testLogFile, $testLogBak);
        }
        file_put_contents($testPidFile, (string)getmypid());
        if ($url !== null && $url !== '') {
            file_put_contents($testUrlFile, $url);
            file_put_contents($testLogFile, "Your quick Tunnel has been created! Visit it at: {$url}\n");
        } else {
            @unlink($testUrlFile);
            file_put_contents($testLogFile, "Starting tunnel daemon, waiting for connection...\n");
        }
    } else {
        @unlink($testPidFile);
        @unlink($testUrlFile);
        @unlink($testLogFile);
        if (file_exists($testLogBak)) {
            @rename($testLogBak, $testLogFile);
        }
    }
};

$renderSidebar = function (string $role) use ($root, $sidebarFile): string {
    $wrapperFile = sys_get_temp_dir() . '/sidebar_render_' . uniqid() . '.php';
    $rootSafe = var_export($root, true);
    $sidebarSafe = var_export($sidebarFile, true);
    $roleSafe = var_export($role, true);

    $code = <<<PHP
<?php
define('APP_ROOT', {$rootSafe});
\$_SERVER['DOCUMENT_ROOT'] = {$rootSafe} . '/src/frontend';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI'] = '/retailmind/components/administrator/dashboard.php';
\$_SERVER['HTTP_HOST'] = '127.0.0.1';

function current_role(): ?string {
    return {$roleSafe};
}

function current_roles(): array {
    return [{$roleSafe}];
}

function current_workspace_roles(?string \$primaryRole = null): array {
    return [{$roleSafe}];
}

function is_logged_in(): bool {
    return true;
}

function app_url(string \$path = ''): string {
    return '/retailmind/' . ltrim(\$path, '/');
}

function profile_avatar_html(int \$userId, string \$name, ?string \$image = null, string \$class = ''): string {
    return '<span class="avatar-mock"></span>';
}

function csrf_token(): string {
    return 'mock-csrf-token';
}

\$_SESSION = [
    'user_id' => 101,
    'username' => 'test_' . {$roleSafe},
    'display_name' => 'Test User',
    'role' => {$roleSafe},
    'roles' => [{$roleSafe}],
];

ob_start();
require {$sidebarSafe};
echo ob_get_clean();
PHP;

    file_put_contents($wrapperFile, $code);
    $output = [];
    $returnCode = 0;
    exec('php ' . escapeshellarg($wrapperFile), $output, $returnCode);
    @unlink($wrapperFile);

    return implode("\n", $output);
};

// Ensure tunnel is offline initially
$setTestTunnelState(false);

foreach (array_keys($roleConfigs) as $roleName) {
    $html = $renderSidebar($roleName);
    
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    // Desktop sidebar footer: pill must NOT be rendered in navigation
    $desktopPill = $xpath->query('//div[contains(@class, "sidebar-footer")]//*[@data-remote-pill-trigger]');
    $assert($desktopPill->length === 0, "Role '{$roleName}' does not have remote access pill in sidebar-footer");

    // Mobile topbar: pill must NOT be rendered in navigation
    $mobilePill = $xpath->query('//div[contains(@class, "admin-mobile-topbar")]//*[@data-remote-pill-trigger]');
    $assert($mobilePill->length === 0, "Role '{$roleName}' does not have remote access pill in admin-mobile-topbar");

    // Global topbar: pill must NOT be rendered in topbar
    $globalPill = $xpath->query('//div[contains(@class, "global-topbar")]//*[@data-remote-pill-trigger]');
    $assert($globalPill->length === 0, "Role '{$roleName}' does not have remote access pill in global-topbar");
}

echo "\n=== 3. Online vs Offline Visual States & Markup Contract (Component Helper) ===\n";

$renderPillHelper = function (bool $online, string $url = '', string $role = 'admin') use ($root, $sidebarFile): string {
    $wrapperFile = sys_get_temp_dir() . '/pill_render_' . uniqid() . '.php';
    $rootSafe = var_export($root, true);
    $sidebarSafe = var_export($sidebarFile, true);
    $roleSafe = var_export($role, true);
    $onlineSafe = $online ? 'true' : 'false';
    $urlSafe = var_export($url, true);

    $code = <<<PHP
<?php
define('APP_ROOT', {$rootSafe});
\$_SERVER['DOCUMENT_ROOT'] = {$rootSafe} . '/src/frontend';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['REQUEST_URI'] = '/retailmind/components/administrator/dashboard.php';
\$_SERVER['HTTP_HOST'] = '127.0.0.1';

function current_role(): ?string { return {$roleSafe}; }
function current_roles(): array { return [{$roleSafe}]; }
function current_workspace_roles(?string \$primaryRole = null): array { return [{$roleSafe}]; }
function is_logged_in(): bool { return true; }
function app_url(string \$path = ''): string { return '/retailmind/' . ltrim(\$path, '/'); }
function profile_avatar_html(): string { return ''; }
function csrf_token(): string { return 'mock-csrf-token'; }

\$_SESSION = [
    'user_id' => 101,
    'username' => 'test_' . {$roleSafe},
    'display_name' => 'Test User',
    'role' => {$roleSafe},
    'roles' => [{$roleSafe}],
];

ob_start();
require_once {$sidebarSafe};
ob_end_clean();

echo sidebar_render_remote_access_pill('desktop', {$roleSafe}, {$onlineSafe}, {$urlSafe});
PHP;

    file_put_contents($wrapperFile, $code);
    $output = [];
    $returnCode = 0;
    exec('php ' . escapeshellarg($wrapperFile), $output, $returnCode);
    @unlink($wrapperFile);

    return implode("\n", $output);
};

// Test Offline State
$setTestTunnelState(false);
$offlineHtml = $renderPillHelper(false, '', 'admin');
$offlineDoc = new DOMDocument();
libxml_use_internal_errors(true);
$offlineDoc->loadHTML($offlineHtml);
libxml_clear_errors();
$offlineXpath = new DOMXPath($offlineDoc);

$offlineTrigger = $offlineXpath->query('//*[@data-remote-pill-trigger]')->item(0);
$assert($offlineTrigger !== null, "Trigger exists in offline state");
if ($offlineTrigger !== null) {
    $classAttr = $offlineTrigger->getAttribute('class');
    $assert(str_contains($classAttr, 'is-offline'), "Trigger has 'is-offline' class in offline state");
    $assert(!str_contains($classAttr, 'is-online'), "Trigger does not have 'is-online' class in offline state");
}

$offlineBadge = $offlineXpath->query('//*[@data-remote-badge]')->item(0);
$assert($offlineBadge !== null && str_contains(strtolower($offlineBadge->textContent), 'offline'), "Popover badge displays 'Offline' when inactive");

// Test Online State with active tunnel URL
$samplePublicUrl = 'https://crystal-frost-beacon.trycloudflare.com';
$setTestTunnelState(true, $samplePublicUrl);
$onlineHtml = $renderPillHelper(true, $samplePublicUrl, 'admin');
$onlineDoc = new DOMDocument();
libxml_use_internal_errors(true);
$onlineDoc->loadHTML($onlineHtml);
libxml_clear_errors();
$onlineXpath = new DOMXPath($onlineDoc);

$onlineTrigger = $onlineXpath->query('//*[@data-remote-pill-trigger]')->item(0);
$assert($onlineTrigger !== null, "Trigger exists in online state");
if ($onlineTrigger !== null) {
    $classAttr = $onlineTrigger->getAttribute('class');
    $assert(str_contains($classAttr, 'is-online'), "Trigger has 'is-online' class in online state");
    $assert(!str_contains($classAttr, 'is-offline'), "Trigger does not have 'is-offline' class in online state");
}

$onlineBadge = $onlineXpath->query('//*[@data-remote-badge]')->item(0);
$assert($onlineBadge !== null && str_contains(strtolower($onlineBadge->textContent), 'online'), "Popover badge displays 'Online' when active");

$onlineUrlInput = $onlineXpath->query('//input[@data-remote-url-input]')->item(0);
$assert($onlineUrlInput !== null, "Popover includes active URL input element");
if ($onlineUrlInput !== null) {
    $assert($onlineUrlInput->getAttribute('value') === $samplePublicUrl, "Popover URL input displays active public tunnel URL");
}

// Test Online State with connecting / resolving URL (empty URL string)
$setTestTunnelState(true, '');
$connectingHtml = $renderPillHelper(true, '', 'admin');
$connectingDoc = new DOMDocument();
libxml_use_internal_errors(true);
$connectingDoc->loadHTML($connectingHtml);
libxml_clear_errors();
$connectingXpath = new DOMXPath($connectingDoc);

$connectingTrigger = $connectingXpath->query('//*[@data-remote-pill-trigger]')->item(0);
$assert($connectingTrigger !== null, "Trigger exists in connecting state");
if ($connectingTrigger !== null) {
    $classAttr = $connectingTrigger->getAttribute('class');
    $assert(str_contains($classAttr, 'is-online'), "Trigger has 'is-online' class when online without URL");
    $assert(!str_contains($classAttr, 'is-offline'), "Trigger does not have 'is-offline' class when online without URL");
}
$connectingBadge = $connectingXpath->query('//*[@data-remote-badge]')->item(0);
$assert($connectingBadge !== null && str_contains(strtolower($connectingBadge->textContent), 'connecting'), "Popover badge displays 'Connecting' when URL is pending");
$connectingDesc = $connectingXpath->query('//*[@data-remote-desc]')->item(0);
$assert($connectingDesc !== null && str_contains(strtolower($connectingDesc->textContent), 'resolving'), "Popover description explains URL is resolving");

echo "\n=== 4. Interactive Dropdown/Popover and 1-Click Copy Markup Contract ===\n";

$popovers = $onlineXpath->query('//*[@data-remote-popover]');
$assert($popovers->length >= 1, "At least one data-remote-popover exists in markup");

$copyButtons = $onlineXpath->query('//*[@data-remote-copy-btn]');
$assert($copyButtons->length >= 1, "At least one 1-click copy button exists");

$feedbackElements = $onlineXpath->query('//*[@data-remote-feedback]');
$assert($feedbackElements->length >= 1, "Feedback element for clipboard copy exists");

$ariaExpanded = $onlineTrigger ? $onlineTrigger->getAttribute('aria-expanded') : '';
$assert($ariaExpanded === 'false', "Popover trigger starts with aria-expanded='false'");

$hasPopup = $onlineTrigger ? $onlineTrigger->getAttribute('aria-haspopup') : '';
$assert($hasPopup === 'true' || $hasPopup === 'dialog', "Popover trigger has aria-haspopup attribute");

echo "\n=== 5. Status Synchronization Contract & Assets ===\n";

$pillJsFile = $root . '/src/frontend/assets/js/remote-access-pill.js';
$assert(file_exists($pillJsFile), "remote-access-pill.js script exists");

$pillCssFile = $root . '/src/frontend/assets/css/remote-access-pill.css';
$assert(file_exists($pillCssFile), "remote-access-pill.css stylesheet exists");

if (file_exists($pillJsFile)) {
    $jsSource = file_get_contents($pillJsFile);
    $assert(str_contains($jsSource, 'remote_access_api.php'), "remote-access-pill.js references remote_access_api.php");
    $assert(str_contains($jsSource, 'navigator.clipboard') || str_contains($jsSource, 'execCommand'), "remote-access-pill.js supports clipboard copy");
    $assert(str_contains($jsSource, 'is-online'), "remote-access-pill.js updates is-online state class");
    $assert(str_contains($jsSource, 'is-offline'), "remote-access-pill.js updates is-offline state class");
    $assert(str_contains($jsSource, 'attachDismissListeners') && str_contains($jsSource, 'detachDismissListeners'), "remote-access-pill.js attaches and detaches dismiss listeners on popover open/close");
    $assert(!preg_match('/document\.addEventListener\(\s*[\'"](?:click|keydown)[\'"]\s*,\s*closeAllPopovers\s*\)/', $jsSource), "remote-access-pill.js does not register unbounded global dismiss listeners");
}

if (file_exists($pillCssFile)) {
    $cssSource = file_get_contents($pillCssFile);
    $assert(str_contains($cssSource, '.is-online'), "remote-access-pill.css defines .is-online illuminated style");
    $assert(str_contains($cssSource, '.is-offline'), "remote-access-pill.css defines .is-offline muted style");
}

// Clean up test files
$setTestTunnelState(false);

echo "\n==========================================\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . " contract tests failed.\n";
    exit(1);
}

echo "SUCCESS: All Global Header Tunnel Pill contract tests passed!\n";
exit(0);
