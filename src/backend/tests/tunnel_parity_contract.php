<?php

declare(strict_types=1);

// Contract tests for ADR-0030: tunnel sessions have full workspace parity for
// admin, inventory_manager, and cashier; the Super Administrator workspace
// stays local-only.

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

// Exercise the real workspace helpers without opening a database connection.
$authSource = str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/../includes/auth.php'));
$evalFunction = static function (string $source, string $name): void {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        throw new RuntimeException("Missing function {$name}() in auth.php");
    }
    $end = strpos($source, "\n}\n", $start);
    if ($end === false) {
        throw new RuntimeException("Unterminated function {$name}() in auth.php");
    }
    eval(substr($source, $start, $end - $start + 3));
};
foreach (['current_role', 'current_roles', 'current_workspace_roles', 'workspace_blocked_over_tunnel'] as $functionName) {
    $evalFunction($authSource, $functionName);
}

$serverKeys = ['HTTP_HOST', 'SERVER_NAME', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_RAY', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_VISITOR', 'RETAILMIND_REMOTE'];
$resetEnv = static function () use ($serverKeys): void {
    foreach ($serverKeys as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
    }
    unset($_SESSION['is_remote']);
};

echo "\n=== 1. Tunnel host detection ===\n";

foreach (['store.trycloudflare.com', 'abc.ngrok-free.app', 'abc.ngrok.io', 'abc.loca.lt', 'abc.lhr.life', 'abc.localtunnel.me'] as $tunnelHost) {
    $assert(is_tunnel_host($tunnelHost), "{$tunnelHost} is a tunnel host");
    $assert(is_tunnel_host(strtoupper($tunnelHost) . ':8443'), "{$tunnelHost} with port and casing is a tunnel host");
}

$assert(!is_tunnel_host('localhost'), 'localhost is not a tunnel host');
$assert(!is_tunnel_host('192.168.1.20'), 'LAN address is not a tunnel host');
$assert(!is_tunnel_host('retailmind.infinityfreeapp.com'), 'Hosted public domain is not a tunnel host');

echo "\n=== 2. Tunnel requests are local, not remote ===\n";

$resetEnv();
$_SERVER['HTTP_HOST'] = 'store.trycloudflare.com';
$assert(is_tunnel_request(), 'Tunnel request is detected as tunnel');
$assert(!is_remote_request(), 'Tunnel request is not remote');

$resetEnv();
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'store.trycloudflare.com';
$assert(!is_remote_request(), 'Forwarded tunnel host is not remote');

$resetEnv();
$_SERVER['HTTP_HOST'] = 'store.trycloudflare.com';
$_SERVER['HTTP_CF_RAY'] = '8d1234567890abcd-NRT';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
$assert(!is_remote_request(), 'Tunnel host wins over Cloudflare edge headers');

$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$assert(!is_remote_request(), 'Localhost stays local');

$resetEnv();
$_SERVER['HTTP_HOST'] = 'retailmind.infinityfreeapp.com';
$assert(is_remote_request(), 'Non-tunnel public host stays remote');

$resetEnv();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
$assert(is_remote_request(), 'Cloudflare edge IP without tunnel host stays remote');

echo "\n=== 3. Super Administrator workspace is local-only on tunnels ===\n";

$resetEnv();
$_SERVER['HTTP_HOST'] = 'store.trycloudflare.com';
$_SESSION['roles'] = ['super_admin', 'admin'];
$_SESSION['role'] = 'super_admin';
$assert(workspace_blocked_over_tunnel(), 'Active super_admin workspace is blocked on a tunnel');
$assert(current_workspace_roles() === ['admin'], 'Tunnel hides the super_admin workspace from the switcher');

$_SESSION['role'] = 'admin';
$assert(!workspace_blocked_over_tunnel(), 'Active admin workspace is allowed on a tunnel');
$assert(current_workspace_roles() === ['admin'], 'Tunnel keeps other assigned workspaces');

$resetEnv();
$_SERVER['HTTP_HOST'] = 'localhost';
$_SESSION['role'] = 'super_admin';
$assert(!workspace_blocked_over_tunnel(), 'super_admin workspace stays available on localhost');
$assert(current_workspace_roles() === ['super_admin', 'admin'], 'Localhost keeps every assigned workspace');

$_SESSION['roles'] = ['super_admin'];
unset($_SESSION['role']);
$resetEnv();
$_SERVER['HTTP_HOST'] = 'store.trycloudflare.com';
$assert(current_workspace_roles() === [], 'super_admin-only account has no tunnel workspace');

unset($_SESSION['roles'], $_SESSION['role']);
$resetEnv();

echo "\n=== 4. Gates and sources wire the policy ===\n";

$root = dirname(__DIR__, 3);
$authCode = (string)file_get_contents(__DIR__ . '/../includes/auth.php');
$bootstrapCode = (string)file_get_contents(__DIR__ . '/../bootstrap/app.php');
$databaseCode = (string)file_get_contents(__DIR__ . '/../config/database.php');
$denialFile = __DIR__ . '/../includes/access_denied.php';

$assert(is_file($denialFile), 'Shared access-denied surface exists');
$assert(str_contains($bootstrapCode, 'function is_tunnel_host('), 'is_tunnel_host lives in bootstrap');
$assert(str_contains($bootstrapCode, 'is_tunnel_request()) {'), 'Remote detection short-circuits tunnel requests');
$assert(str_contains($databaseCode, 'is_tunnel_host($host)'), 'Database host classification reuses the tunnel helper');

$requireRolePos = strpos($authCode, 'function require_role(');
$requireRoleBody = $requireRolePos === false ? '' : substr($authCode, $requireRolePos, 900);
$assert(str_contains($requireRoleBody, 'require_workspace_access();'), 'require_role blocks the super_admin workspace on tunnels');
$assert(str_contains($authCode, "function require_any_capability("), 'Capability gate exists');
$assert(substr_count($authCode, 'require_workspace_access();') >= 3, 'Every gateway applies the workspace block');

$gatewayFile = (string)file_get_contents($root . '/src/frontend/components/auth/workspace.php');
$assert(str_contains($gatewayFile, 'current_workspace_roles()'), 'Workspace switcher uses the filtered workspace list');

$loginFile = (string)file_get_contents($root . '/src/backend/includes/auth.php');
$assert(str_contains($loginFile, '$availableWorkspaces = current_workspace_roles('), 'Sign-in lands on an available workspace');

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s).\n";
    exit(1);
}
echo "Tunnel parity contract: passed.\n";
